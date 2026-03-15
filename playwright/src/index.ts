#!/usr/bin/env node
// pw-generator — Generate Playwright tests from Laravel source code

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
      reportVolume:     flag(args, '--gitea-report-vol') ?? 'playwright-report',
    },
  };

  console.log('\n🔍  Analysing Laravel project...');
  console.log(`    source : ${path.resolve(laravelPath)}`);
  console.log(`    output : ${path.resolve(outputDir)}\n`);

  let analysis;
  try {
    analysis = analyzeProject(laravelPath);
  } catch (err: any) {
    console.error(`❌  ${err.message}`);
    process.exit(1);
  }

  analysis.testUser = opts.testUser;
  analysis.baseUrl  = opts.baseUrl;

  console.log(`📊  Analysis results:`);
  console.log(`    resources : ${analysis.resources.length} (${analysis.resources.map(r => r.name).join(', ')})`);
  console.log(`    auth      : ${analysis.hasAuth    ? '✓' : '✗'}`);
  console.log(`    profile   : ${analysis.hasProfile ? '✓' : '✗'}`);
  for (const r of analysis.resources) {
    const ops = [r.hasIndex && 'index', r.hasCreate && 'create',
                 r.hasEdit  && 'edit',  r.hasDelete && 'delete'].filter(Boolean).join(' | ');
    console.log(`    ${r.name.padEnd(16)} [${r.fields.map(f => f.name).join(', ')}]  ${ops}`);
  }
  console.log('');

  // Create directory tree
  const dirs = [outputDir, 'fixtures', 'pages', 'tests'].map(d =>
    d === outputDir ? d : path.join(outputDir, d)
  );
  if (opts.gitea.enabled) dirs.push(path.join(outputDir, '.gitea', 'workflows'));
  dirs.forEach(d => fs.mkdirSync(d, { recursive: true }));

  const written: string[] = [];
  const write = (rel: string, content: string) => {
    fs.writeFileSync(path.join(outputDir, rel), content, 'utf-8');
    written.push(rel);
  };

  // Config
  write('playwright.config.ts', generatePlaywrightConfig(opts));
  write('package.json',         generatePackageJson(opts));
  write('tsconfig.json',        generateTsConfig());
  if (opts.gitea.enabled) {
    write('.gitea/workflows/playwright.yml', generateGiteaWorkflow(opts));
  }

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

  // Summary
  console.log('✅  Files generated:\n');
  const groups: Record<string, string[]> = {
    Workflow: [], Config: [], Fixtures: [], Pages: [], Tests: [],
  };
  for (const f of written) {
    if      (f.startsWith('.gitea'))     groups.Workflow.push(f);
    else if (f.startsWith('fixtures/')) groups.Fixtures.push(f);
    else if (f.startsWith('pages/'))    groups.Pages.push(f);
    else if (f.startsWith('tests/'))    groups.Tests.push(f);
    else                                groups.Config.push(f);
  }
  for (const [group, files] of Object.entries(groups)) {
    if (!files.length) continue;
    console.log(`  ${group}:`);
    files.forEach(f => console.log(`    ✓ ${f}`));
  }

  console.log('');
  if (opts.gitea.enabled) {
    console.log(`🚀  Gitea workflow generated — push to trigger CI on branch: ${opts.gitea.branch}`);
    console.log(`    App must be running at: http://${opts.gitea.appHost}`);
    console.log(`    HTML report saved to Docker volume: ${opts.gitea.reportVolume}`);
  }
  console.log('');
  console.log(`📦  Next steps:`);
  console.log(`    cd ${outputDir} && npm install`);
  console.log(`    BASE_URL=${opts.baseUrl} npx playwright test   # run locally`);
  if (opts.gitea.enabled) {
    console.log(`    git init && git add . && git commit -m "add playwright tests"`);
    console.log(`    git remote add origin <gitea-repo-url> && git push -u origin ${opts.gitea.branch}`);
  }
  console.log(`\n    test user: ${opts.testUser.email} / ${opts.testUser.password}\n`);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function flag(args: string[], name: string): string | undefined {
  const i = args.indexOf(name);
  return i !== -1 ? args[i + 1] : undefined;
}

function printHelp(): void {
  console.log(`
pw-generator — Generate Playwright tests + Gitea CI from a Laravel project

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
  --gitea-report-vol    HTML report volume name      (default: playwright-report)
  --no-workflow         Skip .gitea/workflows/ generation

  --help, -h            Show this help

EXAMPLES:
  npx ts-node src/index.ts ./my-laravel-app ./pw-tests
  npx ts-node src/index.ts ./app ./tests --email admin@app.com --password secret
  npx ts-node src/index.ts ./app ./tests --no-workflow
`);
}

main();
