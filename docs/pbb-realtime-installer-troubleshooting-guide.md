# PBB Realtime Installer Troubleshooting Guide

## Preflight Fails

Check:

- PHP version
- required extensions
- DB connectivity
- filesystem writeability
- valid websocket URL
- non-placeholder signing secret

## Install Fails

Check:

- target install path actually contains the app
- `.env.example` is present
- `artisan` exists in the install path
- DB credentials are correct

## Admin Bootstrap Fails

Check:

- migrations ran successfully
- `users` table exists
- password value was provided
- installer path points to the correct Laravel root

## Validation Fails

Check:

- `APP_URL`
- `/api/health`
- `/api/ready`
- Ratchet command availability
- websocket bind address and port

## Sandbox Still Fails After Install

Check:

- public websocket path for `/realtime`
- reverse proxy websocket upgrade headers
- trusted issuers
- token signing secret
- public websocket URL

