# PBB Realtime App-Owned Event Ingress Integration Guide

Date: 2026-04-06

## Purpose

Use this ingress when a trusted product backend needs Realtime to fan out a server-originated event into an existing room.

This is for backend-to-room transport only.

It is not:
- browser-facing
- a replacement for websocket admission
- a product business workflow engine

## Endpoint

- `POST /api/v1/events/publish`

Required header:
- `X-Realtime-Backend-Secret`

Content type:
- `application/json`

## Required Request Fields

- `client_code`
- `project_code`
- `room`
- `event_type`
- `payload`

Optional:
- `meta`
- `event_id`

## Backend Credential

The backend secret is configured on the Realtime client record.

Important:
- store it only on trusted product backends
- never expose it to browser code
- rotate it from the Realtime admin surface when needed

## Authorization Model

Realtime validates:

1. client exists and is active
2. backend secret matches the client
3. project exists and belongs to the client
4. project is active
5. project policy allows `event.publish`
6. requested room is allowed by the effective room policy
7. payload size is within the fixed ingress limit
8. client publish rate is within the effective limit

## Hotline-First Example

```http
POST /api/v1/events/publish HTTP/1.1
Host: realtime.pbb.ph
Content-Type: application/json
X-Realtime-Backend-Secret: <backend-secret>
```

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
    "source_module": "hotline-beta-admin"
  },
  "event_id": "evt_hotline_alert_001"
}
```

## Success Response

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
    "event_id": "evt_hotline_alert_001",
    "published": true
  }
}
```

Important:
- `202 Accepted` means Realtime queued the event for dispatch
- room fanout is asynchronous

## Failure Examples

### Invalid Secret

```json
{
  "service": "PBB Realtime",
  "status": "rejected",
  "reason": "invalid-backend-secret",
  "message": "The backend ingress secret is invalid."
}
```

### Unknown Project

```json
{
  "service": "PBB Realtime",
  "status": "rejected",
  "reason": "unknown-project",
  "message": "Unknown project scope."
}
```

### Room Not Allowed

```json
{
  "service": "PBB Realtime",
  "status": "rejected",
  "reason": "room-not-allowed",
  "message": "The requested room is not allowed for this project scope."
}
```

### Payload Too Large

```json
{
  "service": "PBB Realtime",
  "status": "rejected",
  "reason": "payload-too-large",
  "message": "The event payload exceeds the maximum allowed encoded size."
}
```

### Rate Limit Exceeded

```json
{
  "service": "PBB Realtime",
  "status": "rejected",
  "reason": "rate-limit-exceeded",
  "message": "Backend event publish rate limit exceeded."
}
```

## Event Consumption

Connected room members receive a normal Realtime event envelope:

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
    "service": "PBB Realtime",
    "source": "server",
    "client_code": "clt_01KMXFPRXCTHJAG10DMACJFMYB",
    "project_code": "prj_01KMXG0AXB2S9CXS0YK4AFT2C9",
    "event_id": "evt_hotline_alert_001",
    "source_module": "hotline-beta-admin"
  }
}
```

## Practical Guidance

Do:
- reuse one stable room name per backend-originated setting domain
- send small payloads only
- include a product-side `event_id` for traceability
- keep `meta` small and transport-oriented

Do not:
- send secrets in `payload` or `meta`
- treat `202 Accepted` as guaranteed delivery
- use this endpoint from browser code
