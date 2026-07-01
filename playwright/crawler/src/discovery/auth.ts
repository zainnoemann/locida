import type { Page } from 'playwright';
import { CrawlerConfig } from '../transforms/types.js';
import type { Logger } from '../transforms/types.js';
import { toAbsoluteUrl } from '../shared/utils.js';

export async function authenticate(
  page: Page,
  config: CrawlerConfig,
  log: Logger
): Promise<boolean> {
  const loginUrl = toAbsoluteUrl(config.loginPath, config);
  if (!page.url().includes(config.loginPath)) {
    await page.goto(loginUrl, {
      waitUntil: 'domcontentloaded',
    });
  }

  log.info('Authenticating session');
  
  // Dynamically find password input
  const passwordInput = page.locator('input[type="password"]').first();
  if (await passwordInput.count() === 0) {
    log.warning('No password input found on login page.');
    return false;
  }

  // Find the closest form
  const form = page.locator('form').filter({ has: passwordInput }).first();
  if (await form.count() === 0) {
    log.warning('Password input is not inside a form.');
    return false;
  }

  // Find a text or email input for the username (assuming it comes before the password in DOM, or is just any text/email input in the form)
  const usernameInput = form.locator('input[type="text"], input[type="email"]').first();
  
  if (await usernameInput.count() > 0) {
    await usernameInput.fill(config.credentials.email);
  } else {
    log.warning('No username/email input found in the login form.');
  }

  await passwordInput.fill(config.credentials.password);

  const submitButton = form.locator('button[type="submit"], input[type="submit"]').first();
  
  if (await submitButton.count() > 0) {
    await Promise.all([
      page.waitForNavigation({ timeout: 15000 }).catch(() => {}), // wait for navigation (redirect)
      submitButton.click(),
    ]);
  } else {
    log.warning('No submit button found in the login form. Pressing Enter.');
    await Promise.all([
      page.waitForNavigation({ timeout: 15000 }).catch(() => {}),
      passwordInput.press('Enter'),
    ]);
  }

  // After navigation, URL should have changed from loginUrl
  const isAuthenticated = !page.url().includes(config.loginPath);

  if (!isAuthenticated) {
    log.warning(`Auth failed or did not redirect. Current URL: ${page.url()}`);
  }

  return isAuthenticated;
}
