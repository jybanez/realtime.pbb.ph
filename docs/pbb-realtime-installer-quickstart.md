# PBB Realtime Installer Quickstart

## Purpose

Get a hub-local PBB Realtime deployment running through the browser-based installer.

## Basic Flow

1. extract the installer bundle into a web-accessible folder
2. open `/installer/` in a browser
3. choose install mode
4. run environment checks
5. complete configuration
6. run install
7. run validation
8. finish any remaining manual tasks

## Required Inputs

- application URL
- database host, name, username, password
- Realtime token signing secret
- trusted issuers
- public websocket URL
- first admin password collected by Kit Setup

## Current Installer Scope

The current installer already handles:

- draft state
- preflight checks
- `.env` writing
- `APP_KEY` generation
- database migrations
- initial admin bootstrap
  - defaults to `PBB Administrator` / `admin@pbb.local`
  - creates the account only when missing unless `admin.overwrite_existing=true`
  - rejects blank, placeholder, or weak passwords
- install manifest and report generation
- Windows/Linux Ratchet service artifact generation
- optional service registration when permissions and service manager allow it
- packaged installer acceptance through admin login, sandbox admission, websocket connect, room join, presence publish, and chat publish
- Kit Setup-facing release metadata, unattended CLI install, status output, and config schema

Host-specific reverse proxy, TLS, firewall, DNS, and service registration policy are still deployment responsibilities. The installer reports the generated artifacts and manual tasks instead of silently assuming those host-level steps are complete.

## Kit Setup / Unattended Flow

Kit Setup can call the unattended installer with:

```powershell
C:/wamp64/bin/php/php8.2.29/php.exe installer/install-run.php --config C:\pbb\kit-runs\realtime.config.json --report C:\pbb\kit-runs\realtime.report.json
```

Supported flags:

- `--mode fresh|upgrade|repair|preflight`
- `--dry-run`
- `--no-service-register`
- `--verbose`

Machine-readable status is available with:

```powershell
C:/wamp64/bin/php/php8.2.29/php.exe installer/status.php
```

Optional first-run Realtime data population is exposed as a Kit Setup tool:

```powershell
C:/wamp64/bin/php/php8.2.29/php.exe tools/populate-initial-data.php --config installer/docs/realtime-populate.sample.json --report storage/app/installer/realtime-populate-report.json --dry-run
```

The population tool is intentionally separate from install. It can upsert clients, project scopes, policies, media ingest settings, product-query forwarding settings, and backend ingress secret digests when Kit Setup explicitly enables it.

## Validation Target

The install should be considered usable only after:

- admin login works
- `/api/health` and `/api/ready` are reachable
- `/admin/sandbox` can connect successfully

