import { GeneratorOptions, ResourceInfo } from '../types.js';
import { escapeSingle, toConstName } from '../shared/strings.js';
import { sampleValue } from '../shared/data.js';

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

  return `// fixtures/test-data.ts

export const TEST_USER = {
    name: '${escapeSingle(opts.testUser.name)}',
    email: '${escapeSingle(opts.testUser.email)}',
    password: '${escapeSingle(opts.testUser.password)}',
};

${resourceBlocks}

export const ROUTES = {
    login: '/login',
    register: '/register',
    dashboard: '/dashboard',
    profile: '/profile',
${routeResources ? `${routeResources}` : ''}
};
`;
}
