import type { Page } from 'playwright';
import type { Request } from 'crawlee';
import { CrawlerConfig, ExtractedPageData } from './types.js';
import { normalizePathname } from '../shared/utils.js';

export async function extractPageData(
  page: Page,
  request: Request,
  requestedPathname: string,
  phase: 'guest' | 'auth',
  config: CrawlerConfig
): Promise<ExtractedPageData> {
  const effectivePathname = normalizePathname(new URL(page.url()).pathname);
  const pageTitle = await page.title();
  
  // Pass exclude patterns to browser context
  const excludePatterns = config.authExcludePatterns.map(p => p.replace(/\*\*/g, '').replace(/\*/g, ''));

  const pageData = await page.evaluate((excludes) => {
    const normalizePath = (value: string): string | null => {
      if (!value) return null;
      const result = value
        .replace(/\/\d+(?=\/|$)/g, '/[id]')
        .replace(/\/$/, '');
      return result || '/';
    };

    const toLabel = (el: HTMLElement): string => {
      // Check for associated label
      const id = el.getAttribute('id');
      if (id) {
        const byFor = document.querySelector(`label[for="${id}"]`);
        if (byFor?.textContent?.trim()) {
          return byFor.textContent.trim();
        }
      }

      // Check for wrapping label
      const wrapped = el.closest('label');
      if (wrapped?.textContent?.trim()) {
        return wrapped.textContent.trim();
      }

      // Check aria-label
      const ariaLabel = el.getAttribute('aria-label');
      if (ariaLabel?.trim()) {
        return ariaLabel.trim();
      }

      // Check placeholder
      const placeholder = el.getAttribute('placeholder');
      if (placeholder?.trim()) {
        return placeholder.trim();
      }

      // Use name attribute
      const name = el.getAttribute('name');
      if (name?.trim()) {
        return name.replace(/[_-]/g, ' ').trim();
      }

      return el.tagName.toLowerCase();
    };

    // Extract forms
    const forms = Array.from(document.querySelectorAll('form'))
      .map((form) => {
        if (!(form instanceof HTMLFormElement)) return null;
        
        const rawAction = form.action || '';
        if (excludes.some(ex => rawAction.includes(ex))) return null;

        const action = normalizePath(
          new URL(form.action || window.location.href, window.location.href)
            .pathname
        );
        const method = (form.getAttribute('method') || 'GET').toUpperCase();

        const inputs = (
          Array.from(form.querySelectorAll('input, select, textarea')) as Array<
            HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement
          >
        ).map((input) => {
          const tag = input.tagName.toLowerCase();
          const type = (input as HTMLInputElement).getAttribute('type') || tag;

          const baseInput = {
            name: input.getAttribute('name') || null,
            label: toLabel(input as HTMLElement),
            type,
            tag,
            placeholder:
              (input as HTMLInputElement).getAttribute('placeholder') || null,
            required: input.hasAttribute('required'),
          };

          if (tag === 'select') {
            const options = Array.from(input.querySelectorAll('option'))
              .map((opt) => ({
                value: opt.getAttribute('value') || '',
                text: opt.textContent?.trim() || '',
              }))
              .filter((opt) => opt.value !== '');
            return { ...baseInput, options };
          }

          return baseInput;
        });

        return { action, method, inputs };
      })
      .filter(Boolean);

    // Extract tables
    const tables = Array.from(document.querySelectorAll('table')).map((table) => {
      const columns = Array.from(table.querySelectorAll('th'))
        .map((th) => th.textContent?.trim() || '')
        .filter(Boolean);
      const hasActions = !!table.querySelector(
        'a[href*="edit"], button, form button'
      );
      return { columns, hasActions };
    });

    // Extract actions
    const actions = (
      Array.from(document.querySelectorAll('a, button')) as HTMLElement[]
    )
      .map((el) => {
        const text = (el.textContent || '').trim();
        if (!text) return null;

        const href =
          el.tagName.toLowerCase() === 'a' ? el.getAttribute('href') : null;
        const absHref = href
          ? new URL(href, window.location.href).pathname
          : null;

        if (
          !/create|new|edit|delete|save|submit/i.test(text) &&
          !(absHref && /create|edit/.test(absHref))
        ) {
          return null;
        }

        return {
          text,
          tag: el.tagName.toLowerCase(),
          href: absHref ? normalizePath(absHref) : null,
        };
      })
      .filter(Boolean);

    return { forms, tables, actions };
  }, excludePatterns);

  const forms = (
    pageData.forms as Array<ExtractedPageData['forms'][number] | null>
  ).filter((form): form is ExtractedPageData['forms'][number] => form !== null);

  const actions = (
    pageData.actions as Array<ExtractedPageData['components']['actions'][number] | null>
  ).filter(
    (action): action is ExtractedPageData['components']['actions'][number] =>
      action !== null
  );

  return {
    title: pageTitle,
    url: request.url,
    pathname: requestedPathname,
    effectivePathname,
    phase,
    forms,
    components: {
      tables: pageData.tables,
      actions,
    },
  };
}
