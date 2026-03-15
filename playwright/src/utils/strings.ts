// ─── String Utilities ────────────────────────────────────────────────────────

export function camelCase(str: string): string {
  return str.replace(/_([a-z])/g, (_, c) => c.toUpperCase());
}

export function capitalize(str: string): string {
  return str.charAt(0).toUpperCase() + str.slice(1);
}

export function singularize(name: string): string {
  if (name.endsWith('ies')) return name.slice(0, -3) + 'y';
  if (name.endsWith('ses')) return name.slice(0, -2);
  if (name.endsWith('s') && !name.endsWith('ss')) return name.slice(0, -1);
  return name;
}

/** Strip trailing colon from blade labels e.g. "Name:" → "Name" */
export function cleanLabel(label: string): string {
  return label.replace(/:$/, '').trim();
}

/** Build sample data value for a field based on context */
export function sampleValue(fieldName: string, resourceSingular: string, variant: 'valid' | 'updated'): string {
  const n = fieldName.toLowerCase();
  const s = capitalize(resourceSingular);

  if (n === 'title' || n.includes('title'))
    return variant === 'valid' ? `My First ${s}` : `My Updated ${s}`;
  if (n === 'name')
    return variant === 'valid' ? `${s} Name` : `Updated ${s} Name`;
  if (n === 'text' || n.includes('body') || n.includes('content') || n.includes('description'))
    return variant === 'valid' ? `Content for the ${resourceSingular}.` : `Updated content for the ${resourceSingular}.`;
  if (n.includes('email'))
    return variant === 'valid' ? 'test@example.com' : 'updated@example.com';

  return variant === 'valid' ? `Sample ${fieldName}` : `Updated ${fieldName}`;
}

/** Indent a multi-line string by N spaces */
export function indent(str: string, spaces: number): string {
  const pad = ' '.repeat(spaces);
  return str.split('\n').map(l => l ? pad + l : l).join('\n');
}
