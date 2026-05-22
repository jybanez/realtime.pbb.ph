# PBB Realtime Maestro Process Monitoring Implementation Checklist

## Goal

Expose Realtime runtime services to `PBB Maestro` as monitored worker identities so operators can see whether the websocket daemon and media dispatcher are started, fresh, stale, or stopped.

## Scope

- emit `worker.started` on process boot
- emit worker heartbeats while the process is alive
- emit `worker.stopped` on clean shutdown when possible
- reuse Maestro's existing telemetry endpoints and token contract
- keep Maestro as observability only, not process control

## Runtime Contract

- app code: `realtime`
- heartbeat endpoint: `POST /api/v1/telemetry/workers/heartbeat`
- event endpoint: `POST /api/v1/telemetry/worker-events`
- auth: `X-Telemetry-Token` or bearer token

## Required Realtime Config

- `MAESTRO_BASE_URL`
- `MAESTRO_TELEMETRY_TOKEN`
- optional `MAESTRO_TELEMETRY_APP_CODE` defaulting to `realtime`
- optional `MAESTRO_TELEMETRY_HEARTBEAT_SECONDS`
- optional `MAESTRO_TELEMETRY_ENABLED`
- optional `MAESTRO_TELEMETRY_VERIFY_TLS`
- optional `MAESTRO_TELEMETRY_CA_BUNDLE`

Kit Data Prep normally writes these values into `realtime_runtime_settings` through `tools/data-prep/apply-settings.php` instead of relying on `.env`.

## Worker Identity

- generate one stable worker id per process boot
- recommended shape:
  - `realtime:serve:<host>:<pid>:<started_at>:<random_suffix>`

Current roles:

- `realtime:serve`: `websocket-gateway`
- `realtime:dispatch`: `media-chunk-dispatcher`

## Payload Shape

Heartbeat payload:

- `app_code`
- `worker_id`
- `host_name`
- `process_id`
- `status`
- `started_at`
- `last_heartbeat_at`
- `processed_count`
- `failed_count`
- `current_job_id = null`
- `queue_name = null`
- `meta.command = realtime:serve`
- `meta.role = websocket-gateway`
- `meta.listen_host`
- `meta.listen_port`

Lifecycle event payload:

- `app_code`
- `worker_id`
- `event_id`
- `event_type`
- `occurred_at`
- `queue_name = null`
- `job_id = null`
- `payload.command = realtime:serve`
- `payload.role = websocket-gateway`
- `payload.listen_host`
- `payload.listen_port`

## Implementation Steps

- add a small Realtime telemetry client/service for Maestro HTTP calls
- add config entries for Maestro telemetry settings
- create a daemon runtime state object for worker id, host, pid, counters, and timestamps
- wire `worker.started` before entering the Ratchet loop
- wire periodic heartbeat emission on the React loop for `realtime:serve`
- wire dispatcher heartbeat emission inside the media dispatch loop for `realtime:dispatch`
- wire `worker.stopped` on clean shutdown or signal handling where available
- keep failures non-fatal to the websocket daemon
- log telemetry failures locally for operator diagnosis

## Verification

- daemon boots and sends `worker.started`
- heartbeats are accepted by Maestro and update freshness
- daemon shutdown emits `worker.stopped` when possible
- telemetry failure does not crash `realtime:serve`
- Maestro shows the daemon under app code `realtime`
- CA-bundle-backed HTTPS telemetry succeeds on Windows/WAMP hosts where PHP/cURL does not have a usable default trust store

## Follow-up

- keep Maestro heartbeat freshness verification in Maestro Data Prep Verify
- Kit may restart both Realtime services after Data Prep Apply Settings for immediate heartbeat freshness
