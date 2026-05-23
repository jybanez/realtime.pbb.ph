# PBB Realtime Installer UI And Actions Specification

## Purpose

Define the browser installer flow, UI screens, and backend actions for the first PBB Realtime installer.

## Installer Surface

Recommended public entrypoint inside the installer bundle:

- `/installer/`

Recommended internal action endpoints:

- `/installer/api/state`
- `/installer/api/preflight`
- `/installer/api/config/import`
- `/installer/api/config/save`
- `/installer/api/install/run`
- `/installer/api/validate/run`
- `/installer/api/report`

These endpoints are installer-local. They are not part of the normal Realtime runtime API.

## UI Framework

Use the Helper library for:

- buttons
- forms
- select controls
- step navigation
- empty states
- alerts/confirms
- toasts
- progress display
- log viewer shell

The installer UI should visually align with the Realtime admin surface but remain self-contained.

## Screen Flow

### 1. Welcome

Purpose:

- explain what the installer does
- explain supported install modes
- warn that the installer should run from a web-accessible extracted ZIP

Required UI:

- install mode selection:
  - fresh
  - upgrade
  - repair
- optional config import entry
- `Start setup` button

### 2. Environment Checks

Purpose:

- validate the host before any write action

Required checks:

- PHP version
- required extensions
- filesystem writeability
- database driver availability
- target directory safety
- websocket port availability

Required UI:

- checklist with pass/fail items
- remediation text for failed checks
- `Retry checks` button
- `Continue` only when blocking checks pass

### 3. Application Configuration

Purpose:

- capture app-level runtime settings

Fields:

- install path
- `APP_URL`
- `APP_ENV`
- `APP_DEBUG`

Required UI:

- validated form
- inline field help
- warning if install path contains an existing deployment

### 4. Database Configuration

Purpose:

- capture and test DB connectivity

Fields:

- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`

Required UI:

- `Test connection` action
- validation result panel
- explicit failure reason

### 5. Realtime Configuration

Purpose:

- capture Realtime-specific runtime values

Fields:

- `REALTIME_SERVICE_NAME`
- `REALTIME_TOKEN_AUDIENCE`
- `REALTIME_TOKEN_SIGNING_SECRET`
- `REALTIME_TRUSTED_ISSUERS`
- `REALTIME_PUBLIC_WEBSOCKET_URL`
- `REALTIME_WS_BIND_ADDRESS`
- `REALTIME_WS_PORT`
- `REALTIME_ALLOWED_ORIGINS` optional metadata only; browser access is token-authenticated, not globally domain-allowlisted.

Required UI:

- field descriptions
- masking for secrets
- syntax validation for URL and issuer list
- warning if secret looks like placeholder or default

### 6. Admin Bootstrap

Purpose:

- create or prepare the first admin account

Fields:

- admin name
- admin email
- password mode:
  - operator enters password
  - installer generates password

Required UI:

- password policy hint
- generated credential handling warning

### 7. Review

Purpose:

- show final configuration summary before write actions

Required UI:

- masked secrets
- grouped summaries:
  - app
  - database
  - Realtime
  - admin
- `Back` and `Install now`

### 8. Install Progress

Purpose:

- execute install actions and stream results

Required UI:

- current step indicator
- real-time log panel
- pass/fail markers per action
- busy state across the full screen

Install actions should run in this order:

1. prepare directories
2. write `.env`
3. generate `APP_KEY`
4. migrate database
5. seed/bootstrap admin
6. register Ratchet startup
7. write install manifest

### 9. Validation

Purpose:

- verify the installed deployment is actually usable

Validation items:

- app responds
- `/api/health` passes
- `/api/ready` passes
- database schema exists
- admin account exists
- Ratchet command is registered/startable
- websocket bind target is reachable

Required UI:

- pass/fail checklist
- retry failed validations
- unresolved issues panel

### 10. Finish

Purpose:

- close the install cleanly and hand off next actions

Required UI:

- install summary
- manual post-install tasks
- download report action
- `Open admin`
- `Open health`
- lock/remove installer recommendation

## Backend Action Contract

### `GET /installer/api/state`

Returns:

- current step
- saved config state
- install mode
- completion marker state

### `POST /installer/api/config/import`

Accepts:

- uploaded JSON or pasted JSON payload

Returns:

- normalized config
- validation errors

### `POST /installer/api/preflight`

Accepts:

- current draft config

Returns:

- check list with:
  - key
  - label
  - status
  - message
  - blocking boolean

### `POST /installer/api/config/save`

Accepts:

- current step config values

Returns:

- normalized saved state
- field errors if invalid

### `POST /installer/api/install/run`

Accepts:

- full confirmed config

Returns:

- started / completed state
- step-by-step results
- report location

### `POST /installer/api/validate/run`

Returns:

- validation checklist results
- unresolved manual items

### `GET /installer/api/report`

Returns:

- install summary
- validation summary
- masked config summary
- downloadable report payload

## State Persistence

The installer should persist draft state so browser refresh does not destroy progress.

Recommended storage:

- JSON file under installer-specific writable storage

State should include:

- selected mode
- saved config
- completed steps
- install log path
- report path

## Error Handling

The installer must:

- surface blocking errors inline
- keep failed install/validation logs visible
- avoid silent reloads
- never clear busy state until the active request finishes

## Completion Marker

After successful install, write a completion marker so the installer can:

- warn before rerun
- default to repair/upgrade instead of fresh install
- recommend lock or removal

## Recommended v1 Constraint

Do not attempt to implement:

- remote multi-host orchestration
- reverse proxy auto-rewrite for every web server type
- secrets vault integration
- cluster installation

Keep v1 focused on producing one working hub-local Realtime deployment.

