# LOCIDA

[![Laravel](https://img.shields.io/badge/Laravel-12.x-FF2D20?logo=laravel&logoColor=white)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?logo=php&logoColor=white)](https://www.php.net)
[![Docker](https://img.shields.io/badge/Docker-Compose-2496ED?logo=docker&logoColor=white)](https://www.docker.com/)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-4169E1?logo=postgresql&logoColor=white)](https://www.postgresql.org/)
[![Redis](https://img.shields.io/badge/Redis-7-DC382D?logo=redis&logoColor=white)](https://redis.io/)

LOCIDA is a Laravel 12 + Filament application for managing Playwright test generation and reviewing the resulting reports. It is designed around a Docker-first local workflow and is paired with a Gitea integration stack for repository selection, workflow execution, and artifact browsing.

Quick links:
[Quick Start](#quick-start) • [Features](#features) • [Configuration](#configuration) • [Usage](#usage) • [Services](#services) • [Daily Commands](#daily-commands) • [Deployment](#deployment) • [Troubleshooting](#troubleshooting) • [Gitea Guide](GITEA.md)

## Table Of Contents

- [Overview](#overview)
- [Features](#features)
- [Services](#services)
- [Prerequisites](#prerequisites)
- [Quick Start](#quick-start)
- [Default Admin](#default-admin)
- [Configuration](#configuration)
- [Usage](#usage)
- [Project Structure](#project-structure)
- [Daily Commands](#daily-commands)
- [Playwright Target App](#playwright-target-app)
- [Vite](#vite)
- [Port Mapping](#port-mapping)
- [Deployment](#deployment)
- [Docker Files](#docker-files)
- [Related Docs](#related-docs)
- [Troubleshooting](#troubleshooting)

## Overview

The application centers on a `tests` record that captures a target repository, branch pairing, target app URL, and test credentials. From the Filament admin panel you can create a test request, dispatch generation jobs, follow generation status, and open the rendered Playwright report.

At a high level the workflow is:

1. Select a source repository from Gitea.
2. Choose a source branch and a dedicated test branch.
3. Provide the target app URL and test account credentials.
4. Dispatch generation, which stages crawler and Playwright support projects, then queues background work.
5. Review the generated report and status from the admin UI.

## Features

- Filament admin UI for creating, editing, and listing Playwright test requests.
- Gitea repository and branch selection directly from the form.
- Validation for unique repository/branch combinations and reachable application URLs.
- Background generation and polling through queued jobs.
- Playwright report viewing with status filtering and keyword search.
- Signed routes for serving report HTML and asset files from storage.
- Default admin seeding for quick local setup.

## Services

| Service | Image | Purpose | Host Port |
| --- | --- | --- | --- |
| `app` | `locida:latest` | Laravel app runtime | - |
| `queue` | `locida:latest` | Queue worker for background jobs | - |
| `nginx` | `nginx:1.27-alpine` | HTTP server for the app | `8080` |
| `db` | `postgres:16-alpine` | PostgreSQL database | `15432` |
| `redis` | `redis:7-alpine` | Cache/session backend | `16379` |
| `gitea` | `gitea/gitea:latest` | Local Git hosting and Actions UI | `3000` / `2222` |
| `act-runner` | `gitea/act_runner:latest` | Executes Gitea Actions workflows | - |

The Gitea stack is documented in more detail in [GITEA.md](GITEA.md).

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

4. Generate the application key.

```bash
docker compose exec app php artisan key:generate
```

5. Run migrations and seed the default admin account.

```bash
docker compose exec app php artisan migrate --seed
```

6. Open the app.

```text
http://localhost:8080
```

The homepage redirects to the Filament admin login page.

Notes:

- If `.env` is missing, `docker/php/entrypoint.sh` copies `.env.example` automatically.
- Docker-friendly defaults are already set in `.env.example` for the local stack.
- If you plan to use the Gitea integration, follow [GITEA.md](GITEA.md) after the core app is up.

## Default Admin

After running `migrate --seed` or `migrate:fresh --seed`, a default admin account is available:

| Field | Value |
| --- | --- |
| Email | `admin@admin.com` |
| Password | `password` |

Admin panel URL: `http://localhost:8080/admin`

Registration is enabled, so additional admin accounts can be created from the login page.

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
| `REDIS_HOST` | Redis host inside Docker | `redis` |
| `REDIS_PORT` | Redis port inside Docker | `16379` |
| `GITEA_ROOT_URL` | Public Gitea base URL | empty until configured |
| `GITEA_API_URL` | Gitea API endpoint used by the app | empty until configured |
| `GITEA_API_TOKEN` | Personal access token for repository access | empty until configured |
| `GITEA_RUNNER_REGISTRATION_TOKEN` | Runner registration token for act-runner | empty until configured |

For local app development, the important convention is that containers talk to the database and Redis by service name, not by the host-published port.

## Usage

1. Open `http://localhost:8080/admin`.
2. Log in with the seeded admin account, or create a new admin account if registration is enabled.
3. Create a new test request and fill in the source repository, source branch, test branch, target app URL, and test account email and password.
4. Save the record, then start generation from the record actions.
5. Monitor the status on the generation page and open the report once generation completes.

The report viewer exposes the generated Playwright summary, plus filtered views for passed, failed, flaky, and skipped specs.

## Project Structure

- `app/Filament`: Filament resources, pages, actions, and widgets for the admin UI.
- `app/Models`: Eloquent models, including the core `Test` record.
- `app/Livewire`: Livewire components, including the Playwright report viewer.
- `app/Services`: Core application services for Gitea access, crawler staging, script generation, test orchestration, and polling.
- `app/Jobs`: Queued jobs that drive generation and polling.
- `app/Http`: HTTP controllers and request-facing application code.
- `config/`: Laravel configuration for the app, database, queue, cache, session, and services.
- `routes/web.php`: Homepage redirect plus signed Playwright report routes.
- `bootstrap/`: Application bootstrap and provider registration.
- `database/`: Migrations, factories, and seeders.
- `playwright/`: Support projects for the crawler and test generation workflow.
- `resources/`: Application CSS, JS, and Blade views.
- `public/`: Public entrypoint and built frontend assets.
- `docker/`: Dockerfiles, nginx configuration, and container entrypoint scripts.
- `storage/`: Runtime files, generated reports, logs, and cached artifacts.
- `tests/`: Feature and unit tests.

## Daily Commands

| Task | Command |
| --- | --- |
| Run any Artisan command | `docker compose exec app php artisan <command>` |
| Run tests | `docker compose exec app php artisan test` |
| Rebuild database with seed | `docker compose exec app php artisan migrate:fresh --seed` |
| Clear optimization/cache | `docker compose exec app php artisan optimize:clear` |
| Open shell in app container | `docker compose exec app sh` |
| Follow app logs | `docker compose logs -f app` |
| Follow queue logs | `docker compose logs -f queue` |
| Follow nginx logs | `docker compose logs -f nginx` |
| Follow db logs | `docker compose logs -f db` |
| Follow redis logs | `docker compose logs -f redis` |
| Follow Gitea logs | `docker compose logs -f gitea` |
| Follow runner logs | `docker compose logs -f act-runner` |
| Stop all services | `docker compose down` |
| Stop and remove volumes | `docker compose down -v` |

## Playwright Target App

Before running Playwright tests against a target Laravel app, set:

```dotenv
APP_DEBUG=false
```

`APP_DEBUG=true` can render verbose exception pages, which often makes Playwright assertions, snapshots, and screenshots flaky.

## Vite

Vite scripts are defined in `package.json` and are expected to run inside the `app` container.

Current Vite inputs are:

- `resources/css/app.css`
- `resources/css/filament/admin/theme.css`
- `resources/js/app.js`

The Filament admin panel loads `resources/css/filament/admin/theme.css` through `viteTheme(...)`, so Tailwind utility classes used in Filament Blade views are generated from this theme build.

Install frontend dependencies inside Docker:

```bash
docker compose exec app npm install
```

Run the dev watcher:

```bash
docker compose exec app npm run dev
```

Build production assets:

```bash
docker compose exec app npm run build
```

If class changes are not reflected in Filament after a build, clear Laravel caches:

```bash
docker compose exec app php artisan optimize:clear
```

## Port Mapping

- HTTP: `http://localhost:8080`
- PostgreSQL: `localhost:15432`
- Redis: `localhost:16379`
- Gitea web UI: `http://localhost:3000`
- Gitea SSH: `localhost:2222`

## Deployment

This repository is optimized for local Docker development, but the same stack can be adapted for a production-style environment.

Recommended deployment checklist:

1. Set production values for `APP_URL`, database, cache, queue, mail, and Gitea-related variables.
2. Set `APP_DEBUG=false`.
3. Run `composer install --no-dev` in the application container or build image.
4. Install frontend dependencies and build assets with `npm run build`.
5. Run database migrations with `php artisan migrate --force`.
6. Ensure a queue worker is running continuously.
7. If you use Gitea integration, register the runner and confirm the API token is valid.

## Docker Files

- `docker/php/Dockerfile`: PHP 8.3 Alpine image with the extensions needed by Laravel.
- `docker/php/entrypoint.sh`: bootstraps storage/cache permissions and auto-creates `.env` if needed.
- `docker/nginx/default.conf`: nginx config with FastCGI upstream to `app:9000`.

## Related Docs

- [Gitea and Runner Guide](GITEA.md)

## Troubleshooting

Install PHP dependencies if vendor packages are missing:

```bash
docker compose exec app composer install
```

Fix storage/bootstrap permissions:

```bash
docker compose exec app sh -c "chown -R www-data:www-data storage bootstrap/cache && chmod -R ug+rwx storage bootstrap/cache"
```

If the app cannot reach the database or Redis from inside Docker, check that the service hostnames are still `db` and `redis`, not `localhost`.

If the queue worker is not running, generation jobs will stay pending and Playwright reports may never appear.

If the report page is empty, confirm that the generation finished successfully and that the signed report routes are still valid.

If Filament styles do not update after a change, rerun the Vite build and clear Laravel caches.

Full local reset:

```bash
docker compose down -v
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```
