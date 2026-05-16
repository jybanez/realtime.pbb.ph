# PBB Realtime Sandbox Demo Proposal

## Purpose

Define a `PBB Realtime` owned sandbox demo that showcases the platform's transport features using the shared `helpers.pbb.ph` UI components.

The sandbox should let operators and integrators test their own admin-managed client, project-scope, and policy setup inside the Realtime environment before comparing it with behavior in their own downstream project.

## Problem Statement

`PBB Realtime` already has:

- client trust management
- project-scope management
- policy management
- session visibility
- audit visibility

What it does not yet have is a first-party interactive surface that proves the transport contract in a simple, controlled environment.

Without that sandbox:

- integrators must validate transport behavior inside their own project first
- it is harder to isolate whether a problem belongs to Realtime or to the downstream app
- the platform has no self-owned showcase for chat, rooms, presence, and connection lifecycle behavior

## Proposal

Add a Realtime-owned sandbox demo page that uses:

- static browser HTML and JavaScript
- the vendored `helpers.pbb.ph` chat components
- `PBB Realtime` websocket transport
- admin-managed client, project-scope, and policy configuration as selectable sandbox inputs

The sandbox should be treated as:

- a platform showcase
- a transport validation harness
- a reference integration surface

It should not be treated as:

- a production chat application
- a replacement for downstream app UX
- a place to hardcode demo-only trust bypasses

## Primary Goals

The sandbox should prove:

- token admission works
- room join and leave work
- presence updates work
- chat publish and receive work
- reconnect behavior is observable
- session expiry and re-auth behavior can be tested
- project-scope and policy choices in admin produce visible transport differences

## Core User Experience

The demo should feel like a sandbox, not a single canned chat room.

The operator should be able to choose or enter:

- client
- project scope
- room name
- user identity or display name
- optional user role label for testing

The page should then use that configuration to:

- fetch or request demo session admission
- connect to the realtime websocket
- join the chosen room
- send and receive messages
- inspect raw transport state

## Sandbox Model

The sandbox should be explicitly owned by `PBB Realtime`.

That means:

- the route and page live in this repo
- the page uses Realtime-admin-managed configuration as its source of truth
- the page demonstrates the Realtime contract directly

The sandbox should help teams answer:

- does this client and project-scope combination admit correctly?
- does this policy allow the transport behavior we expect?
- does the issue reproduce inside Realtime before we test the downstream app?

## Recommended UX Structure

Use the helper library for the full UI shell.

Recommended layout:

1. Sandbox header
- title
- short explanatory text
- active connection status

2. Configuration panel
- client selector
- project-scope selector filtered by selected client
- room input
- identity/display-name input
- connect / reconnect / disconnect actions

3. Chat surface
- `ui.chat.thread`
- `ui.chat.composer`
- optional `ui.chat.upload.queue`

4. Transport inspector
- websocket status
- joined rooms
- last outbound event
- last inbound event
- recent errors

5. Session/Policy summary
- selected client code
- selected project code
- selected policy profile
- selected token audience/issuer details where appropriate

## Helper Library Requirement

The sandbox should use the current vendored helper library copy.

Specifically, the demo should build on:

- `ui.chat.thread`
- `ui.chat.composer`
- `ui.chat.upload.queue`

This keeps the sandbox aligned with the current PBB UI layer and avoids introducing a separate ad hoc demo UI.

## Data And Trust Boundaries

The sandbox should not hardcode raw trust values into the frontend.

Recommended pattern:

- the page loads client and project-scope options from Realtime admin/browser-data endpoints
- the page requests demo admission from a narrow backend endpoint in this repo
- the backend issues or returns a transport-ready token according to the selected client and project scope

This keeps the trust path realistic while still making the sandbox usable.

## Recommended Demo Scope For V1

Keep the first release intentionally narrow.

V1 should cover:

- connection state
- room join/leave
- presence
- text chat
- upload queue UI state
- raw event visibility
- sandbox-only chunked attachment transport for comparison and debugging

V1 should not yet cover:

- production-grade media workflows
- incident/dispatch business logic
- complex multi-room orchestration
- downstream project-specific persistence logic

## Relationship To Admin

The sandbox should use admin-managed data rather than a separate demo-only configuration system.

That means:

- clients created in admin can appear in the sandbox
- project scopes created under a client can appear in the sandbox
- policy choices visible in admin can be reflected in the sandbox context

Attachment note:

- if the sandbox demonstrates chunked attachment transfer, that behavior remains sandbox-only
- production downstream projects still own attachment ingestion, storage, and durable media handling

This is important because the sandbox is meant to help teams compare:

- their setup inside Realtime
- their setup inside their own project

## Operational Benefits

This sandbox would give `PBB Realtime` a platform-owned proving ground.

Benefits:

- faster transport debugging
- clearer integrator onboarding
- less confusion about whether a bug belongs to Realtime or a downstream app
- a reusable showcase for new transport capabilities
- a stable internal reference app for future features

## Recommendation

Proceed with a Realtime-owned sandbox demo.

Build it as a helper-library-based transport sandbox, not as a product chat UI.

Use admin-managed client and project-scope data as the sandbox's selectable context so teams can validate the exact configuration they created inside Realtime.

## Runtime Note

The current recommended runtime model is:

- Laravel control plane
- Ratchet websocket transport

See:

- `docs/pbb-realtime-transport-runtime-architecture-note.md`
- `docs/pbb-realtime-ratchet-deployment-checklist.md`
