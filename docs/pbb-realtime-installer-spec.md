# PBB Realtime Installer Specification

## Objective

Specify the first deployable installer for PBB Realtime so hubs can provision a working Realtime server with minimal manual setup.

## Supported Target for v1

- Windows Server / Windows host
- Linux host
- PHP runtime available locally
- MySQL or MariaDB available locally or remotely
- filesystem deployment from a packaged release bundle
- Ratchet websocket runtime started through an OS-specific managed background process
- browser access to a temporary installer URL

## Delivery Model

The v1 installer is a web-based PHP installer packaged as a ZIP.

Operator flow:

1. obtain `pbb-realtime-installer.zip`
2. unzip it to a web-accessible directory on the target host
3. open the installer URL in a browser
4. complete the guided setup flow
5. optionally remove or lock the installer after success

The installer UI should use:

- PHP for server-side installer actions
- HTML/JS for the guided flow
- Helper library components for inputs, stepper/navigation, status surfaces, confirm dialogs, busy states, and validation output

## Installer Outputs

After a successful run, the target host must contain:

- deployed PBB Realtime application files
- valid `.env`
- generated `APP_KEY`
- baseline database schema for fresh installs
- seeded admin account or bootstrap admin credentials
- registered Ratchet runtime startup configuration
- installation log
- validation report

## Browser Installer Surface

The installer must expose a browser entrypoint such as:

- `/installer/`

Recommended pages or panels:

- Welcome
- Environment checks
- Configuration
- Admin bootstrap
- Install
- Validation
- Finish

The UI must remain usable on modest hub operator screens without requiring developer tools or manual file edits.

## Install Modes

### `fresh`

Creates a new deployment.

### `upgrade`

Updates an existing deployment in place while preserving:

- `.env`
- uploaded/generated artifacts
- database contents

### `repair`

Re-validates and fixes:

- env drift
- missing app key
- missing service registration
- missing websocket public URL
- failed migrations

## Inputs

The installer must collect or accept:

### Application

- install directory
- `APP_URL`
- `APP_ENV`
- `APP_DEBUG`

### Database

- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`

### Realtime Runtime

- `REALTIME_SERVICE_NAME`
- `REALTIME_TOKEN_AUDIENCE`
- `REALTIME_TOKEN_SIGNING_SECRET`
- `REALTIME_TRUSTED_ISSUERS`
- `REALTIME_PUBLIC_WEBSOCKET_URL`
- `REALTIME_WS_HOST`
- `REALTIME_WS_BIND_ADDRESS`
- `REALTIME_WS_PORT`

`REALTIME_ALLOWED_ORIGINS` is retained as a legacy/metadata setting, but the websocket daemon does not use Ratchet route-level origin filtering. Browser access is controlled by Realtime token admission and client/project policy, so installers must not grow this value with every client domain.

### Admin Bootstrap

- admin name
- admin email
- initial password or password generation mode

### Installer Session

- deployment mode:
  - fresh
  - upgrade
  - repair
- optional install manifest import

### Service Registration

- target OS:
  - windows
  - linux
- startup mode:
  - automatic
  - manual
- service manager:
  - `windows-service`
  - `scheduled-task`
  - `systemd`

## Preflight Checks

Before writing anything, the installer must validate:

- PHP version is compatible
- required PHP extensions are available
- target install path is writable
- database credentials work
- selected websocket port is available
- public websocket URL is syntactically valid
- token signing secret is present and not the placeholder value
- target path is not an obviously unsafe system root
- the installer can write `.env`
- the installer can write `storage` and `bootstrap/cache`

If any preflight check fails, the installer must stop before partial install.

The browser UI must show these checks individually with:

- pass
- fail
- actionable remediation text

## Install Sequence

### 1. Prepare filesystem

- create install directory if missing
- copy release bundle
- preserve existing `.env` in upgrade mode
- persist installer state so browser refresh does not lose progress

### 2. Create runtime config

- write `.env`
- generate `APP_KEY` if missing
- verify `config/realtime.php` resolves expected env values

### 3. Database initialization

- import `database/schema/mysql-schema.sql` for fresh installs
- retain migrations for bounded upgrade and repair paths
- do not run Laravel seeders in Kit bundles
- verify required tables exist

### 4. Admin bootstrap

- create initial admin user if none exists
- record bootstrap outcome in install report

### 5. Runtime Services

Declare Kit-managed runtime services in `release.json`:

- `pbb-realtime-websocket`: `php artisan realtime:serve`
- `pbb-realtime-media-dispatcher`: `php artisan realtime:dispatch`

The installer report and manifest must repeat the resolved `runtime_services` array, including working directory, PHP command, args, environment, health checks, and log paths.

Kit starts and verifies these services. Realtime remains responsible for the app-owned command behavior.

### 6. Validation

Run:

- `/api/health`
- `/api/ready`
- HTTP route reachability
- websocket runtime local reachability
- admin login check

### 7. Standalone Data Prep

Data Prep is not part of normal Setup install. It runs after installed apps and smoke checks are ready.

Realtime declares:

- Prepare Data: `tools/populate-initial-data.php`
- Apply Settings: `tools/data-prep/apply-settings.php`
- Verify: `tools/data-prep/verify.php`

Prepare Data creates/upserts Hotline client records from `resources/data/realtime/hotline-client-data.json` by default. Apply Settings writes Maestro telemetry settings into encrypted runtime settings. Verify checks Hotline records and Maestro telemetry settings.

See `docs/pbb-realtime-data-prep-contract.md`.

### 8. Report

Emit:

- install summary
- validation summary
- unresolved manual tasks

The browser UI must allow the operator to:

- review the report in-browser
- download the report
- copy unresolved manual tasks

## Required Validation Checks

The installer must explicitly report pass/fail for:

- HTTP app reachable
- health endpoint reachable
- ready endpoint reachable
- DB migrations complete
- admin account present
- Ratchet command startable
- websocket bind target reachable
- service artifact generated
- public websocket URL configured
- installer route removal/lock recommendation shown

## Post-Install Manual Tasks

Some environments may still require manual completion. The installer must print them clearly:

- reverse proxy websocket upgrade routing for `/realtime`
- TLS certificate binding
- firewall opening for public HTTP/HTTPS
- DNS mapping
- Kit Setup `setup.exe` rebuild after any app bundle or Data Prep contract change

The installer should not silently assume these are done.

These items should appear in a dedicated final step named something like:

- `Manual completion`
- or `Go-live checklist`

## Configuration File Mode

The installer should support a config file for unattended installs.

Suggested file:

- `realtime-install.json`

Suggested fields:

```json
{
  "mode": "fresh",
  "install_path": "C:\\inetpub\\pbb\\realtime",
  "app_url": "https://realtime.hub-a.pbb.ph",
  "db": {
    "host": "127.0.0.1",
    "port": 3306,
    "database": "pbb_realtime",
    "username": "pbb_realtime",
    "password": "secret"
  },
  "realtime": {
    "service_name": "PBB Realtime Hub A",
    "token_audience": "pbb-realtime",
    "token_signing_secret": "replace-with-real-secret",
    "trusted_issuers": [
      "hub-a.pbb.ph"
    ],
    "public_websocket_url": "wss://realtime.hub-a.pbb.ph/realtime",
    "ws_bind_address": "127.0.0.1",
    "ws_port": 8080,
    "allowed_origins": [
      "https://hq.pbb.ph",
      "https://workspace.pbb.ph"
    ],
    "data_prep": {
      "apply_settings": {
        "maestro": {
          "enabled": true,
          "base_url": "https://maestro.pbb.ph",
          "app_code": "realtime",
          "telemetry_token": "replace-with-kit-generated-token",
          "tls_verify": true,
          "ca_bundle": "C:\\wamp64\\certs\\pbb.ph\\pbb.ph.fullchain.crt",
          "connect_timeout_seconds": 3,
          "timeout_seconds": 5
        }
      }
    }
  },
  "admin": {
    "strategy": "create_if_missing",
    "name": "PBB Administrator",
    "email": "admin@pbb.local",
    "password": "replace-with-strong-admin-password-123",
    "must_change_password": false,
    "overwrite_existing": false
  }
}
```

In the browser flow, this config should be supported as:

- file upload
- or paste/import JSON

## Logging

The installer must generate:

- human-readable browser log output
- file log with timestamps
- final machine-readable summary

Suggested artifacts:

- `storage/logs/install.log`
- `storage/app/install-report.json`

The browser UI should stream or poll progress so long-running steps do not look stalled.

## Repair Behavior

In `repair` mode the installer must detect and correct:

- missing `.env` keys
- empty `APP_KEY`
- missing admin account
- missing service registration
- failed pending migrations

It must not overwrite valid secrets unless explicitly requested.

## Upgrade Behavior

In `upgrade` mode the installer must:

- preserve `.env`
- preserve DB settings
- run migrations
- refresh service command if the release changed runtime expectations
- re-run validation

It must not reset admin credentials.

## Rollback Expectations

The first installer version does not need full transactional rollback, but it must:

- back up the existing `.env` before overwrite
- back up prior release files in upgrade mode
- abort on failed preflight before destructive steps
- print clear repair actions if post-copy validation fails

## Security Requirements

The installer must:

- never print full secrets in the final report
- mask DB passwords and signing secret in logs
- reject placeholder signing secrets in production mode
- restrict generated bootstrap credential output

## Suggested Packaging Structure

```text
realtime-installer/
  app/
  bootstrap/
  config/
  public/
    installer/
      index.php
      assets/
        app.js
        app.css
  installer/
    actions/
      install.php
      repair.php
      validate.php
      report.php
  templates/
    env.template
    service.template
  vendor/
    helpers.pbb.ph/
  docs/
    post-install-checklist.md
```

## Acceptance Criteria

v1 is acceptable when a hub operator can:

1. unzip the installer package
2. open the installer in a browser
3. complete setup without hand-editing application files
4. log into the admin surface
5. connect successfully in `/admin/sandbox`

If sandbox cannot connect after install, the installer is not complete enough yet.

