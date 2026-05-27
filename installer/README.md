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
- upgrade/repair runtime cache refresh with `optimize:clear` and `config:cache`
- upgrade/repair reports that state backup and rollback-support metadata
- install manifest output
- root `release.json` for Kit Setup discovery
- unattended CLI install runner under `installer/install-run.php`
- machine-readable status output under `installer/status.php`
- unattended config schema under `installer/schema/install.schema.json`
- standalone Data Prep tools:
  - Prepare Data: `tools/populate-initial-data.php`
  - Apply Settings: `tools/data-prep/apply-settings.php`
  - Verify: `tools/data-prep/verify.php`
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

Installer ZIP builds create a temporary packaging stage and run `composer install --no-dev --optimize-autoloader` with the configured PHP binary before archiving. The local development `vendor/` tree is not modified. Release ZIPs must not contain Composer dev packages such as PHPUnit, Mockery, Faker, Collision, Ignition, Sail, or Pint.

Installer ZIP builds intentionally package only the production Helper runtime from `public/vendor/helpers.pbb.ph`: the rebuilt `dist` bundle, `js/ui/ui.loader.js`, `js/vendor/marked.esm.js`, direct CSS dependencies, and `boot.*.json` metadata. Helper demos, docs, samples, tests, scripts, `.git`, `node_modules`, and other non-runtime files are excluded from release packages.

Installer ZIP builds also exclude local package-builder and acceptance tooling such as `installer/build-installer.ps1`, `installer/test-installer-bundle.ps1`, public test/demo pages, CI scaffolding, repository tests, database factories, and database seeders. The generated ZIP keeps Kit-facing installer docs and runtime files, and its bundled `release.json` is stamped with build metadata using the `v{milestone}-{version}` display version convention. Optional data loading should use the declared Data Prep tools under `release.json`, not Laravel database seeders.

For Kit Updater handoff, publish canonical app bundles from GitHub Releases rather than raw branch files. The handoff should include the ZIP asset URL, archive SHA-256, size, `release.json` version/build id/build commit, internal checksum scan result, update compatibility, service restart requirement, Data Prep rerun requirement, and any irreversible migration warning. The checked-in `release.json` carries stable identity and update contract defaults; `installer/build-installer.ps1` stamps unique build metadata into the packaged `release.json`.

Optional initial data can be populated after install with:

```powershell
php tools/populate-initial-data.php --config installer/docs/realtime-populate.sample.json --report storage/app/installer/realtime-populate-report.json --dry-run
```

The current Data Prep contract is documented in `docs/pbb-realtime-data-prep-contract.md`. Realtime's packaged Prepare Data source defaults to `resources/data/realtime/hotline-client-data.json`. Apply Settings stores Maestro telemetry settings, including optional CA bundle path, in encrypted runtime settings. Verify checks Hotline records and Maestro telemetry settings without exposing raw secrets.

The scaffold exists to stabilize the operator flow and backend contract before wiring the real installer actions.
