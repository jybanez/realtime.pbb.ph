# PBB Realtime Backend SDK Hotline Example

## Scenario

Hotline operator terminal requests Realtime admission from the Hotline backend.

The Hotline backend already knows:

- the operator is authenticated
- the operator belongs to the Hotline client
- the correct project scope is the Hotline operator scope
- the room being joined is the active dispatch or call room

## Backend Flow

1. authenticate the operator in the Hotline backend
2. resolve the correct Realtime client code
3. resolve the correct Realtime project scope code
4. derive the room name
5. build Realtime admission with the backend SDK
6. return the signed token and websocket URL to the frontend

## Example

```php
<?php

require_once __DIR__ . '/lib/pbb_realtime_backend_sdk.php';

$operator = [
    'id' => 'operator_123',
    'name' => 'Hotline Operator 123',
];

$dispatchRoom = 'dispatch-incident-2026-0001';

$config = new RealtimeConfig([
    'issuer' => 'pbb-hotline-backend',
    'audience' => 'pbb-realtime',
    'signing_secret' => $_ENV['REALTIME_TOKEN_SIGNING_SECRET'],
    'websocket_url' => $_ENV['REALTIME_WEBSOCKET_URL'],
    'token_ttl_seconds' => 1800,
]);

$admission = new RealtimeAdmission($config);

$response = $admission->buildAdmission([
    'app_code' => 'clt_hotline',
    'project_code' => 'prj_hotline_operator',
    'user_id' => $operator['id'],
    'display_name' => $operator['name'],
    'room' => $dispatchRoom,
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
```

## Result

The Hotline frontend can then:

1. call the Hotline backend admission endpoint
2. receive the admission payload
3. create `RealtimeSocketClient`
4. connect to Realtime directly

This keeps:

- trust and token issuance on the backend
- transport and runtime behavior on the frontend SDK
