# PBB Realtime Usage Telemetry Checklist

Goal:
- persist aggregate transport usage by client and project
- expose telemetry to operators through the existing operations surface

Checklist:
- [x] Create durable usage bucket storage
- [x] Add a usage bucket model
- [x] Add a telemetry service that records hourly aggregates
- [x] Record successful realtime transport events into usage telemetry
- [x] Record auth failures into usage telemetry
- [x] Record request errors into usage telemetry
- [x] Record rate-limit hits into usage telemetry
- [x] Track attachment chunk bytes in usage telemetry
- [x] Add summary queries for:
  - last 24 hours totals
  - top clients
  - top project scopes
  - event mix
- [x] Expose usage telemetry in the admin operations API
- [x] Render usage telemetry in a dedicated admin telemetry page
- [x] Add tests for telemetry recording and operations payloads
- [x] Add retention/pruning for old telemetry buckets
