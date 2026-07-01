import { ResourceInfo } from '../shared/types.js';
import { capitalize, escapeSingle, singularize, toConstName, toFieldProp, toWords } from '../shared/strings.js';
import { sampleValue } from '../shared/data.js';

export function generateAuthSetupSpec(registerFields: import('../shared/types.js').FormInput[] = []): string {
    const registerPayloadFields = registerFields.map(f => {
        const name = escapeSingle(f.name || '');
        if (name.includes('password') || name.includes('confirm')) return `            '${name}': TEST_USER.password,`;
        if (name.includes('email')) return `            '${name}': TEST_USER.email,`;
        if (name.includes('name')) return `            '${name}': TEST_USER.name,`;
        return `            '${name}': '${escapeSingle(sampleValue(f, 'valid'))}',`;
    }).join('\n');

    return `// tests/auth.setup.ts

import * as fs from 'fs';
import * as path from 'path';
import { test as setup, expect } from '@playwright/test';
import { LoginPage } from '../pages/LoginPage';
import { RegisterPage } from '../pages/RegisterPage';
import { TEST_USER, ROUTES } from '../fixtures/test-data';

setup('authenticate and cache storage state', async ({ page }) => {
    const loginPage = new LoginPage(page);
    await loginPage.goto();
    await loginPage.login(TEST_USER);

    const isOnDashboard = new RegExp(ROUTES.dashboard.replace(/\\//g, '\\\\/')).test(page.url());
    if (!isOnDashboard) {
        const registerPage = new RegisterPage(page);
        await registerPage.goto();
        await registerPage.register({
${registerPayloadFields}
        });
        await loginPage.goto();
        await loginPage.login(TEST_USER);
    }

    await expect(page).toHaveURL(new RegExp(ROUTES.dashboard.replace(/\\//g, '\\\\/')));
    const authDir = path.join(process.cwd(), '.auth');
    fs.mkdirSync(authDir, { recursive: true });
    await page.context().storageState({ path: path.join(authDir, 'user.json') });
});
`;
}

export function generateAuthSpec(loginFields: import('../shared/types.js').FormInput[] = [], registerFields: import('../shared/types.js').FormInput[] = []): string {
    const loginComponentTests = loginFields.map(f => {
        const prop = toFieldProp(f.name || '');
        return `    test('shows ${f.name} field', async () => { await expect(loginPage.${prop}).toBeVisible(); });`;
    }).join('\n');

    const registerComponentTests = registerFields.map(f => {
        const prop = toFieldProp(f.name || '');
        return `    test('shows ${f.name} field', async () => { await expect(registerPage.${prop}).toBeVisible(); });`;
    }).join('\n');

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

${loginComponentTests}
    test('shows submit button', async () => { await expect(loginPage.submitButton).toBeEnabled(); });
});

test.describe('Login page — Functionality', () => {
    let loginPage: LoginPage;

    test.beforeEach(async ({ page }) => {
        loginPage = new LoginPage(page);
        await loginPage.goto();
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

${registerComponentTests}
    test('shows submit button', async () => { await expect(registerPage.submitButton).toBeEnabled(); });
});

test.describe('Register page — Functionality', () => {
    let registerPage: RegisterPage;


    test.beforeEach(async ({ page }) => {
        registerPage = new RegisterPage(page);
        await registerPage.goto();
    });

    test('stays on register page when form is empty', async () => {
        await registerPage.clickSubmit();
        await registerPage.assertOnRegisterPage();
    });
});

test.describe('Dashboard — Component', () => {
    let dashboardPage: DashboardPage;

    test.beforeEach(async ({ page }) => {
        const loginPage = new LoginPage(page);
        dashboardPage = new DashboardPage(page);
        await loginPage.goto();
        await loginPage.login(TEST_USER);
        await dashboardPage.assertOnDashboard();
    });

    test('shows dashboard heading', async () => { await dashboardPage.assertWelcomeVisible(); });
});
`;
}

export function generateGuestAuthSpec(paths: string[]): string {
    const tests = paths.map((p) => `    test('redirects to login when accessing ${p}', async ({ page }) => {
        await page.goto('${p}');
        await expect(page).toHaveURL(new RegExp(ROUTES.login.replace(/\\//g, '\\\\/')));
    });`).join('\n\n');

    return `// tests/auth.guest.spec.ts

import { test, expect } from '@playwright/test';
import { ROUTES } from '../fixtures/test-data';

// Reset storage state for this file to avoid being logged in
test.use({ storageState: { cookies: [], origins: [] } });

test.describe('Guest Authorization', () => {
${tests}
});
`;
}

export function generateProfileSpec(profileFields: import('../shared/types.js').FormInput[] = []): string {
    const componentTests = profileFields.map(f => {
        const prop = toFieldProp(f.name || '');
        return `    test('shows ${f.name} field', async () => { await expect(profilePage.${prop}).toBeVisible(); });`;
    }).join('\n');

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

${componentTests}
    test('shows save profile button', async () => { await expect(profilePage.saveButton).toBeVisible(); });
});

test.describe('Profile page — Functionality', () => {
    let profilePage: ProfilePage;

    test.beforeEach(async ({ page }) => {
        profilePage = new ProfilePage(page);
        await profilePage.goto();
    });

    test('save button is enabled', async () => {
        await expect(profilePage.saveButton).toBeEnabled();
    });

    test('updates profile successfully', async () => {
        await profilePage.updateProfile({ ...TEST_USER, name: 'Updated Name', email: 'updated@example.com' });
        await profilePage.assertOnProfilePage();
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

    const validationTests = resource.fields
        .map((f) => {
            let tests = '';
            if (f.type === 'email' && f.name) {
                tests += `\n    test('shows error for invalid email format in ${f.name}', async () => {\n        await ${fixtureKey}Page.create${className}({ ...${constName}.valid, ${f.name}: 'invalid-email' });\n        await ${fixtureKey}Page.assertOnCreatePage();\n    });`;
            }
            return tests;
        })
        .filter(Boolean)
        .join('\n');

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

${resource.tableColumns.length > 0 ? `    test('shows ${fixtureKey} table', async () => {
        await ${fixtureKey}Page.assertTableVisible();
    });` : ''}

${resource.hasCreatePage ? `    test('shows create button', async () => {
        await ${fixtureKey}Page.assertCreateButtonVisible();
    });` : ''}

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
${validationTests}
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
