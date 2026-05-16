# PBB Realtime Backend SDK Proposal

## Goal

Provide a small backend-side SDK that PBB projects can add to their own PHP codebases to prepare safe Realtime admission for their frontend clients.

This backend SDK should make `PBB Realtime` the owner of:

- token claim shape
- admission payload shape
- policy-safe transport behavior contracts
- room normalization rules
- signing and validation helpers

It should not own product-specific business decisions.

## Recommended Form

Yes: the first version should be a simple plain-PHP library.

That means:

- no Laravel-only assumptions
- no framework container requirements
- no ORM dependency
- no mandatory Composer package split on day one

The safest first version is:

- a small PHP library folder
- importable by other PBB projects
- focused on claim building, signing, and admission payload generation

This is the right tradeoff because the immediate need is portability across PBB projects, not framework sophistication.

## Why A Backend SDK Is Needed

Without a shared backend SDK, every PBB project will re-implement:

- JWT claim building
- issuer and audience rules
- token expiry defaults
- project-scope claim mapping
- room normalization
- attachment policy claim shape
- call-room admission payloads

That creates predictable problems:

- inconsistent claims
- policy drift
- broken trust assumptions
- duplicated bugs
- harder upgrades when Realtime changes

The backend SDK should eliminate that drift.

## Ownership Boundary

### Backend SDK Should Own

- token claim normalization
- token signing helpers
- admission payload builders
- room normalization helpers
- attachment policy claim helpers
- conference admission helpers
- trusted claim defaults for Realtime

### Product Backends Should Own

- user authentication
- operator assignment
- who may join which business workflow
- case and ticket state
- product-specific room naming intent
- persistence and auditing in the product domain

The key rule remains:

- Realtime owns transport contracts
- product backends own business authorization decisions

## Core Flow

The intended flow is:

1. product frontend calls its own backend
2. product backend authenticates the user and resolves business context
3. product backend decides the correct Realtime client and project scope
4. product backend uses the backend SDK to build and sign the Realtime token
5. product backend returns:
   - `token`
   - `websocket_url`
   - session metadata
6. product frontend passes that into the frontend SDK
7. Realtime validates the signed token and enforces its claims

This means the backend SDK is the trust bridge between the product system and Realtime.

## Recommended Backend SDK Shape

Start with a small set of plain-PHP files or classes.

Recommended internal structure:

- `RealtimeAdmission.php`
- `RealtimeTokenBuilder.php`
- `RealtimeClaimNormalizer.php`
- `RealtimeRoomHelper.php`
- `RealtimePolicyHelper.php`
- `RealtimeConferenceHelper.php`
- `RealtimeConfig.php`

If a single-file entry point is preferred by consuming teams, provide:

- `pbb_realtime_backend_sdk.php`

That entry point can require the internal files and expose the main classes.

## Recommended Public Surface

### `RealtimeConfig`

Responsibilities:

- hold issuer
- hold audience
- hold signing secret
- hold websocket URL
- hold default token TTL

### `RealtimeTokenBuilder`

Responsibilities:

- build Realtime-compatible claims
- sign JWTs
- apply required defaults

Suggested surface:

- `forChatSession(array $context): array`
- `forConferenceSession(array $context): array`
- `sign(array $claims): string`
- `buildSignedToken(array $claims): string`

### `RealtimeClaimNormalizer`

Responsibilities:

- enforce required claim fields
- normalize capabilities
- normalize allowed rooms and prefixes
- normalize attachment policy structure

Suggested surface:

- `normalizeClaims(array $claims): array`
- `normalizeCapabilities(array $capabilities): array`
- `normalizeAttachmentPolicy(array $policy): array`

### `RealtimeRoomHelper`

Responsibilities:

- normalize room names
- derive transport room names
- derive call room names

Suggested surface:

- `normalizeChatRoom(string $room): string`
- `buildCallRoomFromChatRoom(string $room): string`

### `RealtimeAdmission`

Responsibilities:

- produce the final frontend-facing admission payload

Suggested surface:

- `buildAdmission(array $context): array`

Expected result:

- `token`
- `websocket_url`
- `project_code`
- `app_code`
- `room`
- optional display/session metadata

### `RealtimeConferenceHelper`

Responsibilities:

- conference-safe defaults
- mesh guardrails
- call-mode normalization

Suggested surface:

- `conferenceDefaults(): array`
- `normalizeCallMode(string $mode): string`
- `enforceParticipantGuardrail(int $count, int $max = 5): void`

## Required Inputs From Product Projects

The backend SDK should assume the product system already knows:

- authenticated user ID
- display name
- owning Realtime client code
- project scope code
- intended room
- requested capabilities by use case

The backend SDK should not guess these.

## Claim Model Recommendation

The backend SDK should produce the same core claim concepts Realtime already expects:

- `iss`
- `aud`
- `exp`
- `app_code`
- `project_code`
- `user_id`
- `display_name`
- `capabilities`
- `allowed_rooms`
- `allowed_room_prefixes`
- `attachment_policy`

This is important: the backend SDK should be strict about claim shape.

It should not allow product teams to mint arbitrary loosely-structured claim payloads.

## Backend SDK Example Use

```php
<?php

require_once __DIR__ . '/lib/pbb_realtime_backend_sdk.php';

$config = new RealtimeConfig([
    'issuer' => 'pbb-hotline-backend',
    'audience' => 'pbb-realtime',
    'signing_secret' => $_ENV['REALTIME_TOKEN_SIGNING_SECRET'],
    'websocket_url' => 'wss://realtime.pbb.ph/ws',
    'token_ttl_seconds' => 3600,
]);

$builder = new RealtimeTokenBuilder($config);
$admission = new RealtimeAdmission($config, $builder);

$payload = $admission->buildAdmission([
    'app_code' => 'clt_...',
    'project_code' => 'prj_...',
    'user_id' => 'operator_123',
    'display_name' => 'Operator 123',
    'room' => 'chat.thread.hotline-room',
    'capabilities' => [
        'session.connect',
        'room.join',
        'presence.publish',
        'presence.subscribe',
        'chat.publish',
        'chat.subscribe',
        'call.signal',
    ],
    'allowed_room_prefixes' => [
        'chat.thread.',
        'call.session.',
    ],
]);

header('Content-Type: application/json');
echo json_encode($payload);
```

## Security Recommendations

- never expose the signing secret to the frontend
- never let the browser mint claims directly
- keep token TTLs short
- validate business authorization before building admission
- do not let product teams bypass normalized claim helpers

## Packaging Recommendation

Phase 1 should be repo-local and easy to copy or vendor.

That means:

- plain PHP
- minimal dependencies
- one clear include path

Later, if adoption stabilizes, it can evolve into:

- a Composer package
- optional Laravel service provider
- optional Symfony integration

But that should come later. The first version should optimize for ease of adoption across existing PBB backends.

## Recommendation

Yes: a simple backend PHP library is the correct first implementation.

Not because it is the most elegant long-term packaging model, but because it is the fastest way to:

- establish a consistent trust boundary
- reduce duplication across PBB projects
- keep Realtime in control of transport claim semantics

That is the right pragmatic starting point.
