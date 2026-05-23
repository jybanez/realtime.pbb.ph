# PBB Realtime Installer Proposal

## Purpose

Define a repeatable installer for deploying PBB Realtime to multiple hub servers across the PBB network without relying on ad hoc manual setup.

The installer should reduce deployment drift, shorten rollout time, and make hub deployments auditable and supportable.

## Problem Statement

Current deployment depends on manual coordination of:

- application files
- `.env` configuration
- database creation and migration
- admin bootstrap
- Ratchet websocket process setup
- public websocket path exposure
- post-install validation

That is workable for a single environment, but it does not scale cleanly when each hub needs its own Realtime server.

The current failure modes are predictable:

- wrong token signing secret
- wrong trusted issuers
- wrong public websocket URL
- Ratchet not started or not supervised
- reverse proxy not forwarding `/realtime` correctly
- database or app key not initialized
- no seeded admin account

These are installer problems, not operator problems.

## Proposal

Build a first-party PBB Realtime installer that performs and validates the minimum deployment contract required to run a hub-local Realtime service.

The installer should be delivered as a downloadable ZIP bundle. The hub operator should:

1. download the installer ZIP
2. unzip it into the target webroot or staging folder
3. open the installer in a browser
4. complete the installation flow through a web UI built with PHP, HTML/JS, and the Helper library

The installer should be opinionated and bounded:

- it installs and configures PBB Realtime
- it does not attempt to manage downstream PBB project integrations
- it does not become a generic infrastructure orchestrator

## Recommended Scope

### Phase 1: Shared Windows/Linux installer contract

Target the deployment shapes already closest to the current repo and operator environments:

- Windows Server / WAMP-style PHP runtime
- Linux host with PHP CLI + web server
- MySQL or MariaDB reachable from the hub
- Ratchet process managed through an OS-specific startup mechanism:
  - Windows service wrapper or scheduled startup task
  - Linux `systemd`
- TLS/public host and reverse proxy configured either:
  - directly by installer when feasible, or
  - via guided operator inputs plus post-install validation

The installer should keep one browser workflow and one config contract while generating OS-specific service artifacts.

### Phase 2: Deeper host automation

After the shared deployment contract is stable, add:

- stronger Windows service registration automation
- stronger Linux systemd registration automation
- nginx/apache reverse proxy templates
- non-interactive CLI install mode for automation

Do not pretend the first pass fully automates host-level service registration on every OS. Generate correct artifacts first, then deepen automation safely.

## Installer Responsibilities

The installer should own:

- prerequisite validation
- target path preparation
- `.env` generation
- `APP_KEY` generation
- Realtime env collection:
  - `REALTIME_TOKEN_SIGNING_SECRET`
  - `REALTIME_TRUSTED_ISSUERS`
  - `REALTIME_TOKEN_AUDIENCE`
  - `REALTIME_PUBLIC_WEBSOCKET_URL`
  - `REALTIME_WS_BIND_ADDRESS`
  - `REALTIME_WS_PORT`
  - `REALTIME_ALLOWED_ORIGINS` remains optional metadata; do not use it as a global browser-client domain list.
- database connectivity test
- database migration
- optional seed/admin bootstrap
- Ratchet process registration
- local health validation
- websocket validation checklist output
- install report and log generation

## Installer Must Not Own

The installer should not own:

- downstream PBB project authentication logic
- project-specific backend SDK integration
- project/operator business authorization rules
- cross-system identity federation
- generic server hardening beyond what is needed for Realtime runtime

## Deployment Model

Each hub runs a hub-local Realtime instance.

That instance should have:

- its own application URL
- its own websocket public URL
- its own trusted issuer list
- its own token signing secret
- its own database
- its own admin accounts

This is the safer model for operational isolation.

## Delivery Shape

Recommended first delivery:

- interactive web installer
- PHP backend installer actions
- Helper-powered browser UI
- installation manifest output
- repair mode
- upgrade mode

Suggested packaging:

- installer bundle ZIP
- includes:
  - application package
  - web installer entrypoint
  - installer PHP backend
  - installer JS/CSS assets
  - Helper vendored assets or loader references
  - env template
  - service template
  - validation checklist

## Installer UI Model

The installer should not be a plain form page. It should be an operator-facing guided setup flow.

Recommended UI sections:

- welcome / deployment mode
- prerequisite checks
- app and database configuration
- Realtime runtime configuration
- admin bootstrap
- install progress
- validation results
- post-install actions

Recommended UI behavior:

- top-level step navigation
- field validation before advancing
- explicit busy states during install actions
- durable install log panel
- clear pass/fail reporting for validation steps

The installer UI should use the Helper library so it stays aligned with the rest of the PBB operator tooling.

## Installer Modes

### Fresh Install

Use when the hub does not yet have Realtime deployed.

This should be the default browser entry path.

### Upgrade

Use when Realtime already exists and only needs:

- new release files
- migrations
- service/env updates

### Repair

Use when a hub deployment exists but is misconfigured or partially broken.

## Success Criteria

An install is successful only if all of these are true:

- app responds on the configured HTTP URL
- `/api/health` and `/api/ready` pass
- database migrations complete
- admin login works
- `php artisan realtime:serve` is registered and startable
- public websocket path is configured
- `/admin/sandbox` can issue an admission token
- websocket connect, room join, presence publish, and chat publish succeed

## Operational Benefits

The installer should give PBB:

- faster hub rollout
- less environment drift
- reproducible post-install validation
- easier support handoff
- clearer upgrade path
- lower dependence on one-off setup knowledge

## Risks

### If the installer is too thin

It becomes only a scripted checklist and does not materially reduce deployment failure.

### If the installer is too ambitious

It becomes a brittle infrastructure tool that is harder to support than the app itself.

The correct balance is:

- application-focused
- deployment-aware
- infrastructure-light

## Recommendation

Proceed with a Windows-first Realtime installer that formalizes the existing deployment contract before attempting broader platform packaging.

The first installer should be:

- web-based
- PHP-driven
- Helper-backed for UI
- shipped as a ZIP that can be unzipped and run from a browser

The first version should optimize for:

- repeatability
- correctness
- validation

not for broad infrastructure abstraction.

