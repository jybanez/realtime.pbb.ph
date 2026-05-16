# PBB Realtime Token And Auth Spec

## Purpose

Define the V1 token and authentication contract for `PBB Realtime`.

This spec defines:

- how PBB apps authenticate browser clients to `PBB Realtime`
- what a valid realtime access token should contain
- what `PBB Realtime` must validate before accepting a session
- what remains app-owned instead of gateway-owned

This spec does not define:

- the websocket event envelope
- the exact room/event payloads
- WebRTC SDP/ICE signaling payload details

Those belong in separate specs.

## Required Context

This spec assumes the future `PBB Realtime` team has already completed the onboarding requirements in:

- `docs/pbb-realtime-proposal.md`

That includes reading:

- `C:\wamp64\www\pbb\chat_log.md`
- `C:\wamp64\www\pbb\hub.ph\docs\pbb-helper-adoption-guide.md`
- `C:\wamp64\www\pbb\hub.ph\docs\pbb-laravel-application-baseline.md`

## Canonical Vocabulary

Use the following consistent V1 terms:

- `realtime access token`
  - the short-lived user-scoped browser token
- `issuer`
  - the approved app backend that signs or issues the token
- `session.connect`
  - capability allowing websocket session establishment
- `room.join`
  - capability allowing room membership requests
- `chat.publish`
  - capability allowing outbound chat publish
- `chat.subscribe`
  - capability allowing inbound chat events
- `presence.publish`
  - capability allowing presence/heartbeat updates
- `presence.subscribe`
  - capability allowing presence subscriptions
- `call.signal`
  - capability allowing call signaling messages

## Core Rule

Browser clients must not connect to `PBB Realtime` using a long-lived static project token.

The correct model is:

1. the app authenticates the user normally
2. the app backend issues a short-lived realtime access token for that user
3. the browser connects to `PBB Realtime` with that short-lived token

## Preferred V1 Trust Model

The preferred V1 trust model is explicit.

### Preferred Path

- approved PBB app backends issue short-lived signed realtime access tokens
- `PBB Realtime` validates those tokens against the approved issuer/signing configuration

This is the authoritative V1 path.

### Fallback Path

A server-to-server issuance flow where app backends request tokens from `PBB Realtime` is acceptable later if needed, but it should not be treated as the primary V1 model.

## Auth Model

### Actors

- `PBB Realtime`
  - shared gateway service
- `PBB App Backend`
  - app-owned backend that already knows the current user/session
- `Browser Client`
  - frontend runtime connecting to the realtime gateway

### Trust Boundary

`PBB Realtime` trusts:

- realtime access tokens signed or issued by an approved app-backend issuer

`PBB Realtime` must not trust:

- unsigned browser claims
- long-lived frontend-only secrets
- app-local authorization assumptions that are not represented in the token or verified server-side

## Recommended V1 Flow

### 1. App Session

The user authenticates into a PBB app using that app's normal auth/session flow.

### 2. Realtime Token Request

The app frontend requests a short-lived realtime access token from its own backend.

Suggested endpoint shape:

- `POST /api/realtime/token`

The exact app-local path is app-owned.

### 3. Backend Issues Token

The app backend issues a short-lived realtime access token for the current user using the approved issuer/signing configuration.

### 4. Browser Connects

The browser opens a websocket connection to `PBB Realtime` and presents the token during the connection handshake or immediately after connect, depending on the gateway implementation.

### 5. Gateway Validates

`PBB Realtime` validates:

- token signature or issuer trust
- expiry
- audience
- project/app identity
- user identity
- capability claims
- optional tenant/origin restrictions

### 6. Session Established

Only after validation succeeds may the socket session join rooms or receive realtime events.

## Token Shape

A token may be implemented as JWT or an equivalent signed token format.

V1 recommended claims:

- `iss`
  - approved issuer
- `sub`
  - stable user identifier
- `aud`
  - expected audience, e.g. `pbb-realtime`
- `exp`
  - expiry timestamp
- `iat`
  - issued-at timestamp
- `jti`
  - token identifier
- `project_code`
  - owning PBB project, e.g. `hq`, `workspace`, `relay`
- `app_code`
  - app identity within the project
- `user_id`
  - app-owned user id if separate from `sub`
- `email`
  - optional but useful human identity context
- `display_name`
  - optional UI context
- `roles`
  - app-issued role list
- `capabilities`
  - explicit realtime permissions

Optional claims:

- `tenant_id`
- `org_id`
- `workspace_id`
- `allowed_rooms`
- `allowed_room_prefixes`
- `origin`

## Capability Model

Capabilities should be explicit.

Recommended V1 capability examples:

- `session.connect`
- `room.join`
- `presence.publish`
- `presence.subscribe`
- `chat.publish`
- `chat.subscribe`
- `call.signal`
- `stream.publish`
- `stream.subscribe`

Do not rely on broad implied permissions.

The gateway should enforce capabilities directly.

## Example Token Claims

```json
{
  "iss": "hq.pbb.ph",
  "sub": "user_1024",
  "aud": "pbb-realtime",
  "exp": 1772302200,
  "iat": 1772301300,
  "jti": "rt_9f1bc1d4",
  "project_code": "hq",
  "app_code": "pbb-hq",
  "user_id": "1024",
  "email": "operator@pbb.ph",
  "display_name": "Operator One",
  "roles": ["administrator"],
  "capabilities": [
    "session.connect",
    "room.join",
    "presence.subscribe",
    "chat.publish",
    "chat.subscribe",
    "call.signal"
  ]
}
```

## Expiry And Renewal

Realtime access tokens should be short-lived.

Recommended V1 target:

- 5 to 15 minutes lifetime

The app frontend should refresh tokens through its own backend before expiry or reconnect with a newly issued token.

`PBB Realtime` should not rely on indefinite sessions backed by stale auth.

## Revocation

V1 minimum:

- short expiry
- socket disconnect on expired token
- revalidation on reconnect

Optional later improvements:

- server-side revocation list by `jti`
- forced disconnect by user/session/app
- org or tenant-scoped revocation

## Connection Authentication

Recommended connection models:

### Preferred

- websocket connection established
- client sends an explicit auth message with the realtime access token
- gateway responds with authenticated session state

### Acceptable

- token presented during websocket handshake via header/query/cookie

If query-string transport is used, the team must review logging exposure and operational risk carefully.

## Room Authorization Linkage

A valid token alone must not automatically allow joining any room.

`PBB Realtime` should enforce room access by combining:

- capability claims
- reserved room prefixes
- optional `allowed_rooms`
- optional `allowed_room_prefixes`
- project/app boundaries
- tenant/workspace boundaries where applicable

Room rules are standardized further in:

- `docs/pbb-realtime-room-and-presence-spec.md`

## Operational And Security Controls

V1 minimum:

- TLS for all gateway traffic
- signed short-lived tokens
- backend-only app credentials
- capability-based authorization
- per-app auth rate limits
- structured auth failure logs
- per-project/app auditing
- redaction of tokens and sensitive payloads from logs

Optional later improvements:

- per-user concurrent session caps
- adaptive abuse controls under load

## Failure Cases

The gateway must reject:

- expired tokens
- wrong audience
- missing project/app identity
- missing required capabilities
- invalid signature or issuer
- malformed claims
- unauthorized room join attempts

Suggested rejection codes:

- `auth.invalid-token`
- `auth.expired-token`
- `auth.invalid-audience`
- `auth.missing-capability`
- `auth.room-denied`

## Ownership Boundaries

`PBB Realtime` owns:

- token validation
- session authentication
- capability enforcement
- connection/session lifecycle

Each app owns:

- user authentication
- app-local role and authorization policy
- issuing realtime access tokens
- frontend reconnect/refresh strategy

## Acceptance Criteria

`PBB Realtime` token/auth V1 is acceptable when:

- browser clients use short-lived user-scoped realtime access tokens
- static frontend project secrets are not used for websocket access
- the gateway validates expiry, audience, project/app identity, and capabilities
- room joins are authorized server-side using room rules, not trust alone
- disconnect/reconnect behavior on expired auth is defined
- the preferred V1 issuer model is explicit
- app/backend ownership boundaries remain explicit
