import * as fs from 'fs';
import * as path from 'path';
import { RouteInfo } from '../types';

// ─── Route Parser ────────────────────────────────────────────────

export function parseRoutes(projectPath: string): RouteInfo[] {
  const routes: RouteInfo[] = [];
  const routeFiles = [
    path.join(projectPath, 'routes', 'web.php'),
    path.join(projectPath, 'routes', 'auth.php'),
  ];

  for (const file of routeFiles) {
    if (fs.existsSync(file)) {
      const content = fs.readFileSync(file, 'utf-8');
      routes.push(...parseRouteFile(content));
    }
  }

  return routes;
}

function parseRouteFile(content: string): RouteInfo[] {
  const routes: RouteInfo[] = [];

  // Detect middleware context by scanning group blocks
  const middlewareContext = detectMiddlewareContext(content);

  // 1. Resource routes: Route::resource('categories', CategoryController::class);
  const resourceRe = /Route::resource\(\s*['"]([^'"]+)['"]/g;
  let m: RegExpExecArray | null;
  while ((m = resourceRe.exec(content)) !== null) {
    const name = m[1];
    const mw = middlewareForPos(middlewareContext, m.index);
    routes.push(...expandResourceRoutes(name, mw));
  }

  // 2. Named singular routes
  // Route::get('/dashboard', ...)->name('dashboard')
  const namedRe = /Route::(get|post|put|patch|delete)\(\s*['"]([^'"]+)['"]/gi;
  while ((m = namedRe.exec(content)) !== null) {
    const method = m[1].toUpperCase();
    const uri = m[2];

    // get the name if present
    const afterRoute = content.slice(m.index, m.index + 300);
    const nameMatch = afterRoute.match(/->name\(\s*['"]([^'"]+)['"]/);
    const routeName = nameMatch ? nameMatch[1] : uriToName(uri);

    const mw = middlewareForPos(middlewareContext, m.index);

    // Detect controller
    const ctrlMatch = afterRoute.match(/([A-Z]\w+Controller)::class.*?['"](\w+)['"]/);

    routes.push({
      name: routeName,
      method,
      uri,
      action: ctrlMatch?.[2] as string | undefined,
      middleware: mw,
      isResource: false,
    });
  }

  // 3. Auth guest routes (register, login etc.)
  const authRe = /Route::(get|post)\(\s*['"]([^'"]+)['"]/gi;
  // Already captured above

  return dedupe(routes);
}

// ── Resource route expansion ──────────────────────────────────────

const RESOURCE_ACTIONS: Array<{ action: string; method: string; uriSuffix: string; nameSuffix: string }> = [
  { action: 'index',   method: 'GET',    uriSuffix: '',               nameSuffix: 'index'   },
  { action: 'create',  method: 'GET',    uriSuffix: '/create',        nameSuffix: 'create'  },
  { action: 'store',   method: 'POST',   uriSuffix: '',               nameSuffix: 'store'   },
  { action: 'show',    method: 'GET',    uriSuffix: '/{id}',          nameSuffix: 'show'    },
  { action: 'edit',    method: 'GET',    uriSuffix: '/{id}/edit',     nameSuffix: 'edit'    },
  { action: 'update',  method: 'PUT',    uriSuffix: '/{id}',          nameSuffix: 'update'  },
  { action: 'destroy', method: 'DELETE', uriSuffix: '/{id}',          nameSuffix: 'destroy' },
];

function expandResourceRoutes(resource: string, middleware: string[]): RouteInfo[] {
  return RESOURCE_ACTIONS.map(a => ({
    name: `${resource}.${a.nameSuffix}`,
    method: a.method,
    uri: `/${resource}${a.uriSuffix}`,
    action: a.action,
    middleware,
    isResource: true,
  }));
}

// ── Middleware context detection ───────────────────────────────────

interface MwBlock { start: number; end: number; middleware: string[] }

function detectMiddlewareContext(content: string): MwBlock[] {
  const blocks: MwBlock[] = [];

  // Find all Route::middleware(...)->group(function() { ... })
  const mwRe = /Route::middleware\(\s*(?:'([^']+)'|"([^"]+)"|\[([^\]]+)\])\s*\)/g;
  let m: RegExpExecArray | null;

  while ((m = mwRe.exec(content)) !== null) {
    const raw = m[1] || m[2] || m[3] || '';
    const middleware = raw.split(/[,\s'"]+/).filter(Boolean);

    // Find the corresponding ->group(function() { ... })
    const afterMw = content.indexOf('->group(function', m.index);
    if (afterMw === -1) continue;

    const blockStart = content.indexOf('{', afterMw);
    const blockEnd = findClosingBrace(content, blockStart);
    if (blockEnd === -1) continue;

    blocks.push({ start: blockStart, end: blockEnd, middleware });
  }

  return blocks;
}

function middlewareForPos(blocks: MwBlock[], pos: number): string[] {
  const result: string[] = [];
  for (const b of blocks) {
    if (pos >= b.start && pos <= b.end) {
      result.push(...b.middleware);
    }
  }
  return [...new Set(result)];
}

function findClosingBrace(content: string, openPos: number): number {
  let depth = 0;
  for (let i = openPos; i < content.length; i++) {
    if (content[i] === '{') depth++;
    else if (content[i] === '}') {
      depth--;
      if (depth === 0) return i;
    }
  }
  return -1;
}

function uriToName(uri: string): string {
  return uri.replace(/^\//, '').replace(/\//g, '.').replace(/\{[^}]+\}/g, 'item') || 'home';
}

function dedupe(routes: RouteInfo[]): RouteInfo[] {
  const seen = new Set<string>();
  return routes.filter(r => {
    const key = `${r.method}:${r.uri}`;
    if (seen.has(key)) return false;
    seen.add(key);
    return true;
  });
}
