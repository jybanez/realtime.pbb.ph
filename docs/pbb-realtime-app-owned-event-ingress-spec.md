# PBB Realtime App-Owned Event Ingress Spec

Date: 2026-04-06

## Endpoint

- `POST /api/v1/events/publish`

Content type:
- `application/json`

Authentication:
- `X-Realtime-Backend-Secret: <plain-text-secret>`

## Request Body

```json
{
  "client_code": "clt_01KMXFPRXCTHJAG10DMACJFMYB",
  "project_code": "prj_01KMXG0AXB2S9CXS0YK4AFT2C9",
  "room": "hotline.settings.global",
  "event_type": "hotline.alert_level.changed",
  "payload": {
    "alert_level": "Red",
    "changed_at": "2026-04-06T05:55:00Z"
  },
  "meta": {
    "source": "pbb-hotline-backend"
  },
  "event_id": "evt_01..."
}
```

## Required Fields

- `client_code`
  - string
- `project_code`
  - string
- `room`
  - string
- `event_type`
  - string
- `payload`
  - object

## Optional Fields

- `meta`
  - object
- `event_id`
  - string

## Validation Rules

### Request Validation

- `client_code` must exist in `realtime_clients`
- `project_code` must exist in `realtime_projects`
- project must belong to the client
- `room` must be a non-empty string
- `event_type` must be a non-empty string
- `payload` must be a JSON object
- `meta` must be a JSON object when present
- `event_id` must be a non-empty string when present

### Client / Project Status

Reject if:
- client is not active
- project is not active

### Backend Credential

Reject if:
- `X-Realtime-Backend-Secret` is missing
- the supplied secret does not match the client’s configured backend ingress secret

### Policy Resolution

Resolve effective project policy from:
- project `policy_profile_code`
- fallback behavior only if that is already standard in current Realtime client/project resolution

V1 should not invent a separate policy hierarchy just for this ingress.

### Capability Check

The effective policy must allow:
- `event.publish`

V1 capability evaluation should reuse the current policy/capability model.

If the effective policy capability profile includes `allowed_event_types`
or `event_types`, the requested `event_type` must be an exact member of
that list. Policies without either key preserve the legacy behavior and do
not constrain backend event types beyond `event.publish`.

### Room Authorization

Requested `room` must be allowed by the effective scope rules:

- exact allowed room match
- or allowed room prefix match

Recommended source:
- current project/client/policy-derived room rules

V1 should reuse the same room-authorization semantics already used in websocket admission/gateway logic.

### Payload Size

V1 rejects payloads larger than a small fixed limit.

Current default limit:
- `32 KB` encoded JSON body for `payload`

Config key:
- `REALTIME_EVENT_PUBLISH_PAYLOAD_MAX_BYTES`

This can later become policy-driven if needed.

### Publish Rate Limit

V1 should apply one of these:

1. preferred:
   - use a publish-specific limit from the effective rate-limit profile if present
2. fallback:
   - use a fixed server-side per-client limit

Recommended fallback:
- `60 publishes / minute / client`

If the current policy model does not yet include a clean publish-specific key, use the fallback first and document it.

## Successful Response

Status:
- `202 Accepted`

Accepted means:
- request validation passed
- authorization passed
- the event was queued for dispatch into the websocket runtime
- fanout is completed asynchronously by the Realtime publish dispatcher

Body:

```json
{
  "service": "PBB Realtime",
  "status": "accepted",
  "data": {
    "publish_id": "pub_01...",
    "client_code": "clt_01KMXFPRXCTHJAG10DMACJFMYB",
    "project_code": "prj_01KMXG0AXB2S9CXS0YK4AFT2C9",
    "room": "hotline.settings.global",
    "event_type": "hotline.alert_level.changed",
    "event_id": "evt_01...",
    "published": true
  }
}
```

## Rejected Response

Status:
- `401 Unauthorized`
- `403 Forbidden`
- `422 Unprocessable Entity`
- `429 Too Many Requests`

Example:

```json
{
  "service": "PBB Realtime",
  "status": "rejected",
  "reason": "event-type-not-allowed",
  "message": "The requested event type is not allowed for this project scope."
}
```

## Fanout Envelope

Realtime should emit a normal room event envelope:

```json
{
  "namespace": "pbb.realtime.v1",
  "phase": "event",
  "type": "hotline.alert_level.changed",
  "payload": {
    "alert_level": "Red",
    "changed_at": "2026-04-06T05:55:00Z"
  },
  "meta": {
    "source": "server",
    "client_code": "clt_01KMXFPRXCTHJAG10DMACJFMYB",
    "project_code": "prj_01KMXG0AXB2S9CXS0YK4AFT2C9",
    "room": "hotline.settings.global",
    "event_id": "evt_01..."
  }
}
```

The event is queued in `realtime_server_events` before dispatch. V1 does not promise persistent delivery semantics beyond queueing and live room fanout.

## Audit Requirements

Realtime must record an audit event for:
- accepted publish
- rejected publish

Recommended audit fields:
- actor type: `backend`
- client code
- project code
- room
- event type
- result: accepted/rejected
- rejection reason when applicable
- source metadata summary

Payload contents should not be copied into audit in full unless there is a deliberate policy to do so.

## Usage Telemetry Requirements

Realtime should record usage telemetry for:
- accepted publish count
- rejected publish count
- bytes out for accepted fanout
- rate-limit hits

Recommended event names:
- `event.publish.accepted`
- `event.publish.rejected`
- `event.publish.rate_limited`

## Initial Data Model Additions

Minimal preferred additions:

### `realtime_clients`

Add:
- `backend_ingress_secret_hash`

Store only the hash, not the plain-text secret.

### Optional Later Additions

Only if needed later:
- explicit ingress-enabled flag
- publish-rate-limit override fields

These should not be required for V1 if the policy model and client secret are sufficient.

## Recommended Controller / Service Shape

Add:
- `EventPublishController`
- small authorization service to:
  - load client
  - load project
  - resolve effective policy
  - validate room
  - validate capability
  - validate backend secret

Reuse existing services where possible for:
- audit
- telemetry
- websocket room fanout

## Error Reasons

Recommended reason codes:

- `missing-backend-secret`
- `invalid-backend-secret`
- `unknown-client`
- `unknown-project`
- `client-project-mismatch`
- `inactive-client`
- `inactive-project`
- `missing-capability`
- `room-not-allowed`
- `event-type-not-allowed`
- `payload-too-large`
- `rate-limit-exceeded`
- `invalid-request`

## V1 Acceptance Target

Hotline first-pass acceptance:

1. Hotline backend calls `POST /api/v1/events/publish`
2. Realtime accepts request for:
   - client
   - project
   - room
3. connected clients in `hotline.settings.global` receive:
   - `type: hotline.alert_level.changed`
4. event is visible in:
   - Realtime audit
   - Realtime telemetry

If that works, the transport gap is closed without broadening Realtime into product logic.
