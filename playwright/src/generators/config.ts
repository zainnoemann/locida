import { GeneratorOptions } from '../types';

// ─── Config Generators ───────────────────────────────────────────────────────

export function generatePlaywrightConfig(opts: GeneratorOptions, hasAuth: boolean): string {
  const projects = hasAuth
    ? `projects: [
    {
      name: 'setup',
      testMatch: /auth\.setup\.ts/,
      fullyParallel: false,
      use: { ...devices['Desktop Chrome'], storageState: undefined },
    },
    {
      name: 'auth',
      testMatch: /auth\.spec\.ts/,
      dependencies: ['setup'],
      use: { ...devices['Desktop Chrome'], storageState: undefined },
    },
    {
      name: 'chromium',
      testIgnore: [/auth\.setup\.ts/, /auth\.spec\.ts/],
      dependencies: ['setup'],
      use: { ...devices['Desktop Chrome'], storageState: '.auth/user.json' },
    },
  ],`
    : `projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],`;

  return `import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: process.env.CI ? '50%' : undefined,
  reporter: [['html'], ['json', { outputFile: 'playwright-report/report.json' }], ['list']],

  use: {
    baseURL: process.env.BASE_URL ?? '${opts.baseUrl}',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    headless: true,
  },

  ${projects}
});
`;
}

export function generatePackageJson(opts: GeneratorOptions): string {
  return JSON.stringify({
    name: 'testgen',
    version: '1.0.0',
    description: 'Playwright Test Generator',
    scripts: {
      test:          'playwright test',
      'test:headed': 'playwright test --headed',
      'test:ui':     'playwright test --ui',
      'test:report': 'playwright show-report',
    },
    devDependencies: {
      '@playwright/test': '1.58.2',
      typescript:         '^5.4.0',
      '@types/node':      '^20.0.0',
    },
  }, null, 2);
}

export function generateTsConfig(): string {
  return JSON.stringify({
    compilerOptions: {
      target:          'ES2020',
      module:          'commonjs',
      lib:             ['ES2020'],
      strict:          true,
      esModuleInterop: true,
      skipLibCheck:    true,
      outDir:          './dist',
      baseUrl:         '.',
      paths:           {},
    },
    include: ['**/*.ts'],
    exclude: ['node_modules', 'dist'],
  }, null, 2);
}

// Gitea self-hosted: ACTIONS_RUNTIME_URL is set to localhost:3000 by the runner,
// which is unreachable from inside a container job. upload-artifact cannot be used.
// Report is committed into the same branch under playwright/reports.
export function generateGiteaWorkflow(opts: GeneratorOptions): string {
  const { gitea } = opts;
  const containerOptionsParts = [
    `--add-host gitea:host-gateway`,
    `--add-host host.docker.internal:host-gateway`,
    `--mount type=volume,source=${gitea.npmCacheVolume},target=/root/.npm`,
  ];
  const containerOptions = containerOptionsParts.join(' ');

  return [
    `name: Playwright`,
    ``,
    `on:`,
    `  workflow_dispatch:`,
    `  push:`,
    `    branches:`,
    `      - ${gitea.branch}`,
    ``,
    `jobs:`,
    `  playwright:`,
    `    runs-on: ubuntu-latest`,
    `    container:`,
    `      image: ${gitea.playwrightImage}`,
    `      options: ${containerOptions}`,
    `    env:`,
    `      APP_URL: http://${gitea.appHost}`,
    `      BASE_URL: http://${gitea.appHost}`,
    `      PLAYWRIGHT_DIR: playwright`,
    `    steps:`,
    `      - name: Checkout repository`,
    `        uses: actions/checkout@v4`,
    `        with:`,
    `          github-server-url: ${gitea.serverUrl}`,
    ``,
    `      - name: Install dependencies`,
    `        run: |`,
    `          if [ ! -f "$PLAYWRIGHT_DIR/package.json" ]; then`,
    `            echo "Missing $PLAYWRIGHT_DIR/package.json"`,
    `            exit 1`,
    `          fi`,
    `          cd "$PLAYWRIGHT_DIR"`,
    `          npm install --prefer-offline --no-audit --no-fund`,
    ``,
    `      - name: Run Playwright tests`,
    `        run: |`,
    `          cd "$PLAYWRIGHT_DIR"`,
    `          npx playwright test`,
    ``,
    `      - name: Save report to branch folder`,
    `        if: always() && github.actor != 'gitea-actions[bot]'`,
    `        run: |`,
    `          if [ ! -d "$PLAYWRIGHT_DIR/playwright-report" ] && [ ! -f "$PLAYWRIGHT_DIR/playwright-report.json" ]; then`,
    `            echo "No Playwright report output found, skipping publish"`,
    `            exit 0`,
    `          fi`,
    ``,
    `          REPORT_DIR="$PLAYWRIGHT_DIR/reports"`,
    `          rm -rf "$REPORT_DIR"`,
    `          mkdir -p "$REPORT_DIR"`,
    `          if [ -d "$PLAYWRIGHT_DIR/playwright-report" ]; then cp -r "$PLAYWRIGHT_DIR/playwright-report"/. "$REPORT_DIR"/; fi`,
    `          if [ -f "$PLAYWRIGHT_DIR/playwright-report/report.json" ]; then cp "$PLAYWRIGHT_DIR/playwright-report/report.json" "$REPORT_DIR/report.json"; fi`,
    `          if [ -f "$PLAYWRIGHT_DIR/playwright-report.json" ] && [ ! -f "$REPORT_DIR/report.json" ]; then cp "$PLAYWRIGHT_DIR/playwright-report.json" "$REPORT_DIR/report.json"; fi`,
    ``,
    `          git config user.name "gitea-actions[bot]"`,
    `          git config user.email "gitea-actions@localhost"`,
    `          git add "$REPORT_DIR"`,
    `          git commit -m "test(playwright): generate report" || echo "No report changes to commit"`,
    `          git push origin "HEAD:${gitea.branch}"`,
  ].join('\n') + '\n';
}
