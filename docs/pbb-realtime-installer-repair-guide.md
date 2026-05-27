# PBB Realtime Installer Repair Guide

## Purpose

Repair mode should help recover a hub deployment with drift or partial failure.

## Typical Repair Targets

- missing `APP_KEY`
- broken `.env`
- failed migrations
- missing admin account
- missing Ratchet startup registration

## Current State

Repair mode has a real targeted installer path.

Current practical use:

- detect missing `APP_KEY` and rewrite `.env` from the existing installed `.env` when present
- detect missing admin account and bootstrap the configured admin without overwriting existing users
- detect pending migrations and run the bounded Laravel migration path
- detect missing service artifacts and regenerate them
- refresh runtime caches with `optimize:clear` and `config:cache`
- emit a repair report with detected/performed/skipped actions, validation, cache refresh, and rollback-support metadata

## Future Repair Target

Repair mode should remain the operator-safe way to restore a hub deployment without doing a full reinstall. Future additions should stay bounded and app-owned; repair must not erase runtime data or regenerate client/project/policy secrets unless an explicit future contract requires it.
