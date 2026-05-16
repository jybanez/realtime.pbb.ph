# PBB Realtime Installer Implementation Checklist

## Phase 1: Installer Foundation

- [x] Create installer delivery structure for ZIP packaging
- [x] Define installer entry route under `public/installer/`
- [x] Add installer asset bundle:
  - [x] `app.js`
  - [x] `app.css`
- [x] Add installer backend action endpoints
- [x] Add installer state storage strategy
- [x] Add installer log writer
- [x] Add installer report writer

## Phase 2: Browser UI Shell

- [x] Build Helper-based installer shell
- [x] Add step navigation UI
- [x] Add top-level progress state
- [x] Add busy-state handling for long-running steps
- [x] Add persistent install log panel
- [x] Add pass/fail validation display components

## Phase 3: Preflight Checks

- [x] Check PHP version
- [x] Check required PHP extensions
- [x] Check filesystem write access
- [x] Check target install path safety
- [x] Check `.env` writability
- [x] Check `storage` writability
- [x] Check `bootstrap/cache` writability
- [x] Check database connectivity
- [x] Check websocket port availability
- [x] Check `REALTIME_PUBLIC_WEBSOCKET_URL` syntax
- [x] Check token signing secret is not placeholder

## Phase 4: Configuration Capture

- [x] Add application settings form
- [x] Add database settings form
- [x] Add Realtime runtime settings form
- [x] Add admin bootstrap form
- [x] Add config import via JSON upload/paste
- [x] Add field validation before next-step navigation
- [x] Add config summary review step

## Phase 5: Install Actions

- [x] Write `.env`
- [x] Generate `APP_KEY`
- [x] Run database migrations
- [x] Run optional seed/bootstrap actions
- [x] Create initial admin account if needed
- [x] Register Ratchet runtime startup command
- [x] Generate OS-specific service registration artifact
- [x] Write install manifest
- [x] Write install log

## Phase 6: Validation Actions

- [x] Validate HTTP app reachability
- [x] Validate `/api/health`
- [x] Validate `/api/ready`
- [x] Validate database schema presence
- [x] Validate admin account presence
- [x] Validate Ratchet command startability
- [x] Validate websocket bind target
- [x] Show sandbox validation checklist
- [x] Show remaining manual tasks

## Phase 7: Upgrade and Repair

- [x] Add upgrade mode flow
- [x] Preserve existing `.env` in upgrade mode
- [x] Back up previous release files
- [x] Re-run migrations in upgrade mode
- [x] Add repair mode flow
- [x] Detect missing `APP_KEY`
- [x] Detect missing admin account
- [x] Detect missing service registration artifact
- [x] Detect pending migrations
- [x] Add targeted repair actions

## Phase 8: Security and Hardening

- [x] Mask secrets in logs and UI
- [x] Prevent placeholder signing secrets in production mode
- [x] Add installer lock/removal recommendation after success
- [x] Add one-time installer completion marker
- [x] Prevent accidental rerun without explicit mode selection

## Phase 9: Packaging

- [x] Define installer ZIP assembly process
- [x] Include Helper assets correctly
- [x] Include env template
- [x] Include service template
- [x] Include post-install checklist
- [x] Include sample install config JSON

## Phase 10: Validation and Documentation

- [x] Write installer quickstart
- [x] Write hub operator guide
- [x] Write upgrade guide
- [x] Write repair guide
- [x] Write troubleshooting guide
- [x] Add test checklist for a clean Windows host
- [x] Add test checklist for a clean Linux host

## Acceptance Lock

- [x] Operator can unzip and open installer in browser
- [x] Operator can complete install without editing app files
- [x] Installer produces a usable admin login
- [x] Installer produces a working websocket runtime
- [x] `/admin/sandbox` can connect successfully after install
