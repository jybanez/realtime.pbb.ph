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
- Kit runtime service status for `pbb-realtime-websocket`
- Kit runtime service status for `pbb-realtime-media-dispatcher`

## Sandbox Still Fails After Install

Check:

- public websocket path for `/realtime`
- reverse proxy websocket upgrade headers
- trusted issuers
- token signing secret
- public websocket URL

## Data Prep Prepare Data Fails

Check:

- `resources/data/realtime/hotline-client-data.json` exists in the installed bundle
- `checksums.sha256` includes the Hotline data file
- `tools/populate-initial-data.php` is present
- database schema includes Realtime client, policy, project, and runtime settings tables
- report `sources[].used_default_source=true` when Kit intentionally omits an explicit source

Expected packaged data:

- 1 Hotline client
- 5 Hotline policies
- 4 Hotline project scopes

## Data Prep Apply Settings Fails

Check:

- `tools/data-prep/apply-settings.php` exists in the installed bundle
- Kit config contains `realtime.data_prep.apply_settings.maestro`
- `base_url` points to the installed Maestro public URL, normally `https://maestro.pbb.ph`
- `app_code` is `realtime`
- `telemetry_token` is supplied by Kit secrets
- `ca_bundle` or `curl_ca_bundle` points to an existing trusted CA file when `tls_verify=true`

Reports must show `token_supplied=true` without printing the token.

For media ingest projects, the CA rule is per project scope. Any project with HTTPS media ingest enabled and `verify_tls=true` needs `media_ingest_settings.ca_bundle` unless the host PHP/cURL runtime already trusts the downstream certificate chain. This applies to future clients and new project/policy codes, not only the built-in Hotline project scopes.

If media chunks fail with `media.ingest-failed` and Realtime logs show cURL error 60, verify:

- the affected project scope has `media_ingest_settings.ca_bundle`
- the CA file exists on the installed host
- Kit Data Prep Apply Settings was rerun after the project was created
- both `pbb-realtime-websocket` and `pbb-realtime-media-dispatcher` were restarted after settings changed

If media chunks fail with downstream status `401` and Hotline reports `Invalid media ingest secret`, verify:

- Hotline `realtime_media_ingest_secret` and Realtime `media_ingest_settings.auth_token` came from the same Kit-generated secret
- Realtime project scopes use `media_ingest_settings.auth_header=X-Realtime-Media-Ingest-Secret`
- Data Prep Apply Settings included `realtime.data_prep.apply_settings.media_ingest.auth_token`
- the affected project scope is one of the project codes targeted by Data Prep

For future project onboarding, treat media ingest TLS trust as part of the integration contract. A project is ready for install testing only when its Data Prep source or Kit config declares the client/project/policy codes, media ingest endpoint, auth header/secret, TLS verification mode, and CA bundle behavior. The minimum acceptance check is: Data Prep Verify reports the project present, `verify_tls=true`, `ca_bundle_configured=true` for HTTPS media ingest, and an actual media chunk reaches the downstream ingest service.

## Maestro Heartbeat Missing After Data Prep

Check installed logs:

- `storage/logs/laravel.log`
- `storage/logs/realtime-serve-smoke.log`
- `storage/logs/realtime-dispatch-smoke.log`

If Laravel logs show `cURL error 60: SSL certificate problem`, Kit must pass a trusted CA bundle through:

```json
{
  "realtime": {
    "data_prep": {
      "apply_settings": {
        "maestro": {
          "tls_verify": true,
          "ca_bundle": "C:/path/to/cacert.pem"
        }
      }
    }
  }
}
```

Realtime persists this as `maestro_telemetry_ca_bundle` and passes it to Guzzle.

Both Realtime services emit Maestro telemetry:

- `pbb-realtime-websocket`
- `pbb-realtime-media-dispatcher`

They can pick up DB-backed settings on the next heartbeat. For fastest verification, Kit can restart both services after Apply Settings and then run Maestro heartbeat verification.

