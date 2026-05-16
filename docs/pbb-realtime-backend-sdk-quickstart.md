# PBB Realtime Backend SDK Quickstart

## Goal

Use the backend SDK to issue a Realtime admission payload from a product backend without coupling that backend to Laravel or the Realtime admin codebase.

## What The Backend SDK Does

The backend SDK helps a product backend:

- normalize Realtime claim shape
- sign the Realtime token
- derive chat and call room names
- return a frontend-facing admission payload

It does not decide business authorization for you.

## Files To Vendor

Copy or vendor:

- `sdk/php/pbb_realtime_backend_sdk.php`
- `sdk/php/src/*.php`

## Basic Example

```php
<?php

require_once __DIR__ . '/lib/pbb_realtime_backend_sdk.php';

$config = new RealtimeConfig([
    'issuer' => 'pbb-hotline-backend',
    'audience' => 'pbb-realtime',
    'signing_secret' => $_ENV['REALTIME_TOKEN_SIGNING_SECRET'],
    'websocket_url' => 'wss://realtime.pbb.ph/realtime',
    'token_ttl_seconds' => 3600,
]);

$admission = new RealtimeAdmission($config);

$payload = $admission->buildAdmission([
    'app_code' => 'clt_...',
    'project_code' => 'prj_...',
    'user_id' => 'operator_123',
    'display_name' => 'Operator 123',
    'room' => 'hotline-room',
    'presence' => true,
    'attachments' => true,
    'conference' => true,
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
    'attachment_policy' => [
        'max_attachment_count' => 6,
        'max_attachment_bytes' => 2 * 1024 * 1024,
        'max_total_bytes_per_message' => 6 * 1024 * 1024,
        'chunk_events_per_minute' => 180,
        'chunk_bytes_per_minute' => 12 * 1024 * 1024,
    ],
]);

header('Content-Type: application/json');
echo json_encode([
    'status' => true,
    'data' => $payload,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
```

## What The Frontend Receives

The frontend receives:

- `token`
- `websocket_url`
- `app_code`
- `project_code`
- `room`
- `call_room` when `conference` is enabled
- session metadata such as:
  - `user_id`
  - `display_name`
  - `capabilities`
  - `allowed_rooms`
  - `attachment_policy`

That payload is then passed into the frontend SDK.

## Required Inputs

Your product backend must already know:

- authenticated user identity
- Realtime client code
- Realtime project scope code
- intended room
- requested transport behavior

The backend SDK does not discover those values on its own.

## Practical Rule

Use the backend SDK only after your product backend has already made the business decision that the user is allowed to connect.
