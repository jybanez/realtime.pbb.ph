# PBB Realtime Transport Runtime Architecture Note

## Current Direction

`PBB Realtime` should currently run with:

- Laravel as the control plane
- Ratchet/PHP as the websocket transport runtime

This is the best compromise for the current repo state because it preserves the work already completed while keeping a clean path for a future Node.js transport runtime if scale later demands it.

## Boundary

### Laravel Owns

- admin surface
- client management
- client-owned project-scope management
- policy management
- sandbox context and admission
- token issuance rules
- session preflight APIs
- specs, docs, and configuration APIs

### Ratchet Owns

- `/realtime` websocket transport
- session auth at websocket open or `session.auth.request`
- room join and leave
- presence subscribe and publish
- chat message publish and event fanout
- call signaling fanout
- in-memory room membership and presence state

## Why This Is The Right Short-Term Architecture

- It avoids rewriting the existing gateway while the admin/data model is still stabilizing.
- It keeps the current sandbox, admin, and token work usable.
- It lets the team validate the realtime contract before changing the transport runtime.
- It minimizes near-term refactoring while still keeping the websocket boundary explicit.

## Why This Is Node-Ready Later

The long-term migration path should not be "rewrite Realtime". It should be "swap the websocket transport runtime".

That is only safe if the following remain stable:

- JWT/token claims contract
- websocket envelope contract
- room naming and authorization rules
- admin-managed client/project/policy source of truth
- sandbox admission inputs and outputs

If those remain stable, a future Node.js websocket server can replace Ratchet without invalidating the rest of the repo.

## Stable Contract To Preserve

The following should be treated as the transport contract that must survive any runtime swap:

- `docs/pbb-realtime-websocket-envelope-spec.md`
- `docs/pbb-realtime-token-and-auth-spec.md`
- `docs/pbb-realtime-room-and-presence-spec.md`

And the current transport capabilities:

- `session.connect`
- `room.join`
- `presence.subscribe`
- `presence.publish`
- `chat.publish`
- `call.signal`

## Operational Rule

Do not let project-specific business logic accumulate inside Ratchet.

Ratchet should remain a shared transport layer only:

- transport
- signaling
- fanout
- session state

It should not become the owner of downstream project workflows, persistence rules, or custom domain logic.

## Public Websocket Endpoint

The browser must connect to an explicit public websocket URL.

Use:

- `REALTIME_PUBLIC_WEBSOCKET_URL`

This should point to the public websocket endpoint that proxies or exposes the Ratchet server.

Examples:

- `wss://realtime.pbb.ph/realtime`
- `wss://ws.realtime.pbb.ph/realtime`
- `ws://127.0.0.1:8080/realtime` for local testing

## Current Constraint

The sandbox can only connect if the deployment exposes a reachable websocket endpoint.

If `https://realtime.pbb.ph/realtime` is only an HTTP route and not a websocket upgrade endpoint, sandbox connection will fail even if token issuance is correct.

## Migration Trigger For Node.js Later

Only revisit a Node.js transport runtime if one or more of these become true:

- sustained high websocket concurrency
- Ratchet process management becomes operationally painful
- multi-instance fanout requires a more deliberate event backbone
- transport performance becomes the bottleneck rather than admin/configuration logic

## Recommendation

Keep the current architecture:

- Laravel control plane
- Ratchet transport runtime

Strengthen deployment and contract discipline now.

If scale later requires Node.js, replace only the websocket runtime and keep the rest of the system stable.
