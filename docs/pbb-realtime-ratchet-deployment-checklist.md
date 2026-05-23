# PBB Realtime Ratchet Deployment Checklist

## Purpose

Track the minimum production setup required to run the current Ratchet websocket transport reliably behind the Laravel application.

## Runtime Configuration

- [ ] Confirm `REALTIME_TOKEN_SIGNING_SECRET` is set in production
- [ ] Confirm `REALTIME_TRUSTED_ISSUERS` is set in production
- [ ] Confirm `REALTIME_TOKEN_AUDIENCE` matches the expected client audience
- [ ] Confirm `REALTIME_PUBLIC_WEBSOCKET_URL` points to the real public websocket endpoint
- [ ] Confirm browser clients receive valid realtime tokens; Ratchet route-level origin filtering is disabled and client domains should not be listed globally in `.env`
- [ ] Confirm `REALTIME_WS_HOST` and `REALTIME_WS_PORT` match the local Ratchet bind target

## Process Management

- [ ] Ensure `php artisan realtime:serve` runs as a supervised long-lived process
- [ ] For media-heavy deployments, set `REALTIME_EMBEDDED_MEDIA_CHUNK_DISPATCH_ENABLED=false` and supervise `php artisan realtime:dispatch` as a separate long-lived process
- [ ] Ensure the Ratchet process restarts on failure
- [ ] Ensure stdout/stderr or application logs are captured
- [ ] Ensure process startup ordering is documented for deployment

## Public Endpoint

- [ ] Expose a real websocket endpoint for `/realtime`
- [ ] Verify the public endpoint supports websocket upgrade, not just normal HTTP requests
- [ ] Verify the endpoint is reachable from the browser
- [ ] Verify TLS is configured correctly when using `wss://`

## Reverse Proxy

- [ ] Proxy websocket upgrade traffic from the public endpoint to the Ratchet process
- [ ] Preserve `Upgrade` and `Connection` headers correctly
- [ ] Preserve the request URI including `/realtime`
- [ ] Verify the token query string reaches Ratchet unchanged

## Browser Validation

- [ ] Open `/admin/sandbox`
- [ ] Issue a sandbox admission token successfully
- [ ] Confirm websocket connect succeeds
- [ ] Confirm room join succeeds
- [ ] Confirm presence publish succeeds
- [ ] Confirm chat publish succeeds

## Failure Checks

If sandbox connect fails, verify these first:

- [ ] `https://<host>/realtime` is actually a websocket endpoint and not a `404` HTML route
- [ ] the configured public websocket URL is not pointing to the wrong host/origin
- [ ] the Ratchet bind port is actually open on the server or correctly proxied
- [ ] the issued token validates against the current signing secret and trusted issuers

## Local Validation

- [ ] Run `php artisan realtime:serve`
- [ ] Set `REALTIME_PUBLIC_WEBSOCKET_URL=ws://127.0.0.1:8080/realtime`
- [ ] Confirm local sandbox connect succeeds before shipping proxy changes

## Node-Ready Discipline

Even while running Ratchet, keep the transport layer swappable later:

- [ ] Do not add downstream project business logic into Ratchet
- [ ] Keep the websocket envelope contract stable
- [ ] Keep token claims stable
- [ ] Keep admin-managed client/project/policy configuration as the source of truth

## Recommendation

Do not change runtime architecture and deployment architecture at the same time.

First make Ratchet work cleanly in production.

Only revisit a Node.js transport runtime after the current public websocket path, proxying, and sandbox validation are working correctly.
