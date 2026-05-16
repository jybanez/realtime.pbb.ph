# PBB Realtime Maestro Process Monitoring Implementation Checklist

## Goal

Expose `php artisan realtime:serve` to `PBB Maestro` as one monitored worker identity so operators can see whether the websocket daemon is started, fresh, stale, or stopped.

## Scope

- emit `worker.started` on daemon boot
- emit worker heartbeats on a fixed interval while the daemon is alive
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

## Worker Identity

- generate one stable worker id per `realtime:serve` process boot
- recommended shape:
  - `realtime:serve:<host>:<pid>:<started_at>:<random_suffix>`

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
- wire periodic heartbeat emission on the React loop
- wire `worker.stopped` on clean shutdown or signal handling where available
- keep failures non-fatal to the websocket daemon
- log telemetry failures locally for operator diagnosis

## Verification

- daemon boots and sends `worker.started`
- heartbeats are accepted by Maestro and update freshness
- daemon shutdown emits `worker.stopped` when possible
- telemetry failure does not crash `realtime:serve`
- Maestro shows the daemon under app code `realtime`

## Follow-up

- add processed or failed counters if Realtime later wants to expose meaningful room/event throughput
- consider separate telemetry for event-publish drain metrics if daemon visibility alone proves insufficient
