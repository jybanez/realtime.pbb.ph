# PBB Realtime Backend SDK Arguments Reference

## `new RealtimeConfig(array $config)`

Expected keys:

- `issuer`
  - string
  - trusted issuer name that Realtime will validate in `iss`
- `audience`
  - string
  - expected Realtime audience, usually `pbb-realtime`
- `signing_secret`
  - string
  - HS256 signing secret shared with Realtime validation
- `websocket_url`
  - string
  - public websocket URL given back to frontend clients
- `token_ttl_seconds`
  - int
  - optional
  - token lifetime in seconds

## `new RealtimeTokenBuilder(RealtimeConfig $config, ?RealtimeClaimNormalizer $normalizer = null)`

Arguments:

- `config`
  - required
  - backend SDK configuration object
- `normalizer`
  - optional
  - custom claim normalizer if a project wants to wrap or extend normalization rules

## `new RealtimeAdmission(RealtimeConfig $config, ?RealtimeTokenBuilder $builder = null)`

Arguments:

- `config`
  - required
  - backend SDK configuration object
- `builder`
  - optional
  - token builder instance to reuse or customize claim generation

## `RealtimeAdmission::buildAdmission(array $context)`

Expected context keys:

- `app_code`
  - string
  - required
  - Realtime client code
- `project_code`
  - string
  - required
  - Realtime project scope code
- `user_id`
  - string
  - required
  - authenticated product user identifier
- `display_name`
  - string
  - optional but recommended
  - user-facing label shown in chat, presence, and call surfaces
- `room`
  - string
  - required
  - business room input; backend SDK normalizes it to `chat.thread.*`
- `conference`
  - bool
  - optional
  - when true, also derives a `call.session.*` room
- `presence`
  - bool
  - optional
  - when true, merges default presence capabilities
- `attachments`
  - bool
  - optional
  - when true, preserves attachment policy and attachment-capable defaults
- `capabilities`
  - array of strings
  - optional
  - explicit Realtime capabilities to embed in the token
- `allowed_room_prefixes`
  - array of strings
  - optional
  - allowed room prefixes enforced at runtime by Realtime
- `attachment_policy`
  - associative array
  - optional
  - attachment transport limits
- `roles`
  - array of strings
  - optional
- `email`
  - string
  - optional
- `tenant_id`
  - string
  - optional
- `org_id`
  - string
  - optional
- `workspace_id`
  - string
  - optional
- `origin`
  - string
  - optional
- `sub`
  - string
  - optional
  - explicit JWT subject if the product backend does not want the default `session:{user_id}`
- `jti`
  - string
  - optional
  - explicit token id if required by the product backend

## `RealtimeTokenBuilder::forChatSession(array $context)`

Arguments:

- same context shape as `buildAdmission()`
- focuses on a single normalized chat room in `allowed_rooms`

## `RealtimeTokenBuilder::forPresenceSession(array $context)`

Arguments:

- same context shape as `buildAdmission()`
- merges default capabilities:
  - `session.connect`
  - `room.join`
  - `presence.subscribe`
  - `presence.publish`

## `RealtimeTokenBuilder::forAttachmentSession(array $context)`

Arguments:

- same context shape as `buildAdmission()`
- merges default capabilities:
  - `session.connect`
  - `room.join`
  - `chat.publish`
  - `chat.subscribe`

## `RealtimeTokenBuilder::forConferenceSession(array $context)`

Arguments:

- same context shape as `buildAdmission()`
- derives:
  - normalized chat room
  - matching `call.session.*` room

## `RealtimeTokenBuilder::sign(array $claims)`

Arguments:

- `claims`
  - normalized associative array of JWT claims

## `RealtimeTokenBuilder::buildSignedToken(array $claims)`

Arguments:

- `claims`
  - normalized associative array of JWT claims

## `RealtimeClaimNormalizer::normalizeClaims(array $claims)`

Arguments:

- `claims`
  - associative array of Realtime JWT claims

Required keys inside `claims`:

- `sub`
- `project_code`
- `app_code`
- `user_id`

## `RealtimeClaimNormalizer::normalizeCapabilities(array $capabilities)`

Arguments:

- `capabilities`
  - array of strings

## `RealtimeClaimNormalizer::normalizeAttachmentPolicy(array $policy)`

Arguments:

- `policy`
  - associative array containing any of:
    - `max_attachment_count`
    - `max_attachment_bytes`
    - `max_total_bytes_per_message`
    - `chunk_events_per_minute`
    - `chunk_bytes_per_minute`

## `RealtimeRoomHelper::normalizeChatRoom(string $room)`

Arguments:

- `room`
  - raw business room string or already-normalized chat room

## `RealtimeRoomHelper::buildCallRoomFromChatRoom(string $room)`

Arguments:

- `room`
  - raw business room string, normalized chat room, or normalized call room

## `RealtimeConferenceHelper::normalizeCallMode(string $mode)`

Arguments:

- `mode`
  - call mode string such as `audio` or `video`

## `RealtimeConferenceHelper::enforceParticipantGuardrail(int $count, int $max = 5)`

Arguments:

- `count`
  - current participant count
- `max`
  - optional hard cap
