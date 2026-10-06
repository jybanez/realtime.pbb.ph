# Offline validation sequencing proposal

Prepared in response to timeline 7985. This is source preparation, not permission to execute. Realtime Developer accepts the proposed sole executable-owner role; Hotline Developer reviews artifacts and the plan read-only, and Alfred coordinates explicit release of the existing execution hold. No parallel checks, live collectors, application calls, restarts, or deployment are included.

## Independent outer review first

Review `tools/supervise-diagnostic-review.py` at commit 52f160c5a7d51e9900276584ad8350c34e8a9966 independently of `tools/WindowsDiagnosticJob.cs`. Its Windows Job ownership precedes child resume; normal exit, timeout and exception paths attempt descendant cleanup, require verified empty job and completed/stopped output drains, and preserve capped raw output and fixed outcome evidence. Unknown cleanup or failed output returns 124 and stops the sequence. Source inspection is not ABI or containment validation.

Read-only command resolution found Python at `C:/Python312/python.exe` and PowerShell at `C:/Program Files/PowerShell/7/pwsh.exe`. Names and paths do not verify versions, architecture, provenance or compatibility. Before release, agree executable hashes and Windows/Python/PowerShell/.NET versions and architecture; intended target is Windows x64, CPython 3.12 x64 and PowerShell 7 x64 with its bundled Add-Type compiler. Actual identities remain unverified. No runtime identity probes have been launched.

From the exact reviewed checkout, proposed commands (EVIDENCE must be a new private directory for each phase):

```powershell
C:/Python312/python.exe tools/supervise-diagnostic-review.py --phase compile --powershell 'C:/Program Files/PowerShell/7/pwsh.exe' --evidence EVIDENCE_COMPILE
C:/Python312/python.exe tools/supervise-diagnostic-review.py --phase driver --powershell 'C:/Program Files/PowerShell/7/pwsh.exe' --evidence EVIDENCE_DRIVER
```

Run the second command only after confirmed zero exit and complete/verified first-phase evidence. Compile has a 30-second child ceiling; driver has a 20-second total child ceiling. Each outer phase allows one shared additional second for cleanup. The driver deliberately recompiles the exact C# source in its own fresh process with Add-Type: that compilation, PowerShell startup, all six serialized fixture cases, inner cleanup and evidence writes are included in its 20 seconds. The separate compile phase does not supply an assembly or exempt driver compilation. The six inner cases model up to 16 seconds including cleanup; compilation/startup overhead may exceed the remaining margin and must fail closed, never extend or automatically retry. Combined modeled outer ceiling is 52 seconds, excluding OS scheduling/native call and final evidence-disk latency; this is not a hard real-time guarantee.

Stop after any unexpected nonzero result, timeout, assertion failure, unresolved descendant cleanup, missing/failed evidence, or operator-observed host degradation. The incomplete-UTF8 fixture expects inner 124, which its driver validates; it does not permit outer 124 or later continuation. Preserve each case outcome/stdout/stderr before assertions. No automatic retries.

## Separate clean PHP dependency preparation, still gated

After outer validation, propose a new isolated checkout at the final reviewed source commit, with no copied vendor, junctions, class-map remapping, production environment file or cached bootstrap config. Its own composer.lock must remain unchanged. PHP is `C:/wamp64/bin/php/php8.2.29/php.exe`; its 8.2.29 version, x64 architecture, extensions and hash must be verified under an agreed bounded identity phase. Composer's resolved launcher is `C:/ProgramData/ComposerSetup/bin/composer.bat`; a specific verified composer.phar and hash must be selected before execution. Do not execute that launcher with an unspecified PHP runtime.

Proposed installation arguments for the verified PHP/composer.phar are `install --no-interaction --prefer-dist --no-progress --no-scripts --no-plugins`. Development dependencies are required. Install uses the checkout's lockfile, never update. Propose one externally contained 120-second installation ceiling plus one second cleanup; no parallel installs or automatic retry. Current independent watchdog restricts phases to compile/driver and does not yet implement this installation scope: a concrete restricted installation supervisor and configuration must be source-reviewed before release. No dependency installation is authorized by this plan. Scripts/package discovery, if required, need a separately bounded reviewed offline step; skipping scripts is not proof of usable Laravel bootstrap.

Offline configuration must force testing, array cache/session/mail, synchronous queue, SQLite :memory:, diagnostics/transport tracing and Maestro delivery disabled, and a nonsecret test-only application key. Verify config files, absence of cached production config, autoload App/Tests mappings, and reflection source paths from the isolated tree under containment before tests. Do not copy a production .env or use shared-vendor mappings as acceptance evidence.

Proposed first exact PHPUnit filter is `^(RealtimeDiagnosticEmitterTest|RealtimeDiagnosticRecordTest|RealtimeDiagnosticWriteResultTest|RealtimeLoopLagDiagnosticsTest|RealtimeCallbackDiagnosticsTest)$`, using that checkout's vendor/phpunit/phpunit/phpunit and phpunit.xml. One PHP child ceiling of 15 seconds, with compilation/startup/evidence included in a separately reviewed 20-second outer scope plus one second cleanup. A later gateway filter is not included until this stage succeeds and an exact method list and budget are reviewed. The existing offline-test wrapper alone is not an independent outer deadline. Restricted PHP/installation outer artifacts, runtime identities and configuration acceptance remain deliverables; no checks may start merely because this plan exists.
