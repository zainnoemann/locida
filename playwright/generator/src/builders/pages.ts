import { escapeSingle, toFieldProp, capitalize, toWords } from '../shared/strings.js';
import { ResourceInfo } from '../types.js';

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

export function generateLoginPage(): string {
    return `// pages/LoginPage.ts

import { Page, Locator, expect } from '@playwright/test';
import { BasePage } from './BasePage';

export class LoginPage extends BasePage {
    readonly emailInput: Locator;
    readonly passwordInput: Locator;
    readonly rememberMeCheckbox: Locator;
    readonly submitButton: Locator;
    readonly forgotPasswordLink: Locator;
    readonly registerLink: Locator;

    constructor(page: Page) {
        super(page);
        const form = page.locator('form').first();
        this.emailInput = form.locator('input[name="email"]');
        this.passwordInput = form.locator('input[name="password"]');
        this.rememberMeCheckbox = form.locator('input[name="remember"]');
        this.submitButton = form.locator('button[type="submit"], input[type="submit"]').first();
        this.forgotPasswordLink = page.locator('a[href*="forgot-password"], a[href*="reset-password"]').first();
        this.registerLink = page.locator('a[href*="/register"]').first();
    }

    async goto(): Promise<void> { await this.navigate('/login'); }

    async login(email: string, password: string): Promise<void> {
        await this.emailInput.fill(email);
        await this.passwordInput.fill(password);
        await this.submitButton.click();
    }

    async fillEmail(value: string): Promise<void> { await this.emailInput.fill(value); }
    async fillPassword(value: string): Promise<void> { await this.passwordInput.fill(value); }
    async clickSubmit(): Promise<void> { await this.submitButton.click(); }

    async assertOnLoginPage(): Promise<void> {
        await this.assertURL(/\\/login/);
        await expect(this.submitButton).toBeVisible();
    }
}
`;
}

export function generateRegisterPage(): string {
    return `// pages/RegisterPage.ts

import { Page, Locator, expect } from '@playwright/test';
import { BasePage } from './BasePage';

export class RegisterPage extends BasePage {
    readonly nameInput: Locator;
    readonly emailInput: Locator;
    readonly passwordInput: Locator;
    readonly confirmPasswordInput: Locator;
    readonly submitButton: Locator;
    readonly loginLink: Locator;

    constructor(page: Page) {
        super(page);
        const form = page.locator('form').first();
        this.nameInput = form.locator('input[name="name"]');
        this.emailInput = form.locator('input[name="email"]');
        this.passwordInput = form.locator('input[name="password"]');
        this.confirmPasswordInput = form.locator('input[name="password_confirmation"]');
        this.submitButton = form.locator('button[type="submit"], input[type="submit"]').first();
        this.loginLink = page.locator('a[href*="/login"]').first();
    }

    async goto(): Promise<void> { await this.navigate('/register'); }

    async register(name: string, email: string, password: string, confirmPassword = password): Promise<void> {
        await this.nameInput.fill(name);
        await this.emailInput.fill(email);
        await this.passwordInput.fill(password);
        await this.confirmPasswordInput.fill(confirmPassword);
        await this.submitButton.click();
    }

    async clickSubmit(): Promise<void> { await this.submitButton.click(); }

    async assertOnRegisterPage(): Promise<void> {
        await this.assertURL(/\\/register/);
        await expect(this.submitButton).toBeVisible();
    }
}
`;
}

export function generateDashboardPage(): string {
    return `// pages/DashboardPage.ts

import { Page, Locator, expect } from '@playwright/test';
import { BasePage } from './BasePage';

export class DashboardPage extends BasePage {
    readonly heading: Locator;
    readonly profileLink: Locator;
    readonly logoutButton: Locator;

    constructor(page: Page) {
        super(page);
        this.heading = page.locator('h1, h2').first();
        this.profileLink = page.locator('a[href*="/profile"]').first();
        this.logoutButton = page.locator('form[action*="logout"] button[type="submit"], button[data-action*="logout"], a[href*="logout"]').first();
    }

    async goto(): Promise<void> { await this.navigate('/dashboard'); }
    async assertOnDashboard(): Promise<void> { await this.assertURLContains('dashboard'); }
    async assertWelcomeVisible(): Promise<void> { await expect(this.heading).toBeVisible(); }
    async assertLogoutVisible(): Promise<void> { await expect(this.logoutButton).toBeVisible(); }
    async assertProfileLinkVisible(): Promise<void> { await expect(this.profileLink).toBeVisible(); }
}
`;
}

export function generateProfilePage(): string {
    return `// pages/ProfilePage.ts

import { Page, Locator, expect } from '@playwright/test';
import { BasePage } from './BasePage';

export class ProfilePage extends BasePage {
    readonly nameInput: Locator;
    readonly emailInput: Locator;
    readonly saveProfileButton: Locator;
    readonly currentPasswordInput: Locator;
    readonly newPasswordInput: Locator;
    readonly confirmNewPasswordInput: Locator;
    readonly savePasswordButton: Locator;
    readonly deleteAccountButton: Locator;

    constructor(page: Page) {
        super(page);
        this.nameInput = page.locator('input[name="name"]').first();
        this.emailInput = page.locator('input[name="email"]').first();
        this.saveProfileButton = page.locator('button[type="submit"], input[type="submit"]').first();
        this.currentPasswordInput = page.locator('input[name="current_password"]').first();
        this.newPasswordInput = page.locator('input[name="password"]').nth(1);
        this.confirmNewPasswordInput = page.locator('input[name="password_confirmation"]').first();
        this.savePasswordButton = page.locator('button[type="submit"], input[type="submit"]').nth(1);
        this.deleteAccountButton = page.locator('button[data-confirm], form[action*="delete"] button[type="submit"], button[type="submit"][form*="delete"]').first();
    }

    async goto(): Promise<void> { await this.navigate('/profile'); }

    async updateName(name: string): Promise<void> {
        await this.nameInput.clear();
        await this.nameInput.fill(name);
        await this.saveProfileButton.click();
    }

    async updateEmail(email: string): Promise<void> {
        await this.emailInput.clear();
        await this.emailInput.fill(email);
        await this.saveProfileButton.click();
    }

    async updateProfile(name: string, email: string): Promise<void> {
        await this.nameInput.clear();
        await this.nameInput.fill(name);
        await this.emailInput.clear();
        await this.emailInput.fill(email);
        await this.saveProfileButton.click();
    }

    async updatePassword(current: string, next: string, confirm: string): Promise<void> {
        await this.currentPasswordInput.fill(current);
        await this.newPasswordInput.fill(next);
        await this.confirmNewPasswordInput.fill(confirm);
        await this.savePasswordButton.click();
    }

    async assertOnProfilePage(): Promise<void> {
        await this.assertURLContains('profile');
        await expect(this.nameInput).toBeVisible();
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
                return `  async fill${capitalize(prop.replace(/Input$/, ''))}(value: string): Promise<void> {\n    await this.${prop}.selectOption({ label: value });\n  }`;
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
                const call = `fill${capitalize(toFieldProp(f.name || '').replace(/Input$/, ''))}`;
                return `    if (data['${escapeSingle(f.name || '')}'] !== undefined) {\n      await this.${toFieldProp(f.name || '')}.clear();\n      await this.${call}(data['${escapeSingle(f.name || '')}']);\n    }`;
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
