<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/sdk-demo/inc/demo-bootstrap.php';

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
        'attachments' => true,
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
        'attachment_policy' => [
            'max_attachment_count' => 6,
            'max_attachment_bytes' => 2 * 1024 * 1024,
            'max_total_bytes_per_message' => 6 * 1024 * 1024,
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
