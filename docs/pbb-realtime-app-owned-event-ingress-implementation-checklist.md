# PBB Realtime App-Owned Event Ingress Implementation Checklist

Date: 2026-04-06

## Phase 1: Contract Lock

- [x] Approve the V1 endpoint shape: `POST /api/v1/events/publish`
- [x] Lock request field names to:
  - `client_code`
  - `project_code`
  - `room`
  - `event_type`
  - `payload`
  - optional `meta`
  - optional `event_id`
- [x] Lock the backend auth header:
  - `X-Realtime-Backend-Secret`
- [x] Lock one explicit capability:
  - `event.publish`
- [x] Lock the fanout envelope shape:
  - `phase: "event"`
  - `type`
  - `payload`
  - `meta.source = "server"`

## Phase 2: Minimal Data Model

- [x] Add backend ingress secret storage to `realtime_clients`
- [x] Store only a hash for the backend ingress secret
- [x] Add model casts/fillables as needed
- [x] Add migration coverage

## Phase 3: Request Validation

- [x] Add controller route for `POST /api/v1/events/publish`
- [x] Validate request body structure
- [x] Validate client existence and status
- [x] Validate project existence and status
- [x] Validate client/project relationship
- [x] Validate `payload` and optional `meta` are objects
- [x] Enforce payload size limit

## Phase 4: Authorization

- [x] Validate `X-Realtime-Backend-Secret`
- [x] Resolve effective project policy from `project_code`
- [x] Enforce `event.publish` capability from the effective policy
- [x] Enforce room authorization against effective allowed rooms / prefixes
- [x] Return stable rejection reasons for all expected failure modes

## Phase 5: Publish Runtime

- [x] Add a narrow publish service for server-originated room events
- [x] Reuse the existing websocket runtime/envelope path where possible
- [x] Broadcast to current room members only
- [x] Attach server-originated meta fields
- [x] Support optional `event_id`

## Phase 6: Audit And Telemetry

- [x] Record accepted publish attempts into audit
- [x] Record rejected publish attempts into audit
- [x] Record accepted publish telemetry
- [x] Record rejected publish telemetry
- [x] Record rate-limit hits into telemetry

## Phase 7: Rate Limiting

- [x] Decide whether V1 uses:
  - fixed fallback server limit
  - or policy-driven publish limit
- [x] Implement the chosen limit path
- [x] Return `429` with stable reason code on rate-limit rejection

## Phase 8: Admin Surface

- [x] Add client-level backend ingress secret management
- [x] Keep the secret hidden after write
- [x] Audit secret creation/rotation events
- [x] Document that this secret is backend-only and must never be exposed to browsers

## Phase 9: Documentation

- [x] Add endpoint contract to:
  - [pbb-realtime-openapi.yaml](/c:/wamp64/www/pbb/realtime/docs/pbb-realtime-openapi.yaml)
- [x] Add project-integration guidance for backend publish ingress
- [x] Add Hotline-first example payloads
- [x] Add failure-mode examples

## Phase 10: Tests

- [x] Accepted publish test
- [x] Invalid backend secret test
- [x] Unknown project test
- [x] Client/project mismatch test
- [x] Missing capability test
- [x] Room not allowed test
- [x] Payload too large test
- [x] Rate-limit test
- [x] Audit assertion test
- [x] Usage telemetry assertion test
- [x] End-to-end room fanout test against a connected room member

## Phase 11: Hotline Acceptance

- [ ] Create `hotline.settings.global` acceptance fixture
- [ ] Publish `hotline.alert_level.changed` from Hotline backend
- [ ] Verify connected caller/admin/operator clients receive the event live
- [ ] Verify no page refresh is required
- [ ] Verify audit record exists
- [ ] Verify telemetry record exists

## Efficiency Notes

Keep this implementation narrow.

Do:
- reuse project scope lookup
- reuse policy capability concepts
- reuse room allowlist/prefix logic
- reuse existing audit and telemetry services
- reuse existing websocket event envelope conventions

Do not:
- add a second transport architecture
- add product-specific schema validation in Realtime
- redesign token/runtime authorization globally just for this ingress
- widen V1 into persistent event history or guaranteed delivery
