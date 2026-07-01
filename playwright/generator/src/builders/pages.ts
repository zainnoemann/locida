import { escapeSingle, toFieldProp, capitalize, toWords } from '../shared/strings.js';
import { FormInput, ResourceInfo } from '../shared/types.js';

export function generateBasePage(): string {
    return `// pages/BasePage.ts

import { Page, expect } from '@playwright/test';

export class BasePage {
    readonly page: Page;

    constructor(page: Page) {
        this.page = page;
    }

    async navigate(path: string): Promise<void> {
        await this.page.goto(path);
    }

    async assertURL(pattern: string | RegExp): Promise<void> {
        await expect(this.page).toHaveURL(pattern);
    }

    async assertURLContains(segment: string): Promise<void> {
        await expect(this.page).toHaveURL(new RegExp(segment));
    }

    async assertErrorMessage(message: string): Promise<void> {
        await expect(
            this.page.locator('.text-red-600, .text-red-500, [class*="error"], .alert-danger')
                .filter({ hasText: message })
        ).toBeVisible();
    }
}
`;
}

export function generateLoginPage(fields: FormInput[] = []): string {
    const fieldDeclarations = fields.map(f => `    readonly ${toFieldProp(f.name || '')}: Locator;`).join('\n');
    const fieldInit = fields.map(f => `        this.${toFieldProp(f.name || '')} = page.locator('[name="${escapeSingle(f.name || '')}"]').first();`).join('\n');
    const fillMethods = fields.map(f => {
        const prop = toFieldProp(f.name || '');
        if ((f.type || '').toLowerCase() === 'checkbox') {
            return `    async fill${capitalize(prop.replace(/Input$/, ''))}(value: string): Promise<void> {\n        if (value === 'true') await this.${prop}.check();\n        else await this.${prop}.uncheck();\n    }`;
        }
        return `    async fill${capitalize(prop.replace(/Input$/, ''))}(value: string): Promise<void> { await this.${prop}.fill(value); }`;
    }).join('\n');
    
    return `// pages/LoginPage.ts

import { Page, Locator, expect } from '@playwright/test';
import { BasePage } from './BasePage';
import { ROUTES } from '../fixtures/test-data';

export class LoginPage extends BasePage {
${fieldDeclarations}
    readonly submitButton: Locator;
    readonly forgotPasswordLink: Locator;
    readonly registerLink: Locator;

    constructor(page: Page) {
        super(page);
${fieldInit}
        this.submitButton = page.locator('button[type="submit"], input[type="submit"]').first();
        this.forgotPasswordLink = page.locator('a[href*="forgot-password"], a[href*="reset-password"]').first();
        this.registerLink = page.locator(\`a[href*="\${ROUTES.register}"]\`).first();
    }

    async goto(): Promise<void> { await this.navigate(ROUTES.login); }

    async login(data: Record<string, string>): Promise<void> {
${fields.map(f => {
        const prop = toFieldProp(f.name || '');
        return `        if (data['${escapeSingle(f.name || '')}'] !== undefined) await this.fill${capitalize(prop.replace(/Input$/, ''))}(data['${escapeSingle(f.name || '')}']);`;
    }).join('\n')}
        await this.submitButton.click();
    }

${fillMethods}
    async clickSubmit(): Promise<void> { await this.submitButton.click(); }

    async assertOnLoginPage(): Promise<void> {
        await this.assertURL(new RegExp(ROUTES.login.replace(/\\//g, '\\\\/')));
        await expect(this.submitButton).toBeVisible();
    }
}
`;
}

export function generateRegisterPage(fields: FormInput[] = []): string {
    const fieldDeclarations = fields.map(f => `    readonly ${toFieldProp(f.name || '')}: Locator;`).join('\n');
    const fieldInit = fields.map(f => `        this.${toFieldProp(f.name || '')} = page.locator('[name="${escapeSingle(f.name || '')}"]').first();`).join('\n');
    const fillMethods = fields.map(f => {
        const prop = toFieldProp(f.name || '');
        if ((f.type || '').toLowerCase() === 'checkbox') {
            return `    async fill${capitalize(prop.replace(/Input$/, ''))}(value: string): Promise<void> {\n        if (value === 'true') await this.${prop}.check();\n        else await this.${prop}.uncheck();\n    }`;
        }
        return `    async fill${capitalize(prop.replace(/Input$/, ''))}(value: string): Promise<void> { await this.${prop}.fill(value); }`;
    }).join('\n');

    return `// pages/RegisterPage.ts

import { Page, Locator, expect } from '@playwright/test';
import { BasePage } from './BasePage';
import { ROUTES } from '../fixtures/test-data';

export class RegisterPage extends BasePage {
${fieldDeclarations}
    readonly submitButton: Locator;
    readonly loginLink: Locator;

    constructor(page: Page) {
        super(page);
${fieldInit}
        this.submitButton = page.locator('button[type="submit"], input[type="submit"]').first();
        this.loginLink = page.locator(\`a[href*="\${ROUTES.login}"]\`).first();
    }

    async goto(): Promise<void> { await this.navigate(ROUTES.register); }

    async register(data: Record<string, string>): Promise<void> {
${fields.map(f => {
        const prop = toFieldProp(f.name || '');
        return `        if (data['${escapeSingle(f.name || '')}'] !== undefined) await this.fill${capitalize(prop.replace(/Input$/, ''))}(data['${escapeSingle(f.name || '')}']);`;
    }).join('\n')}
        await this.submitButton.click();
    }

${fillMethods}
    async clickSubmit(): Promise<void> { await this.submitButton.click(); }

    async assertOnRegisterPage(): Promise<void> {
        await this.assertURL(new RegExp(ROUTES.register.replace(/\\//g, '\\\\/')));
        await expect(this.submitButton).toBeVisible();
    }
}
`;
}

export function generateDashboardPage(): string {
    return `// pages/DashboardPage.ts

import { Page, expect } from '@playwright/test';
import { BasePage } from './BasePage';
import { ROUTES } from '../fixtures/test-data';

export class DashboardPage extends BasePage {
    constructor(page: Page) {
        super(page);
    }

    async goto(): Promise<void> {
        await this.navigate(ROUTES.dashboard);
    }

    async assertWelcomeVisible(): Promise<void> {
        // Assert we reached the dashboard URL and there is some heading (h1, h2, h3, h4)
        await this.assertURL(new RegExp(ROUTES.dashboard.replace(/\\//g, '\\\\/')));
        await expect(this.page.locator('h1, h2, h3, h4').first()).toBeVisible();
    }

    async assertOnDashboard(): Promise<void> {
        await this.assertURL(new RegExp(ROUTES.dashboard.replace(/\\//g, '\\\\/')));
    }
}
`;
}

export function generateProfilePage(fields: FormInput[] = []): string {
    const fieldDeclarations = fields.map(f => `    readonly ${toFieldProp(f.name || '')}: Locator;`).join('\n');
    const fieldInit = fields.map(f => `        this.${toFieldProp(f.name || '')} = page.locator('[name="${escapeSingle(f.name || '')}"]').first();`).join('\n');
    const fillMethods = fields.map(f => {
        const prop = toFieldProp(f.name || '');
        if ((f.type || '').toLowerCase() === 'checkbox') {
            return `    async fill${capitalize(prop.replace(/Input$/, ''))}(value: string): Promise<void> {\n        if (value === 'true') await this.${prop}.check();\n        else await this.${prop}.uncheck();\n    }`;
        }
        return `    async fill${capitalize(prop.replace(/Input$/, ''))}(value: string): Promise<void> { await this.${prop}.fill(value); }`;
    }).join('\n');

    return `// pages/ProfilePage.ts

import { Page, Locator, expect } from '@playwright/test';
import { BasePage } from './BasePage';
import { ROUTES } from '../fixtures/test-data';

export class ProfilePage extends BasePage {
${fieldDeclarations}
    readonly saveButton: Locator;

    constructor(page: Page) {
        super(page);
${fieldInit}
        this.saveButton = page.locator('button[type="submit"], input[type="submit"]').first();
    }

    async goto(): Promise<void> { await this.navigate(ROUTES.profile); }

    async updateProfile(data: Record<string, string>): Promise<void> {
${fields.map(f => {
        const prop = toFieldProp(f.name || '');
        return `        if (data['${escapeSingle(f.name || '')}'] !== undefined) await this.fill${capitalize(prop.replace(/Input$/, ''))}(data['${escapeSingle(f.name || '')}']);`;
    }).join('\n')}
        await this.saveButton.click();
    }

${fillMethods}
    async assertOnProfilePage(): Promise<void> {
        await this.assertURL(new RegExp(ROUTES.profile.replace(/\\//g, '\\\\/')));
    }
}
`;
}

export function generateResourcePage(resource: ResourceInfo): string {
    const subject = resource.className;
    const fieldDeclarations = resource.fields
        .map((f) => `  readonly ${toFieldProp(f.name || '')}: Locator;`)
        .join('\n');
    const fieldCtorInit = resource.fields
        .map((f) => `    this.${toFieldProp(f.name || '')} = page.locator('[name="${escapeSingle(f.name || '')}"]').first();`)
        .join('\n');

    const fillMethods = resource.fields
        .map((f) => {
            const prop = toFieldProp(f.name || '');
            if ((f.type || '').toLowerCase() === 'select') {
                return `  async fill${capitalize(prop.replace(/Input$/, ''))}(value: string): Promise<void> {
    try {
      await this.${prop}.selectOption(value, { timeout: 2000 });
    } catch {
      const options = this.${prop}.locator('option');
      await options.first().waitFor({ state: 'attached', timeout: 2000 }).catch(() => {});
      const count = await options.count();
      if (count > 1) {
        await this.${prop}.selectOption({ index: count - 1 }).catch(() => {});
      } else if (count === 1) {
        await this.${prop}.selectOption({ index: 0 }).catch(() => {});
      }
    }
  }`;
            }
            if ((f.type || '').toLowerCase() === 'checkbox') {
                return `  async fill${capitalize(prop.replace(/Input$/, ''))}(value: string): Promise<void> {\n    if (value === 'true') await this.${prop}.check();\n    else await this.${prop}.uncheck();\n  }`;
            }
            return `  async fill${capitalize(prop.replace(/Input$/, ''))}(value: string): Promise<void> {\n    await this.${prop}.fill(value);\n  }`;
        })
        .join('\n\n');

    const createFillLines = resource.fields
        .map((f) => {
            const propName = capitalize(toFieldProp(f.name || '').replace(/Input$/, ''));
            return `    if (data['${escapeSingle(f.name || '')}'] !== undefined) await this.fill${propName}(data['${escapeSingle(f.name || '')}']);`;
        })
        .join('\n');

    const assertFieldMethods = resource.fields
        .map((f) => {
            const prop = toFieldProp(f.name || '');
            const name = capitalize(toWords(f.name || ''));
            const requiredAssertion = f.required
                ? `\n  async assert${name.replace(/\s+/g, '')}Required(): Promise<void> {\n    await expect(this.${prop}).toHaveAttribute('required');\n  }`
                : '';

            return `  async assert${name.replace(/\s+/g, '')}Visible(): Promise<void> {\n    await expect(this.${prop}).toBeVisible();\n  }${requiredAssertion}`;
        })
        .join('\n\n');

    return `// pages/${subject}Page.ts

import { Page, Locator, expect } from '@playwright/test';
import { BasePage } from './BasePage';

export class ${subject}Page extends BasePage {
    readonly createButton: Locator;
    readonly ${resource.name}Table: Locator;
    readonly tableRows: Locator;
${fieldDeclarations ? `${fieldDeclarations}\n` : ''}  readonly submitButton: Locator;

    constructor(page: Page) {
        super(page);
        this.createButton = page.locator('a[href*="/create"], a[href$="create"], a[href*="/new"]').first();
        this.${resource.name}Table = page.locator('table').first();
        this.tableRows = page.locator('table tbody tr');
${fieldCtorInit ? `${fieldCtorInit}\n` : ''}    this.submitButton = page.locator('button[type="submit"], input[type="submit"]').first();
    }

    async gotoIndex(): Promise<void> { await this.navigate('${resource.indexPath}'); }
    async gotoCreate(): Promise<void> { await this.navigate('${resource.createPath}'); }
    async clickSubmit(): Promise<void> { await this.submitButton.click(); }

${fillMethods ? `${fillMethods}\n\n` : ''}  async create${subject}(data: Record<string, string>): Promise<void> {
        await this.gotoCreate();
${createFillLines}
        await this.clickSubmit();
    }

    async edit${subject}ByName(current: string, data: Record<string, string>): Promise<void> {
        const row = this.page.locator('table tbody tr').filter({ hasText: current });
        await row.locator('a[href*="/edit"], a[href*="edit"]').first().click();
${resource.fields
            .map((f) => {
                const isSelectOrCheckbox = ['select', 'checkbox'].includes((f.type || '').toLowerCase());
                const call = `fill${capitalize(toFieldProp(f.name || '').replace(/Input$/, ''))}`;
                const clearStr = !isSelectOrCheckbox ? `      await this.${toFieldProp(f.name || '')}.clear();\n` : '';
                return `    if (data['${escapeSingle(f.name || '')}'] !== undefined) {\n${clearStr}      await this.${call}(data['${escapeSingle(f.name || '')}']);\n    }`;
            })
            .join('\n')}
        await this.clickSubmit();
    }

    async delete${subject}ByName(name: string): Promise<void> {
        const row = this.page.locator('table tbody tr').filter({ hasText: name });
        this.page.once('dialog', (d) => d.accept());
        await row.locator('form button[type="submit"], button[data-action*="delete"], button[type="submit"]').first().click();
    }

    async assertOnIndexPage(): Promise<void> {
        await this.assertURLContains('${resource.name}');
        await expect(this.${resource.name}Table).toBeVisible();
    }

    async assertOnCreatePage(): Promise<void> {
        await this.assertURLContains('${resource.name}/create');
${resource.fields[0] ? `    await expect(this.${toFieldProp(resource.fields[0].name || '')}).toBeVisible();` : '    await expect(this.submitButton).toBeVisible();'}
    }

    async assert${subject}Exists(value: string): Promise<void> {
        await expect(this.page.locator('table').getByText(value)).toBeVisible();
    }

    async assert${subject}NotExists(value: string): Promise<void> {
        await expect(this.page.locator('table').getByText(value)).not.toBeVisible();
    }

    async assertTableVisible(): Promise<void> {
        await expect(this.${resource.name}Table).toBeVisible();
    }

    async assertCreateButtonVisible(): Promise<void> {
        await expect(this.createButton).toBeVisible();
    }

${assertFieldMethods ? `${assertFieldMethods}\n\n` : ''}  async assertSubmitButtonVisible(): Promise<void> {
        await expect(this.submitButton).toBeVisible();
    }

    async getRowCount(): Promise<number> {
        return this.tableRows.count();
    }
}
`;
}
