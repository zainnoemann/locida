# LOCIDA GITEA

[![Gitea](https://img.shields.io/badge/Gitea-latest-609926?logo=gitea&logoColor=white)](https://about.gitea.com/)
[![Act Runner](https://img.shields.io/badge/Act%20Runner-latest-1F6FEB)](https://docs.gitea.com/usage/actions/act-runner)
[![Docker](https://img.shields.io/badge/Docker-Compose-2496ED?logo=docker&logoColor=white)](https://www.docker.com/)

Reference guide for running the bundled local Gitea + Gitea Actions runner stack in this repository.

Quick links:
[Quick Start](#quick-start) • [Initial Setup](#initial-setup) • [Runner Setup](#runner-setup) • [Daily Commands](#daily-commands) • [Troubleshooting](#troubleshooting)

## Table Of Contents

- [Overview](#overview)
- [Services](#services)
- [Prerequisites](#prerequisites)
- [Quick Start](#quick-start)
- [Initial Setup](#initial-setup)
- [Runner Setup](#runner-setup)
- [Environment Variables](#environment-variables)
- [Daily Commands](#daily-commands)
- [Port Mapping](#port-mapping)
- [Volumes](#volumes)
- [Troubleshooting](#troubleshooting)

## Overview

This repository ships with two Gitea-related services in `docker-compose.yml`:

- `gitea`: self-hosted Git service and web UI
- `act-runner`: self-hosted Gitea Actions runner

The runner auto-registers on first boot when `GITEA_RUNNER_REGISTRATION_TOKEN` is provided.

## Services

| Service | Image | Purpose | Host Port |
| --- | --- | --- | --- |
| `gitea` | `gitea/gitea:latest` | Git hosting + web UI | `3000` (HTTP), `2222` (SSH) |
| `act-runner` | `gitea/act_runner:latest` | Executes Gitea Actions workflows | - |

## Prerequisites

- Docker Desktop (or Docker Engine + Docker Compose plugin)
- Repository cloned locally
- `GITEA_RUNNER_REGISTRATION_TOKEN` value (generated from Gitea UI)

## Quick Start

1. Start only the Gitea stack (or start all services if preferred).

```bash
docker compose up -d gitea act-runner
```

2. Open Gitea setup UI:

```text
http://localhost:3000
```

3. Complete the first-run Gitea web installation wizard.

4. Create a Gitea user and a personal access token (PAT).

5. Put required env values in `.env`:

```dotenv
GITEA_API_URL=http://gitea:3000/api/v1
GITEA_API_TOKEN=<your_pat>
GITEA_RUNNER_REGISTRATION_TOKEN=<runner_registration_token>
```

6. Restart runner so it registers using the token:

```bash
docker compose up -d --force-recreate act-runner
```

## Initial Setup

When opening `http://localhost:3000` for the first time:

- Keep most defaults from Gitea installer unless you have custom needs.
- Make sure the final `ROOT_URL` is `http://localhost:3000/` for local usage.
- After install, log in and create your first repository.

Optional local clone examples:

```bash
# HTTP
git clone http://localhost:3000/<user>/<repo>.git

# SSH (mapped to host port 2222)
git clone ssh://git@localhost:2222/<user>/<repo>.git
```

## Runner Setup

1. In Gitea web UI, open:

```text
Site Administration -> Actions -> Runners
```

2. Generate a registration token.
3. Set token in `.env` as `GITEA_RUNNER_REGISTRATION_TOKEN`.
4. Recreate `act-runner` container.

```bash
docker compose up -d --force-recreate act-runner
```

5. Verify runner is online from Gitea UI.

## Environment Variables

Variables used by the Laravel app and runner integration:

| Variable | Description | Example |
| --- | --- | --- |
| `GITEA_API_URL` | Gitea API base URL for app integration | `http://gitea:3000/api/v1` |
| `GITEA_API_TOKEN` | Personal access token for API calls | `<PAT>` |
| `GITEA_RUNNER_REGISTRATION_TOKEN` | Token used by `act-runner` auto-registration | `<registration_token>` |

## Daily Commands

| Task | Command |
| --- | --- |
| Start Gitea stack | `docker compose up -d gitea act-runner` |
| Restart runner only | `docker compose restart act-runner` |
| Recreate runner (re-register) | `docker compose up -d --force-recreate act-runner` |
| View Gitea logs | `docker compose logs -f gitea` |
| View runner logs | `docker compose logs -f act-runner` |
| Stop Gitea stack | `docker compose stop gitea act-runner` |
| Stop and remove Gitea stack | `docker compose down` |

## Port Mapping

- Gitea web UI: `http://localhost:3000`
- Gitea SSH: `localhost:2222`

## Volumes

Named volumes relevant to Gitea:

- `gitea_data`: persistent Gitea data (`/data` in `gitea` container)
- `act_runner_data`: persistent runner state (`/data` in `act-runner` container)

Useful artifact/log locations inside `gitea` data volume:

- Actions artifacts: `/data/gitea/actions_artifacts`
- Actions logs: `/data/gitea/actions_log/<owner>/<repo>/...`

## Troubleshooting

Runner did not register:

```bash
docker compose logs -f act-runner
```

Ensure `.env` contains valid `GITEA_RUNNER_REGISTRATION_TOKEN`, then recreate `act-runner`.

Check Gitea availability from inside runner network:

```bash
docker compose exec act-runner sh -lc "wget -qO- http://gitea:3000 | head"
```

Reset only Gitea + runner containers (without removing volumes):

```bash
docker compose up -d --force-recreate gitea act-runner
```

Hard reset Gitea data (destructive):

```bash
docker compose down -v
docker compose up -d gitea act-runner
```