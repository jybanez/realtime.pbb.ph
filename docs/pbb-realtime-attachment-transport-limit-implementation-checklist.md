# PBB Realtime Attachment Transport Limit Checklist

Purpose:
- make sandbox attachment transfer fail early and predictably on the sender side
- enforce the same attachment transport rules on the gateway
- replace coarse media-event-only limiting with an attachment-aware transport profile

Checklist:
- [x] Define an attachment transport profile structure under `rate_limit_profile.attachment_transport`
- [x] Include attachment transport policy data in sandbox context payloads
- [x] Include attachment transport policy data in sandbox-issued realtime tokens
- [x] Add sender-side sandbox validation for:
  - `max_attachment_count`
  - `max_attachment_bytes`
  - `max_total_bytes_per_message`
- [x] Add attachment byte metadata to sandbox transport payloads
- [x] Add server-side validation for chat attachment metadata against policy
- [x] Add server-side validation for chunk transfer size against policy
- [x] Add separate attachment transport rate buckets for:
  - chunk events per minute
  - chunk bytes per minute
- [x] Preserve coarse request limits for non-attachment message traffic
- [x] Update seeded policy profiles to use the new attachment transport structure
- [x] Add tests covering:
  - attachment metadata rejection when size exceeds policy
  - chunk publish rejection when declared bytes exceed policy
  - chunk publish rate limiting using attachment transport bucket
- [x] Keep sandbox chunk transport explicitly demo-only in behavior/docs
