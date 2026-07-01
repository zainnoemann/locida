import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { parseArgs, printHelp } from './shared/args.js';
import { loadDatasets } from './analyzers/load.js';
import { analyzeDatasets } from './analyzers/analyze.js';
import { getConfig } from './shared/config.js';
import { generateBasePage, generateDashboardPage, generateLoginPage, generateProfilePage, generateRegisterPage, generateResourcePage } from './builders/pages.js';
import { generateAuthSetupSpec, generateAuthSpec, generateProfileSpec, generateResourceSpec, generateGuestAuthSpec } from './builders/specs.js';
import { generateFixtures } from './builders/fixtures.js';
import { generatePlaywrightConfig, generatePackageJson, generateTsConfig } from './builders/config.js';


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

  const log = { info: (msg: string) => console.log(`\x1b[32mINFO\x1b[0m  ${msg}`) };

  log.info('Starting the generator');
  log.info('Analysing dataset');

  const byPath = loadDatasets(datasetDir);
  const analysis = analyzeDatasets(byPath);

  fs.rmSync(outputDir, { recursive: true, force: true });
  const dirs = [outputDir, 'fixtures', 'pages', 'tests'].map((d) => (d === outputDir ? d : path.join(outputDir, d)));
  dirs.forEach((d) => fs.mkdirSync(d, { recursive: true }));

  const written: Record<string, string[]> = {
    pages: [],
    tests: [],
    config: [],
  };

  const write = (relPath: string, content: string): void => {
    fs.writeFileSync(path.join(outputDir, relPath), content, 'utf8');
    if (relPath.startsWith('pages/')) written.pages.push(relPath);
    else if (relPath.startsWith('tests/')) written.tests.push(relPath);
    else written.config.push(relPath);
  };

  write('playwright.config.ts', generatePlaywrightConfig(opts, analysis.hasAuth));
  write('package.json', generatePackageJson());
  write('tsconfig.json', generateTsConfig());
  write('fixtures/test-data.ts', generateFixtures(analysis.resources, opts));

  write('pages/BasePage.ts', generateBasePage());
  if (analysis.hasAuth) {
    write('pages/LoginPage.ts', generateLoginPage(analysis.authForms.login));
    write('pages/RegisterPage.ts', generateRegisterPage(analysis.authForms.register));
    write('pages/DashboardPage.ts', generateDashboardPage());
    write('tests/auth.setup.ts', generateAuthSetupSpec(analysis.authForms.register));
    write('tests/auth.spec.ts', generateAuthSpec(analysis.authForms.login, analysis.authForms.register));
  }

  if (analysis.hasProfile) {
    write('pages/ProfilePage.ts', generateProfilePage(analysis.authForms.profile));
    write('tests/profile.spec.ts', generateProfileSpec(analysis.authForms.profile));
  }

  for (const resource of analysis.resources) {
    write(`pages/${resource.className}Page.ts`, generateResourcePage(resource));
    write(`tests/${resource.name}.spec.ts`, generateResourceSpec(resource));
  }

  if (analysis.hasAuth) {
    const config = getConfig();
    const protectedPaths: string[] = [];
    if (analysis.hasDashboard) protectedPaths.push(config.dashboardPath);
    if (analysis.hasProfile) protectedPaths.push(config.profilePath);
    for (const res of analysis.resources) {
      protectedPaths.push(res.indexPath);
    }
    if (protectedPaths.length > 0) {
      write('tests/auth.guest.spec.ts', generateGuestAuthSpec(protectedPaths));
    }
  }



  if (written.pages.length > 0) {
    log.info('Generating Page Objects');
    written.pages.forEach((f) => log.info(`Generating: ${f}`));
  }

  if (written.tests.length > 0) {
    log.info('Generating Test Specs');
    written.tests.forEach((f) => log.info(`Generating: ${f}`));
  }

  if (written.config.length > 0) {
    log.info('Generating Playwright Configuration');
    written.config.forEach((f) => log.info(`Generating: ${f}`));
  }


  const totalFiles = Object.values(written).flat().length;
  const elapsedSec = ((Date.now() - startTime) / 1000).toFixed(1);
  log.info(`${totalFiles} artifacts generated (${elapsedSec}s)`);
  log.info(`Artifacts saved to ${path.resolve(outputDir)}`);


}

main();
