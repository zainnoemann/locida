export function normalizePathname(pathname: string): string {
  if (!pathname || pathname === '/') return '/';
  return pathname.replace(/\/\d+(?=\/|$)/g, '/[id]').replace(/\/$/, '') || '/';
}

export function toWords(value: string): string {
  return value.replace(/[_-]/g, ' ').trim();
}

export function capitalize(value: string): string {
  return value ? value[0].toUpperCase() + value.slice(1) : value;
}

export function singularize(name: string): string {
  if (name.endsWith('ies')) return `${name.slice(0, -3)}y`;
  if (name.endsWith('s')) return name.slice(0, -1);
  return name;
}

export function toClassName(resourceName: string): string {
  return capitalize(singularize(resourceName));
}

export function toConstName(resourceName: string): string {
  return resourceName.toUpperCase().replace(/[^A-Z0-9]/g, '_');
}

export function toFieldProp(name: string): string {
  const safe = name.replace(/[^a-zA-Z0-9_]/g, '_');
  return `${safe.replace(/_([a-z])/g, (_, c) => c.toUpperCase())}Input`;
}

export function escapeSingle(value: string | undefined | null): string {
  return String(value || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}
