# pw-generator

Static analysis tool that reads a Laravel project and generates Playwright tests. It parses Blade views, routes, migrations, and controllers to produce a fully working Page Object Model test suite with a Gitea CI workflow.

---

## How it works

The generator performs **static analysis** on four Laravel source files:

| Source | What is extracted |
|---|---|
| `routes/web.php` | Resource routes, middleware groups, URL patterns |
| `resources/views/*.blade.php` | Form field labels, input types, button text, table columns |
| `app/Http/Requests/*.php` | Validation rules (required fields) |
| `database/migrations/*.php` | Column types, nullable flags, foreign keys |

No application needs to run. No database connection required.

---

## Requirements

- Node.js 18+
- `npm install` in the generator directory

```bash
npm install
```

---

## Usage

```bash
npx ts-node src/index.ts <laravel-path> [output-dir] [options]
```

### Arguments

| Argument | Description | Default |
|---|---|---|
| `laravel-path` | Path to the Laravel project | *(required)* |
| `output-dir` | Directory where tests will be written | `./playwright-tests` |

### Options

**Test configuration**

| Flag | Description | Default |
|---|---|---|
| `--base-url` | App URL for local test runs | `http://localhost:8000` |
| `--email` | Test user email | `playwright@example.com` |
| `--password` | Test user password | `playwright` |
| `--user-name` | Test user display name | `Test User` |

**Gitea CI configuration**

| Flag | Description | Default |
|---|---|---|
| `--gitea-server-url` | Gitea server URL | `http://gitea:3000` |
| `--gitea-app-host` | Laravel app host inside Docker | `host.docker.internal:8000` |
| `--gitea-image` | Playwright Docker image | `mcr.microsoft.com/playwright:v1.58.2-jammy` |
| `--gitea-branch` | Branch that triggers the workflow | `main` |
| `--gitea-cache-vol` | Docker volume name for npm cache | `playwright-npm-cache` |
| `--gitea-report-branch` | Git branch target for HTML report publish | `playwright-report` |
| `--no-workflow` | Skip `.gitea/workflows/` generation | — |

### Examples

```bash
# Minimal — defaults work for a standard Gitea + Docker setup
npx ts-node src/index.ts ./my-laravel-app ./pw-tests

# Custom test user and Gitea server
npx ts-node src/index.ts ./app ./tests \
  --email admin@app.com \
  --password secret \
  --gitea-server-url http://gitea.local:3000 \
  --gitea-app-host 192.168.1.10:8000

# Test files only, no CI workflow
npx ts-node src/index.ts ./app ./tests --no-workflow

# Save HTML report to a Git branch
npx ts-node src/index.ts ./app ./tests \
  --gitea-report-branch playwright-report
```

---

## Output

```
playwright-tests/
├── .gitea/
│   └── workflows/
│       └── playwright.yml       ← Gitea Actions CI workflow
├── fixtures/
│   └── test-data.ts             ← TEST_USER, per-resource data, ROUTES
├── pages/
│   ├── BasePage.ts              ← Shared navigation and assertion helpers
│   ├── LoginPage.ts
│   ├── RegisterPage.ts
│   ├── DashboardPage.ts
│   ├── ProfilePage.ts
│   └── <Resource>Page.ts        ← One page object per resource
├── tests/
│   ├── auth.spec.ts             ← Login, register, dashboard, navigation
│   ├── profile.spec.ts          ← Profile info, password update
│   └── <resource>.spec.ts       ← 7 test groups per resource (see below)
├── playwright.config.ts
├── package.json
└── tsconfig.json
```

### Test groups per resource

Each resource generates seven `test.describe` blocks:

| Group | Coverage |
|---|---|
| `index — UI elements` | Table visible, create button, named columns |
| `create — UI elements` | All form fields visible, submit enabled, required attrs, empty defaults |
| `create — functionality` | Valid create, required field validation, redirect, row count increase |
| `edit — UI elements` | All fields visible on edit page, current value pre-filled, save button |
| `edit — functionality` | Successful update, required field validation |
| `delete — UI elements` | Delete button visible and enabled |
| `delete — functionality` | Row removed after confirming dialog |

Resources with `select` / foreign key fields also get:
- A dropdown visibility test in the create and edit UI groups
- A `creates <resource> with <relation> selected` test in the create functionality group

---

## Running the tests

### Locally

```bash
cd playwright-tests
npm install
BASE_URL=http://localhost:8000 npx playwright test

# Run a single suite
npx playwright test tests/categories.spec.ts

# Open interactive UI
npx playwright test --ui
```

### Via Gitea CI

Push the generated folder to a Gitea repository. The workflow triggers on every push to `main` (or the configured branch):

```bash
cd playwright-tests
git init
git add .
git commit -m "add playwright tests"
git remote add origin http://gitea:3000/<user>/<repo>.git
git push -u origin main
```

The workflow runs automatically and publishes the HTML report to a dedicated Git branch:

```bash
npx ts-node src/index.ts ./app ./pw-tests \
  --gitea-report-branch playwright-report

# After CI run completes, inspect report by cloning branch
git fetch origin playwright-report
git checkout playwright-report
```

### Test user setup

Create the test user in Laravel before running tests:

```bash
php artisan tinker
User::create([
  'name'     => 'Test User',
  'email'    => 'playwright@example.com',
  'password' => bcrypt('playwright'),
  'is_admin' => true,
]);
```

---

## Gitea workflow details

The generated `.gitea/workflows/playwright.yml` mirrors a working self-hosted setup:

```yaml
container:
  image: mcr.microsoft.com/playwright:v1.58.2-jammy
  options: >-
    --add-host gitea:host-gateway
    --add-host host.docker.internal:host-gateway
    --mount type=volume,source=playwright-npm-cache,target=/root/.npm
```

**Why `upload-artifact` is not used:** Gitea self-hosted sets `ACTIONS_RUNTIME_URL` to `localhost:3000`, which is unreachable from inside a container job. All versions of `upload-artifact` fail with `ECONNREFUSED 127.0.0.1:3000`.

The generator publishes report files into a dedicated Git branch (default branch name: `playwright-report`) so you can review report contents from repository history.

---

## Project structure

```
src/
├── index.ts              ← CLI entry point
├── analyzer.ts           ← Orchestrates all parsers, builds ProjectAnalysis
├── types.ts              ← TypeScript interfaces
├── parsers/
│   ├── bladeParser.ts    ← Extracts form fields, table columns, button labels from Blade
│   ├── routeParser.ts    ← Parses web.php: routes, middleware, resource groups
│   └── migrationParser.ts← Parses migrations: field types, nullable, foreign keys
├── generators/
│   ├── config.ts         ← playwright.config.ts, package.json, tsconfig, Gitea workflow
│   ├── fixtures.ts       ← test-data.ts (TEST_USER, per-resource constants, ROUTES)
│   ├── pages.ts          ← All page objects (Base, Login, Register, Dashboard, Profile, Resource)
│   └── specs.ts          ← All spec files (auth, profile, per-resource CRUD)
└── utils/
    ├── strings.ts        ← camelCase, capitalize, singularize, cleanLabel, sampleValue
    └── codegen.ts        ← CodeBuilder: replaces verbose lines.push() pattern
```

---

## Extending for a new project

The generator detects resources automatically from `Route::resource()` declarations. Adding a new resource to a Laravel project requires no changes to the generator — re-run it against the updated codebase.

For projects using GitHub Actions instead of Gitea, use `--no-workflow` and add your own `.github/workflows/playwright.yml`. The test files themselves are CI-agnostic.
