import { getConfig } from './shared/config.js';
import { clearDefaultDataset, ensureStorageDirectories } from './shared/utils.js';
import { runGuestPhase, runAuthPhase, visitedRoutePatterns } from './discovery/phases.js';
import { log } from 'crawlee';

async function main(): Promise<void> {
  const startTime = Date.now();
  try {
    ensureStorageDirectories();
    const config = getConfig();
    clearDefaultDataset();
    await runGuestPhase(config);
    await runAuthPhase(config);

    const duration = ((Date.now() - startTime) / 1000).toFixed(1);
    log.info(`${visitedRoutePatterns.size} unique routes (${duration}s)`);
    log.info('Datasets saved to storage/datasets/default');
  } catch (error) {
    log.error('Discovery aborted due to a fatal error:', { error });
    process.exit(1);
  }
}

main();
