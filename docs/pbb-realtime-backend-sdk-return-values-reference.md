# PBB Realtime Backend SDK Return Values Reference

## `new RealtimeConfig(array $config)`

Returns:

- `RealtimeConfig` instance with resolved properties:
  - `issuer`
  - `audience`
  - `signingSecret`
  - `websocketUrl`
  - `tokenTtlSeconds`

## `new RealtimeTokenBuilder(...)`

Returns:

- `RealtimeTokenBuilder` instance for claim creation and signing

## `new RealtimeAdmission(...)`

Returns:

- `RealtimeAdmission` instance for producing frontend-facing admission payloads

## `RealtimeAdmission::buildAdmission(array $context)`

Returns:

- associative array with:
  - `token`
    - signed JWT string
  - `websocket_url`
    - public websocket URL for the frontend SDK
  - `app_code`
    - client code embedded in the token
  - `project_code`
    - project scope code embedded in the token
  - `room`
    - normalized `chat.thread.*` room
  - `expires_at`
    - ISO-8601 timestamp string
  - `session`
    - nested session metadata:
      - `token_id`
      - `user_id`
      - `display_name`
      - `capabilities`
      - `allowed_rooms`
      - `allowed_room_prefixes`
      - `attachment_policy`
  - `call_room`
    - only present when `conference` is enabled

## `RealtimeTokenBuilder::forChatSession(array $context)`

Returns:

- normalized JWT claim array for a chat-capable session

Important fields:

- `iss`
- `sub`
- `aud`
- `iat`
- `exp`
- `jti`
- `project_code`
- `app_code`
- `user_id`
- `display_name`
- `capabilities`
- `allowed_rooms`
- `allowed_room_prefixes`
- `attachment_policy`

## `RealtimeTokenBuilder::forPresenceSession(array $context)`

Returns:

- normalized JWT claim array with presence-ready capabilities merged in

## `RealtimeTokenBuilder::forAttachmentSession(array $context)`

Returns:

- normalized JWT claim array with attachment-capable defaults merged in

## `RealtimeTokenBuilder::forConferenceSession(array $context)`

Returns:

- normalized JWT claim array that includes:
  - normalized chat room
  - derived call room

`allowed_rooms` will include both:

- `chat.thread.*`
- `call.session.*`

## `RealtimeTokenBuilder::sign(array $claims)`

Returns:

- signed HS256 JWT string

## `RealtimeTokenBuilder::buildSignedToken(array $claims)`

Returns:

- signed HS256 JWT string after claim normalization

## `RealtimeClaimNormalizer::normalizeClaims(array $claims)`

Returns:

- normalized claim array compatible with current Realtime validation expectations

Normalization behavior includes:

- trimming required strings
- removing empty list items
- deduplicating string lists
- coercing attachment policy values to non-negative integers
- converting empty nullable strings to `null`

## `RealtimeClaimNormalizer::normalizeCapabilities(array $capabilities)`

Returns:

- deduplicated array of non-empty capability strings

## `RealtimeClaimNormalizer::normalizeAttachmentPolicy(array $policy)`

Returns:

- associative array with integer keys:
  - `max_attachment_count`
  - `max_attachment_bytes`
  - `max_total_bytes_per_message`
  - `chunk_events_per_minute`
  - `chunk_bytes_per_minute`

## `RealtimeRoomHelper::normalizeChatRoom(string $room)`

Returns:

- normalized `chat.thread.*` room string

## `RealtimeRoomHelper::buildCallRoomFromChatRoom(string $room)`

Returns:

- normalized `call.session.*` room string

## `RealtimeConferenceHelper::conferenceDefaults()`

Returns:

- associative array:
  - `mesh_participant_limit`
  - `warning_threshold`

## `RealtimeConferenceHelper::normalizeCallMode(string $mode)`

Returns:

- `audio` or `video`

Falls back to:

- `audio`

## `RealtimeConferenceHelper::enforceParticipantGuardrail(int $count, int $max = 5)`

Returns:

- no value on success

Behavior:

- throws `InvalidArgumentException` when participant count exceeds the configured limit
