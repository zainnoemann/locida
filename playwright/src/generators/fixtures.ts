import { ProjectAnalysis, GeneratorOptions } from '../types';
import { sampleValue, capitalize } from '../utils/strings';
import { code } from '../utils/codegen';

// ─── Fixtures Generator ───────────────────────────────────────────────────────

export function generateFixtures(analysis: ProjectAnalysis, opts: GeneratorOptions): string {
  const { resources, testUser } = analysis;
  const b = code();

  b.line(
    `// fixtures/test-data.ts`,
    ``,
    `export const TEST_USER = {`,
    `  name:     '${testUser.name}',`,
    `  email:    '${testUser.email}',`,
    `  password: '${testUser.password}',`,
    `};`,
    ``,
  );

  for (const resource of resources) {
    const textFields = resource.fields.filter(
      f => f.type !== 'select' && !f.name.endsWith('_id')
    );
    if (textFields.length === 0) continue;

    const CONST = resource.name.toUpperCase();
    b.line(`export const ${CONST} = {`);
    b.line(`  valid: {`);
    for (const f of textFields) {
      b.line(`    ${f.name}: '${sampleValue(f.name, resource.singular, 'valid')}',`);
    }
    b.line(`  },`);
    b.line(`  updated: {`);
    for (const f of textFields) {
      b.line(`    ${f.name}: '${sampleValue(f.name, resource.singular, 'updated')}',`);
    }
    b.line(`  },`);
    b.line(`  empty: {`);
    for (const f of textFields) {
      b.line(`    ${f.name}: '',`);
    }
    b.line(`  },`);
    b.line(`};`).blank();
  }

  b.line(`export const ROUTES = {`);
  b.line(`  login:     '/login',`);
  b.line(`  register:  '/register',`);
  b.line(`  dashboard: '/dashboard',`);
  b.line(`  profile:   '/profile',`);
  for (const r of resources) {
    b.line(`  ${r.name}: { index: '/${r.name}', create: '/${r.name}/create' },`);
  }
  b.line(`};`);

  return b.toString();
}
