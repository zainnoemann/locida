import { CrawlerConfig } from '../transforms/types.js';

const DEFAULT_CONFIG: CrawlerConfig = {
  startUrl: process.env.START_URL || 'http://127.0.0.1:8000',
  credentials: {
    email: process.env.TEST_EMAIL || 'playwright@example.com',
    password: process.env.TEST_PASSWORD || 'playwright',
  },
  headless: process.env.HEADLESS !== 'false',
  guestSeedPaths: ['/', '/login', '/register', '/forgot-password'],
  protectedSeedPaths: [
    '/dashboard',
    '/profile',
    '/categories',
    '/categories/create',
    '/posts',
    '/posts/create',
  ],
  guestExcludePatterns: [
    '**/logout**',
    '**/dashboard**',
    '**/profile**',
    '**/categories**',
    '**/posts**',
  ],
  authExcludePatterns: ['**/logout**', '**/delete**', '**/?destroy**'],
};

export function getConfig(overrides: Partial<CrawlerConfig> = {}): CrawlerConfig {
  return {
    ...DEFAULT_CONFIG,
    ...overrides,
  };
}
