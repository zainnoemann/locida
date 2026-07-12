# GITEA

[![Gitea](https://img.shields.io/badge/Gitea-latest-609926?logo=gitea&logoColor=white)](https://about.gitea.com/)
[![Act Runner](https://img.shields.io/badge/Act%20Runner-latest-1F6FEB)](https://docs.gitea.com/usage/actions/act-runner)

Reference guide for running the bundled local Gitea and Gitea Actions runner stack used by LOCIDA.

This document is the companion guide for the Gitea-related setup referenced from [README.md](README.md).

## Table Of Contents

- [Overview](#overview)
- [Quick Start](#quick-start)
- [Initial Setup](#initial-setup)
- [Runner Setup](#runner-setup)
- [Daily Commands](#daily-commands)
- [Troubleshooting](#troubleshooting)

## Overview

This repository ships with two Gitea-related services in `docker-compose.yml`:

- `gitea`: self-hosted Git service and web UI
- `act-runner`: self-hosted Gitea Actions runner

The runner auto-registers on first boot when `GITEA_RUNNER_REGISTRATION_TOKEN` is provided.
The LOCIDA application uses this stack to discover repositories, fetch branches, and browse test execution artifacts.

## Quick Start

1. Start only the Gitea stack, or start all services if you want the runner and app online together.

```bash
docker compose up -d gitea act-runner
```

2. Open Gitea setup UI:

```text
http://localhost:3000
```

3. Complete the first-run Gitea web installation wizard.

4. Create a Gitea user and a personal access token.

5. Put required env values in `.env`:

```dotenv
GIT_PROVIDER=gitea
GITEA_INTERNAL_HOST=gitea:3000
GITEA_ROOT_URL=http://localhost:3000/
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
- Set `ROOT_URL` to the URL you actually use. For this repository, that is usually `http://localhost:3000`.
- After install, log in and create your first repository.

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

Notes:

- The runner container mounts `/var/run/docker.sock`, so Docker must be available on the host.
- If the runner does not register, the token is usually wrong, expired, or missing from `.env`.


## Daily Commands

| Task | Command |
| --- | --- |
| Start Gitea stack | `docker compose up -d gitea act-runner` |
| Restart runner only | `docker compose restart act-runner` |
| Recreate runner | `docker compose up -d --force-recreate act-runner` |
| Stop Gitea stack | `docker compose stop gitea act-runner` |

If you want to restart the full integration stack including the app, use `docker compose up -d --build` from the repository root.


## Troubleshooting

Runner did not register:

```bash
docker compose logs -f act-runner
```

Ensure `.env` contains valid `GITEA_RUNNER_REGISTRATION_TOKEN`, then recreate `act-runner`.

If the PAT cannot access repositories from the Filament form, verify the token scope and the account's repository visibility.

If Gitea links or notifications point to the wrong address, check `GITEA_ROOT_URL` and restart the `gitea` container.

Check Gitea availability from inside runner network:

```bash
docker compose exec act-runner sh -lc "wget -qO- http://gitea:3000 | head"
```

Reset only Gitea and runner containers without removing volumes:

```bash
docker compose up -d --force-recreate gitea act-runner
```

Hard reset Gitea data, this is destructive:

```bash
docker compose down -v
docker compose up -d gitea act-runner
```