import path from 'path';
import { CliDefaults, CliResult, GeneratorOptions } from '../types.js';

function flag(args: string[], name: string): string | undefined {
  const i = args.indexOf(name);
  return i !== -1 ? args[i + 1] : undefined;
}

function extractPositionalArgs(args: string[]): string[] {
  const noValueFlags = new Set(['--no-workflow', '--help', '-h']);
  const positional: string[] = [];

  for (let i = 0; i < args.length; i += 1) {
    const token = args[i];

    if (token.startsWith('--') || token === '-h') {
      if (!noValueFlags.has(token) && i + 1 < args.length && !args[i + 1].startsWith('--')) {
        i += 1;
      }
      continue;
    }

    positional.push(token);
  }

  return positional;
}

export function printHelp(): void {
  console.log(`
Generator — Generate Playwright tests from Crawlee dataset

USAGE:
    node dist/index.js [dataset-dir] [output-dir] [options]

OPTIONS:
    --base-url            App URL for local test runs  (default: http://localhost:8000)
    --email               Test user email              (default: playwright@example.com)
    --password            Test user password           (default: playwright)
    --user-name           Test user display name       (default: Test User)

    --gitea-server-url    Gitea server URL             (default: http://gitea:3000)
    --gitea-app-host      Laravel app host in CI job   (default: 127.0.0.1:8000)
    --gitea-image         Playwright Docker image      (default: mcr.microsoft.com/playwright:v1.58.2-jammy)
    --gitea-branch        CI trigger branch            (default: main)
    --gitea-cache-vol     npm cache volume name        (default: playwright-npm-cache)
    --no-workflow         Skip .gitea/workflows generation
`);
}

export function parseArgs(args: string[], defaults: CliDefaults): CliResult {
  const showHelp = args.includes('--help') || args.includes('-h');

  const positionalArgs = extractPositionalArgs(args);
  const datasetDir = positionalArgs[0]
    ? path.resolve(positionalArgs[0])
    : defaults.datasetDir;
  const outputDir = positionalArgs[1]
    ? path.resolve(positionalArgs[1])
    : defaults.outputDir;

  const opts: GeneratorOptions = {
    baseUrl: flag(args, '--base-url') || defaults.baseUrl,
    testUser: {
      email: flag(args, '--email') || 'playwright@example.com',
      password: flag(args, '--password') || 'playwright',
      name: flag(args, '--user-name') || 'Test User',
    },
    gitea: {
      enabled: !args.includes('--no-workflow'),
      serverUrl: flag(args, '--gitea-server-url') || 'http://gitea:3000',
      appHost: flag(args, '--gitea-app-host') || 'host.docker.internal:8000',
      playwrightImage: flag(args, '--gitea-image') || 'mcr.microsoft.com/playwright:v1.58.2-jammy',
      branch: flag(args, '--gitea-branch') || 'playwright',
      npmCacheVolume: flag(args, '--gitea-cache-vol') || 'playwright-npm-cache',
    },
  };

  return {
    datasetDir,
    outputDir,
    opts,
    showHelp,
  };
}
