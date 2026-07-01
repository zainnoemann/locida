import { AnalysisResult, ExtractedPageData, ResourceInfo } from '../shared/types.js';
import { toClassName } from '../shared/strings.js';
import { uniqueByName } from '../shared/data.js';
import { getConfig } from '../shared/config.js';

export function analyzeDatasets(byPath: Map<string, ExtractedPageData>): AnalysisResult {
  const config = getConfig();
  const authPaths = [config.loginPath, config.registerPath, config.dashboardPath, config.profilePath];
  const reservedRoots = new Set([
    config.loginPath.slice(1), 
    config.registerPath.slice(1), 
    config.dashboardPath.slice(1), 
    config.profilePath.slice(1), 
    config.forgotPasswordPath.slice(1), 
    config.resetPasswordPath.slice(1)
  ]);

  const allPaths = Array.from(byPath.keys());

  const resourceRoots = new Set<string>();

  for (const p of allPaths) {
    if (p.endsWith('/create')) {
      const root = p.slice(1, -7);
      if (!reservedRoots.has(root)) resourceRoots.add(root);
    }
  }

  for (const p of allPaths) {
    if (/^\/[^/]+$/.test(p)) {
      const root = p.slice(1);
      if (!reservedRoots.has(root)) resourceRoots.add(root);
    }
  }

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

  const getPrimaryFields = (path: string) => {
    const data = byPath.get(path);
    const primaryForm = data?.forms?.find((f) => Array.isArray(f.inputs) && uniqueByName(f.inputs).length > 0) || { inputs: [] };
    return uniqueByName(primaryForm.inputs || []);
  };

  return {
    hasAuth: authPaths.some((p) => byPath.has(p)),
    hasProfile: byPath.has(config.profilePath),
    hasDashboard: byPath.has(config.dashboardPath),
    authForms: {
      login: getPrimaryFields(config.loginPath),
      register: getPrimaryFields(config.registerPath),
      profile: getPrimaryFields(config.profilePath),
    },
    resources,
  };
}
