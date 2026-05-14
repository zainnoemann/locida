import {
  PlaywrightCrawler,
  Dataset,
  RequestQueue,
} from 'crawlee';
import { CrawlerConfig } from '../transforms/types.js';
import { authenticate } from './auth.js';
import { extractPageData } from '../transforms/extractor.js';
import { createQueueName, normalizePathname, toAbsoluteUrl } from '../shared/utils.js';

export const visitedRoutePatterns = new Set<string>();

export async function runGuestPhase(config: CrawlerConfig): Promise<void> {
  console.log('PHASE 1: Discovering guest routes');

  const guestQueue = await RequestQueue.open(createQueueName('guest-queue'));

  for (const pathItem of config.guestSeedPaths) {
    await guestQueue.addRequest({
      url: toAbsoluteUrl(pathItem, config),
      uniqueKey: `guest:${normalizePathname(pathItem)}`,
    });
  }

  const crawler = new PlaywrightCrawler({
    requestQueue: guestQueue,
    headless: config.headless,
    maxConcurrency: 1,
    async requestHandler({ page, request, enqueueLinks, log }) {
      const requestedPathname = normalizePathname(
        new URL(request.url).pathname
      );

      if (visitedRoutePatterns.has(requestedPathname)) {
        return;
      }

      log.info(`Extracting guest route: ${requestedPathname}`);
      visitedRoutePatterns.add(requestedPathname);

      const dataset = await extractPageData(
        page,
        request,
        requestedPathname,
        'guest'
      );
      await Dataset.pushData(dataset);

      await enqueueLinks({
        strategy: 'same-domain',
        exclude: config.guestExcludePatterns,
      });
    },
  });

  await crawler.run();
}

export async function runAuthPhase(config: CrawlerConfig): Promise<void> {
  console.log('\nPHASE 2: Discovering protected routes');

  const authQueue = await RequestQueue.open(createQueueName('auth-queue'));

  await authQueue.addRequest({
    url: toAbsoluteUrl('/login', config),
    uniqueKey: 'auth:/login',
    userData: { loginStep: true },
  });

  for (const pathItem of config.protectedSeedPaths) {
    await authQueue.addRequest({
      url: toAbsoluteUrl(pathItem, config),
      uniqueKey: `auth:${normalizePathname(pathItem)}`,
      userData: { requiresAuth: true },
    });
  }

  const crawler = new PlaywrightCrawler({
    requestQueue: authQueue,
    headless: config.headless,
    maxConcurrency: 1,
    useSessionPool: true,
    sessionPoolOptions: { maxPoolSize: 1 },
    persistCookiesPerSession: true,
    preNavigationHooks: [
      async ({ page, log, session }) => {
        if (!session) return;
        session.userData = session.userData || {};
        if (!session.userData.authenticated) {
          session.userData.authenticated = await authenticate(page, config, log);
        }
      },
    ],
    async requestHandler({ page, request, log }) {
      const requestedPathname = normalizePathname(
        new URL(request.url).pathname
      );
      const effectivePathname = normalizePathname(
        new URL(page.url()).pathname
      );

      if (
        request.userData?.requiresAuth &&
        effectivePathname === '/login'
      ) {
        log.warning(
          `Skipping ${requestedPathname}: still redirected to /login`
        );
        return;
      }

      if (visitedRoutePatterns.has(requestedPathname)) {
        return;
      }

      log.info(`Extracting auth route: ${requestedPathname}`);
      visitedRoutePatterns.add(requestedPathname);

      const dataset = await extractPageData(
        page,
        request,
        requestedPathname,
        'auth'
      );
      await Dataset.pushData(dataset);
    },
  });

  await crawler.run();
}
