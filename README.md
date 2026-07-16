# LOCIDA

[![Laravel](https://img.shields.io/badge/Laravel-12.x-FF2D20?logo=laravel&logoColor=white)](https://laravel.com)
[![Filament](https://img.shields.io/badge/Filament-5.x-FFA500?logo=filament&logoColor=white)](https://filamentphp.com)
[![Playwright](https://img.shields.io/badge/Playwright-1.x-2EAD33?logo=playwright&logoColor=white)](https://playwright.dev/)
[![Docker](https://img.shields.io/badge/Docker-29.x-2496ED?logo=docker&logoColor=white)](https://www.docker.com/)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-4169E1?logo=postgresql&logoColor=white)](https://www.postgresql.org/)
[![Verdaccio](https://img.shields.io/badge/Verdaccio-6-4B275F?logo=verdaccio&logoColor=white)](https://verdaccio.org/)

LOCIDA is a Laravel application for managing Playwright test generation and reviewing the resulting reports. It is designed around a Docker-first local workflow and is paired with GitHub and Gitea integrations for repository selection, workflow execution, and artifact browsing.

## Table Of Contents

- [Overview](#overview)
- [Features](#features)
- [Prerequisites](#prerequisites)
- [Quick Start](#quick-start)
- [Usage](#usage)
- [Configuration](#configuration)
- [Services](#services)
- [Project Structure](#project-structure)
- [Daily Commands](#daily-commands)
- [Deployment](#deployment)
- [Troubleshooting](#troubleshooting)

## Overview

The application centers on a `tests` record that captures a target repository, branch pairing, target app URL, and test credentials. From the Filament admin panel you can create a test request, dispatch generation jobs, follow generation status, and open the rendered Playwright report.

At a high level the workflow is:

1. Select a source repository from GitHub or Gitea.
2. Choose a source branch and a dedicated test branch.
3. Provide the test account credentials.
4. Dispatch execution, which queues the background workflow.
5. Review the generated report and status from the admin UI.

## Features

- Filament admin UI for creating, editing, and listing Playwright test requests.
- GitHub and Gitea repository and branch selection directly from the form.
- Validation for unique repository/branch combinations.
- Background generation and polling through queued jobs.
- Playwright report viewing with status filtering and keyword search.
- Signed routes for serving report and asset files from storage.
- Default admin seeding for quick local setup.

## Prerequisites

- Docker Desktop, or Docker Engine with the Compose plugin
- Git
- Node.js + npm only if you want to run Vite outside Docker

## Quick Start

1. Clone the repository and open the project directory.
2. Start the full stack.

```bash
docker compose up -d --build
```

3. Install PHP dependencies.

```bash
docker compose exec app composer install
```

4. Install frontend dependencies and build assets.

```bash
docker compose exec app npm install
docker compose exec app npm run build
```

5. Generate the application key.

```bash
docker compose exec app php artisan key:generate
```

6. Run migrations and seed the default admin account.

```bash
docker compose exec app php artisan migrate --seed
```

7. Open the app.

```text
http://localhost:8080
```

The homepage redirects to the Filament admin login page.

Notes:

- If `.env` is missing, `docker/php/entrypoint.sh` copies `.env.example` automatically.
- Docker-friendly defaults are already set in `.env.example' for the local stack.
- If you plan to use the Gitea integration, follow [GITEA.md](GITEA.md) after the core app is up.
- After running `migrate --seed`, a default admin account is available at `http://localhost:8080/admin` with email `admin@admin.com` and password `password`. Registration is enabled, so additional accounts can be created from the login page.

## Usage

1. Open `http://localhost:8080/admin`.
2. Log in with the seeded admin account, or create a new admin account if registration is enabled.
3. Create a new test request and fill in the source repository, source branch, test branch, and test account email and password.
4. Save the record, then start execution from the record actions.
5. Monitor the status on the generation page and open the dedicated report page once execution completes.

The report page exposes the generated Playwright summary, plus filtered views for passed, failed, flaky, and skipped specs.

## Configuration

The main environment values are already present in `.env.example`. The most important ones are:

| Variable | Purpose | Local default |
| --- | --- | --- |
| `APP_URL` | Canonical app URL used by Laravel and Playwright helpers | `http://localhost:8080` |
| `APP_DEBUG` | Debug mode. Keep this `false` for Playwright runs against a target app | `true` |
| `DB_HOST` | Database host inside Docker | `db` |
| `DB_PORT` | Database port inside Docker | `15432` |
| `DB_DATABASE` | Database name | `locida` |
| `DB_USERNAME` | Database user | `locida` |
| `DB_PASSWORD` | Database password | `locida` |
| `SESSION_DRIVER` | Session storage backend | `database` |
| `CACHE_STORE` | Cache storage backend | `database` |
| `QUEUE_CONNECTION` | Queue backend for background jobs | `database` |
| `GIT_PROVIDER` | Git integration to use, either `github` or `gitea` | empty |
| `GITEA_INTERNAL_HOST` | Internal Docker hostname and port for Gitea | `gitea:3000` |
| `GITEA_ROOT_URL` | Public Gitea base URL | `http://localhost:3000/` |
| `GITEA_API_URL` | Gitea API endpoint used by the app | `http://gitea:3000/api/v1` |
| `GITEA_API_TOKEN` | Personal access token for repository access | empty until configured |
| `GITEA_RUNNER_REGISTRATION_TOKEN` | Runner registration token for act-runner | empty until configured |
| `GITHUB_ROOT_URL` | Public GitHub base URL | `https://github.com/` |
| `GITHUB_API_URL` | GitHub API endpoint used by the app | `https://api.github.com` |
| `GITHUB_API_TOKEN` | Personal access token for GitHub access | empty until configured |

For local app development, the important convention is that containers talk to the database by service name, not by the host-published port.

## Services

| Service | Image | Purpose | Host Port |
| --- | --- | --- | --- |
| `app` | `locida:latest` | Laravel app runtime | - |
| `queue` | `locida:latest` | Queue worker for background jobs | - |
| `nginx` | `nginx:1.27-alpine` | HTTP server for the app | `8080` |
| `db` | `postgres:16-alpine` | PostgreSQL database | `15432` |
| `gitea` | `gitea/gitea:latest` | Local Git hosting and Actions UI | `3000` / `2222` |
| `act-runner` | `gitea/act_runner:latest` | Executes Gitea Actions workflows | - |
| `registry` | `registry:3` | Local Docker registry for caching | `5000` |
| `verdaccio` | `verdaccio/verdaccio:6` | Local npm registry for caching | `4873` |

## Project Structure

- `app/Filament`: Filament resources, pages, actions, and widgets for the admin UI.
- `app/Models`: Eloquent models, including the core `Test` record.
- `app/Livewire`: Livewire components, including the Playwright report viewer.
- `app/Services`: Core application services for Pipeline execution, GitHub/Gitea integrations, and test orchestration.
- `app/Jobs`: Queued jobs that drive test execution and polling.
- `app/Http`: HTTP controllers and request-facing application code.
- `config/`: Laravel configuration for the app, database, queue, cache, session, and services.
- `routes/web.php`: Homepage redirect plus signed Playwright report routes.
- `bootstrap/`: Application bootstrap and provider registration.
- `database/`: Migrations, factories, and seeders.
- `playwright/`: Support projects and pipelines for test execution workflows.
- `resources/`: Application CSS, JS, and Blade views.
- `public/`: Public entrypoint and built frontend assets.
- `cli/`: Standalone Laravel Zero command-line application for utility operations.
- `docker/`: Dockerfiles, nginx configuration, and container entrypoint scripts.
  - `php/Dockerfile`: PHP 8.3 Alpine image with the extensions needed by Laravel.
  - `php/entrypoint.sh`: bootstraps storage/cache permissions and auto-creates `.env` if needed.
  - `nginx/default.conf`: nginx config with FastCGI upstream to `app:9000`.
- `storage/`: Runtime files, generated reports, logs, and cached artifacts.
- `tests/`: Feature and unit tests.


## Daily Commands

| Task | Command |
| --- | --- |
| Push code to new remote repository | `locida push` |
| Run any Artisan command | `docker compose exec app php artisan <command>` |
| Run tests | `docker compose exec app php artisan test` |
| Rebuild database with seed | `docker compose exec app php artisan migrate:fresh --seed` |
| Clear optimization/cache | `docker compose exec app php artisan optimize:clear` |
| Open shell in app container | `docker compose exec app sh` |
| Follow app logs | `docker compose logs -f app` |
| Follow queue logs | `docker compose logs -f queue` |
| Follow nginx logs | `docker compose logs -f nginx` |
| Follow db logs | `docker compose logs -f db` |
| Follow Gitea logs | `docker compose logs -f gitea` |
| Follow runner logs | `docker compose logs -f act-runner` |
| Stop all services | `docker compose down` |
| Stop and remove volumes | `docker compose down -v` |



## Deployment

This repository is optimized for local Docker development, but the same stack can be adapted for a production-style environment.

Recommended deployment checklist:

1. Set production values for `APP_URL`, database, cache, queue, mail, and Git provider variables for GitHub or Gitea.
2. Set `APP_DEBUG=false`.
3. Run `composer install --no-dev` in the application container or build image.
4. Install frontend dependencies and build assets with `npm run build`.
5. Run database migrations with `php artisan migrate --force`.
6. Ensure a queue worker is running continuously.
7. For Gitea integration, register the runner. For GitHub integration, ensure your API token is valid.

## Troubleshooting

Install PHP dependencies if vendor packages are missing:

```bash
docker compose exec app composer install
```

Fix storage/bootstrap permissions:

```bash
docker compose exec app sh -c "chown -R www-data:www-data storage bootstrap/cache && chmod -R ug+rwx storage bootstrap/cache"
```

If the app cannot reach the database from inside Docker, check that the service hostname is still `db`, not `localhost`.

If the queue worker is not running, background workflows will stay pending and Playwright reports may never appear.

If the report page is empty, confirm that the execution finished successfully and that the signed report routes are still valid.

If Filament styles do not update after a change, rebuild frontend assets (`npm run build`) and clear Laravel caches.

Full local reset:

```bash
docker compose down -v
docker compose up -d --build
docker compose exec app composer install
docker compose exec app npm install
docker compose exec app npm run build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```
