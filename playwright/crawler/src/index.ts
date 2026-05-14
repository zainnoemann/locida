import { getConfig } from './shared/config.js';
import { clearDefaultDataset, ensureStorageDirectories } from './shared/utils.js';
import { runGuestPhase, runAuthPhase, visitedRoutePatterns } from './discovery/phases.js';

async function main(): Promise<void> {
  try {
    console.log('=== STARTING PLAYWRIGHT DISCOVERY ===\n');

    ensureStorageDirectories();
    const config = getConfig();
    clearDefaultDataset();
    await runGuestPhase(config);
    await runAuthPhase(config);

    console.log('\n=== DISCOVERY COMPLETED ===');
    console.log(`Total unique routes discovered: ${visitedRoutePatterns.size}`);
    console.log('JSON datasets are available in storage/datasets/default');
  } catch (error) {
    console.error('Crawler error:', error);
    process.exit(1);
  }
}

main();
