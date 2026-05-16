# PBB Realtime Admin Surface Proposal

## Purpose

Define the recommended administrative and operational surfaces for `PBB Realtime` as a shared platform service.

This document is intentionally separate from the core `PBB Realtime` gateway, token, websocket, and room specs.

That separation is important because:

- transport contracts should remain stable and implementation-focused
- platform administration is a distinct operator-facing concern
- the future `PBB Realtime` team may phase admin surfaces independently from transport/runtime rollout

## Deployment Boundary

The deployment boundary should be explicit.

Recommended V1 direction:

- the admin surface should be a private operator/admin surface owned by the `PBB Realtime` project
- it may be implemented as a private route set inside the `PBB Realtime` repo/runtime
- or as a closely coupled private admin app owned by the same team

What matters is the boundary, not the exact Laravel/app packaging choice.

The important rule is:

- this is a private operator-facing surface for `PBB Realtime`
- not a public client-facing app surface
- not part of the websocket transport contract itself

So this proposal is not describing a mandatory public browser app.

It is describing a private platform-admin surface that the Realtime team may host inside the project or beside it.

## Admin Surface Access-Control Model

The admin surface should not leave authentication and authorization open to interpretation.

Recommended V1 protection model:

- use normal authenticated browser-app access inside the `PBB Realtime` project or its private admin companion surface
- protect the admin surface with session-authenticated operator/admin accounts
- enforce role or capability checks inside the `PBB Realtime` admin surface itself
- optionally add network allowlisting as defense in depth only

V1 should not rely primarily on:

- network-only protection without app authentication
- implicit reuse of `PBB HQ` authentication unless explicitly designed later
- a separate operator identity system unless the Realtime team intentionally chooses to build one

### Why This Is The Right Default

This keeps the admin surface aligned with existing PBB browser-app conventions instead of inventing a separate protection model too early.

The most relevant existing HQ references are:

- `C:\wamp64\www\pbb\hub.ph\docs\login-logout-flow-reference.md`
- `C:\wamp64\www\pbb\hub.ph\docs\pbb-user-session-handling-proposal.md`
- `C:\wamp64\www\pbb\hub.ph\docs\pbb-user-session-keepalive-proposal.md`
- `C:\wamp64\www\pbb\hub.ph\docs\pbb-laravel-application-baseline.md`
- `C:\wamp64\www\pbb\hub.ph\docs\pbb-helper-adoption-guide.md`

These documents already establish the expected baseline for:

- session-authenticated browser access
- login/logout handling
- session expiry and re-auth
- keepalive for long-lived operator pages
- helper-aligned authenticated app surfaces

### Practical V1 Interpretation

The recommended V1 model is:

- `PBB Realtime` admin is a private session-authenticated browser surface
- only authorized Realtime operators/admins may access it
- authorization is enforced inside the Realtime app by role/capability policy
- network restrictions may be added, but they are not the primary auth model

## Why `PBB Realtime` Needs An Admin Surface

`PBB Realtime` is not just another app feature.

It is a shared platform dependency used by multiple PBB projects.

That means the platform will need operator-facing surfaces for:

- client registration and trust policy metadata
- capability and room policy management
- session and room visibility
- audit and security review
- operational controls during incidents

Without an admin surface, those concerns drift into:

- ad hoc database edits
- environment-only policy changes
- poor visibility during failures
- inconsistent incident response

## Trust-Material Boundary

The admin surface proposal must be strict about trust material.

Recommended V1 rule:

- the admin surface may manage trust metadata and policy
- the admin surface must not be treated as the canonical place for managing raw signing keys or shared secret material unless the Realtime team intentionally designs a separate secure key-management workflow later

That means V1 admin surfaces may manage:

- issuer identity metadata
- which issuer is active for a client
- token issuance mode metadata
- allow/deny status
- origin policy
- capability policy

But V1 admin surfaces should not be assumed to manage:

- raw private keys
- raw signing secrets
- arbitrary secret rotation workflows
- browser-visible secret distribution

If the team later wants admin-managed key material, that should be a separate security-focused design decision, not an implicit assumption inside `Client Management`.

## Administrative Areas

## 1. Client Management

This should be the first required admin area.

Manage:

- registered PBB clients/apps
- project ownership
- issuer/trust metadata
- allowed origins
- active/inactive status
- token issuance mode metadata
- integration notes or ownership metadata

Why it matters:

- `PBB Realtime` must know which clients are allowed to use the gateway
- operator teams need a supported way to onboard or disable a client

Important boundary:

- `Client Management` is for client metadata and policy references
- it is not a V1 key-management console

## 2. Capability And Policy Management

Manage:

- allowed capabilities per client
- room prefix policies
- session/connect limits
- per-app rate limits
- abuse controls
- optional default policy templates

Why it matters:

- this is where the platform's authorization and protection posture becomes enforceable

## 3. Session And Connection Monitoring

View:

- active websocket sessions
- connected users by client/project
- session counts
- disconnect reasons
- stale or orphaned sessions
- connection trends at a baseline level

Why it matters:

- operators need a supported way to understand current gateway activity and health

## 4. Rooms And Presence Diagnostics

View:

- room membership
- room join denials
- room churn
- presence publish volume
- stale presence transitions
- room-level diagnostics during incidents

Why it matters:

- many realtime integration issues are room or presence issues, not transport issues

## 5. Audit And Security

View/manage:

- auth failures
- invalid token attempts
- origin violations
- rate-limit hits
- policy changes
- client disable/enable history
- operator audit trail

Why it matters:

- platform services need traceability and security review surfaces

## 6. Operational Controls

Manage:

- enable/disable client access
- force disconnect by session/user/client if needed
- emergency revoke or quarantine controls
- maintenance/incident controls later if needed
- gateway health summary

Why it matters:

- the team needs controlled response mechanisms during incidents, not only passive dashboards

## Recommended V1 Admin Scope

The first practical admin wave should stay narrow.

Recommended V1 surfaces:

- `Clients`
- `Policies`
- `Sessions`
- `Audit`

These four are the minimum useful set for a platform rollout.

Recommended early follow-on surfaces:

- `Rooms & Presence`
- `Operations`

## Recommended V1 Admin Navigation

Suggested top-level admin navigation:

- `Clients`
- `Policies`
- `Sessions`
- `Audit`
- `Rooms & Presence`
- `Operations`

This is a clean platform-admin structure and matches the actual operational responsibilities of a realtime gateway.

## What Should Stay Out Of V1

Do not overload the first admin wave with unrelated platform ambitions.

Keep out of early scope:

- chat-content moderation consoles
- full analytics warehouse dashboards
- billing/chargeback features
- full media-server administration
- broad tenant CRM-style management
- implicit secret/key-management workflows hidden inside client-management screens

Those are separate products or later concerns.

## Ownership Boundaries

The `PBB Realtime` admin surface should own:

- platform client registration metadata
- platform policy visibility and editing
- session/room/audit diagnostics
- operational control actions

It should not own:

- app-local user management
- app business records
- app chat content governance unless explicitly added later
- media transport infrastructure administration in V1
- implicit signing-key or secret custody in V1

## Likely Early Users Of The Admin Surface

Expected early operator/admin users:

- `PBB Realtime` platform team
- authorized platform operators
- possibly limited project leads during integration rollout

Expected coordination-only stakeholders:

- `PBB HQ`
- `PBB Workspace`
- `PBB Helper`
- other teams needing visibility into onboarding status or incidents

## Suggested Follow-On Docs For The New Team

After accepting this direction, the future `PBB Realtime` team should draft:

1. `PBB Realtime Client Management Spec`
2. `PBB Realtime Policy And Capability Management Spec`
3. `PBB Realtime Session And Audit Surface Spec`
4. `PBB Realtime Operations Console Checklist`
5. a separate security/key-management memo if the team later wants admin-managed trust material

## Bottom Line

`PBB Realtime` should have a dedicated private admin surface.

The correct first-wave platform areas are:

- clients
- policies
- sessions
- audit

with rooms/presence diagnostics and operational controls following immediately after the base platform surfaces are stable.

That admin surface should manage metadata, policy, visibility, and operations.

It should not be assumed to manage raw signing keys or secret material in V1.
