import * as fs from 'fs';
import * as path from 'path';
import { FormField, ParsedView } from '../types';

// ─── Blade Parser ────────────────────────────────────────────────

export function parseBladeView(filePath: string): ParsedView {
  const content = fs.readFileSync(filePath, 'utf-8');
  const resourceName = extractResourceName(filePath);
  const viewType = extractViewType(filePath);

  return {
    path: filePath,
    resourceName,
    viewType,
    fields: extractFormFields(content),
    tableColumns: extractTableColumns(content),
    hasTable: /<table[\s>]/.test(content),
    hasDeleteButton: /onclick=.*confirm|name="delete"|method.*DELETE/.test(content) ||
                     /<button[^>]*>[\s\S]*?[Dd]elete[\s\S]*?<\/button>/.test(content),
    hasEditLink: /route\(['"][\w.]+\.edit['"]/.test(content) ||
                 /<a[^>]*>[^<]*[Ee]dit[^<]*<\/a>/.test(content),
    hasCreateLink: /route\(['"][\w.]+\.create['"]/.test(content) ||
                   /<a[^>]*>[^<]*[Aa]dd new|[Cc]reate[^<]*<\/a>/.test(content),
    buttonLabels: extractButtonLabels(content),
    pageTitle: extractPageTitle(content),
  };
}

function extractResourceName(filePath: string): string {
  const parts = filePath.split(path.sep);
  const viewsIdx = parts.lastIndexOf('views');
  if (viewsIdx >= 0 && parts[viewsIdx + 1]) {
    return parts[viewsIdx + 1]; // e.g. "categories", "posts", "auth"
  }
  return path.basename(path.dirname(filePath));
}

function extractViewType(filePath: string): ParsedView['viewType'] {
  const basename = path.basename(filePath, '.blade.php');
  const dirName = path.basename(path.dirname(filePath));

  if (dirName === 'auth' || ['login', 'register'].includes(basename)) return 'auth';
  if (dirName === 'profile' || basename === 'profile') return 'profile';
  if (basename === 'dashboard') return 'dashboard';
  if (basename === 'index') return 'index';
  if (basename === 'create') return 'create';
  if (basename === 'edit') return 'edit';
  if (basename === 'show') return 'show';
  return 'other';
}

export function extractFormFields(content: string): FormField[] {
  const fields: FormField[] = [];

  // Match label+input pairs — supports multiple patterns:
  // 1. <label for="X">Text</label> then <input id="X">
  // 2. <x-input-label for="X" :value="__('Text')">
  // 3. <label>Text:</label> then next input

  const labelInputPattern = /(?:<x-input-label[^>]+for=["'](\w+)["'][^>]*:value=["']__\(['"]([^'"]+)['"]\)["']|<label[^>]*for=["'](\w+)["'][^>]*>([^<]+)<\/label>|<label[^>]*>\s*([^<:]+?)(?::|)\s*<\/label>)\s*(?:[\s\S]*?(?=<input|<textarea|<select)){0,200}([\s\S]*?)(?=<\/div>|<div\s|$)/gi;

  // Simpler, more reliable: find all labeled inputs
  // Step 1: collect all labels with their `for` target
  const labelMap = new Map<string, string>();

  // x-input-label (Breeze style): <x-input-label for="email" :value="__('Email')" />
  const xLabelRe = /<x-input-label[^>]+for=["'](\w+)["'][^>]*:value=["']__\(['"]([^'"]+)['"]\)["'][^>]*\/?>/gi;
  let m: RegExpExecArray | null;
  while ((m = xLabelRe.exec(content)) !== null) {
    labelMap.set(m[1], m[2]);
  }

  // Standard label: <label for="name">Name:</label>
  const stdLabelRe = /<label[^>]+for=["'](\w+)["'][^>]*>([^<]+)<\/label>/gi;
  while ((m = stdLabelRe.exec(content)) !== null) {
    const text = m[2].replace(/:$/, '').trim();
    if (text) labelMap.set(m[1], text);
  }

  // Step 2: find all form inputs
  // <input id="X" type="Y" name="Z" required>
  const inputRe = /<input([^>]+)>/gi;
  while ((m = inputRe.exec(content)) !== null) {
    const attrs = m[1];
    const id = attrVal(attrs, 'id');
    const name = attrVal(attrs, 'name');
    const type = attrVal(attrs, 'type') || 'text';

    if (!id && !name) continue;
    if (['_token', 'hidden'].includes(type)) continue;
    if (name === '_method') continue;

    const key = id || name;
    const label = labelMap.get(key) || humanize(key);
    const required = /\brequired\b/.test(attrs);

    if (!fields.find(f => f.name === name)) {
      fields.push({ id: id || name, name: name || id, label, type, required, htmlRequired: required });
    }
  }

  // <textarea id="X" name="Y">
  const textareaRe = /<textarea([^>]+)>/gi;
  while ((m = textareaRe.exec(content)) !== null) {
    const attrs = m[1];
    const id = attrVal(attrs, 'id');
    const name = attrVal(attrs, 'name');
    if (!id && !name) continue;
    const key = id || name;
    const label = labelMap.get(key) || humanize(key);
    const required = /\brequired\b/.test(attrs);
    if (!fields.find(f => f.name === name)) {
      fields.push({ id: id || name, name: name || id, label, type: 'textarea', required, htmlRequired: required });
    }
  }

  // <select id="X" name="Y">
  const selectRe = /<select([^>]+)>/gi;
  while ((m = selectRe.exec(content)) !== null) {
    const attrs = m[1];
    const id = attrVal(attrs, 'id');
    const name = attrVal(attrs, 'name');
    if (!id && !name) continue;
    const key = id || name;
    const label = labelMap.get(key) || humanize(key);
    const required = /\brequired\b/.test(attrs);
    if (!fields.find(f => f.name === name)) {
      fields.push({ id: id || name, name: name || id, label, type: 'select', required, htmlRequired: required });
    }
  }

  // Checkbox: remember_me etc. — already caught by inputRe above
  return fields;
}

export function extractTableColumns(content: string): string[] {
  const cols: string[] = [];
  const thRe = /<th[^>]*>([\s\S]*?)<\/th>/gi;
  let m: RegExpExecArray | null;
  while ((m = thRe.exec(content)) !== null) {
    const text = m[1].replace(/<[^>]+>/g, '').trim();
    if (text) {
      cols.push(text);
    } else {
      // Empty <th></th> — typical Breeze "Actions" column (has Edit/Delete links in tbody)
      const hasActions = /route\([^)]+\.(edit|destroy)\)/.test(content);
      if (hasActions && !cols.includes('Actions')) {
        cols.push('Actions');
      }
    }
  }
  return cols;
}

function extractFormAction(content: string): string | undefined {
  const m = content.match(/action=["']\{\{[^}]*route\(['"]([^'"]+)['"]/);
  return m ? m[1] : undefined;
}

function extractButtonLabels(content: string): string[] {
  const labels: string[] = [];
  const re = /<(?:button|x-primary-button|x-secondary-button)[^>]*>([\s\S]*?)<\/(?:button|x-primary-button|x-secondary-button)>/gi;
  let m: RegExpExecArray | null;
  while ((m = re.exec(content)) !== null) {
    const text = m[1].replace(/<[^>]+>/g, '').replace(/\{\{[^}]+\}\}/g, '').trim();
    if (text && !labels.includes(text)) labels.push(text);
  }
  return labels;
}

function extractPageTitle(content: string): string {
  // x-slot name="header" -> h2 content
  const m1 = content.match(/x-slot[^>]*header[\s\S]*?<h\d[^>]*>([\s\S]*?)<\/h\d>/i);
  if (m1) {
    return m1[1].replace(/\{\{[^}]*__\(['"]([^'"]+)['"]\)[^}]*\}\}/g, '$1')
               .replace(/<[^>]+>/g, '').trim();
  }
  return '';
}

function extractCreateLinkText(content: string): string | undefined {
  const m = content.match(/<a[^>]*route\([^)]*\.create[^)]*\)[^>]*>([^<]+)<\/a>/);
  return m ? m[1].trim() : undefined;
}

// ── Helpers ──────────────────────────────────────────────────────

function attrVal(attrs: string, name: string): string {
  const m = attrs.match(new RegExp(`\\b${name}=["']([^"']+)["']`));
  return m ? m[1] : '';
}

function humanize(str: string): string {
  return str.replace(/_id$/, '').replace(/_/g, ' ')
    .replace(/\b\w/g, c => c.toUpperCase());
}

// ─── Scan all blade views in a Laravel project ───────────────────

export function scanViews(projectPath: string): ParsedView[] {
  const viewsDir = path.join(projectPath, 'resources', 'views');
  if (!fs.existsSync(viewsDir)) return [];

  const bladeFiles: string[] = [];
  walkDir(viewsDir, bladeFiles);

  return bladeFiles
    .filter(f => !f.includes(`${path.sep}components${path.sep}`) &&
                 !f.includes(`${path.sep}layouts${path.sep}`))
    .map(f => parseBladeView(f));
}

function walkDir(dir: string, results: string[]): void {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      walkDir(full, results);
    } else if (entry.name.endsWith('.blade.php')) {
      results.push(full);
    }
  }
}
