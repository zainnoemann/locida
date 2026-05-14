import { AnalysisResult, ExtractedPageData, ResourceInfo } from '../types.js';
import { toClassName } from '../shared/strings.js';
import { uniqueByName } from '../shared/data.js';

export function analyzeDatasets(byPath: Map<string, ExtractedPageData>): AnalysisResult {
  const authPaths = ['/login', '/register', '/dashboard', '/profile'];
  const reservedRoots = new Set(['login', 'register', 'dashboard', 'profile', 'forgot-password', 'reset-password']);

  const allPaths = Array.from(byPath.keys());

  const resourceRoots = allPaths
    .filter((p) => /^\/[^/]+$/.test(p))
    .map((p) => p.slice(1))
    .filter((root) => !reservedRoots.has(root));

  const resources: ResourceInfo[] = Array.from(new Set(resourceRoots)).map((name) => {
    const indexPath = `/${name}`;
    const createPath = `/${name}/create`;
    const indexData = byPath.get(indexPath);
    const createData = byPath.get(createPath);

    const sourceForms = (createData?.forms?.length ? createData.forms : indexData?.forms) || [];
    const primaryForm = sourceForms.find((f) => Array.isArray(f.inputs) && f.inputs.length > 0) || { inputs: [] };
    const fields = uniqueByName(primaryForm.inputs || []);

    const tableColumns = indexData?.components?.tables?.[0]?.columns || [];
    const className = toClassName(name);

    return {
      name,
      className,
      indexPath,
      createPath,
      fields,
      tableColumns,
      hasCreatePage: Boolean(createData),
    };
  });

  return {
    hasAuth: authPaths.some((p) => byPath.has(p)),
    hasProfile: byPath.has('/profile'),
    hasDashboard: byPath.has('/dashboard'),
    resources,
  };
}
