# PBB Realtime Sandbox Demo Implementation Checklist

## Purpose

Track implementation of the Realtime-owned sandbox demo that uses the helper library chat components and admin-managed client/project-scope configuration.

## Phase 1 - Helper And Asset Baseline

- [x] Verify the vendored `helpers.pbb.ph` copy includes:
  - `ui.chat.thread`
  - `ui.chat.composer`
  - `ui.chat.upload.queue`
- [x] Keep the local vendor copy aligned with the official helper repository revision used for the sandbox
- [x] Confirm the sandbox shell loads helper assets through the same vendored path strategy used by the admin surface

## Phase 2 - Sandbox Route And Page Shell

- [x] Add a dedicated Realtime sandbox route and mount view
- [x] Add a browser-rendered sandbox shell in `resources/js/app.js` or a dedicated sandbox entrypoint
- [x] Keep the page JS-driven and helper-owned, consistent with the PBB browser-app pattern
- [x] Make the sandbox a Realtime-owned feature surface, not a downstream app stub

## Phase 3 - Sandbox Data Sources

- [x] Expose client options for sandbox use
- [x] Expose project-scope options filtered by selected client
- [x] Expose policy/profile summary metadata needed for sandbox display
- [x] Ensure the sandbox reads admin-managed data instead of a separate hardcoded demo configuration

## Phase 4 - Demo Admission And Transport

- [x] Add a narrow backend endpoint that prepares demo transport admission
- [x] Avoid hardcoding raw tokens or trust material in the static frontend
- [x] Use the selected client and project-scope context to request demo admission
- [x] Connect the sandbox to the realtime websocket transport using the same contract expected by downstream projects

## Phase 5 - Sandbox UX

- [x] Add a configuration panel for:
  - client
  - project scope
  - room name
  - display name / identity
- [x] Add a transport status panel for:
  - connection state
  - joined room
  - reconnect activity
  - latest transport error
- [x] Add a chat surface using:
  - `ui.chat.thread`
  - `ui.chat.composer`
- [x] Add upload-queue presentation using `ui.chat.upload.queue` where appropriate for UI-state testing

## Phase 6 - Debug And Comparison Value

- [x] Show selected client/project/policy context clearly in the sandbox
- [x] Show recent inbound and outbound transport events for debugging
- [x] Make it obvious that the sandbox is intended for comparing Realtime behavior against downstream project behavior
- [x] Keep the sandbox useful even when the downstream project is not yet implemented

## Phase 7 - Tests And Docs

- [x] Add feature tests for sandbox page access and demo-admission endpoints
- [x] Add basic browser/runtime verification for configuration loading and chat shell mounting
- [x] Document how the sandbox uses admin-managed data
- [x] Document what the sandbox proves and what remains the downstream project's responsibility

## Current V1 Status

- The sandbox now supports:
  - admin-managed client and project-scope selection
  - real transport token admission
  - token preflight through `/api/realtime/session`
  - websocket connect / room join
  - presence subscribe / publish
  - helper-owned chat thread, composer, and upload queue
  - inbound / outbound event inspection
- Sandbox attachment behavior is now explicit:
  - chunked attachment transfer is demo-only inside the sandbox
  - upload queue shows transfer progress/state
  - non-image files are transported as file attachments for comparison testing
  - downstream projects still own production ingestion and persistence
- Phase 7 is now covered by feature tests plus a browser/runtime smoke check.
- Added browser/runtime smoke verification:
  - `public/tests/sandbox.shell.smoke.html`
  - `tests/sandbox.shell.smoke.mjs`
  - `npm run test:sandbox-smoke`
- Current deployment/runtime reference docs:
  - `docs/pbb-realtime-transport-runtime-architecture-note.md`
  - `docs/pbb-realtime-ratchet-deployment-checklist.md`
