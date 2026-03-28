#!/usr/bin/env node
// testgen — Generate Playwright tests from Laravel source code

import * as fs   from 'fs';
import * as path from 'path';
import { analyzeProject } from './analyzer';
import { generateFixtures }                            from './generators/fixtures';
import { generateBasePage, generateLoginPage,
         generateRegisterPage, generateDashboardPage,
         generateProfilePage, generateResourcePage }   from './generators/pages';
import { generateAuthSpec, generateProfileSpec,
         generateResourceSpec }                        from './generators/specs';
import { generatePlaywrightConfig, generatePackageJson,
         generateTsConfig, generateGiteaWorkflow }     from './generators/config';
import { GeneratorOptions } from './types';

// ─── CLI ─────────────────────────────────────────────────────────────────────

function main(): void {
  const startTime = Date.now();
  const args = process.argv.slice(2);

  if (args.length === 0 || args.includes('--help') || args.includes('-h')) {
    printHelp();
    process.exit(0);
  }

  const laravelPath = args[0];
  const outputDir   = args[1] ?? './playwright-tests';

  const opts: GeneratorOptions = {
    outputDir,
    baseUrl:   flag(args, '--base-url')   ?? 'http://localhost:8000',
    testUser: {
      email:    flag(args, '--email')     ?? 'playwright@example.com',
      password: flag(args, '--password')  ?? 'playwright',
      name:     flag(args, '--user-name') ?? 'Test User',
    },
    gitea: {
      enabled:          !args.includes('--no-workflow'),
      serverUrl:        flag(args, '--gitea-server-url') ?? 'http://gitea:3000',
      appHost:          flag(args, '--gitea-app-host')   ?? 'host.docker.internal:8000',
      playwrightImage:  flag(args, '--gitea-image')      ?? 'mcr.microsoft.com/playwright:v1.58.2-jammy',
      branch:           flag(args, '--gitea-branch')     ?? 'main',
      npmCacheVolume:   flag(args, '--gitea-cache-vol')  ?? 'playwright-npm-cache',
      reportBranch:     flag(args, '--gitea-report-branch') ?? 'playwright-report',
    },
  };

  console.log('\nPlaywright Test Generator v1.0.0\n');
  console.log('Analysing Laravel workspace...');
  console.log(`  ✓ Source : ${path.resolve(laravelPath)}`);
  console.log(`  ✓ Target : ${path.resolve(outputDir)}\n`);

  let analysis;
  try {
    analysis = analyzeProject(laravelPath);
  } catch (err: any) {
    console.error(`❌  ${err.message}`);
    process.exit(1);
  }

  analysis.testUser = opts.testUser;
  analysis.baseUrl  = opts.baseUrl;

  // Create directory tree
  const dirs = [outputDir, 'fixtures', 'pages', 'tests'].map(d =>
    d === outputDir ? d : path.join(outputDir, d)
  );
  if (opts.gitea.enabled) dirs.push(path.join(outputDir, '.gitea', 'workflows'));
  dirs.forEach(d => fs.mkdirSync(d, { recursive: true }));

  const written: Record<string, string[]> = {
    pages: [],
    tests: [],
    config: [],
    workflow: [],
  };

  const write = (rel: string, content: string) => {
    fs.writeFileSync(path.join(outputDir, rel), content, 'utf-8');
    if      (rel.startsWith('pages/'))    written.pages.push(rel);
    else if (rel.startsWith('tests/'))    written.tests.push(rel);
    else if (rel.startsWith('.gitea/'))   written.workflow.push(rel);
    else                                  written.config.push(rel);
  };

  // Config
  write('playwright.config.ts', generatePlaywrightConfig(opts));
  write('package.json',         generatePackageJson(opts));
  write('tsconfig.json',        generateTsConfig());

  // Fixtures & base
  write('fixtures/test-data.ts', generateFixtures(analysis, opts));
  write('pages/BasePage.ts',     generateBasePage());

  // Auth pages & spec
  if (analysis.hasAuth) {
    const loginView    = analysis.authViews.find(v => v.path.includes('login'));
    const registerView = analysis.authViews.find(v => v.path.includes('register'));
    write('pages/LoginPage.ts',    generateLoginPage(loginView));
    write('pages/RegisterPage.ts', generateRegisterPage(registerView));
    write('pages/DashboardPage.ts',generateDashboardPage());
    write('tests/auth.spec.ts',    generateAuthSpec());
  }

  // Profile page & spec
  if (analysis.hasProfile) {
    write('pages/ProfilePage.ts',  generateProfilePage());
    write('tests/profile.spec.ts', generateProfileSpec());
  }

  // Resource pages & specs
  for (const resource of analysis.resources) {
    write(`pages/${resource.className}Page.ts`, generateResourcePage(resource));
    write(`tests/${resource.name}.spec.ts`,     generateResourceSpec(resource));
  }

  // Gitea workflow
  if (opts.gitea.enabled) {
    write('.gitea/workflows/playwright.yml', generateGiteaWorkflow(opts));
  }

  // Output generation summary
  if (written.pages.length > 0) {
    console.log('Generating Page Objects...');
    written.pages.forEach(f => console.log(`  ✓ ${f}`));
    console.log('');
  }

  if (written.tests.length > 0) {
    console.log('Generating Test Specs...');
    written.tests.forEach(f => console.log(`  ✓ ${f}`));
    console.log('');
  }

  if (written.config.length > 0) {
    console.log('Generating Playwright Configuration...');
    written.config.forEach(f => console.log(`  ✓ ${f}`));
    console.log('');
  }

  if (written.workflow.length > 0) {
    console.log('Generating Gitea CI/CD Workflow...');
    written.workflow.forEach(f => console.log(`  ✓ ${f}`));
    console.log('');
  }

  const totalFiles = Object.values(written).flat().length;
  const elapsedMs = Date.now() - startTime;
  const elapsedSec = (elapsedMs / 1000).toFixed(1);
  console.log(`Done in ${elapsedSec}s. ${totalFiles} artifacts generated successfully.\n`);

  // Next steps
  console.log('Next Steps:');
  console.log(`  1. Install dependencies`);
  console.log(`     $ cd ${outputDir} && npm install\n`);
  console.log(`  2. Run UI functional tests locally`);
  console.log(`     $ BASE_URL=${opts.baseUrl} npx playwright test\n`);
  console.log(`  3. Commit and push to Gitea`);
  console.log(`     $ git init && git add .`);
  console.log(`     $ git commit -m "test(ui): add playwright tests"`);
  console.log(`     $ git remote add origin <gitea-repo-url>`);
  console.log(`     $ git push -u origin ${opts.gitea.branch}\n`);

  // Test credentials
  console.log('Test Credentials:');
  console.log(`  Email    : ${opts.testUser.email}`);
  console.log(`  Password : ${opts.testUser.password}\n`);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function flag(args: string[], name: string): string | undefined {
  const i = args.indexOf(name);
  return i !== -1 ? args[i + 1] : undefined;
}

function printHelp(): void {
  console.log(`
testgen — Generate Playwright tests + Gitea CI from a Laravel project

USAGE:
  npx ts-node src/index.ts <laravel-path> [output-dir] [options]

OPTIONS:
  --base-url            App URL for local test runs  (default: http://localhost:8000)
  --email               Test user email              (default: playwright@example.com)
  --password            Test user password           (default: playwright)
  --user-name           Test user display name       (default: Test User)

  --gitea-server-url    Gitea server URL             (default: http://gitea:3000)
  --gitea-app-host      Laravel app host in Docker   (default: host.docker.internal:8000)
  --gitea-image         Playwright Docker image      (default: mcr.microsoft.com/playwright:v1.58.2-jammy)
  --gitea-branch        CI trigger branch            (default: main)
  --gitea-cache-vol     npm cache volume name        (default: playwright-npm-cache)
  --gitea-report-branch Git branch for HTML report   (default: playwright-report)
  --no-workflow         Skip .gitea/workflows/ generation

  --help, -h            Show this help

EXAMPLES:
  npx ts-node src/index.ts ./my-laravel-app ./pw-tests
  npx ts-node src/index.ts ./app ./tests --email admin@app.com --password secret
  npx ts-node src/index.ts ./app ./tests --no-workflow
`);
}

main();
