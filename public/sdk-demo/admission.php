<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/demo-bootstrap.php';

try {
    $input = demoJsonInput();
    $admission = new RealtimeAdmission(demoRealtimeConfig());

    $payload = $admission->buildAdmission([
        'app_code' => (string) ($input['client_code'] ?? ''),
        'project_code' => (string) ($input['project_code'] ?? ''),
        'user_id' => (string) ($input['user_id'] ?? ''),
        'display_name' => (string) ($input['display_name'] ?? ''),
        'room' => (string) ($input['room'] ?? ''),
        'presence' => true,
        'attachments' => false,
        'conference' => false,
        'capabilities' => [
            'session.connect',
            'room.join',
            'presence.publish',
            'presence.subscribe',
            'chat.publish',
            'chat.subscribe',
        ],
        'allowed_room_prefixes' => [
            'chat.thread.',
        ],
    ]);

    demoJsonResponse([
        'status' => true,
        'data' => $payload,
    ]);
} catch (Throwable $exception) {
    demoJsonResponse([
        'status' => false,
        'message' => $exception->getMessage(),
    ], 422);
}
