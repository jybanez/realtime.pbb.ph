# PBB Realtime Installer Scaffold

This directory contains the first browser-based installer scaffold for PBB Realtime.

Current scope:

- browser entrypoint under `public/installer/`
- plain PHP installer state/actions
- Helper-backed browser UI
- persisted draft state
- real preflight checks
- real `.env` writing and `APP_KEY` generation
- database migrations
- initial admin bootstrap
  - Kit Setup defaults: `PBB Administrator` / `admin@pbb.local`
  - `create_if_missing` strategy, `overwrite_existing=false` by default
  - blank, placeholder, and weak passwords are rejected
- OS-specific service artifact generation:
  - Windows PowerShell/service helper
  - Linux systemd unit template
- repair mode for missing APP_KEY, pending migrations, missing admin, and missing service artifact
- install manifest output
- root `release.json` for Kit Setup discovery
- unattended CLI install runner under `installer/install-run.php`
- machine-readable status output under `installer/status.php`
- unattended config schema under `installer/schema/install.schema.json`
- optional initial-data population tool under `tools/populate-initial-data.php`
- scaffolded validation actions
- generated report/log artifacts
- packaged acceptance validation through admin login, sandbox admission, websocket connect, room join, presence publish, and chat publish

Current limitation:

- host-specific reverse proxy, TLS, firewall, DNS, and service registration policy still require operator or Kit Setup completion when they cannot be safely applied by the app installer

Artifacts are written under:

- `storage/app/installer/state.json`
- `storage/app/installer/install.log`
- `storage/app/installer/install-report.json`
- `storage/app/installer/generated/`

Packaged acceptance builds are written under `storage/app/installer-build/`.
Successful acceptance runs remove extracted `acceptance-*` folders automatically and keep only the latest three `pbb-realtime-installer-*.zip` files by default. Pass `-KeepArtifacts` to retain extracted acceptance files for debugging, or `-ZipRetention <count>` to adjust ZIP retention.

Installer ZIP builds intentionally package only the production Helper runtime from `public/vendor/helpers.pbb.ph`: the rebuilt `dist` bundle, `js/ui/ui.loader.js`, `js/vendor/marked.esm.js`, direct CSS dependencies, and `boot.*.json` metadata. Helper demos, docs, samples, tests, scripts, `.git`, `node_modules`, and other non-runtime files are excluded from release packages.

Installer ZIP builds also exclude local package-builder and acceptance tooling such as `installer/build-installer.ps1`, `installer/test-installer-bundle.ps1`, public test pages, CI scaffolding, and repository tests. The generated ZIP keeps Kit-facing installer docs and runtime files, and its bundled `release.json` is stamped with build metadata using the `v{milestone}-{version}` display version convention.

Optional initial data can be populated after install with:

```powershell
php tools/populate-initial-data.php --config installer/docs/realtime-populate.sample.json --report storage/app/installer/realtime-populate-report.json --dry-run
```

The scaffold exists to stabilize the operator flow and backend contract before wiring the real installer actions.
