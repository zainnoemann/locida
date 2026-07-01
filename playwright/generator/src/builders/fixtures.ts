import { GeneratorOptions, ResourceInfo } from '../shared/types.js';
import { escapeSingle, toConstName } from '../shared/strings.js';
import { sampleValue } from '../shared/data.js';
import { getConfig } from '../shared/config.js';

export function generateFixtures(resources: ResourceInfo[], opts: GeneratorOptions): string {
  const resourceBlocks = resources
    .map((resource) => {
      const valid = resource.fields.map((f) => `    ${f.name}: '${escapeSingle(sampleValue(f, 'valid'))}',`).join('\n');
      const updated = resource.fields.map((f) => `    ${f.name}: '${escapeSingle(sampleValue(f, 'updated'))}',`).join('\n');
      const empty = resource.fields.map((f) => `    ${f.name}: '',`).join('\n');

      return `export const ${toConstName(resource.name)} = {\n  valid: {\n${valid}\n  },\n  updated: {\n${updated}\n  },\n  empty: {\n${empty}\n  },\n};`;
    })
    .join('\n\n');

  const routeResources = resources
    .map((resource) => `  ${resource.name}: { index: '${resource.indexPath}', create: '${resource.createPath}' },`)
    .join('\n');

  const config = getConfig();

  return `// fixtures/test-data.ts

export const TEST_USER = {
    name: process.env.TEST_NAME || '${escapeSingle(opts.testUser.name)}',
    email: process.env.TEST_EMAIL || '${escapeSingle(opts.testUser.email)}',
    password: process.env.TEST_PASSWORD || '${escapeSingle(opts.testUser.password)}',
};

${resourceBlocks}

export const ROUTES = {
    login: '${config.loginPath}',
    register: '${config.registerPath}',
    dashboard: '${config.dashboardPath}',
    profile: '${config.profilePath}',
${routeResources ? `${routeResources}` : ''}
};
`;
}
