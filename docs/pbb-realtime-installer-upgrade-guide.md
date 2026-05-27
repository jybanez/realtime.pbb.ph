# PBB Realtime Installer Upgrade Guide

## Intended Upgrade Behavior

Upgrade mode should preserve:

- `.env`
- database contents
- generated runtime secrets

and should re-run:

- migrations
- validation

## Current State

Upgrade mode now has a real installer path.

Current safe expectation:

- use it as a controlled path for:
  - backing up installer-managed release files and generated artifacts
  - preserving the current `.env` as the base before applying Kit-managed values
  - re-running migrations
  - refreshing admin bootstrap
  - regenerating Laravel runtime caches with `optimize:clear` and `config:cache`
  - regenerating the OS-specific service artifact
  - emitting an upgrade report with backup, cache, service, database, and rollback-support metadata
- do not yet treat it as a full release orchestrator that swaps application bundles automatically

Rollback support is file/artifact oriented. Upgrade mode backs up `.env`, core installer-managed release files, and generated service artifacts before mutation. It does not automatically roll back database schema or data changes, so any future bundle with irreversible schema/data changes must declare `release.json.update.rollback_supported=false`.

## Required Future Upgrade Features

- full release replacement workflow
- selective file diff reporting
- service registration refresh automation
- app-version-aware bounded data migrations beyond normal Laravel migration status
