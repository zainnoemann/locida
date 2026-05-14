import fs from 'fs';
import path from 'path';
import { ExtractedPageData } from '../types.js';
import { normalizePathname } from '../shared/strings.js';

export function loadDatasets(datasetDir: string): Map<string, ExtractedPageData> {
  const files = fs.existsSync(datasetDir)
    ? fs.readdirSync(datasetDir).filter((f) => f.endsWith('.json')).sort()
    : [];

  const byPath = new Map<string, ExtractedPageData>();
  for (const fileName of files) {
    const filePath = path.join(datasetDir, fileName);
    const raw = fs.readFileSync(filePath, 'utf8');
    const data = JSON.parse(raw) as ExtractedPageData;
    if (!data || Array.isArray(data) || typeof data !== 'object') continue;
    if (!data.pathname) continue;

    const pathname = normalizePathname(data.pathname);
    byPath.set(pathname, { ...data, pathname });
  }
  return byPath;
}
