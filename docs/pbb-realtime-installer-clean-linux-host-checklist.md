# PBB Realtime Installer Clean Linux Host Checklist

Use this checklist when validating the installer from a fresh extracted bundle on a Linux host.

## Host Baseline

- PHP CLI is available on `PATH`
- required PHP extensions are installed:
  - `openssl`
  - `pdo`
  - `pdo_mysql`
  - `mbstring`
  - `json`
  - `fileinfo`
- web server can serve the extracted installer directory
- MySQL or MariaDB is reachable from the host
- target install path is writable by the web/PHP user
- `storage` and `bootstrap/cache` are writable

## Installer Flow

- unzip the installer bundle into a web-accessible path
- open `/installer/` in a browser
- confirm welcome step loads without PHP warnings
- import sample JSON or fill the draft manually
- switch `Target OS` to `Linux`
- confirm `Service manager` narrows to `systemd`
- run preflight successfully with valid DB and Realtime values

## Install Flow

- save the config draft successfully
- run installer successfully
- confirm:
  - `.env` is written
  - `APP_KEY` exists
  - database migrations complete
  - admin account is bootstrapped
  - Linux service artifact is generated
- confirm install report and manifest are written under `storage/app/installer`

## Validation Flow

- run validation successfully
- confirm the report includes:
  - HTTP app reachability
  - `/api/health`
  - `/api/ready`
  - admin account presence
  - pending migration status
  - websocket bind target check
  - service artifact check

## Service Artifact

- confirm generated service artifact path exists
- review the generated `systemd` unit for:
  - correct PHP binary
  - correct working directory
  - correct command:
    - `php artisan realtime:serve`
  - restart behavior
  - log path

## Go-Live Smoke Tests

- register the generated `systemd` unit manually
- start the Realtime runtime
- verify local HTTP app is reachable
- verify websocket runtime starts
- verify `/admin/login` works
- verify `/admin/sandbox` can:
  - issue admission
  - connect websocket
  - join room
  - publish presence
  - publish chat

