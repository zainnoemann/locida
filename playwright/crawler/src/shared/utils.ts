import fs from 'fs';
import path from 'path';
import { CrawlerConfig } from '../transforms/types.js';

export function createQueueName(prefix: string): string {
  return `${prefix}-${Date.now()}`;
}

export function normalizePathname(pathname: string): string {
  if (!pathname || pathname === '/') return '/';

  let normalized = pathname
    .replace(/\/\d+(?=\/|$)/g, '/[id]')
    .replace(/\/$/, '');

  if (!normalized.startsWith('/')) normalized = `/${normalized}`;

  return normalized || '/';
}

export function toAbsoluteUrl(pathname: string, config: CrawlerConfig): string {
  return new URL(pathname, config.startUrl).toString();
}

export function clearDefaultDataset(): void {
  const datasetPath = path.resolve('./storage/datasets/default');
  fs.rmSync(datasetPath, { recursive: true, force: true });
}

export function ensureStorageDirectories(): void {
  const dirs = [
    './storage/datasets',
    './storage/key_value_stores',
    './storage/request_queues',
  ];

  dirs.forEach((dir) => {
    fs.mkdirSync(dir, { recursive: true });
  });
}
