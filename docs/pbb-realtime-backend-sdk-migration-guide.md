# PBB Realtime Backend SDK Migration Guide

## Goal

Move projects that currently hand-build Realtime tokens toward the shared backend SDK.

## Existing Pattern To Replace

Many projects will already have custom code that:

- assembles JWT claims manually
- signs HS256 tokens inline
- hardcodes room normalization logic
- returns ad hoc admission payloads

That pattern should be replaced gradually.

## Recommended Migration Steps

### 1. Keep The Existing Endpoint

Do not change the frontend contract first.

Keep the existing product backend admission endpoint and replace only the token-building internals.

### 2. Replace Manual Claim Assembly

Use:

- `RealtimeAdmission`
- `RealtimeTokenBuilder`
- `RealtimeClaimNormalizer`

instead of hand-built arrays scattered across controllers or services.

### 3. Normalize Rooms Through The SDK

Replace local room helpers with:

- `RealtimeRoomHelper::normalizeChatRoom(...)`
- `RealtimeRoomHelper::buildCallRoomFromChatRoom(...)`

### 4. Move Attachment Limits Into `attachment_policy`

If a project currently spreads attachment limits across separate fields, map them into:

- `max_attachment_count`
- `max_attachment_bytes`
- `max_total_bytes_per_message`
- `chunk_events_per_minute`
- `chunk_bytes_per_minute`

### 5. Keep Frontend Payload Stable

The frontend should still receive:

- `token`
- `websocket_url`
- `app_code`
- `project_code`
- `room`
- optional `call_room`

### 6. Add Tests Around The New Admission Builder

Before removing the old code, verify:

- correct client code
- correct project scope code
- correct room normalization
- correct conference room derivation
- correct capability set

## Success Condition

The project frontend should not need to know whether the backend uses custom token code or the shared backend SDK.

The migration is successful when:

- frontend contract is unchanged
- backend claim generation is centralized
- Realtime behavior remains consistent
