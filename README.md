# PBB Realtime

PBB Realtime is the shared realtime gateway for the PBB ecosystem. It provides short-lived token admission, a Ratchet-based WebSocket transport, room and presence handling, backend-driven server event ingress, an operator/admin surface, installer tooling, and bundled SDK documentation for integrating product apps.

This repository is a Laravel 10 application, but it is not a generic Laravel starter. The default Laravel README was left in place and did not describe the service.

## What Realtime Owns

PBB Realtime sits between product backends and browser clients.

- Product backends authenticate their own users and issue short-lived realtime tokens.
- Browser clients use those tokens to request session admission and connect to the WebSocket gateway.
- Realtime enforces token validity, capability checks, room policy, publish limits, and presence lifecycle.
- Trusted backends can also push server-originated events into the gateway through the backend ingress API.

The current codebase includes:

- HTTP status and readiness endpoints
- session admission endpoint
- backend event publish ingress
- Ratchet WebSocket gateway at `/realtime`
- operator/admin APIs and browser UI
- public SDK docs and demo-doc references
- browser-based installer scaffold
- PHP backend SDK for issuing admission payloads
- usage telemetry and Maestro process telemetry integration

## Main Surfaces

### Public and operator HTTP routes

- `/` service landing/status shell
- `/api/health` basic health metadata
- `/api/ready` readiness with database connectivity
- `/api/metrics` transport and publish counters
- `/api/realtime/session` validates a short-lived realtime token and returns accepted session metadata
- `/api/v1/events/publish` trusted backend ingress for server-originated events
- `/api/docs` OpenAPI viewer shell
- `/sdk-docs` public SDK reference pages
- `/admin` operator console
- `/public/installer/` browser installer entrypoint

### WebSocket transport

Start the gateway with:

```bash
php artisan realtime:serve
```

By default it binds using the values in `config/realtime.php` and exposes the Ratchet route:

```text
/realtime
```

Relevant runtime settings include:

- `REALTIME_TOKEN_SIGNING_SECRET`
- `REALTIME_TRUSTED_ISSUERS`
- `REALTIME_PUBLIC_WEBSOCKET_URL`
- `REALTIME_WS_BIND_ADDRESS`
- `REALTIME_WS_PUBLIC_HOST`
- `REALTIME_WS_PORT`

Important runtime boundary:

- The Ratchet route-level origin allowlist is disabled intentionally. Browser admission is governed by signed realtime tokens, capabilities, room policy, and client/project policy instead of a global `.env` domain list.
- Room membership and presence rosters are held in memory inside the running `realtime:serve` process.
- Backend-published events and media chunk outcomes are persisted as pending database rows, then drained by the running gateway into connected rooms.
- If `realtime:serve` is stopped, HTTP event publish can still accept and queue work, but connected clients will not receive those events until a gateway process is running and draining.
- Multiple independent gateway processes do not automatically share in-memory room or presence state. Run one gateway process per served room namespace unless a future shared-state fanout layer is added.

### Admin surface

The operator/admin area manages:

- clients
- projects
- policies
- users
- sessions and audit views
- sandbox/presence inspection
- runtime telemetry settings
- SDK downloads and documentation access

### Installer

The installer scaffold under [`installer`](installer) and [`public/installer`](public/installer) already handles:

- environment draft state
- preflight checks
- `.env` writing
- `APP_KEY` generation
- database migrations
- initial operator bootstrap
- install report generation
- service artifact template generation for Windows and Linux
- Kit Setup-facing `release.json`, unattended CLI runner, status output, and config schema
- packaged acceptance validation through admin login, sandbox admission, websocket connect, room join, presence publish, and chat publish

Current installer limitation:

- host-specific reverse proxy, TLS, firewall, DNS, and service registration policy still require operator or Kit Setup completion when they cannot be safely applied by the app installer

## Architecture Summary

At a high level:

1. A product backend authenticates the user in its own domain.
2. The backend issues a short-lived realtime token or admission payload.
3. The browser calls `/api/realtime/session` and then connects to the WebSocket gateway.
4. The gateway validates capabilities such as `session.connect`, `room.join`, `presence.publish`, `chat.publish`, and `call.signal`.
5. Realtime tracks sessions, room membership, presence, usage telemetry, and backend-published events.

Detailed transport flow:

1. Product backend signs a JWT with `iss`, `aud`, `exp`, `jti`, `app_code`, `project_code`, `user_id`, capabilities, allowed rooms, and allowed room prefixes.
2. Browser may call `/api/realtime/session` to validate admission metadata before opening the socket.
3. Browser opens `REALTIME_PUBLIC_WEBSOCKET_URL`, usually `/realtime`, and authenticates either with a `token` query parameter or a `session.auth.request` envelope.
4. Browser sends `room.join.request` for an allowed room before publishing room-scoped messages.
5. Browser publishes supported envelopes such as `presence.publish`, `chat.message.publish`, `app.event.publish`, `call.signal.publish`, or `media.chunk.publish`.
6. Trusted product backends can publish server-originated events through `/api/v1/events/publish`; Realtime authorizes the client/project/room, queues the event, and the gateway drains it to current room members.
7. Usage buckets, session state, and audit entries are updated as side effects for operator visibility.

Binary media chunk V1 is additive to the existing base64 path:

1. Browser sends `media.chunk.prepare` with metadata, `transfer_id`, and `total_bytes`.
2. Realtime validates auth, room membership, project media-ingest config, binary enablement, and declared size.
3. Realtime replies with `delivery: "awaiting_binary"`.
4. Browser sends one binary WebSocket frame encoded as `PBBM` magic, version byte `1`, four-byte big-endian JSON header length, JSON header containing `transfer_id`, then raw chunk bytes.
5. Realtime validates the frame against the pending transfer, writes JSON spool metadata plus a `.bin` sidecar file, and emits sender-scoped `media.chunk.queued` with `delivery: "queued"`.
6. The existing dispatcher forwards the chunk downstream and emits unchanged `media.chunk.forwarded` / `media.chunk.failed` outcome events.

The transport contract is spec-driven. Key design assumptions in this repo:

- token audience defaults to `pbb-realtime`
- token TTL defaults to 15 minutes
- the WebSocket namespace is `pbb.realtime.v1`
- room naming is standardized, for example `presence.workspace.<id>` or `chat.thread.<id>`
- product business authorization stays in the product backend, not in the frontend SDK
- media chunk transport stays generic; downstream ingest routing is resolved from project configuration rather than being hard-coded to one product app
- binary media chunk transport is opt-in per project media-ingest setting; base64 `media.chunk.publish` remains the compatibility fallback

## Local Setup

### Requirements

- PHP 8.1+
- Composer
- MySQL or compatible database
- Node.js for frontend assets

### Bootstrap

```bash
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
```

Set the important realtime environment values in `.env` before using the admission or gateway flows:

```dotenv
REALTIME_TOKEN_SIGNING_SECRET=replace-me
REALTIME_TRUSTED_ISSUERS=local.pbb.test
REALTIME_PUBLIC_WEBSOCKET_URL=ws://localhost:8080/realtime
REALTIME_WS_BIND_ADDRESS=127.0.0.1
REALTIME_WS_PUBLIC_HOST=localhost
REALTIME_WS_PORT=8080
```

### Run locally

In separate terminals:

```bash
php artisan serve
php artisan realtime:serve
```

For higher websocket responsiveness under media-ingest load, run media forwarding in its own process and leave `realtime:serve` focused on websocket IO plus server-event fanout:

```dotenv
REALTIME_EMBEDDED_MEDIA_CHUNK_DISPATCH_ENABLED=false
```

```bash
php artisan realtime:serve
php artisan realtime:dispatch
```

The separate media dispatcher forwards pending `media.chunk.publish` rows to downstream ingest, then queues `media.chunk.forwarded` / `media.chunk.failed` server events. The websocket daemon still drains server events so connected clients receive those outcomes through the normal room fanout path.

Optional:

```bash
npm run dev
php artisan realtime:prune-usage-telemetry
```

### Runtime Performance Notes

`php artisan realtime:serve` uses the PHP CLI binary, not the WAMP Apache PHP runtime. If the CLI binary has Xdebug loaded, the websocket daemon and test suite can be significantly slower even when Xdebug is disabled for Apache.

Check the active CLI configuration with:

```bash
C:/wamp64/bin/php/php8.2.29/php.exe --ini
C:/wamp64/bin/php/php8.2.29/php.exe -m
```

For production or long-running local daemon testing, prefer a CLI PHP configuration without Xdebug loaded, then cache Laravel config/routes/views after `.env` is final:

```bash
C:/wamp64/bin/php/php8.2.29/php.exe artisan config:cache
C:/wamp64/bin/php/php8.2.29/php.exe artisan route:cache
C:/wamp64/bin/php/php8.2.29/php.exe artisan view:cache
```

## Testing

The repository includes feature and unit coverage for status, session admission, backend event publish ingress, admin flows, room policy, media chunk dispatch, backend SDK compatibility, and telemetry behavior.

Run the PHP test suite with:

```bash
php artisan test
```

On the local WAMP setup used for this project, run tests with the same PHP binary configured in VS Code:

```bash
C:/wamp64/bin/php/php8.2.29/php.exe artisan test
```

There is also a sandbox smoke script in [`tests/sandbox.shell.smoke.mjs`](tests/sandbox.shell.smoke.mjs).

## Documentation Map

The `docs/` directory is the main product and protocol reference. Start with:

- [`docs/pbb-realtime-proposal.md`](docs/pbb-realtime-proposal.md) for project scope and ecosystem context
- [`docs/pbb-realtime-token-and-auth-spec.md`](docs/pbb-realtime-token-and-auth-spec.md) for token trust and admission rules
- [`docs/pbb-realtime-room-and-presence-spec.md`](docs/pbb-realtime-room-and-presence-spec.md) for room naming and presence lifecycle
- [`docs/pbb-realtime-websocket-envelope-spec.md`](docs/pbb-realtime-websocket-envelope-spec.md) for the transport envelope
- [`docs/pbb-realtime-backend-sdk-quickstart.md`](docs/pbb-realtime-backend-sdk-quickstart.md) for backend admission issuance
- [`docs/pbb-realtime-installer-quickstart.md`](docs/pbb-realtime-installer-quickstart.md) for installer flow

Related repo areas:

- [`sdk/php`](sdk/php) plain-PHP backend SDK
- [`installer`](installer) installer scaffold and templates
- [`config/realtime.php`](config/realtime.php) runtime configuration defaults
- [`routes/web.php`](routes/web.php) and [`routes/api.php`](routes/api.php) HTTP surface

## Current State

Implemented in code:

- Laravel app shell and operator UI
- WebSocket gateway command
- session token validation endpoint
- backend event ingress endpoint
- telemetry persistence and process heartbeat support
- public SDK docs browser surface
- installer scaffold
- PHP backend SDK

Still called out in docs as partial or pending:

- full installer-driven websocket validation
- automatic host service registration during install
- broader end-to-end operator validation beyond the current scaffold

## Notes For Contributors

- Treat `docs/` as a source of truth for protocol and product contracts.
- Keep README updates aligned with actual routes, commands, and installer behavior.
- Do not describe this repo as a generic Laravel app; it is a PBB platform service with Laravel as the host framework.
