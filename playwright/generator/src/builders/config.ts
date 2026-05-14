import { GeneratorOptions } from '../types.js';
import { escapeSingle } from '../shared/strings.js';

export function generatePlaywrightConfig(opts: GeneratorOptions, hasAuth: boolean): string {
  const setupProject = hasAuth
    ? `,\n        {\n            name: 'setup',\n            testMatch: /auth.setup.ts/,\n            fullyParallel: false,\n            use: { ...devices['Desktop Chrome'], storageState: undefined },\n        }`
    : '';

  const authProject = hasAuth
    ? `,\n        {\n            name: 'auth',\n            testMatch: /auth.spec.ts/,\n            dependencies: ['setup'],\n            use: { ...devices['Desktop Chrome'], storageState: undefined },\n        }`
    : '';

  const chromiumProject = hasAuth
    ? `,\n        {\n            name: 'chromium',\n            testIgnore: [/auth.setup.ts/, /auth.spec.ts/],\n            dependencies: ['setup'],\n            use: { ...devices['Desktop Chrome'], storageState: '.auth/user.json' },\n        }`
    : `,\n        {\n            name: 'chromium',\n            use: { ...devices['Desktop Chrome'] },\n        }`;

  return `import { defineConfig, devices } from '@playwright/test';\n\nexport default defineConfig({\n    testDir: './tests',\n    fullyParallel: false,\n    forbidOnly: !!process.env.CI,\n    retries: process.env.CI ? 1 : 0,\n    workers: process.env.CI ? '50%' : undefined,\n    reporter: [['html'], ['json', { outputFile: 'playwright-report/report.json' }], ['list']],\n\n    use: {\n        baseURL: process.env.BASE_URL ?? '${escapeSingle(opts.baseUrl)}',\n        trace: 'on-first-retry',\n        screenshot: 'only-on-failure',\n        headless: true,\n    },\n\n    projects: [${setupProject}${authProject}${chromiumProject}\n    ],\n});\n`;
}

export function generatePackageJson(): string {
  return JSON.stringify({
    name: 'generator',
    version: '1.0.0',
    description: 'Playwright Test Generator (from Crawlee dataset)',
    scripts: {
      test: 'playwright test',
      'test:headed': 'playwright test --headed',
      'test:ui': 'playwright test --ui',
      'test:report': 'playwright show-report',
    },
    devDependencies: {
      '@playwright/test': '1.58.2',
      typescript: '^5.4.0',
      '@types/node': '^20.0.0',
    },
  }, null, 2) + '\n';
}

export function generateTsConfig(): string {
  return JSON.stringify({
    compilerOptions: {
      target: 'ES2020',
      module: 'NodeNext',
      moduleResolution: 'NodeNext',
      strict: true,
      esModuleInterop: true,
      skipLibCheck: true,
      forceConsistentCasingInFileNames: true,
      types: ['node', '@playwright/test'],
      outDir: 'dist',
    },
    include: ['tests/**/*.ts', 'pages/**/*.ts', 'fixtures/**/*.ts', 'playwright.config.ts'],
  }, null, 2) + '\n';
}
