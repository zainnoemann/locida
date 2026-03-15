import * as fs from 'fs';
import * as path from 'path';
import { ParsedMigration, MigrationField } from '../types';

// ─── Migration Parser ─────────────────────────────────────────────

export function parseMigrations(projectPath: string): ParsedMigration[] {
  const migrationsDir = path.join(projectPath, 'database', 'migrations');
  if (!fs.existsSync(migrationsDir)) return [];

  return fs.readdirSync(migrationsDir)
    .filter(f => f.endsWith('.php') && f.includes('create_'))
    .map(f => parseMigrationFile(path.join(migrationsDir, f)))
    .filter((m): m is ParsedMigration => m !== null);
}

function parseMigrationFile(filePath: string): ParsedMigration | null {
  const content = fs.readFileSync(filePath, 'utf-8');

  // Extract table name
  const tableMatch = content.match(/Schema::create\(['"]([^'"]+)['"]/);
  if (!tableMatch) return null;
  const tableName = tableMatch[1];

  const fields: MigrationField[] = [];

  // Match $table->type('name') patterns
  const fieldRe = /\$table->(\w+)\(\s*['"](\w+)['"]/g;
  let m: RegExpExecArray | null;
  while ((m = fieldRe.exec(content)) !== null) {
    const type = m[1];
    const name = m[2];

    // Skip system fields
    if (['id', 'timestamps', 'rememberToken', 'softDeletes'].includes(type)) continue;
    if (name === 'id') continue;

    // Check if nullable
    const lineEnd = content.indexOf(';', m.index);
    const line = content.slice(m.index, lineEnd);
    const nullable = /->nullable\(\)/.test(line);

    // foreignId detection
    const isFk = type === 'foreignId' || name.endsWith('_id');
    const foreignKey = isFk ? name.replace(/_id$/, 's') : undefined; // naive pluralize

    fields.push({ name, type, nullable, foreignKey });
  }

  return { tableName, fields };
}
