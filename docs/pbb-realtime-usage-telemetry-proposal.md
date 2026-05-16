# PBB Realtime Usage Telemetry Proposal

## Purpose

If `PBB Realtime` becomes shared transport infrastructure for multiple clients, the platform needs durable usage telemetry.

Live counters are useful for health checks, but they are not enough for:

- capacity planning
- rate-limit tuning
- attachment transport policy decisions
- identifying top clients and project scopes
- deciding when the websocket runtime needs to scale

The platform should store aggregate usage data in a form that is:

- cheap to query
- durable across restarts
- scoped by client and project
- useful to operators

## Current Gap

Today the system has:

- session records
- admin audit records
- in-memory/cache counters from `RealtimeMetrics`

What it does not have yet is persisted aggregate usage telemetry.

That means the platform cannot answer questions like:

- which client generated the most traffic in the last 24 hours
- which project scope is driving attachment transport usage
- whether rate limits are being hit repeatedly
- whether room traffic is trending upward over time

## Proposed Model

Add persisted usage buckets with these characteristics:

- bucket granularity: `hour`
- dimensions:
  - `client_code`
  - `project_code`
  - `event_type`
- aggregate fields:
  - `event_count`
  - `bytes_in`
  - `bytes_out`
  - `error_count`
  - `rate_limited_count`

This is a usage-telemetry layer, not raw event storage.

The goal is to keep the storage narrow and query-friendly.

## Initial Event Coverage

The first version should record:

- `auth.success`
- `auth.failure`
- `room.join`
- `room.leave`
- `presence.subscribe`
- `presence.publish`
- `chat.publish`
- `call.signal`
- `sandbox.attachment.chunk`

It should also record:

- request-level errors
- rate-limit hits

## Byte Accounting

The initial byte model should be pragmatic:

- `bytes_in`
  - incoming payload cost for the accepted request
  - for chunked attachment transport, count actual decoded chunk bytes
- `bytes_out`
  - approximate fanout bytes for broadcast events where feasible

It is acceptable for V1 byte accounting to be approximate as long as it is consistent.

## Storage Shape

Recommended table:

- `realtime_usage_buckets`

Fields:

- `bucket_start`
- `bucket_granularity`
- `client_code`
- `project_code`
- `event_type`
- `event_count`
- `bytes_in`
- `bytes_out`
- `error_count`
- `rate_limited_count`
- timestamps

## Query Surfaces

The first operator-facing usage surface should be the existing `Operations` page.

It should show:

- usage summary for the last 24 hours
- top clients by traffic
- top project scopes by traffic
- event mix

This is enough to make the telemetry immediately useful without creating a dedicated analytics console yet.

## Scope Boundary

This proposal is for aggregate usage telemetry only.

It does not include:

- raw message persistence
- long-term message archiving
- business analytics for consuming applications
- project-owned media persistence

## Recommendation

Implement persisted hourly aggregate telemetry now, before more clients adopt Realtime as a primary transport service.

That gives the platform:

- objective usage visibility
- better policy tuning
- better scaling decisions
- cleaner operator insight

