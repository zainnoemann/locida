import { ResourceGroup, FormField, ParsedView } from '../types';
import { camelCase, capitalize, cleanLabel, singularize } from '../utils/strings';
import { code } from '../utils/codegen';

// ─── Page Object Generators ───────────────────────────────────────────────────

export function generateBasePage(): string {
  return `// pages/BasePage.ts

import { Page, Locator, expect } from '@playwright/test';

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

  async assertTextVisible(text: string | RegExp): Promise<void> {
    await expect(this.page.getByText(text)).toBeVisible();
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

export function generateLoginPage(loginView?: ParsedView): string {
  const emailLabel    = loginView?.fields.find(f => f.name === 'email')?.label    ?? 'Email';
  const passwordLabel = loginView?.fields.find(f => f.name === 'password')?.label ?? 'Password';
  const rememberLabel = loginView?.fields.find(
    f => f.name === 'remember' || f.name === 'remember_me'
  )?.label ?? 'Remember me';

  return `// pages/LoginPage.ts

import { Page, Locator, expect } from '@playwright/test';
import { BasePage } from './BasePage';

export class LoginPage extends BasePage {
  readonly emailInput:         Locator;
  readonly passwordInput:      Locator;
  readonly rememberMeCheckbox: Locator;
  readonly submitButton:       Locator;
  readonly forgotPasswordLink: Locator;
  readonly registerLink:       Locator;

  constructor(page: Page) {
    super(page);
    this.emailInput         = page.getByLabel('${emailLabel}');
    this.passwordInput      = page.getByLabel('${passwordLabel}');
    this.rememberMeCheckbox = page.getByLabel('${rememberLabel}');
    this.submitButton       = page.getByRole('button', { name: /log in/i });
    this.forgotPasswordLink = page.getByRole('link', { name: /forgot/i });
    this.registerLink       = page.getByRole('link', { name: /register/i });
  }

  async goto(): Promise<void> { await this.navigate('/login'); }

  async login(email: string, password: string): Promise<void> {
    await this.emailInput.fill(email);
    await this.passwordInput.fill(password);
    await this.submitButton.click();
  }

  async fillEmail(email: string): Promise<void>       { await this.emailInput.fill(email); }
  async fillPassword(pw: string): Promise<void>        { await this.passwordInput.fill(pw); }
  async clickSubmit(): Promise<void>                   { await this.submitButton.click(); }

  async assertOnLoginPage(): Promise<void> {
    await this.assertURL(/\\/login/);
    await expect(this.submitButton).toBeVisible();
  }
}
`;
}

export function generateRegisterPage(registerView?: ParsedView): string {
  const nameLabel    = registerView?.fields.find(f => f.name === 'name')?.label    ?? 'Name';
  const emailLabel   = registerView?.fields.find(f => f.name === 'email')?.label   ?? 'Email';
  const pwLabel      = registerView?.fields.find(f => f.name === 'password')?.label ?? 'Password';
  const confirmLabel = registerView?.fields.find(
    f => f.name === 'password_confirmation'
  )?.label ?? 'Confirm Password';

  return `// pages/RegisterPage.ts

import { Page, Locator, expect } from '@playwright/test';
import { BasePage } from './BasePage';

export class RegisterPage extends BasePage {
  readonly nameInput:            Locator;
  readonly emailInput:           Locator;
  readonly passwordInput:        Locator;
  readonly confirmPasswordInput: Locator;
  readonly submitButton:         Locator;
  readonly loginLink:            Locator;

  constructor(page: Page) {
    super(page);
    this.nameInput            = page.getByLabel('${nameLabel}');
    this.emailInput           = page.getByLabel('${emailLabel}');
    this.passwordInput        = page.getByLabel('${pwLabel}', { exact: true });
    this.confirmPasswordInput = page.getByLabel('${confirmLabel}');
    this.submitButton         = page.getByRole('button', { name: /register/i });
    this.loginLink            = page.getByRole('link', { name: /already registered|login|sign in/i });
  }

  async goto(): Promise<void> { await this.navigate('/register'); }

  async register(
    name: string, email: string, password: string, confirmPassword = password
  ): Promise<void> {
    await this.nameInput.fill(name);
    await this.emailInput.fill(email);
    await this.passwordInput.fill(password);
    await this.confirmPasswordInput.fill(confirmPassword);
    await this.submitButton.click();
  }

  async fillName(v: string): Promise<void>            { await this.nameInput.fill(v); }
  async fillEmail(v: string): Promise<void>           { await this.emailInput.fill(v); }
  async fillPassword(v: string): Promise<void>        { await this.passwordInput.fill(v); }
  async fillConfirmPassword(v: string): Promise<void> { await this.confirmPasswordInput.fill(v); }
  async clickSubmit(): Promise<void>                  { await this.submitButton.click(); }

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
  readonly heading:            Locator;
  readonly navDropdownTrigger: Locator;
  readonly logoutLink:         Locator;
  readonly profileLink:        Locator;

  constructor(page: Page) {
    super(page);
    this.heading            = this.page.getByRole('heading', { name: /dashboard/i });
    this.navDropdownTrigger = this.page.locator('nav button').filter({ hasText: /./i }).first();
    this.logoutLink         = this.page.getByRole('link', { name: /log out/i });
    this.profileLink        = this.page.getByRole('link', { name: /^profile$/i });
  }

  async assertOnDashboard(): Promise<void> {
    await this.assertURL(/\\/dashboard/);
  }

  async assertWelcomeVisible(): Promise<void> {
    await expect(this.page.getByText(/you're logged in|welcome/i)).toBeVisible();
  }

  async assertLogoutVisible(): Promise<void> {
    await this.navDropdownTrigger.click();
    await expect(this.logoutLink).toBeVisible();
  }

  async assertProfileLinkVisible(): Promise<void> {
    await this.navDropdownTrigger.click();
    await expect(this.profileLink).toBeVisible();
  }
}
`;
}

export function generateProfilePage(): string {
  return `// pages/ProfilePage.ts

import { Page, Locator, expect } from '@playwright/test';
import { BasePage } from './BasePage';

export class ProfilePage extends BasePage {
  readonly nameInput:              Locator;
  readonly emailInput:             Locator;
  readonly saveProfileButton:      Locator;
  readonly currentPasswordInput:   Locator;
  readonly newPasswordInput:       Locator;
  readonly confirmNewPasswordInput:Locator;
  readonly savePasswordButton:     Locator;
  readonly deleteAccountButton:    Locator;

  constructor(page: Page) {
    super(page);
    this.nameInput               = page.getByLabel('Name');
    this.emailInput              = page.getByLabel('Email');
    this.saveProfileButton       = page.getByRole('button', { name: 'Save' }).first();
    this.currentPasswordInput    = page.getByLabel('Current Password');
    this.newPasswordInput        = page.getByLabel('New Password');
    this.confirmNewPasswordInput = page.getByLabel('Confirm Password');
    this.savePasswordButton      = page.getByRole('button', { name: 'Save' }).nth(1);
    this.deleteAccountButton     = page.getByRole('button', { name: /delete account/i });
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

  async assertSavedSuccessfully(): Promise<void> {
    await expect(this.page.getByText('Saved.')).toBeVisible();
  }
}
`;
}

export function generateResourcePage(resource: ResourceGroup): string {
  const { className, name, fields, hasDelete, hasEdit, hasCreate } = resource;
  const pageName    = `${className}Page`;
  const textFields  = fields.filter(f => f.type !== 'select' && !f.name.endsWith('_id'));
  const selectFields = fields.filter(f => f.type === 'select' || f.name.endsWith('_id'));
  const primaryField = textFields[0];

  const b = code();
  b.line(`// pages/${pageName}.ts`, ``);
  b.line(`import { Page, Locator, expect } from '@playwright/test';`);
  b.line(`import { BasePage } from './BasePage';`);
  b.blank();
  b.line(`export class ${pageName} extends BasePage {`);

  // Locators
  if (hasCreate) b.line(`  readonly createButton: Locator;`);
  b.line(`  readonly ${name}Table:  Locator;`);
  b.line(`  readonly tableRows:    Locator;`);
  for (const f of fields) {
    b.line(`  readonly ${locatorName(f)}: Locator;`);
  }
  b.line(`  readonly submitButton: Locator;`);
  b.blank();

  // Constructor
  b.line(`  constructor(page: Page) {`);
  b.line(`    super(page);`);
  if (hasCreate) b.line(`    this.createButton = page.getByRole('link', { name: /create|add/i });`);
  b.line(`    this.${name}Table   = page.locator('table');`);
  b.line(`    this.tableRows     = page.locator('table tbody tr');`);
  for (const f of fields) {
    b.line(`    this.${locatorName(f).padEnd(18)} = ${buildLocator(f)};`);
  }
  b.line(`    this.submitButton  = page.getByRole('button', { name: /save|create|submit/i });`);
  b.line(`  }`);
  b.blank();

  // Navigation
  b.line(`  async gotoIndex(): Promise<void>  { await this.navigate('/${name}'); }`);
  if (hasCreate) {
    b.line(`  async gotoCreate(): Promise<void> { await this.navigate('/${name}/create'); }`);
  }
  b.line(`  async clickSubmit(): Promise<void> { await this.submitButton.click(); }`);
  b.blank();

  // Fill methods
  for (const f of fields) {
    const method = `fill${capitalize(camelCase(f.name))}`;
    if (f.type === 'select' || f.name.endsWith('_id')) {
      b.line(`  async ${method}(value: string): Promise<void> {`);
      b.line(`    await this.${locatorName(f)}.selectOption({ label: value });`);
      b.line(`  }`);
    } else {
      b.line(`  async ${method}(value: string): Promise<void> {`);
      b.line(`    await this.${locatorName(f)}.fill(value);`);
      b.line(`  }`);
    }
  }
  b.blank();

  // Composite create
  if (hasCreate) {
    const params = fields.map(f => {
      const p = camelCase(f.name);
      return (f.type === 'select' || f.name.endsWith('_id')) ? `${p}?: string` : `${p}: string`;
    }).join(', ');

    b.line(`  async create${className}(${params}): Promise<void> {`);
    b.line(`    await this.gotoCreate();`);
    for (const f of fields) {
      const p = camelCase(f.name);
      const method = `fill${capitalize(camelCase(f.name))}`;
      if (f.type === 'select' || f.name.endsWith('_id')) {
        b.line(`    if (${p}) await this.${method}(${p});`);
      } else {
        b.line(`    await this.${method}(${p});`);
      }
    }
    b.line(`    await this.clickSubmit();`);
    b.line(`  }`);
    b.blank();
  }

  // Edit by primary field
  if (hasEdit && primaryField) {
    const editParams = textFields.map(f => `${camelCase(f.name)}: string`).join(', ');
    b.line(`  async edit${className}ByName(current: string, ${editParams}): Promise<void> {`);
    b.line(`    const row = this.page.locator('table tbody tr').filter({ hasText: current });`);
    b.line(`    await row.getByRole('link', { name: /edit/i }).click();`);
    for (const f of textFields) {
      b.line(`    await this.${locatorName(f)}.clear();`);
      b.line(`    await this.${locatorName(f)}.fill(${camelCase(f.name)});`);
    }
    b.line(`    await this.clickSubmit();`);
    b.line(`  }`);
    b.blank();
  }

  // Delete by name
  if (hasDelete) {
    b.line(`  async delete${className}ByName(name: string): Promise<void> {`);
    b.line(`    const row = this.page.locator('table tbody tr').filter({ hasText: name });`);
    b.line(`    this.page.once('dialog', d => d.accept());`);
    b.line(`    await row.getByRole('button', { name: /delete/i }).click();`);
    b.line(`  }`);
    b.blank();
  }

  // Assertions
  b.line(`  async assertOnIndexPage(): Promise<void> {`);
  b.line(`    await this.assertURLContains('${name}');`);
  b.line(`    await expect(this.${name}Table).toBeVisible();`);
  b.line(`  }`);

  if (hasCreate) {
    b.line(`  async assertOnCreatePage(): Promise<void> {`);
    b.line(`    await this.assertURLContains('${name}/create');`);
    if (primaryField) b.line(`    await expect(this.${locatorName(primaryField)}).toBeVisible();`);
    b.line(`  }`);
  }

  if (primaryField) {
    b.line(`  async assert${className}Exists(value: string): Promise<void> {`);
    b.line(`    await expect(this.page.locator('table').getByText(value)).toBeVisible();`);
    b.line(`  }`);
    b.line(`  async assert${className}NotExists(value: string): Promise<void> {`);
    b.line(`    await expect(this.page.locator('table').getByText(value)).not.toBeVisible();`);
    b.line(`  }`);
  }

  b.line(`  async assertTableVisible(): Promise<void> {`);
  b.line(`    await expect(this.${name}Table).toBeVisible();`);
  b.line(`  }`);

  if (hasCreate) {
    b.line(`  async assertCreateButtonVisible(): Promise<void> {`);
    b.line(`    await expect(this.createButton).toBeVisible();`);
    b.line(`  }`);
  }

  for (const f of textFields) {
    const suffix = capitalize(camelCase(f.name));
    b.line(`  async assert${suffix}InputVisible(): Promise<void> {`);
    b.line(`    await expect(this.${locatorName(f)}).toBeVisible();`);
    b.line(`  }`);
    if (f.required && f.type !== 'textarea') {
      b.line(`  async assert${suffix}Required(): Promise<void> {`);
      b.line(`    await expect(this.${locatorName(f)}).toHaveAttribute('required');`);
      b.line(`  }`);
    }
  }

  for (const f of selectFields) {
    const suffix = capitalize(camelCase(f.name.replace(/_id$/, '')));
    b.line(`  async assert${suffix}SelectVisible(): Promise<void> {`);
    b.line(`    if (await this.${locatorName(f)}.isVisible()) {`);
    b.line(`      await expect(this.${locatorName(f)}).toBeVisible();`);
    b.line(`    }`);
    b.line(`  }`);
  }

  b.line(`  async assertSubmitButtonVisible(): Promise<void> {`);
  b.line(`    await expect(this.submitButton).toBeVisible();`);
  b.line(`  }`);

  b.line(`  async getRowCount(): Promise<number> {`);
  b.line(`    return this.tableRows.count();`);
  b.line(`  }`);

  b.line(`}`);

  return b.toString();
}

// ── Private helpers ───────────────────────────────────────────────────────────

function locatorName(f: FormField): string {
  if (f.type === 'select' || f.name.endsWith('_id')) {
    return `${camelCase(f.name.replace(/_id$/, ''))}Select`;
  }
  return `${camelCase(f.name)}Input`;
}

function buildLocator(f: FormField): string {
  return `page.getByLabel('${cleanLabel(f.label)}')`;
}
