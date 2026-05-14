import { GeneratorOptions } from '../types.js';

export function generateGiteaWorkflow(opts: GeneratorOptions): string {
  return `name: Playwright UI Test

on:
    push:
        branches: [${opts.gitea.branch}]
    pull_request:
        branches: [${opts.gitea.branch}]

jobs:
    playwright:
        runs-on: ubuntu-latest
        container:
            image: ${opts.gitea.playwrightImage}
            options: >-
                --add-host host.docker.internal:host-gateway
                --mount type=volume,source=${opts.gitea.npmCacheVolume},target=/root/.npm

        steps:
            - name: Checkout
                uses: actions/checkout@v4

            - name: Install deps
                run: npm ci

            - name: Run tests
                env:
                    BASE_URL: http://${opts.gitea.appHost}
                run: npx playwright test
`;
}
