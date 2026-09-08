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
7. run validation and smoke checks
8. run standalone Data Prep when the installed app set is ready
9. finish any remaining manual tasks

## Required Inputs

- application URL
- database host, name, username, password
- Realtime token signing secret
- trusted issuers
- public websocket URL
- first admin password collected by Kit Setup
- Kit-provided MySQL binary for baseline schema loading when running under Kit

## Current Installer Scope

The current installer already handles:

- draft state
- preflight checks
- `.env` writing
- `APP_KEY` generation
- baseline schema import for fresh installs
- migrations retained for bounded upgrade/repair paths
- initial admin bootstrap
  - defaults to `PBB Administrator` / `admin@pbb.local`
  - creates the account only when missing unless `admin.overwrite_existing=true`
  - rejects blank, placeholder, or weak passwords
- install manifest and report generation
- Windows/Linux Ratchet service artifact generation
- Kit-managed runtime service metadata for:
  - `pbb-realtime-websocket`
  - `pbb-realtime-media-dispatcher`
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

Realtime Data Prep is exposed as standalone Kit workflow tooling, separate from Setup install. Prepare Data can be dry-run with:

```powershell
C:/wamp64/bin/php/php8.2.29/php.exe tools/populate-initial-data.php --config installer/docs/realtime-populate.sample.json --report storage/app/installer/realtime-populate-report.json --dry-run
```

The population tool defaults to `resources/data/realtime/hotline-client-data.json` when no explicit source or clients array is configured. It prepares the Hotline client, 5 policies, and 4 project scopes.

Data Prep also includes:

- Apply Settings: `tools/data-prep/apply-settings.php`
- Verify: `tools/data-prep/verify.php`

Apply Settings persists Maestro telemetry settings into `realtime_runtime_settings`, including the telemetry token and optional CA bundle path supplied by Kit. Reports must remain secret-safe.

Installed Realtime releases also publish the Node commissioning provider adapter `realtime.backend-ingress` in `release.json`. Kit Setup discovers this trusted command from the installed release metadata, passes only identifiers in `PBB_COMMISSIONING_CONTEXT`, and receives the transient backend ingress secret only long enough to hand it to the matching consumer adapter. See the Data Prep contract for the exact adapter response shape and future-app compliance requirements.

See [pbb-realtime-data-prep-contract.md](pbb-realtime-data-prep-contract.md) for the current Data Prep contract.

## Validation Target

The install should be considered usable only after:

- admin login works
- `/api/health` and `/api/ready` are reachable
- `/admin/sandbox` can connect successfully
- Kit starts `pbb-realtime-websocket` and `pbb-realtime-media-dispatcher`
- Data Prep Verify reports the Hotline records and Maestro telemetry settings as present when Data Prep is run
- Kit can resolve `commissioning.adapters.providers.realtime.backend-ingress` from the installed `release.json`
