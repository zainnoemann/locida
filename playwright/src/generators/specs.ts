import { ResourceGroup, FormField } from '../types';
import { camelCase, capitalize, cleanLabel, singularize } from '../utils/strings';
import { code, CodeBuilder } from '../utils/codegen';

// ─── Spec Generators ─────────────────────────────────────────────────────────

export function generateAuthSpec(): string {
  return `// tests/auth.spec.ts

import { test, expect } from '@playwright/test';
import { LoginPage }    from '../pages/LoginPage';
import { RegisterPage } from '../pages/RegisterPage';
import { DashboardPage }from '../pages/DashboardPage';
import { TEST_USER }    from '../fixtures/test-data';

// ── Login page ────────────────────────────────────────────────────
test.describe('Login page — UI elements', () => {
  let loginPage: LoginPage;

  test.beforeEach(async ({ page }) => {
    loginPage = new LoginPage(page);
    await loginPage.goto();
  });

  test('shows email field',           async () => { await expect(loginPage.emailInput).toBeVisible(); });
  test('shows password field',        async () => { await expect(loginPage.passwordInput).toBeVisible(); });
  test('shows submit button',         async () => { await expect(loginPage.submitButton).toBeEnabled(); });
  test('shows forgot password link',  async () => { await expect(loginPage.forgotPasswordLink).toBeVisible(); });
  test('shows remember me checkbox',  async () => { await expect(loginPage.rememberMeCheckbox).toBeVisible(); });
  test('email field is type email',   async () => { await expect(loginPage.emailInput).toHaveAttribute('type', 'email'); });
  test('password field is type password', async () => { await expect(loginPage.passwordInput).toHaveAttribute('type', 'password'); });
  test('email field is required',     async () => { await expect(loginPage.emailInput).toHaveAttribute('required'); });
  test('password field is required',  async () => { await expect(loginPage.passwordInput).toHaveAttribute('required'); });
});

test.describe('Login page — validation', () => {
  let loginPage: LoginPage;

  test.beforeEach(async ({ page }) => {
    loginPage = new LoginPage(page);
    await loginPage.goto();
  });

  test('shows error for invalid email format', async () => {
    await loginPage.fillEmail('not-an-email');
    await loginPage.fillPassword('password');
    await loginPage.clickSubmit();
    await loginPage.assertOnLoginPage();
  });

  test('shows error for wrong credentials', async () => {
    await loginPage.login('wrong@email.com', 'wrongpassword');
    await loginPage.assertOnLoginPage();
    await loginPage.assertErrorMessage('These credentials do not match our records.');
  });

  test('stays on login page when form is empty', async () => {
    await loginPage.clickSubmit();
    await loginPage.assertOnLoginPage();
  });
});

// ── Register page ─────────────────────────────────────────────────
test.describe('Register page — UI elements', () => {
  let registerPage: RegisterPage;

  test.beforeEach(async ({ page }) => {
    registerPage = new RegisterPage(page);
    await registerPage.goto();
  });

  test('shows name field',             async () => { await expect(registerPage.nameInput).toBeVisible(); });
  test('shows email field',            async () => { await expect(registerPage.emailInput).toBeVisible(); });
  test('shows password field',         async () => { await expect(registerPage.passwordInput).toBeVisible(); });
  test('shows confirm password field', async () => { await expect(registerPage.confirmPasswordInput).toBeVisible(); });
  test('shows register button',        async () => { await expect(registerPage.submitButton).toBeVisible(); });
  test('shows link to login page',     async () => { await expect(registerPage.loginLink).toBeVisible(); });
  test('password field is type password',
    async () => { await expect(registerPage.passwordInput).toHaveAttribute('type', 'password'); });
  test('confirm password field is type password',
    async () => { await expect(registerPage.confirmPasswordInput).toHaveAttribute('type', 'password'); });
});

test.describe('Register page — validation', () => {
  let registerPage: RegisterPage;

  test.beforeEach(async ({ page }) => {
    registerPage = new RegisterPage(page);
    await registerPage.goto();
  });

  test('shows error when passwords do not match', async () => {
    await registerPage.register('Test User', 'test@example.com', 'Password123!', 'Different!');
    await registerPage.assertOnRegisterPage();
    await registerPage.assertErrorMessage('The password field confirmation does not match.');
  });

  test('stays on register page when form is empty', async () => {
    await registerPage.clickSubmit();
    await registerPage.assertOnRegisterPage();
  });

  test('shows error for password too short', async ({ page }) => {
    await page.evaluate(() => {
      document.querySelectorAll('input').forEach(el => el.removeAttribute('minlength'));
    });
    await registerPage.register('Test User', 'test@example.com', '123', '123');
    await registerPage.assertOnRegisterPage();
    await registerPage.assertErrorMessage('The password field must be at least 8 characters.');
  });
});

// ── Dashboard ─────────────────────────────────────────────────────
test.describe('Dashboard — UI elements', () => {
  let dashboardPage: DashboardPage;

  test.beforeEach(async ({ page }) => {
    const loginPage = new LoginPage(page);
    dashboardPage = new DashboardPage(page);
    await loginPage.goto();
    await loginPage.login(TEST_USER.email, TEST_USER.password);
    await dashboardPage.assertOnDashboard();
  });

  test('shows dashboard heading',  async () => { await expect(dashboardPage.heading).toBeVisible(); });
  test('shows welcome text',        async () => { await dashboardPage.assertWelcomeVisible(); });
  test('shows logout in nav',       async () => { await dashboardPage.assertLogoutVisible(); });
  test('shows profile link in nav', async () => { await dashboardPage.assertProfileLinkVisible(); });
});

// ── Navigation ────────────────────────────────────────────────────
test.describe('Auth navigation', () => {
  test('register page links to login', async ({ page }) => {
    const registerPage = new RegisterPage(page);
    const loginPage    = new LoginPage(page);
    await registerPage.goto();
    await registerPage.loginLink.click();
    await loginPage.assertOnLoginPage();
  });

  test('unauthenticated access to dashboard redirects to login', async ({ page }) => {
    const loginPage = new LoginPage(page);
    await page.goto('/dashboard');
    await loginPage.assertOnLoginPage();
  });
});
`;
}

export function generateProfileSpec(): string {
  return `// tests/profile.spec.ts

import { test, expect } from '@playwright/test';
import { LoginPage }   from '../pages/LoginPage';
import { ProfilePage } from '../pages/ProfilePage';
import { TEST_USER }   from '../fixtures/test-data';

test.beforeEach(async ({ page }) => {
  const loginPage = new LoginPage(page);
  await loginPage.goto();
  await loginPage.login(TEST_USER.email, TEST_USER.password);
  await page.waitForURL(/dashboard/);
});

// ── UI elements ───────────────────────────────────────────────────
test.describe('Profile page — UI elements', () => {
  let profilePage: ProfilePage;

  test.beforeEach(async ({ page }) => {
    profilePage = new ProfilePage(page);
    await profilePage.goto();
  });

  test('shows name field',                async () => { await expect(profilePage.nameInput).toBeVisible(); });
  test('shows email field',               async () => { await expect(profilePage.emailInput).toBeVisible(); });
  test('shows save profile button',       async () => { await expect(profilePage.saveProfileButton).toBeVisible(); });
  test('name field is required',          async () => { await expect(profilePage.nameInput).toHaveAttribute('required'); });
  test('email field is type email',       async () => { await expect(profilePage.emailInput).toHaveAttribute('type', 'email'); });
  test('shows current password field',    async () => { await expect(profilePage.currentPasswordInput).toBeVisible(); });
  test('current password is type password', async () => { await expect(profilePage.currentPasswordInput).toHaveAttribute('type', 'password'); });
  test('shows delete account button',     async () => { await expect(profilePage.deleteAccountButton).toBeVisible(); });
  test('name field shows logged-in user name',  async () => { await expect(profilePage.nameInput).toHaveValue(TEST_USER.name); });
  test('email field shows logged-in user email', async () => { await expect(profilePage.emailInput).toHaveValue(TEST_USER.email); });
});

// ── Update profile ────────────────────────────────────────────────
test.describe('Profile page — update info', () => {
  let profilePage: ProfilePage;

  test.beforeEach(async ({ page }) => {
    profilePage = new ProfilePage(page);
    await profilePage.goto();
  });

  test('saves new name successfully', async () => {
    await profilePage.updateName('Updated Name');
    await profilePage.assertSavedSuccessfully();
  });

  test('name field reflects new value after save', async () => {
    await profilePage.updateName('Name After Update');
    await expect(profilePage.nameInput).toHaveValue('Name After Update');
  });

  test('shows error when name is cleared', async () => {
    await profilePage.updateName('');
    await profilePage.assertOnProfilePage();
  });

  test('shows error for invalid email format', async () => {
    await profilePage.updateEmail('not-an-email');
    await profilePage.assertOnProfilePage();
  });

  test('save button is enabled', async () => {
    await expect(profilePage.saveProfileButton).toBeEnabled();
  });

  test.afterAll(async ({ browser }) => {
    const context = await browser.newContext();
    const page    = await context.newPage();
    const login   = new LoginPage(page);
    const profile = new ProfilePage(page);
    await login.goto();
    await login.login(TEST_USER.email, TEST_USER.password);
    await profile.goto();
    await profile.updateProfile(TEST_USER.name, TEST_USER.email);
    await context.close();
  });
});

// ── Update password ───────────────────────────────────────────────
test.describe('Profile page — update password', () => {
  let profilePage: ProfilePage;

  test.beforeEach(async ({ page }) => {
    profilePage = new ProfilePage(page);
    await profilePage.goto();
  });

  test('shows new password field',          async () => { await expect(profilePage.newPasswordInput).toBeVisible(); });
  test('shows confirm password field',      async () => { await expect(profilePage.confirmNewPasswordInput).toBeVisible(); });
  test('new password field is type password', async () => { await expect(profilePage.newPasswordInput).toHaveAttribute('type', 'password'); });

  test('shows error when passwords do not match', async () => {
    await profilePage.updatePassword(TEST_USER.password, 'NewPass123!', 'Different456!');
    await profilePage.assertOnProfilePage();
    await profilePage.assertErrorMessage('The password field confirmation does not match.');
  });

  test('shows error for incorrect current password', async () => {
    await profilePage.updatePassword('wrongpassword', 'NewPass123!', 'NewPass123!');
    await profilePage.assertOnProfilePage();
    await profilePage.assertErrorMessage('The password is incorrect.');
  });

  test('save password button is enabled', async () => {
    await expect(profilePage.savePasswordButton).toBeEnabled();
  });
});
`;
}

export function generateResourceSpec(resource: ResourceGroup): string {
  const { name, singular, className, fields, hasCreate, hasEdit, hasDelete, relations } = resource;
  const pageName     = `${className}Page`;
  const textFields   = fields.filter(f => f.type !== 'select' && !f.name.endsWith('_id'));
  const selectFields = fields.filter(f => f.type === 'select' || f.name.endsWith('_id'));
  const primaryField = textFields[0];

  const relImports = relations.map(r => {
    const rc = capitalize(singularize(r.relatedResource));
    return `import { ${rc}Page } from '../pages/${rc}Page';`;
  });
  const fixtureNames = ['TEST_USER', name.toUpperCase(),
    ...relations.map(r => r.relatedResource.toUpperCase())];

  const b = code();

  // Header
  b.line(`// tests/${name}.spec.ts`, ``);
  b.line(`import { test, expect } from '@playwright/test';`);
  b.line(`import { LoginPage }    from '../pages/LoginPage';`);
  b.line(`import { ${pageName} } from '../pages/${pageName}';`);
  if (relImports.length) b.line(...relImports);
  b.line(`import { ${fixtureNames.join(', ')} } from '../fixtures/test-data';`);
  b.blank();

  // Global beforeEach — login
  b.line(`test.beforeEach(async ({ page }) => {`);
  b.line(`  const loginPage = new LoginPage(page);`);
  b.line(`  await loginPage.goto();`);
  b.line(`  await loginPage.login(TEST_USER.email, TEST_USER.password);`);
  b.line(`  await page.waitForURL(/dashboard/);`);
  b.line(`});`).blank();

  // ── Group 1: Index UI
  b.append(describeBlock(`${className} index — UI elements`, b => {
    b.line(`  let ${singular}Page: ${pageName};`).blank();
    b.line(`  test.beforeEach(async ({ page }) => {`);
    b.line(`    ${singular}Page = new ${pageName}(page);`);
    b.line(`    await ${singular}Page.gotoIndex();`);
    b.line(`  });`).blank();

    b.line(`  test('shows ${singular} table', async () => {`);
    b.line(`    await ${singular}Page.assertTableVisible();`);
    b.line(`  });`);

    if (hasCreate) {
      b.blank();
      b.line(`  test('shows create button', async () => {`);
      b.line(`    await ${singular}Page.assertCreateButtonVisible();`);
      b.line(`  });`);
    }

    const indexView = resource.views.find(v => v.viewType === 'index');
    const relLabels = new Set(relations.map(r => r.label.replace(/:$/, '').toLowerCase()));
    if (indexView) {
      for (const col of indexView.tableColumns) {
        if (!col.trim() || relLabels.has(col.toLowerCase())) continue;
        b.blank();
        b.line(`  test('table has ${col} column', async ({ page }) => {`);
        b.line(`    await expect(page.locator('table th').filter({ hasText: /${col}/i })).toBeVisible();`);
        b.line(`  });`);
      }
    }
  })).blank();

  if (!hasCreate) return b.toString();

  // ── Group 2: Create UI
  b.append(describeBlock(`${className} create — UI elements`, b => {
    b.line(`  let ${singular}Page: ${pageName};`).blank();
    b.line(`  test.beforeEach(async ({ page }) => {`);
    b.line(`    ${singular}Page = new ${pageName}(page);`);
    b.line(`    await ${singular}Page.gotoCreate();`);
    b.line(`  });`).blank();

    for (const f of textFields) {
      b.line(`  test('shows ${cleanLabel(f.label)} field', async () => {`);
      b.line(`    await ${singular}Page.assert${capitalize(camelCase(f.name))}InputVisible();`);
      b.line(`  });`).blank();
    }
    for (const f of selectFields) {
      b.line(`  test('shows ${cleanLabel(f.label)} dropdown', async () => {`);
      b.line(`    await ${singular}Page.assert${capitalize(camelCase(f.name.replace(/_id$/, '')))}SelectVisible();`);
      b.line(`  });`).blank();
    }
    b.line(`  test('shows submit button', async () => {`);
    b.line(`    await ${singular}Page.assertSubmitButtonVisible();`);
    b.line(`  });`).blank();
    b.line(`  test('submit button is enabled', async () => {`);
    b.line(`    await expect(${singular}Page.submitButton).toBeEnabled();`);
    b.line(`  });`);

    for (const f of textFields.filter(f => f.htmlRequired || (f.required && f.type !== 'textarea'))) {
      b.blank();
      b.line(`  test('${cleanLabel(f.label)} field is required', async () => {`);
      b.line(`    await ${singular}Page.assert${capitalize(camelCase(f.name))}Required();`);
      b.line(`  });`);
    }
    for (const f of textFields) {
      b.blank();
      b.line(`  test('${cleanLabel(f.label)} field is empty on open', async () => {`);
      b.line(`    await expect(${singular}Page.${camelCase(f.name)}Input).toHaveValue('');`);
      b.line(`  });`);
    }
  })).blank();

  // ── Group 3: Create functionality
  b.append(describeBlock(`${className} create — functionality`, b => {
    b.line(`  let ${singular}Page: ${pageName};`);
    for (const rel of relations) {
      const rs = singularize(rel.relatedResource);
      const rc = capitalize(rs);
      b.line(`  let ${rs}Page: ${rc}Page;`);
      b.line(`  let test${rc}Name: string;`);
    }
    b.blank();
    b.line(`  test.beforeEach(async ({ page }) => {`);
    b.line(`    ${singular}Page = new ${pageName}(page);`);
    for (const rel of relations) {
      const rs = singularize(rel.relatedResource);
      const rc = capitalize(rs);
      b.line(`    ${rs}Page = new ${rc}Page(page);`);
      b.line(`    test${rc}Name = \`${rc}-\${Date.now()}\`;`);
      b.line(`    await ${rs}Page.create${rc}(test${rc}Name);`);
    }
    b.line(`  });`).blank();

    const createArgs = buildCreateArgs(fields, relations, singular, 'unique');

    if (primaryField) {
      const pCamel = capitalize(camelCase(primaryField.name));
      b.line(`  test('creates ${singular} with valid data', async () => {`);
      b.line(`    const unique${pCamel} = \`${capitalize(singular)}-\${Date.now()}\`;`);
      b.line(`    await ${singular}Page.create${className}(${createArgs});`);
      b.line(`    await ${singular}Page.assertOnIndexPage();`);
      b.line(`    await ${singular}Page.assert${className}Exists(unique${pCamel});`);
      b.line(`  });`).blank();
    }

    b.line(`  test('shows error when required fields are empty', async () => {`);
    b.line(`    await ${singular}Page.gotoCreate();`);
    b.line(`    await ${singular}Page.clickSubmit();`);
    b.line(`    await ${singular}Page.assertOnCreatePage();`);
    b.line(`  });`).blank();

    b.line(`  test('redirects to index after successful create', async () => {`);
    b.line(`    await ${singular}Page.create${className}(${buildCreateArgs(fields, relations, singular, 'redirect')});`);
    b.line(`    await ${singular}Page.assertOnIndexPage();`);
    b.line(`  });`).blank();

    b.line(`  test('row count increases after create', async () => {`);
    b.line(`    await ${singular}Page.gotoIndex();`);
    b.line(`    const before = await ${singular}Page.getRowCount();`);
    b.line(`    await ${singular}Page.create${className}(${buildCreateArgs(fields, relations, singular, 'count')});`);
    b.line(`    await ${singular}Page.gotoIndex();`);
    b.line(`    expect(await ${singular}Page.getRowCount()).toBe(before + 1);`);
    b.line(`  });`);

    if (selectFields.length > 0) {
      b.blank();
      b.line(`  test('creates ${singular} with ${cleanLabel(selectFields[0].label)} selected', async ({ page }) => {`);
      for (const rel of relations) {
        const rs = singularize(rel.relatedResource);
        const rc = capitalize(rs);
        b.line(`    const extra${rc}Page = new ${rc}Page(page);`);
        b.line(`    const extra${rc}Name = \`${rc}-\${Date.now()}\`;`);
        b.line(`    await extra${rc}Page.create${rc}(extra${rc}Name);`);
      }
      b.line(`    await ${singular}Page.gotoCreate();`);
      for (const f of textFields) {
        b.line(`    await ${singular}Page.fill${capitalize(camelCase(f.name))}(\`${capitalize(singular)}-\${Date.now()}\`);`);
      }
      for (const rel of relations) {
        const rs = singularize(rel.relatedResource);
        const rc = capitalize(rs);
        const sf = selectFields.find(f => f.name === rel.field);
        const ln = `${camelCase((sf?.name ?? rel.field).replace(/_id$/, ''))}Select`;
        b.line(`    if (await ${singular}Page.${ln}.isVisible()) {`);
        b.line(`      await ${singular}Page.fill${capitalize(camelCase(sf?.name ?? rel.field))}(extra${rc}Name);`);
        b.line(`    }`);
      }
      b.line(`    await ${singular}Page.clickSubmit();`);
      b.line(`    await ${singular}Page.assertOnIndexPage();`);
      b.line(`  });`);
    }
  })).blank();

  if (!hasEdit || !primaryField) return b.toString();

  const relSetup = buildRelSetup(relations);

  // ── Group 4: Edit UI
  b.append(describeBlock(`${className} edit — UI elements`, b => {
    b.line(`  let ${singular}Page: ${pageName};`);
    b.line(`  let test${className}Name: string;`).blank();
    b.line(`  test.beforeEach(async ({ page }) => {`);
    b.line(`    ${singular}Page = new ${pageName}(page);`);
    b.line(`    test${className}Name = \`Edit-\${Date.now()}\`;`);
    b.line(...relSetup);
    b.line(`    await ${singular}Page.create${className}(${buildEditArgs(fields, relations, className)});`);
    b.line(`    await ${singular}Page.assertOnIndexPage();`);
    b.line(`    const row = ${singular}Page.page.locator('table tbody tr').filter({ hasText: test${className}Name });`);
    b.line(`    await row.getByRole('link', { name: /edit/i }).click();`);
    b.line(`  });`).blank();

    for (const f of textFields) {
      b.line(`  test('edit page shows ${cleanLabel(f.label)} field', async () => {`);
      b.line(`    await ${singular}Page.assert${capitalize(camelCase(f.name))}InputVisible();`);
      b.line(`  });`).blank();
    }
    for (const f of selectFields) {
      b.line(`  test('edit page shows ${cleanLabel(f.label)} dropdown', async () => {`);
      b.line(`    await ${singular}Page.assert${capitalize(camelCase(f.name.replace(/_id$/, '')))}SelectVisible();`);
      b.line(`  });`).blank();
    }
    b.line(`  test('${cleanLabel(primaryField.label)} field shows current value', async () => {`);
    b.line(`    await expect(${singular}Page.${camelCase(primaryField.name)}Input).toHaveValue(test${className}Name);`);
    b.line(`  });`).blank();
    b.line(`  test('save button is visible and enabled', async () => {`);
    b.line(`    await expect(${singular}Page.submitButton).toBeVisible();`);
    b.line(`    await ${singular}Page.assertSubmitButtonVisible();`);
    b.line(`  });`);
  })).blank();

  // ── Group 5: Edit functionality
  b.append(describeBlock(`${className} edit — functionality`, b => {
    b.line(`  let ${singular}Page: ${pageName};`);
    b.line(`  let test${className}Name: string;`).blank();
    b.line(`  test.beforeEach(async ({ page }) => {`);
    b.line(`    ${singular}Page = new ${pageName}(page);`);
    b.line(`    test${className}Name = \`Edit-\${Date.now()}\`;`);
    b.line(...relSetup);
    b.line(`    await ${singular}Page.create${className}(${buildEditArgs(fields, relations, className)});`);
    b.line(`    await ${singular}Page.assertOnIndexPage();`);
    b.line(`  });`).blank();

    const pCamel = capitalize(camelCase(primaryField.name));
    b.line(`  test('updates ${singular} successfully', async () => {`);
    b.line(`    const updated${pCamel} = \`Updated-\${Date.now()}\`;`);
    const editCallArgs = textFields.map((f, i) =>
      i === 0 ? `updated${pCamel}` : `'updated text'`
    ).join(', ');
    b.line(`    await ${singular}Page.edit${className}ByName(test${className}Name, ${editCallArgs});`);
    b.line(`    await ${singular}Page.assertOnIndexPage();`);
    b.line(`    await ${singular}Page.assert${className}Exists(updated${pCamel});`);
    b.line(`  });`).blank();

    b.line(`  test('shows error when required field is cleared on edit', async () => {`);
    b.line(`    const row = ${singular}Page.page.locator('table tbody tr').filter({ hasText: test${className}Name });`);
    b.line(`    await row.getByRole('link', { name: /edit/i }).click();`);
    b.line(`    await ${singular}Page.${camelCase(primaryField.name)}Input.clear();`);
    b.line(`    await ${singular}Page.clickSubmit();`);
    b.line(`    await expect(${singular}Page.${camelCase(primaryField.name)}Input).toBeVisible();`);
    b.line(`  });`);
  })).blank();

  if (!hasDelete) return b.toString();

  // ── Group 6: Delete UI
  b.append(describeBlock(`${className} delete — UI elements`, b => {
    b.line(`  let ${singular}Page: ${pageName};`);
    b.line(`  let test${className}Name: string;`).blank();
    b.line(`  test.beforeEach(async ({ page }) => {`);
    b.line(`    ${singular}Page = new ${pageName}(page);`);
    b.line(`    test${className}Name = \`Delete-\${Date.now()}\`;`);
    b.line(...relSetup);
    b.line(`    await ${singular}Page.create${className}(${buildEditArgs(fields, relations, className)});`);
    b.line(`    await ${singular}Page.assertOnIndexPage();`);
    b.line(`  });`).blank();
    b.line(`  test('delete button is visible in row', async () => {`);
    b.line(`    const row = ${singular}Page.page.locator('table tbody tr').filter({ hasText: test${className}Name });`);
    b.line(`    await expect(row.getByRole('button', { name: /delete/i })).toBeVisible();`);
    b.line(`  });`).blank();
    b.line(`  test('delete button is enabled', async () => {`);
    b.line(`    const row = ${singular}Page.page.locator('table tbody tr').filter({ hasText: test${className}Name });`);
    b.line(`    await expect(row.getByRole('button', { name: /delete/i })).toBeEnabled();`);
    b.line(`  });`);
  })).blank();

  // ── Group 7: Delete functionality
  b.append(describeBlock(`${className} delete — functionality`, b => {
    b.line(`  let ${singular}Page: ${pageName};`);
    b.line(`  let test${className}Name: string;`).blank();
    b.line(`  test.beforeEach(async ({ page }) => {`);
    b.line(`    ${singular}Page = new ${pageName}(page);`);
    b.line(`    test${className}Name = \`Delete-\${Date.now()}\`;`);
    b.line(...relSetup);
    b.line(`    await ${singular}Page.create${className}(${buildEditArgs(fields, relations, className)});`);
    b.line(`    await ${singular}Page.assertOnIndexPage();`);
    b.line(`  });`).blank();
    b.line(`  test('deletes ${singular} after confirming dialog', async () => {`);
    b.line(`    const before = await ${singular}Page.getRowCount();`);
    b.line(`    await ${singular}Page.delete${className}ByName(test${className}Name);`);
    b.line(`    await ${singular}Page.assert${className}NotExists(test${className}Name);`);
    b.line(`    expect(await ${singular}Page.getRowCount()).toBe(before - 1);`);
    b.line(`  });`);
  }));

  return b.toString();
}

// ── Private helpers ───────────────────────────────────────────────────────────

function describeBlock(title: string, fill: (b: CodeBuilder) => void): CodeBuilder {
  const inner = code();
  fill(inner);
  const b = code();
  b.line(`test.describe('${title}', () => {`);
  b.append(inner);
  b.line(`});`);
  return b;
}

function buildRelSetup(relations: { field: string; relatedResource: string; label: string }[]): string[] {
  return relations.flatMap(rel => {
    const rs = singularize(rel.relatedResource);
    const rc = capitalize(rs);
    return [
      `    const ${rs}Page = new ${rc}Page(page);`,
      `    const rel${rc}Name = \`${rc}-\${Date.now()}\`;`,
      `    await ${rs}Page.create${rc}(rel${rc}Name);`,
    ];
  });
}

function buildCreateArgs(
  fields: FormField[],
  relations: { field: string; relatedResource: string; label: string }[],
  singular: string,
  variant: string
): string {
  return fields.map(f => {
    if (f.type === 'select' || f.name.endsWith('_id')) {
      const rel = relations.find(r => r.field === f.name);
      if (rel) return `test${capitalize(singularize(rel.relatedResource))}Name`;
      return `'test'`;
    }
    const isFirst = fields.filter(x => x.type !== 'select' && !x.name.endsWith('_id')).indexOf(f) === 0;
    if (variant === 'unique' && isFirst) return `unique${capitalize(camelCase(f.name))}`;
    return `\`${capitalize(singular)}-\${Date.now()}\``;
  }).join(', ');
}

function buildEditArgs(
  fields: FormField[],
  relations: { field: string; relatedResource: string; label: string }[],
  className: string
): string {
  const textOnly = fields.filter(f => f.type !== 'select' && !f.name.endsWith('_id'));
  return fields.map(f => {
    if (f.type === 'select' || f.name.endsWith('_id')) {
      const rel = relations.find(r => r.field === f.name);
      if (rel) return `rel${capitalize(singularize(rel.relatedResource))}Name`;
      return `'test'`;
    }
    return textOnly.indexOf(f) === 0
      ? `test${className}Name`
      : `\`text-\${Date.now()}\``;
  }).join(', ');
}
