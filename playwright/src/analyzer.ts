import * as path from 'path';
import * as fs from 'fs';
import { scanViews } from './parsers/bladeParser';
import { parseRoutes } from './parsers/routeParser';
import { parseMigrations } from './parsers/migrationParser';
import { singularize, capitalize } from './utils/strings';
import { ProjectAnalysis, ResourceGroup, FormField, RelationInfo } from './types';

// ─── Project Analyzer ────────────────────────────────────────────────────────

export function analyzeProject(projectPath: string): ProjectAnalysis {
  const absPath = path.resolve(projectPath);
  if (!fs.existsSync(absPath)) throw new Error(`Path not found: ${absPath}`);

  const views      = scanViews(absPath);
  const routes     = parseRoutes(absPath);
  const migrations = parseMigrations(absPath);

  const authViews = views.filter(v => v.resourceName === 'auth' || v.viewType === 'auth');
  const hasProfile = views.some(v => v.resourceName === 'profile');

  // Collect unique resource names from Route::resource() calls
  const resourceNames = [...new Set(
    routes.filter(r => r.isResource).map(r => r.name.split('.')[0])
  )];

  const resources: ResourceGroup[] = [];

  for (const name of resourceNames) {
    const resourceRoutes = routes.filter(r => r.name.startsWith(`${name}.`));
    const resourceViews  = views.filter(v => v.resourceName === name);

    const fields = collectFields(resourceViews);
    const tableColumns = resourceViews.find(v => v.viewType === 'index')?.tableColumns ?? [];
    const relations = detectRelations(fields, resources);

    applyMigrationTypes(fields, name, migrations);

    resources.push({
      name,
      singular:  singularize(name),
      className: capitalize(singularize(name)),
      routes:    resourceRoutes,
      views:     resourceViews,
      fields,
      tableColumns,
      requiresAuth: resourceRoutes.some(r => r.middleware.includes('auth')),
      hasIndex:  resourceRoutes.some(r => r.action === 'index'),
      hasCreate: resourceRoutes.some(r => r.action === 'create'),
      hasEdit:   resourceRoutes.some(r => r.action === 'edit'),
      hasDelete: resourceRoutes.some(r => r.action === 'destroy'),
      hasShow:   resourceRoutes.some(r => r.action === 'show') &&
                 resourceViews.some(v => v.viewType === 'show'),
      relations,
    });
  }

  return {
    projectPath: absPath,
    resources,
    hasAuth:    authViews.length > 0 || routes.some(r => r.name === 'login'),
    hasProfile,
    authViews,
    baseUrl:  'http://localhost:8000',
    testUser: { email: 'playwright@example.com', password: 'playwright', name: 'Test User' },
  };
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function collectFields(views: ReturnType<typeof scanViews>): FormField[] {
  const seen = new Set<string>();
  const fields: FormField[] = [];
  for (const v of views) {
    if (v.viewType !== 'create' && v.viewType !== 'edit') continue;
    for (const f of v.fields) {
      if (!seen.has(f.name)) {
        seen.add(f.name);
        fields.push(f);
      }
    }
  }
  return fields;
}

function detectRelations(
  fields: FormField[],
  existing: ResourceGroup[]
): RelationInfo[] {
  return fields
    .filter(f => f.type === 'select' || f.name.endsWith('_id'))
    .map(f => {
      const base    = f.name.replace(/_id$/, '');
      const related = existing.find(r => r.singular === base || r.name === base + 's');
      return { field: f.name, relatedResource: related?.name ?? base + 's', label: f.label };
    });
}

function applyMigrationTypes(
  fields: FormField[],
  resourceName: string,
  migrations: ReturnType<typeof parseMigrations>
): void {
  const migration = migrations.find(
    m => m.tableName === resourceName ||
         m.tableName === `${resourceName}s` ||
         m.tableName === `${resourceName}es`
  );
  if (!migration) return;

  for (const field of fields) {
    const mf = migration.fields.find(f => f.name === field.name);
    if (!mf) continue;
    field.required = !mf.nullable;
    if (mf.foreignKey) field.type = 'select';
  }
}
