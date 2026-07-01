import { CrawlerConfig } from '../transforms/types.js';

const DEFAULT_CONFIG: CrawlerConfig = {
  startUrl: process.env.APP_URL || 'http://127.0.0.1:8000',
  credentials: {
    email: process.env.TEST_EMAIL || 'playwright@example.com',
    password: process.env.TEST_PASSWORD || 'playwright',
  },
  headless: process.env.HEADLESS !== 'false',
  loginPath: process.env.LOGIN_PATH || '/login',
  guestSeedPaths: process.env.GUEST_SEED_PATHS 
    ? process.env.GUEST_SEED_PATHS.split(',')
    : [
        '/', 
        process.env.LOGIN_PATH || '/login', 
        process.env.REGISTER_PATH || '/register', 
        process.env.FORGOT_PASSWORD_PATH || '/forgot-password'
      ],
  guestExcludePatterns: process.env.GUEST_EXCLUDE_PATTERNS 
    ? process.env.GUEST_EXCLUDE_PATTERNS.split(',')
    : ['**/logout**'],
  authExcludePatterns: process.env.AUTH_EXCLUDE_PATTERNS 
    ? process.env.AUTH_EXCLUDE_PATTERNS.split(',')
    : ['**/logout**', '**/delete**', '**/?destroy**'],
};

export function getConfig(overrides: Partial<CrawlerConfig> = {}): CrawlerConfig {
  return {
    ...DEFAULT_CONFIG,
    ...overrides,
  };
}
