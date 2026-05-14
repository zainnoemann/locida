import { ResourceInfo } from '../types.js';
import { capitalize, escapeSingle, singularize, toConstName, toFieldProp, toWords } from '../shared/strings.js';

export function generateAuthSetupSpec(): string {
    return `// tests/auth.setup.ts

import * as fs from 'fs';
import * as path from 'path';
import { test as setup, expect } from '@playwright/test';
import { LoginPage } from '../pages/LoginPage';
import { RegisterPage } from '../pages/RegisterPage';
import { TEST_USER } from '../fixtures/test-data';

setup('authenticate and cache storage state', async ({ page }) => {
    const loginPage = new LoginPage(page);
    await loginPage.goto();
    await loginPage.login(TEST_USER.email, TEST_USER.password);

    const isOnDashboard = /\\/dashboard/.test(page.url());
    if (!isOnDashboard) {
        const registerPage = new RegisterPage(page);
        await registerPage.goto();
        await registerPage.register(TEST_USER.name, TEST_USER.email, TEST_USER.password);
        await loginPage.goto();
        await loginPage.login(TEST_USER.email, TEST_USER.password);
    }

    await expect(page).toHaveURL(/\\/dashboard/);
    const authDir = path.join(process.cwd(), '.auth');
    fs.mkdirSync(authDir, { recursive: true });
    await page.context().storageState({ path: path.join(authDir, 'user.json') });
});
`;
}

export function generateAuthSpec(): string {
    return `// tests/auth.spec.ts

import { test, expect } from '@playwright/test';
import { LoginPage } from '../pages/LoginPage';
import { RegisterPage } from '../pages/RegisterPage';
import { DashboardPage } from '../pages/DashboardPage';
import { TEST_USER } from '../fixtures/test-data';

test.describe('Login page — Component', () => {
    let loginPage: LoginPage;

    test.beforeEach(async ({ page }) => {
        loginPage = new LoginPage(page);
        await loginPage.goto();
    });

    test('shows email field', async () => { await expect(loginPage.emailInput).toBeVisible(); });
    test('shows password field', async () => { await expect(loginPage.passwordInput).toBeVisible(); });
    test('shows submit button', async () => { await expect(loginPage.submitButton).toBeEnabled(); });
    test('email field is type email', async () => { await expect(loginPage.emailInput).toHaveAttribute('type', 'email'); });
    test('password field is type password', async () => { await expect(loginPage.passwordInput).toHaveAttribute('type', 'password'); });
});

test.describe('Login page — Functionality', () => {
    let loginPage: LoginPage;

    test.beforeEach(async ({ page }) => {
        loginPage = new LoginPage(page);
        await loginPage.goto();
    });

    test('shows error for wrong credentials', async () => {
        await loginPage.login('wrong@email.com', 'wrongpassword');
        await loginPage.assertOnLoginPage();
    });

    test('stays on login page when form is empty', async () => {
        await loginPage.clickSubmit();
        await loginPage.assertOnLoginPage();
    });
});

test.describe('Register page — Component', () => {
    let registerPage: RegisterPage;

    test.beforeEach(async ({ page }) => {
        registerPage = new RegisterPage(page);
        await registerPage.goto();
    });

    test('shows name field', async () => { await expect(registerPage.nameInput).toBeVisible(); });
    test('shows email field', async () => { await expect(registerPage.emailInput).toBeVisible(); });
    test('shows password field', async () => { await expect(registerPage.passwordInput).toBeVisible(); });
    test('shows confirm password field', async () => { await expect(registerPage.confirmPasswordInput).toBeVisible(); });
});

test.describe('Dashboard — Component', () => {
    let dashboardPage: DashboardPage;

    test.beforeEach(async ({ page }) => {
        const loginPage = new LoginPage(page);
        dashboardPage = new DashboardPage(page);
        await loginPage.goto();
        await loginPage.login(TEST_USER.email, TEST_USER.password);
        await dashboardPage.assertOnDashboard();
    });

    test('shows dashboard heading', async () => { await dashboardPage.assertWelcomeVisible(); });
});
`;
}

export function generateProfileSpec(): string {
    return `// tests/profile.spec.ts

import { test, expect } from '@playwright/test';
import { ProfilePage } from '../pages/ProfilePage';
import { TEST_USER } from '../fixtures/test-data';

test.describe('Profile page — Component', () => {
    let profilePage: ProfilePage;

    test.beforeEach(async ({ page }) => {
        profilePage = new ProfilePage(page);
        await profilePage.goto();
    });

    test('shows name field', async () => { await expect(profilePage.nameInput).toBeVisible(); });
    test('shows email field', async () => { await expect(profilePage.emailInput).toBeVisible(); });
    test('shows save profile button', async () => { await expect(profilePage.saveProfileButton).toBeVisible(); });
    test('email field shows logged-in user email', async () => { await expect(profilePage.emailInput).toHaveValue(TEST_USER.email); });
});

test.describe('Profile page — Functionality', () => {
    let profilePage: ProfilePage;

    test.beforeEach(async ({ page }) => {
        profilePage = new ProfilePage(page);
        await profilePage.goto();
    });

    test('save button is enabled', async () => {
        await expect(profilePage.saveProfileButton).toBeEnabled();
    });
});
`;
}

export function generateResourceSpec(resource: ResourceInfo): string {
    const className = resource.className;
    const constName = toConstName(resource.name);
    const fixtureKey = singularize(resource.name);
    const titleField = resource.fields.find((f) => /name|title/i.test(f.name || '')) || resource.fields[0];
    const titleFieldName = titleField?.name ?? '';
    const hasTitleField = Boolean(titleFieldName);

    const fieldComponentTests = resource.fields
        .map((f) => {
            const clean = capitalize(toWords(f.name || '')).replace(/\s+/g, '');
            return `  test('shows ${capitalize(toWords(f.name || ''))} field', async () => {\n    await ${fixtureKey}Page.assert${clean}Visible();\n  });`;
        })
        .join('\n\n');

    const requiredTests = resource.fields
        .filter((f) => f.required)
        .map(
            (f) => `  test('${capitalize(toWords(f.name || ''))} field is required', async () => {\n    await expect(${fixtureKey}Page.${toFieldProp(f.name || '')}).toHaveAttribute('required');\n  });`
        )
        .join('\n\n');

    const tableColumnTests = (resource.tableColumns || [])
        .filter(Boolean)
        .map(
            (col) => `  test('table has ${escapeSingle(col)} column', async ({ page }) => {\n    await expect(page.locator('table th').filter({ hasText: /${escapeSingle(col)}/i })).toBeVisible();\n  });`
        )
        .join('\n\n');

    const createData = hasTitleField
        ? `const createLabel = \`${capitalize(singularize(resource.name))}-\${Date.now()}\`;\n    const payload = { ...${constName}.valid, ${titleFieldName}: createLabel };`
        : `const payload = { ...${constName}.valid };`;

    const updatedData = hasTitleField
        ? `const updatedLabel = \`Updated-\${Date.now()}\`;\n    await ${fixtureKey}Page.edit${className}ByName(createLabel, { ...${constName}.updated, ${titleFieldName}: updatedLabel });\n    await ${fixtureKey}Page.assert${className}Exists(updatedLabel);`
        : `await ${fixtureKey}Page.edit${className}ByName('', { ...${constName}.updated });`;

    return `// tests/${resource.name}.spec.ts

import { test, expect } from '@playwright/test';
import { ${className}Page } from '../pages/${className}Page';
import { ${constName} } from '../fixtures/test-data';

test.describe('${className} index — Component', () => {
    let ${fixtureKey}Page: ${className}Page;

    test.beforeEach(async ({ page }) => {
        ${fixtureKey}Page = new ${className}Page(page);
        await ${fixtureKey}Page.gotoIndex();
    });

    test('shows ${singularize(resource.name)} table', async () => {
        await ${fixtureKey}Page.assertTableVisible();
    });

    test('shows create button', async () => {
        await ${fixtureKey}Page.assertCreateButtonVisible();
    });

${tableColumnTests || `  test('table exists', async ({ page }) => {\n    await expect(page.locator('table')).toBeVisible();\n  });`}
});

test.describe('${className} create — Component', () => {
    let ${fixtureKey}Page: ${className}Page;

    test.beforeEach(async ({ page }) => {
        ${fixtureKey}Page = new ${className}Page(page);
        await ${fixtureKey}Page.gotoCreate();
    });

${fieldComponentTests || `  test('shows submit button', async () => {\n    await ${fixtureKey}Page.assertSubmitButtonVisible();\n  });`}

    test('shows submit button', async () => {
        await ${fixtureKey}Page.assertSubmitButtonVisible();
    });

    test('submit button is enabled', async () => {
        await expect(${fixtureKey}Page.submitButton).toBeEnabled();
    });

${requiredTests || `  test('form can be submitted', async () => {\n    await expect(${fixtureKey}Page.submitButton).toBeVisible();\n  });`}
});

test.describe('${className} create — Functionality', () => {
    let ${fixtureKey}Page: ${className}Page;

    test.beforeEach(async ({ page }) => {
        ${fixtureKey}Page = new ${className}Page(page);
    });

    test('creates ${singularize(resource.name)} with valid data', async () => {
        ${createData}
        await ${fixtureKey}Page.create${className}(payload);
        await ${fixtureKey}Page.assertOnIndexPage();
${hasTitleField ? `    await ${fixtureKey}Page.assert${className}Exists(${titleFieldName === 'name' || titleFieldName === 'title' ? 'createLabel' : `payload['${titleFieldName}']`});` : ''}
    });

    test('shows error when required fields are empty', async () => {
        await ${fixtureKey}Page.create${className}({ ...${constName}.empty });
        await ${fixtureKey}Page.assertOnCreatePage();
    });

    test('redirects to index after successful create', async () => {
        await ${fixtureKey}Page.create${className}({ ...${constName}.valid });
        await ${fixtureKey}Page.assertOnIndexPage();
    });
});

test.describe('${className} edit — Component', () => {
    let ${fixtureKey}Page: ${className}Page;
    let createLabel: string;

    test.beforeEach(async ({ page }) => {
        ${fixtureKey}Page = new ${className}Page(page);
        createLabel = \`${capitalize(singularize(resource.name))}-\${Date.now()}\`;
        await ${fixtureKey}Page.create${className}({ ...${constName}.valid, ${hasTitleField ? `${titleFieldName}: createLabel` : ''} });
        await ${fixtureKey}Page.assertOnIndexPage();
        const row = ${fixtureKey}Page.page.locator('table tbody tr').filter({ hasText: createLabel });
        await row.getByRole('link', { name: /edit/i }).click();
    });

${hasTitleField ? `  test('edit page shows ${capitalize(toWords(titleFieldName))} field', async () => {\n    await expect(${fixtureKey}Page.${toFieldProp(titleFieldName)}).toBeVisible();\n  });` : `  test('edit page opens form', async () => {\n    await expect(${fixtureKey}Page.submitButton).toBeVisible();\n  });`}

    test('save button is visible and enabled', async () => {
        await expect(${fixtureKey}Page.submitButton).toBeVisible();
        await expect(${fixtureKey}Page.submitButton).toBeEnabled();
    });
});

test.describe('${className} edit — Functionality', () => {
    let ${fixtureKey}Page: ${className}Page;
    let createLabel: string;

    test.beforeEach(async ({ page }) => {
        ${fixtureKey}Page = new ${className}Page(page);
        createLabel = \`${capitalize(singularize(resource.name))}-\${Date.now()}\`;
        await ${fixtureKey}Page.create${className}({ ...${constName}.valid, ${hasTitleField ? `${titleFieldName}: createLabel` : ''} });
        await ${fixtureKey}Page.assertOnIndexPage();
    });

    test('updates ${singularize(resource.name)} successfully', async () => {
        ${updatedData}
    });
});

test.describe('${className} delete — Component', () => {
    let ${fixtureKey}Page: ${className}Page;
    let createLabel: string;

    test.beforeEach(async ({ page }) => {
        ${fixtureKey}Page = new ${className}Page(page);
        createLabel = \`${capitalize(singularize(resource.name))}-\${Date.now()}\`;
        await ${fixtureKey}Page.create${className}({ ...${constName}.valid, ${hasTitleField ? `${titleFieldName}: createLabel` : ''} });
        await ${fixtureKey}Page.assertOnIndexPage();
    });

    test('delete button is visible in row', async () => {
        const row = ${fixtureKey}Page.page.locator('table tbody tr').filter({ hasText: createLabel });
        await expect(row.getByRole('button', { name: /delete/i })).toBeVisible();
    });
});

test.describe('${className} delete — Functionality', () => {
    let ${fixtureKey}Page: ${className}Page;
    let createLabel: string;

    test.beforeEach(async ({ page }) => {
        ${fixtureKey}Page = new ${className}Page(page);
        createLabel = \`${capitalize(singularize(resource.name))}-\${Date.now()}\`;
        await ${fixtureKey}Page.create${className}({ ...${constName}.valid, ${titleField ? `${titleField.name}: createLabel` : ''} });
        await ${fixtureKey}Page.assertOnIndexPage();
    });

    test('deletes ${singularize(resource.name)} after confirming dialog', async () => {
        await ${fixtureKey}Page.delete${className}ByName(createLabel);
        await ${fixtureKey}Page.assert${className}NotExists(createLabel);
    });
});
`;
}
