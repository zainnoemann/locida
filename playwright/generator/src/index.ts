import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { parseArgs, printHelp } from './shared/args.js';
import { loadDatasets } from './analyzers/load.js';
import { analyzeDatasets } from './analyzers/analyze.js';
import { generateBasePage, generateDashboardPage, generateLoginPage, generateProfilePage, generateRegisterPage, generateResourcePage } from './builders/pages.js';
import { generateAuthSetupSpec, generateAuthSpec, generateProfileSpec, generateResourceSpec } from './builders/specs.js';
import { generateFixtures } from './builders/fixtures.js';
import { generatePlaywrightConfig, generatePackageJson, generateTsConfig } from './builders/config.js';
import { generateGiteaWorkflow } from './builders/workflow.js';

function defaultDatasetDir(): string {
  return path.resolve(process.cwd(), '../crawler/storage/datasets/default');
}

function defaultOutputDir(): string {
  return path.resolve(process.cwd(), 'tests');
}

function scriptDir(): string {
  const scriptFile = fileURLToPath(import.meta.url);
  return path.dirname(scriptFile);
}

function main(): void {
  const startTime = Date.now();
  const args = process.argv.slice(2);

  const defaults = {
    datasetDir: defaultDatasetDir(),
    outputDir: defaultOutputDir(),
    baseUrl: 'http://host.docker.internal:8000',
  };

  const { datasetDir, outputDir, opts, showHelp } = parseArgs(args, defaults);

  if (showHelp) {
    printHelp();
    process.exit(0);
  }

  console.log('\nPlaywright Test Generator v1.0.0\n');
  console.log('Analysing Crawlee dataset...');
  console.log(`  ✓ Source : ${path.resolve(datasetDir)}`);
  console.log(`  ✓ Target : ${path.resolve(outputDir)}\n`);

  const byPath = loadDatasets(datasetDir);
  const analysis = analyzeDatasets(byPath);

  fs.rmSync(outputDir, { recursive: true, force: true });
  const dirs = [outputDir, 'fixtures', 'pages', 'tests'].map((d) => (d === outputDir ? d : path.join(outputDir, d)));
  if (opts.gitea.enabled) dirs.push(path.join(outputDir, '.gitea', 'workflows'));
  dirs.forEach((d) => fs.mkdirSync(d, { recursive: true }));

  const written: Record<string, string[]> = {
    pages: [],
    tests: [],
    config: [],
    workflow: [],
  };

  const write = (relPath: string, content: string): void => {
    fs.writeFileSync(path.join(outputDir, relPath), content, 'utf8');
    if (relPath.startsWith('pages/')) written.pages.push(relPath);
    else if (relPath.startsWith('tests/')) written.tests.push(relPath);
    else if (relPath.startsWith('.gitea/')) written.workflow.push(relPath);
    else written.config.push(relPath);
  };

  write('playwright.config.ts', generatePlaywrightConfig(opts, analysis.hasAuth));
  write('package.json', generatePackageJson());
  write('tsconfig.json', generateTsConfig());
  write('fixtures/test-data.ts', generateFixtures(analysis.resources, opts));

  write('pages/BasePage.ts', generateBasePage());
  if (analysis.hasAuth) {
    write('pages/LoginPage.ts', generateLoginPage());
    write('pages/RegisterPage.ts', generateRegisterPage());
    write('pages/DashboardPage.ts', generateDashboardPage());
    write('tests/auth.setup.ts', generateAuthSetupSpec());
    write('tests/auth.spec.ts', generateAuthSpec());
  }

  if (analysis.hasProfile) {
    write('pages/ProfilePage.ts', generateProfilePage());
    write('tests/profile.spec.ts', generateProfileSpec());
  }

  for (const resource of analysis.resources) {
    write(`pages/${resource.className}Page.ts`, generateResourcePage(resource));
    write(`tests/${resource.name}.spec.ts`, generateResourceSpec(resource));
  }

  if (opts.gitea.enabled) {
    write('.gitea/workflows/playwright.yml', generateGiteaWorkflow(opts));
  }

  if (written.pages.length > 0) {
    console.log('Generating Page Objects...');
    written.pages.forEach((f) => console.log(`  ✓ ${f}`));
    console.log('');
  }

  if (written.tests.length > 0) {
    console.log('Generating Test Specs...');
    written.tests.forEach((f) => console.log(`  ✓ ${f}`));
    console.log('');
  }

  if (written.config.length > 0) {
    console.log('Generating Playwright Configuration...');
    written.config.forEach((f) => console.log(`  ✓ ${f}`));
    console.log('');
  }

  if (written.workflow.length > 0) {
    console.log('Generating Gitea CI/CD Workflow...');
    written.workflow.forEach((f) => console.log(`  ✓ ${f}`));
    console.log('');
  }

  const totalFiles = Object.values(written).flat().length;
  const elapsedSec = ((Date.now() - startTime) / 1000).toFixed(1);
  console.log(`Done in ${elapsedSec}s. ${totalFiles} artifacts generated successfully.\n`);

  console.log('Next Steps:');
  console.log('  1. Install dependencies');
  console.log(`     $ cd ${outputDir} && npm install\n`);
  console.log('  2. Run UI functional tests locally');
  console.log(`     $ BASE_URL=${opts.baseUrl} npx playwright test\n`);
  console.log('  3. Commit and push to Gitea');
  console.log('     $ git init && git add .');
  console.log('     $ git commit -m "test(ui): add playwright tests"');
  console.log('     $ git remote add origin <gitea-repo-url>');
  console.log(`     $ git push -u origin ${opts.gitea.branch}\n`);

  console.log('Test Credentials:');
  console.log(`  Email    : ${opts.testUser.email}`);
  console.log(`  Password : ${opts.testUser.password}\n`);
}

main();
