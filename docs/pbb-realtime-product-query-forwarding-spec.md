# PBB Realtime Product Query Forwarding Spec

## Status

Implemented V1 on 2026-05-11.

## Purpose

Product query forwarding lets an authenticated browser client ask its product backend for a small authoritative state refresh over the existing Realtime websocket. Realtime stays generic: it validates and forwards allowlisted query requests, while the product backend owns authorization, business data, and response content.

## Browser Request

The browser uses the existing app-event publish lane:

```json
{
  "namespace": "pbb.realtime.v1",
  "phase": "request",
  "id": "msg_qry_001",
  "type": "app.event.publish",
  "room": "presence.global.hotline",
  "payload": {
    "event_type": "product.query.request",
    "data": {
      "schema_version": 1,
      "request_id": "qry_001",
      "query": "hotline.incident.snapshot",
      "context": {
        "incident_id": 204
      },
      "projection": {
        "preset": "status"
      },
      "client_state": {
        "reason": "post-call-reconcile"
      }
    },
    "correlation_id": "qry_001"
  }
}
```

## Realtime Validation

Realtime accepts the request only when all checks pass:

- websocket session is authenticated
- token includes `event.publish`
- sender has joined the request room
- project has enabled `product_query_forwarding_settings`
- event type is `product.query.request`
- payload includes valid `schema_version`, `request_id`, and `query`
- query is in the project `allowed_queries` list
- encoded request payload does not exceed `max_payload_bytes`
- per-session product query rate limit has not been exceeded

Normal app-event fanout is bypassed for `product.query.request`; Realtime forwards the request to the product backend instead of broadcasting the request event to the room.

## Forwarded Backend Envelope

Realtime POSTs JSON to the configured product backend endpoint:

```json
{
  "type": "product.query.request",
  "schema_version": 1,
  "client_code": "pbb-hq",
  "project_code": "hq",
  "room": "presence.global.hotline",
  "request": {
    "request_id": "qry_001",
    "query": "hotline.incident.snapshot",
    "context": {
      "incident_id": 204
    },
    "projection": {
      "preset": "status"
    },
    "client_state": {
      "reason": "post-call-reconcile"
    }
  },
  "meta": {
    "service": "PBB Realtime",
    "source": "client",
    "session_id": "rt_...",
    "received_at": "2026-05-11T08:00:00+00:00",
    "correlation_id": "qry_001",
    "sender": {
      "user_id": "1024",
      "display_name": "Gateway User",
      "project_code": "hq",
      "app_code": "pbb-hq"
    }
  }
}
```

The product backend must authenticate the request with the configured backend-only shared secret and re-authorize the sender against the requested context.

## Browser Ack

If the product backend accepts the forwarded request, Realtime sends an ack to the sender:

```json
{
  "phase": "ack",
  "type": "app.event.publish",
  "payload": {
    "accepted": true,
    "delivery": "forwarded",
    "event_type": "product.query.request",
    "request_id": "qry_001",
    "query": "hotline.incident.snapshot",
    "correlation_id": "qry_001"
  }
}
```

The ack means accepted for forwarding only. It does not mean the product query has completed.

## Product Response

The product backend publishes the eventual response through the existing backend ingress `POST /api/v1/events/publish`:

```json
{
  "event_type": "product.query.response",
  "payload": {
    "schema_version": 1,
    "request_id": "qry_001",
    "query": "hotline.incident.snapshot",
    "context": {
      "incident_id": 204
    },
    "status": "ok",
    "data": {
      "incident": {
        "id": 204,
        "status": "Resolved",
        "updated_at": "2026-05-11T07:40:48Z"
      }
    }
  },
  "meta": {
    "source": "backend",
    "source_module": "hotline-beta"
  }
}
```

Realtime does not inspect product-owned response data.

## Forwarding Failure

If Realtime validates the request but the backend callback definitely fails, Realtime emits a standardized `product.query.response` error to the request room and sends a request error to the sender.

```json
{
  "type": "product.query.response",
  "payload": {
    "schema_version": 1,
    "request_id": "qry_001",
    "query": "hotline.incident.snapshot",
    "context": {},
    "status": "error",
    "error": {
      "code": "product-query.forward-failed",
      "message": "Product backend did not accept the query."
    }
  },
  "meta": {
    "source": "realtime",
    "source_module": "product-query-forwarder"
  }
}
```

Clients must still tolerate no response or stale responses. Product completion is async and product-owned.

## Project Settings

V1 settings live on `RealtimeProject.product_query_forwarding_settings`:

```json
{
  "enabled": true,
  "base_url": "https://hotline.pbb.ph",
  "path": "/api/internal/realtime/product-query",
  "auth_header": "X-Realtime-Backend-Secret",
  "auth_token": "<stored secret>",
  "allowed_event_types": ["product.query.request"],
  "allowed_queries": ["hotline.incident.snapshot"],
  "max_payload_bytes": 4096,
  "rate_limit_per_minute": 12,
  "connect_timeout_seconds": 3,
  "timeout_seconds": 8,
  "verify_tls": true
}
```
