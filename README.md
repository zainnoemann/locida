# LOCIDA

[![Laravel](https://img.shields.io/badge/Laravel-12.x-FF2D20?logo=laravel&logoColor=white)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?logo=php&logoColor=white)](https://www.php.net)
[![Docker](https://img.shields.io/badge/Docker-Compose-2496ED?logo=docker&logoColor=white)](https://www.docker.com/)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-4169E1?logo=postgresql&logoColor=white)](https://www.postgresql.org/)
[![Redis](https://img.shields.io/badge/Redis-7-DC382D?logo=redis&logoColor=white)](https://redis.io/)

Laravel application with a Docker-first local development setup.

Quick links:
[Quick Start](#quick-start) • [Default Admin](#default-admin) • [Services](#services) • [Daily Commands](#daily-commands) • [Playwright Target App](#playwright-target-app) • [Troubleshooting](#troubleshooting) • [Gitea Guide](GITEA.md)

## Table Of Contents

- [Overview](#overview)
- [Services](#services)
- [Prerequisites](#prerequisites)
- [Quick Start](#quick-start)
- [Default Admin](#default-admin)
- [Daily Commands](#daily-commands)
- [Playwright Target App](#playwright-target-app)
- [Vite](#vite)
- [Port Mapping](#port-mapping)
- [Docker Files](#docker-files)
- [Related Docs](#related-docs)
- [Troubleshooting](#troubleshooting)

## Overview

This repository includes a complete development environment using Docker Compose:

- `app`: PHP-FPM container for Laravel
- `nginx`: web server routing requests to PHP-FPM
- `db`: PostgreSQL database
- `redis`: Redis cache/session backend

## Services

| Service | Image | Purpose | Host Port |
| --- | --- | --- | --- |
| `app` | `locida` | Runs Laravel | - |
| `nginx` | `nginx:1.27-alpine` | HTTP server | `8080` |
| `db` | `postgres:16-alpine` | PostgreSQL database | `15432` |
| `redis` | `redis:7-alpine` | Cache/queue/session backend | `16379` |

## Prerequisites

- Docker Desktop (or Docker Engine + Docker Compose plugin)
- Git
- Node.js + npm (only if you run Vite locally)

## Quick Start

1. Clone the repository and move into the project directory.
2. Build and start all containers.

```bash
docker compose up -d --build
```

3. Install PHP dependencies.

```bash
docker compose exec app composer install
```

4. Generate an application key.

```bash
docker compose exec app php artisan key:generate
```

5. Run database migrations and seed the default admin user.

```bash
docker compose exec app php artisan migrate --seed
```

6. Open the app:

```text
http://localhost:8080
```

The homepage redirects to the Filament admin login page. Log in with the default admin credentials (see [Default Admin](#default-admin)).

Notes:

- If `.env` is missing, `docker/php/entrypoint.sh` copies `.env.example` automatically.
- Docker-ready defaults are already set in `.env.example`:
`DB_HOST=db`, `DB_PORT=15432`, `REDIS_HOST=redis`, `REDIS_PORT=16379`.

## Default Admin

After running `migrate --seed` or `migrate:fresh --seed`, a default admin account is available:

| Field | Value |
| --- | --- |
| Email | `admin@admin.com` |
| Password | `password` |

Admin panel URL: `http://localhost:8080/admin`

Registration is also enabled — new admin accounts can be created via the "Sign up" link on the login page.

## Daily Commands

| Task | Command |
| --- | --- |
| Run any Artisan command | `docker compose exec app php artisan <command>` |
| Run tests | `docker compose exec app php artisan test` |
| Rebuild database with seed | `docker compose exec app php artisan migrate:fresh --seed` |
| Clear optimization/cache | `docker compose exec app php artisan optimize:clear` |
| Open shell in app container | `docker compose exec app sh` |
| Follow app logs | `docker compose logs -f app` |
| Follow nginx logs | `docker compose logs -f nginx` |
| Follow db logs | `docker compose logs -f db` |
| Follow redis logs | `docker compose logs -f redis` |
| Stop all services | `docker compose down` |
| Stop and remove volumes | `docker compose down -v` |

## Playwright Target App

Before running Playwright tests against a target Laravel app, set:

```dotenv
APP_DEBUG=false
```

`APP_DEBUG=true` can render verbose exception pages, which often makes Playwright assertions, snapshots, and screenshots flaky.

## Vite

Vite scripts are defined in `package.json` and should be run from the `app` container.

Current Vite inputs are:

- `resources/css/app.css`
- `resources/css/filament/admin/theme.css`
- `resources/js/app.js`

The Filament admin panel loads `resources/css/filament/admin/theme.css` via `viteTheme(...)`, so Tailwind utility classes used in Filament Blade views are generated from this theme build.

Install frontend dependencies inside Docker:

```bash
docker compose exec app npm install
```

Run dev watcher:

```bash
docker compose exec app npm run dev
```

Build production assets:

```bash
docker compose exec app npm run build
```

If class changes are not reflected in Filament after build, clear Laravel caches:

```bash
docker compose exec app php artisan optimize:clear
```

## Port Mapping

- HTTP: `http://localhost:8080`
- PostgreSQL: `localhost:15432`
- Redis: `localhost:16379`

## Docker Files

- `docker/php/Dockerfile`: PHP 8.3 Alpine image with required extensions
- `docker/php/entrypoint.sh`: bootstraps storage/cache permissions and auto-creates `.env` if needed
- `docker/nginx/default.conf`: Nginx config with FastCGI upstream to `app:9000`

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

Full local reset:

```bash
docker compose down -v
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```
