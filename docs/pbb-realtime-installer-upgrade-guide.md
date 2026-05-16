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
  - preserving the current `.env` as the base
  - re-running migrations
  - refreshing admin bootstrap
  - regenerating the OS-specific service artifact
- do not yet treat it as a full release orchestrator that swaps application bundles automatically

## Required Future Upgrade Features

- full release replacement workflow
- selective file diff reporting
- service registration refresh automation
- post-upgrade validation
