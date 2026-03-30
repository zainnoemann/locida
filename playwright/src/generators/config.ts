import { GeneratorOptions } from '../types';

// ─── Config Generators ───────────────────────────────────────────────────────

export function generatePlaywrightConfig(opts: GeneratorOptions): string {
  return `import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: 1,
  reporter: [['html'], ['json', { outputFile: 'playwright-report/report.json' }], ['list']],

  use: {
    baseURL: process.env.BASE_URL ?? '${opts.baseUrl}',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    headless: true,
  },

  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
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
      '@playwright/test': '^1.44.0',
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
// Report is published to a dedicated Git branch for later analysis.
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
    `    steps:`,
    `      - name: Checkout repository`,
    `        uses: actions/checkout@v4`,
    `        with:`,
    `          github-server-url: ${gitea.serverUrl}`,
    ``,
    `      - name: Install dependencies`,
    `        run: npm install --prefer-offline --no-audit --no-fund`,
    ``,
    `      - name: Run Playwright tests`,
    `        run: npx playwright test`,
    ``,
    `      - name: Save report to Git branch`,
    `        if: always()`,
    `        env:`,
    `          REPORT_BRANCH: ${gitea.reportBranch}`,
    `        run: |`,
    `          if [ ! -d playwright-report ] && [ ! -f playwright-report.json ]; then`,
    `            echo "No Playwright report output found, skipping publish"`,
    `            exit 0`,
    `          fi`,
    ``,
    `          TMP_REPORT_DIR="$(mktemp -d)"`,
    `          if [ -d playwright-report ]; then cp -r playwright-report/. "$TMP_REPORT_DIR"/; fi`,
    `          if [ -f playwright-report/report.json ]; then cp playwright-report/report.json "$TMP_REPORT_DIR"/report.json; fi`,
    `          if [ -f playwright-report.json ]; then cp playwright-report.json "$TMP_REPORT_DIR"/report.json; fi`,
    `          printf "%s\\n" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$TMP_REPORT_DIR"/GENERATED_AT_UTC.txt`,
    ``,
    `          git config user.name "gitea-actions[bot]"`,
    `          git config user.email "gitea-actions@localhost"`,
    `          git checkout --orphan "$REPORT_BRANCH-publish"`,
    `          git rm -rf . >/dev/null 2>&1 || true`,
    `          find . -mindepth 1 -maxdepth 1 ! -name '.git' -exec rm -rf {} +`,
    `          cp -r "$TMP_REPORT_DIR"/. .`,
    `          git add .`,
    `          git commit -m "chore: update playwright report $(date -u +%Y-%m-%dT%H:%M:%SZ)" || echo "No report changes to commit"`,
    `          git branch -M "$REPORT_BRANCH"`,
    `          git push origin "$REPORT_BRANCH" --force`,
  ].join('\n') + '\n';
}
