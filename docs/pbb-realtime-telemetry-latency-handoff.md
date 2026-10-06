# Gateway telemetry isolation and callback latency

## Incident evidence

Hotline incident 43/session 61 on 2026-10-06 (times below UTC):

- Citizen reported socket connect start at 14:58:03.890 and auth acknowledgement at 14:59:44.832: 100.942 seconds.
- A discovery event published at 14:58:04.143 arrived at 14:58:56.684: 52.541 seconds.
- Local Realtime logs show another citizen session accepted at 14:58:56, then the call session accepted and joined at 14:59:44. These are distinct token/session IDs, not evidence that the same socket spent the whole interval inside authentication.
- At 14:59:25 Maestro heartbeat HTTP timed out after 20.016 seconds. It connected to loopback in 729 ms, then received zero response bytes. The majority of this measured request was waiting for Maestro's HTTP response, not WebSocket network transport.

The old process heartbeat calls BOTH workers/heartbeat and worker-events synchronously, every tick; startup also sends both. Successful slow requests did not generate warning logs. These calls run on the same event loop that accepts sockets and emits signaling. One timeout proves one blocking interval, not the full 100.942-second root cause. Existing logs lack per-callback start/end, event-loop lag, and reverse-proxy upgrade timestamps, so the remaining delay cannot be attributed confidently.

## Blocking dependency trace

1. Ratchet HTTP upgrade invokes gateway onOpen. Anything before onOpen (proxy, TCP/TLS, accept backlog, previously blocked loop) is outside gateway callback duration.
2. JWT verification is local CPU work. Issuer validation synchronously looks up the client in SQL. Signature, audience, expiry, issuer and session.connect checks stay unchanged.
3. authenticateConnection writes realtime_sessions via updateOrCreate BEFORE sending auth ACK. The existing session accepted log precedes this write; it never proved the ACK was already sent.
4. Auth success/failure usage telemetry synchronously creates/updates SQL usage buckets. Success recording happens after the ACK call but may delay socket flushing or other clients.
5. Room authorization reads signed capabilities/room grants in memory. Room join then synchronously touches session state and usage buckets before ACK. Fanout walks in-memory connections by room name.
6. Presence, signaling, and other publishes include synchronous session/usage writes and local logging. Metrics increments also read/write the configured cache (local files by default, or a remote cache if configured). These remain measurable dependencies, not automatically isolated by this change.
7. Event drain periodically reads pending SQL events, broadcasts, then saves status and writes usage/audit records. Embedded media dispatch may also perform downstream synchronous HTTP. Keep media in its existing separate realtime:dispatch service under load.
8. Product-query forwarding performs synchronous configured backend HTTP in the gateway (up to its configured timeout); this remains a separate potential blocker.
9. Maestro settings previously performed repeated synchronous schema checks, DB reads and decryptions on each heartbeat in addition to the two HTTP sends.

## Change and reliability limits

realtime:serve now resolves Maestro producer settings once before running, and writes best-effort local snapshots. ONLY realtime:dispatch-telemetry sends those snapshots to Maestro, in another PHP process. Producer app code/enabled settings require a gateway restart; delivery credentials, target, TLS and other settings are read fresh by the worker. Disabling Maestro at delivery drops snapshots rather than sending them.

The spool uses 128 fixed slots (up to 32 KiB payload each), non-waiting exclusive locks, and latest-wins replacement. Collisions can drop an older snapshot or lifecycle event; this is monitoring, not an authoritative audit queue. Records older than 60 seconds are discarded. The worker consumes before HTTP and never automatically retries uncertain outcomes. A crash may lose the current snapshot. Credentials are resolved only in the worker and never written to spool records. Store the directory outside the web root on local disk with access restricted to the service account; local filesystem and log I/O are still synchronous.

Telemetry HTTP is capped at 3-second connection and 5-second total timeouts, preserving smaller configured values, current token header, loopback behavior, and TLS verification. A slow worker can drop snapshots; it cannot block gateway network I/O. Running no telemetry worker leaves the bounded spool collecting latest snapshots, but Maestro updates stop.

No admission checks were removed or moved after ACK. No Hotline code, DNS, proxy routing, recording, or terminal reconciliation changed.

## Timing diagnostics

Set REALTIME_GATEWAY_TIMING_ENABLED=true temporarily, refresh config cache and restart the gateway. Callback logs include UTC received_at, pid, connection_id, session_id, stage and elapsed_ms for socket.open, token.validate, session.persist, room.authorize and socket.message. SQL timings include pid, operation and elapsed_ms; SQL text and bindings are excluded. Callbacks taking >=1 second are logged even with verbose diagnostics off. A 1-second loop probe logs lag >=1 second independently of Maestro.

Correlate browser connect/open/auth/room ACK timestamps with proxy upgrade timing and gateway socket.open. Large pre-onOpen delay plus loop lag points to event-loop queueing; a long token.validate or session.persist identifies synchronous authorization/persistence work. Fast server callbacks with delayed browser reception require socket flush/proxy/network measurements. These diagnostics alone cannot measure when a queued WebSocket reached the OS or prove one-way transport latency. Keep clocks synchronized and do not infer ICE values from collapsed client log objects.

## Service and deployment handoff

This change is for review, not a live deployment. The operator should:

1. Deploy the reviewed commit into the Realtime application; no new Composer dependency or database migration is needed.
2. Provision a service under the SAME application account, PHP CLI/config, working directory, .env and APP_KEY as the gateway, running `php artisan realtime:dispatch-telemetry`. Use systemd/Supervisor on Linux or the existing hidden Windows service/task convention. Give it restart-on-failure and write access to storage/app/realtime-telemetry and storage/logs. Run one consumer for this local spool.
3. Run `php artisan config:cache` after choosing diagnostics and existing Maestro settings. Start the telemetry worker before restarting the gateway. Smoke-check one-shot drain with `php artisan realtime:dispatch-telemetry --once` when the continuous worker is stopped.
4. Restart the actual managed `realtime:serve` process so it loads the new code (cached config alone does not update a daemon). Expect connected users to reconnect; coordinate a maintenance window. Do not spawn a duplicate gateway on the served namespace/port.
5. Keep `REALTIME_EMBEDDED_MEDIA_CHUNK_DISPATCH_ENABLED=false` with the existing `php artisan realtime:dispatch` service where media isolation is already configured. This document does not authorize changing that live setting or registering services automatically.
6. Verify fresh browser auth ACK, room join and offer/answer signaling while Maestro is unavailable or deliberately slow; confirm loop lag and callback timing, plus worker warnings and Maestro freshness. Verify rejected tokens and unauthorized rooms remain rejected.
7. Repeat the Hotline callback live check with browser, proxy and gateway timestamps. Recording completeness and missed/late/already-ended reconciliation remain separate acceptance checks.
8. Turn verbose timings off and refresh cache/restart after evidence collection. For rollback, deploy the previous reviewed version and restart the gateway; stop the new worker. The old version restores synchronous telemetry, so account for its latency risk.

## Regression evidence scope

Automated coverage exercises producer lifecycle without any HTTP, bounded growth with no worker, non-waiting lock contention, stale/corrupt drops, credential rotation at delivery, and uncertain-outcome consumption without replay. A separate PHP process holds the real telemetry delivery path for two seconds, once with a slow success and once with a simulated connection timeout; gateway authentication, room joins and call signaling complete within 750 ms while that worker is still waiting. This verifies process isolation with mocked downstream HTTP and in-process gateway connections, not production reverse-proxy performance or resolution of every incident delay.
