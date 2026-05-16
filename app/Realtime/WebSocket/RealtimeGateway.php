<?php

namespace App\Realtime\WebSocket;

use App\Realtime\Auth\RealtimeTokenClaims;
use App\Realtime\Auth\RealtimeTokenValidationException;
use App\Realtime\Auth\RealtimeTokenValidator;
use App\Realtime\Media\RealtimeMediaChunkQueue;
use App\Realtime\Observability\RealtimeUsageTelemetry;
use App\Realtime\ProductQuery\RealtimeProductQueryForwarder;
use App\Realtime\Rooms\RealtimeRoomPolicy;
use App\Realtime\Sessions\RealtimeSessionRecorder;
use App\Realtime\Observability\RealtimeMetrics;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use SplObjectStorage;
use Throwable;

class RealtimeGateway implements MessageComponentInterface
{
    private const CALL_MESH_PARTICIPANT_LIMIT = 5;
    private const BINARY_MEDIA_MAGIC = 'PBBM';
    private const BINARY_MEDIA_VERSION = 1;

    private SplObjectStorage $connections;

    /**
     * @var array<string, array<string, bool>>
     */
    private array $roomMembers = [];

    /**
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $roomPresence = [];

    /**
     * @var array<string, array{window:int, count:int}>
     */
    private array $rateWindow = [];

    public function __construct(
        private readonly RealtimeTokenValidator $tokenValidator,
        private readonly RealtimeRoomPolicy $roomPolicy,
        private readonly RealtimeMetrics $metrics,
        private readonly RealtimeSessionRecorder $sessionRecorder,
        private readonly RealtimeUsageTelemetry $telemetry,
        private readonly RealtimeMediaChunkQueue $mediaChunkQueue,
        private readonly RealtimeProductQueryForwarder $productQueryForwarder,
        private readonly string $serviceName,
        private readonly int $heartbeatIntervalSeconds,
        private readonly int $presenceStaleSeconds,
        private readonly int $messageRateLimitPerMinute,
        private readonly int $roomJoinRateLimitPerMinute,
        private readonly int $maxRoomsPerSession
    ) {
        $this->connections = new SplObjectStorage();
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        $this->attachConnection($conn);

        $request = $conn->httpRequest ?? null;
        $query = $request?->getUri()?->getQuery() ?? '';
        parse_str($query, $params);

        $token = is_string($params['token'] ?? null) ? $params['token'] : '';

        if ($token !== '') {
            try {
                $claims = $this->tokenValidator->validate($token);

                if (!$claims->hasCapability('session.connect')) {
                    $this->metrics->increment('auth.failure');
                    $this->telemetry->record('auth.failure', $claims, errorCount: 1);
                    $this->rejectConnection($conn, 'missing-capability', 'The realtime token does not allow session establishment.');
                    return;
                }

                $this->authenticateConnection($conn, $claims, 'session.open');
                $this->metrics->increment('auth.success');
                $this->telemetry->record('auth.success', $claims);
            } catch (RealtimeTokenValidationException $e) {
                $this->metrics->increment('auth.failure');
                $this->telemetry->record('auth.failure', null, errorCount: 1);
                $this->rejectConnection($conn, $e->reason, $e->getMessage());
            }
        } else {
            $this->sendSystem($conn, 'session.awaiting-auth', [
                'message' => 'Awaiting realtime session authentication.',
            ]);
        }
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        if (!$this->connections->contains($from)) {
            $this->attachConnection($from);
        }

        $rawMessage = (string) $msg;
        if ($this->isBinaryMediaFrame($rawMessage)) {
            $this->handleBinaryMediaFrame($from, $rawMessage);
            return;
        }

        try {
            $envelope = RealtimeEnvelope::fromJson($rawMessage);
        } catch (InvalidArgumentException $e) {
            $this->sendError($from, 'validation.invalid-envelope', $e->getMessage());
            return;
        }

        $rateByteCost = 0;
        if ($envelope->type === 'sandbox.attachment.chunk.publish' && is_string($envelope->payload['chunk_data'] ?? null)) {
            try {
                $rateByteCost = $this->decodedBase64Length($envelope->payload['chunk_data'], 'payload.chunk_data');
            } catch (InvalidArgumentException) {
                $rateByteCost = 0;
            }
        }

        if ($this->checkRateLimit($from, $envelope->type, $rateByteCost)) {
            $this->sendError($from, 'rate-limited', 'Realtime request rate limit exceeded.');
            return;
        }

        if ($envelope->phase !== 'request' && $envelope->type !== 'ping') {
            $this->sendError($from, 'validation.invalid-phase', 'Client messages must use the request phase.', $envelope);
            return;
        }

        if ($this->hasCachedResponse($from, $envelope->id)) {
            $from->send($this->cachedResponse($from, $envelope->id));
            return;
        }

        if ($envelope->type === 'session.auth.request') {
            $this->handleSessionAuthRequest($from, $envelope);
            return;
        }

        if (!$this->isAuthenticated($from)) {
            $this->sendError($from, 'auth.required', 'Authenticate before sending realtime requests.');
            return;
        }

        match ($envelope->type) {
            'room.join.request' => $this->handleRoomJoinRequest($from, $envelope),
            'room.leave.request' => $this->handleRoomLeaveRequest($from, $envelope),
            'session.health.request' => $this->handleSessionHealthRequest($from, $envelope),
            'presence.subscribe' => $this->handlePresenceSubscribe($from, $envelope),
            'presence.publish' => $this->handlePresencePublish($from, $envelope),
            'app.event.publish' => $this->handleAppEventPublish($from, $envelope),
            'media.chunk.prepare' => $this->handleMediaChunkPrepare($from, $envelope),
            'media.chunk.publish' => $this->handleMediaChunkPublish($from, $envelope),
            'chat.message.publish' => $this->handleChatPublish($from, $envelope),
            'sandbox.attachment.chunk.publish' => $this->handleSandboxAttachmentChunkPublish($from, $envelope),
            'call.signal.publish' => $this->handleCallSignalPublish($from, $envelope),
            'ping' => $this->sendAck($from, $envelope, ['pong' => true]),
            default => $this->sendError($from, 'validation.unsupported-type', 'The message type is not supported yet.'),
        };
    }

    public function onClose(ConnectionInterface $conn): void
    {
        if (!$this->connections->contains($conn)) {
            return;
        }

        $state = $this->connections[$conn];
        $sessionId = is_array($state) ? (string) ($state['session_id'] ?? '') : '';
        $rooms = is_array($state) ? ($state['rooms'] ?? []) : [];
        $claims = is_array($state) ? ($state['claims'] ?? null) : null;

        foreach (array_keys(is_array($rooms) ? $rooms : []) as $room) {
            unset($this->roomMembers[$room][spl_object_hash($conn)]);

            if ($claims instanceof RealtimeTokenClaims && $sessionId !== '' && isset($this->roomPresence[$room][$sessionId])) {
                $offlinePresence = $this->roomPresence[$room][$sessionId];
                $offlinePresence['state'] = 'offline';
                $offlinePresence['expires_at'] = (new DateTimeImmutable())->format(DATE_ATOM);
                $this->broadcast($room, 'presence.state.event', $offlinePresence);
                unset($this->roomPresence[$room][$sessionId]);
                if ($this->roomPresence[$room] === []) {
                    unset($this->roomPresence[$room]);
                }
            }
        }

        $this->connections->detach($conn);

        Log::info('Realtime connection closed.', [
            'service' => $this->serviceName,
            'session_id' => $sessionId,
            'reason' => 'disconnect',
        ]);

        if ($sessionId !== '') {
            $this->sessionRecorder->leaveClosedSession($sessionId, 'disconnect');
        }
    }

    public function onError(ConnectionInterface $conn, Throwable $e): void
    {
        Log::warning('Realtime websocket error.', [
            'service' => $this->serviceName,
            'message' => $e->getMessage(),
            'exception' => $e::class,
        ]);

        $conn->close();
    }

    private function handleSessionAuthRequest(ConnectionInterface $conn, RealtimeEnvelope $envelope): void
    {
        $token = is_string($envelope->payload['token'] ?? null) ? $envelope->payload['token'] : '';

        if ($token === '') {
            $this->sendError($conn, 'validation.missing-token', 'A realtime token is required.');
            return;
        }

        try {
            $claims = $this->tokenValidator->validate($token);
        } catch (RealtimeTokenValidationException $e) {
            $this->metrics->increment('auth.failure');
            $this->telemetry->record('auth.failure', null, errorCount: 1);
            Log::warning('Realtime session admission rejected.', [
                'service' => $this->serviceName,
                'reason' => $e->reason,
            ]);

            $this->sendError($conn, $this->errorCodeForReason($e->reason), $e->getMessage(), $envelope);
            return;
        }

        if (!$claims->hasCapability('session.connect')) {
            $this->metrics->increment('auth.failure');
            $this->telemetry->record('auth.failure', $claims, errorCount: 1);
            Log::warning('Realtime session admission rejected.', [
                'service' => $this->serviceName,
                'reason' => 'missing-capability',
            ]);

            $this->sendError($conn, 'auth.missing-capability', 'The realtime token does not allow session establishment.', $envelope);
            return;
        }

        $this->authenticateConnection($conn, $claims, $envelope->id);
        $this->metrics->increment('auth.success');
        $this->telemetry->record('auth.success', $claims);
    }

    private function authenticateConnection(ConnectionInterface $conn, RealtimeTokenClaims $claims, string $requestId): void
    {
        $state = $this->connectionState($conn);
        $state['claims'] = $claims;
        $state['session_id'] = $claims->tokenId ?: 'sess_' . bin2hex(random_bytes(8));
        $state['rooms'] = $state['rooms'] ?? [];
        $state['presence'] = $state['presence'] ?? [];
        $this->connections[$conn] = $state;

        Log::info('Realtime session accepted.', [
            'service' => $this->serviceName,
            'session_id' => $state['session_id'],
            'project_code' => $claims->projectCode,
            'app_code' => $claims->appCode,
            'user_id' => $claims->userId,
        ]);

        $this->sessionRecorder->recordAuthentication($claims, $state['session_id']);

        $this->sendAck($conn, new RealtimeEnvelope(
            namespace: 'pbb.realtime.v1',
            phase: 'request',
            id: $requestId,
            type: 'session.auth.request',
            room: null,
            payload: [],
            meta: []
        ), [
            'session_id' => $state['session_id'],
            'project_code' => $claims->projectCode,
            'app_code' => $claims->appCode,
            'user_id' => $claims->userId,
            'heartbeat_interval_seconds' => $this->heartbeatIntervalSeconds,
        ]);
    }

    private function handleSessionHealthRequest(ConnectionInterface $conn, RealtimeEnvelope $envelope): void
    {
        $state = $this->connectionState($conn);

        $this->sendAck($conn, $envelope, [
            'ok' => true,
            'server_time' => (new DateTimeImmutable())->format(DATE_ATOM),
            'authenticated' => true,
            'session_id' => $this->sessionId($conn),
            'connection_id' => spl_object_hash($conn),
            'rooms_joined_count' => is_array($state['rooms'] ?? null) ? count($state['rooms']) : 0,
            'heartbeat_interval_seconds' => $this->heartbeatIntervalSeconds,
        ]);
    }

    private function handleRoomJoinRequest(ConnectionInterface $conn, RealtimeEnvelope $envelope): void
    {
        $room = $this->requiredString($envelope->room, 'room');
        $claims = $this->claims($conn);

        if (!$this->authorizeRoomJoin($claims, $room)) {
            Log::warning('Realtime room join rejected.', [
                'service' => $this->serviceName,
                'reason' => 'room-forbidden',
                'room' => $room,
                'session_id' => $this->sessionId($conn),
            ]);

            $this->sendError($conn, 'auth.room-denied', 'Room access denied.', $envelope);
            return;
        }

        if (count($this->connectionRooms($conn)) >= $this->maxRoomsPerSession && !$this->isInRoom($conn, $room)) {
            $this->sendError($conn, 'room-limit-exceeded', 'The session has joined too many rooms.', $envelope);
            return;
        }

        if (
            str_starts_with($room, 'call.session.')
            && !$this->isInRoom($conn, $room)
            && count($this->roomMembers[$room] ?? []) >= self::CALL_MESH_PARTICIPANT_LIMIT
        ) {
            $this->sendError($conn, 'call.mesh-limit-exceeded', 'Mesh call rooms are limited to 5 participants.', $envelope);
            return;
        }

        $this->joinRoom($conn, $room);

        Log::info('Realtime room join accepted.', [
            'service' => $this->serviceName,
            'room' => $room,
            'session_id' => $this->sessionId($conn),
        ]);
        $this->metrics->increment('room.join');
        $this->telemetry->record('room.join', $claims);
        $this->sessionRecorder->touch($this->sessionId($conn), 'connected', null, count($this->connectionRooms($conn)));

        $this->sendAck($conn, $envelope, [
            'joined' => true,
            'room' => $room,
        ]);
    }

    private function handleRoomLeaveRequest(ConnectionInterface $conn, RealtimeEnvelope $envelope): void
    {
        $room = $this->requiredString($envelope->room, 'room');
        $this->leaveRoom($conn, $room);
        $this->metrics->increment('room.leave');
        $this->telemetry->record('room.leave', $this->claims($conn));
        $this->sessionRecorder->touch($this->sessionId($conn), 'connected', null, count($this->connectionRooms($conn)));
        $this->sendAck($conn, $envelope, [
            'left' => true,
            'room' => $room,
        ]);
    }

    private function handlePresenceSubscribe(ConnectionInterface $conn, RealtimeEnvelope $envelope): void
    {
        $room = $this->requiredString($envelope->room, 'room');
        $claims = $this->claims($conn);

        if (!$claims->hasCapability('presence.subscribe')) {
            $this->sendError($conn, 'auth.missing-capability', 'The realtime token does not allow presence subscriptions.', $envelope);
            return;
        }

        if (!$this->authorizeRoomJoin($claims, $room)) {
            $this->sendError($conn, 'auth.room-denied', 'Room access denied.', $envelope);
            return;
        }

        $this->joinRoom($conn, $room);

        $roster = $this->currentPresenceRoster($room);

        $this->sendAck($conn, $envelope, [
            'subscribed' => true,
            'room' => $room,
            'roster' => $roster,
        ]);
        $this->metrics->increment('presence.subscribe');
        $this->telemetry->record('presence.subscribe', $claims);

        foreach ($roster as $presence) {
            $this->sendEvent($conn, 'presence.state.event', $room, $presence);
        }
    }

    private function handlePresencePublish(ConnectionInterface $conn, RealtimeEnvelope $envelope): void
    {
        $room = $this->requiredString($envelope->room, 'room');
        $claims = $this->claims($conn);

        if (!$claims->hasCapability('presence.publish')) {
            $this->sendError($conn, 'auth.missing-capability', 'The realtime token does not allow presence publishing.', $envelope);
            return;
        }

        if (!$this->authorizeRoomJoin($claims, $room)) {
            $this->sendError($conn, 'auth.room-denied', 'Room access denied.', $envelope);
            return;
        }

        if (!$this->isInRoom($conn, $room)) {
            $this->sendError($conn, 'room.not-joined', 'Join the room before publishing presence.', $envelope);
            return;
        }

        try {
            $state = $this->requiredString($envelope->payload['state'] ?? null, 'payload.state');
            $statusText = $this->nullableString($envelope->payload['status_text'] ?? null, 'payload.status_text');
            $updatedAt = $this->nullableString($envelope->payload['updated_at'] ?? null, 'payload.updated_at') ?? (new DateTimeImmutable())->format(DATE_ATOM);
            $presenceMeta = $this->nullablePresenceMeta($envelope->payload['meta'] ?? null, 'payload.meta');
        } catch (InvalidArgumentException $e) {
            $this->sendError($conn, 'validation.invalid-payload', $e->getMessage(), $envelope);
            return;
        }

        $sessionId = $this->sessionId($conn);
        $presence = $this->buildPresencePayload($claims, $sessionId, $state, $statusText, $updatedAt, $presenceMeta);
        if ($sessionId !== '') {
            if ($state === 'offline') {
                unset($this->roomPresence[$room][$sessionId]);
                if (($this->roomPresence[$room] ?? []) === []) {
                    unset($this->roomPresence[$room]);
                }
            } else {
                $this->roomPresence[$room][$sessionId] = $presence;
            }
        }
        $fanoutCount = $this->broadcast($room, 'presence.state.event', $presence);
        $this->metrics->increment('presence.publish');
        $this->telemetry->record(
            'presence.publish',
            $claims,
            bytesOut: $fanoutCount * $this->measureEventBytes('presence.state.event', $room, $presence)
        );
        $this->sessionRecorder->touch($this->sessionId($conn), 'connected', null, count($this->connectionRooms($conn)));
        $this->sendAck($conn, $envelope, [
            'published' => true,
            'room' => $room,
        ]);
    }

    private function handleChatPublish(ConnectionInterface $conn, RealtimeEnvelope $envelope): void
    {
        $room = $this->requiredString($envelope->room, 'room');
        $claims = $this->claims($conn);

        if (!$claims->hasCapability('chat.publish')) {
            $this->sendError($conn, 'auth.missing-capability', 'The realtime token does not allow chat publishing.', $envelope);
            return;
        }

        if (!$this->authorizeRoomJoin($claims, $room) || !str_starts_with($room, 'chat.thread.')) {
            $this->sendError($conn, 'auth.room-denied', 'Room access denied.', $envelope);
            return;
        }

        if (!$this->isInRoom($conn, $room)) {
            $this->sendError($conn, 'room.not-joined', 'Join the room before publishing chat.', $envelope);
            return;
        }

        $text = $this->requiredString($envelope->payload['text'] ?? null, 'payload.text');
        $attachments = $this->normalizeChatAttachments($envelope->payload['attachments'] ?? []);
        if (!$this->validateChatAttachmentsAgainstPolicy($attachments, $claims, $envelope, $conn)) {
            return;
        }

        $event = [
            'message_id' => 'msg_' . bin2hex(random_bytes(8)),
            'sender' => [
                'user_id' => $claims->userId,
                'display_name' => $claims->displayName,
            ],
            'text' => $text,
            'attachments' => $attachments,
            'sent_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ];

        $fanoutCount = $this->broadcast($room, 'chat.message.event', $event);
        $this->metrics->increment('chat.publish');
        $this->telemetry->record(
            'chat.publish',
            $claims,
            bytesIn: strlen($text),
            bytesOut: $fanoutCount * $this->measureEventBytes('chat.message.event', $room, $event)
        );
        $this->sessionRecorder->touch($this->sessionId($conn), 'connected', null, count($this->connectionRooms($conn)));
        $this->sendAck($conn, $envelope, [
            'published' => true,
            'message_id' => $event['message_id'],
        ]);
    }

    private function handleAppEventPublish(ConnectionInterface $conn, RealtimeEnvelope $envelope): void
    {
        $room = $this->requiredString($envelope->room, 'room');
        $claims = $this->claims($conn);

        if (!$claims->hasCapability('event.publish')) {
            $this->sendError($conn, 'auth.missing-capability', 'The realtime token does not allow custom event publishing.', $envelope);
            return;
        }

        if (!$this->authorizeRoomJoin($claims, $room)) {
            $this->sendError($conn, 'auth.room-denied', 'Room access denied.', $envelope);
            return;
        }

        if (!$this->isInRoom($conn, $room)) {
            $this->sendError($conn, 'room.not-joined', 'Join the room before publishing custom events.', $envelope);
            return;
        }

        try {
            $eventType = $this->requiredString($envelope->payload['event_type'] ?? null, 'payload.event_type');
            $payload = $this->requiredArray($envelope->payload['data'] ?? null, 'payload.data');
            $correlationId = $this->nullableString($envelope->payload['correlation_id'] ?? null, 'payload.correlation_id');
        } catch (InvalidArgumentException $e) {
            $this->sendError($conn, 'validation.invalid-payload', $e->getMessage(), $envelope);
            return;
        }

        $meta = [
            'source' => 'client',
            'sender' => [
                'user_id' => $claims->userId,
                'display_name' => $claims->displayName,
                'project_code' => $claims->projectCode,
                'app_code' => $claims->appCode,
            ],
        ];

        if ($correlationId !== null) {
            $meta['correlation_id'] = $correlationId;
        }

        if ($eventType === 'product.query.request') {
            $this->handleProductQueryRequest($conn, $envelope, $claims, $room, $payload, $correlationId);
            return;
        }

        $fanoutCount = $this->broadcastWithMeta($room, $eventType, $payload, $meta);
        $this->metrics->increment('event.publish');
        $bytesIn = strlen(RealtimeEnvelope::encode([
            'event_type' => $eventType,
            'data' => $payload,
            'correlation_id' => $correlationId,
        ]));
        $this->telemetry->record(
            'event.publish',
            $claims,
            bytesIn: $bytesIn,
            bytesOut: $fanoutCount * $this->measureEventBytes($eventType, $room, $payload, $meta)
        );
        $this->sessionRecorder->touch($this->sessionId($conn), 'connected', null, count($this->connectionRooms($conn)));
        $this->sendAck($conn, $envelope, [
            'published' => true,
            'event_type' => $eventType,
            'correlation_id' => $correlationId,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handleProductQueryRequest(
        ConnectionInterface $conn,
        RealtimeEnvelope $envelope,
        RealtimeTokenClaims $claims,
        string $room,
        array $payload,
        ?string $correlationId
    ): void {
        $settings = $this->productQueryForwarder->settingsForProject($claims->projectCode);

        if (!is_array($settings)) {
            $this->sendError($conn, 'product-query.unavailable', 'Product query forwarding is not configured for this integration.', $envelope);
            return;
        }

        try {
            $request = $this->normalizeProductQueryPayload($payload);
        } catch (InvalidArgumentException $e) {
            $this->sendError($conn, 'validation.invalid-payload', $e->getMessage(), $envelope);
            return;
        }

        $allowedEventTypes = $this->stringList($settings['allowed_event_types'] ?? ['product.query.request']);
        if ($allowedEventTypes !== [] && !in_array('product.query.request', $allowedEventTypes, true)) {
            $this->sendError($conn, 'product-query.event-not-allowed', 'Product query request forwarding is not allowed for this project scope.', $envelope);
            return;
        }

        $allowedQueries = $this->stringList($settings['allowed_queries'] ?? []);
        if ($allowedQueries === [] || !in_array($request['query'], $allowedQueries, true)) {
            $this->sendError($conn, 'product-query.query-not-allowed', 'The requested product query is not allowed for this project scope.', $envelope);
            return;
        }

        $maxPayloadBytes = (int) ($settings['max_payload_bytes'] ?? 4096);
        $requestBytes = strlen(RealtimeEnvelope::encode($request));
        if ($maxPayloadBytes > 0 && $requestBytes > $maxPayloadBytes) {
            $this->sendError($conn, 'product-query.payload-too-large', 'Product query payload exceeds the configured size limit.', $envelope);
            return;
        }

        if ($this->checkProductQueryRateLimit($conn, (int) ($settings['rate_limit_per_minute'] ?? 12))) {
            $this->sendError($conn, 'rate-limited', 'Product query request rate limit exceeded.', $envelope);
            return;
        }

        $result = $this->productQueryForwarder->forward(
            $claims,
            $room,
            $this->sessionId($conn),
            $request,
            $correlationId,
            $settings
        );

        if (!$result->accepted) {
            $this->broadcastProductQueryFailure($room, $request, $result->code ?? 'product-query.forward-failed', $result->message ?? 'Product backend did not accept the query.');
            $this->sendError($conn, $result->code ?? 'product-query.forward-failed', $result->message ?? 'Product backend did not accept the query.', $envelope);
            return;
        }

        $this->metrics->increment('product.query.forward');
        $this->telemetry->record('product.query.forward', $claims, bytesIn: $requestBytes);
        $this->sessionRecorder->touch($this->sessionId($conn), 'connected', null, count($this->connectionRooms($conn)));
        $this->sendAck($conn, $envelope, [
            'accepted' => true,
            'delivery' => 'forwarded',
            'event_type' => 'product.query.request',
            'request_id' => $request['request_id'],
            'query' => $request['query'],
            'correlation_id' => $correlationId,
        ]);
    }

    private function handleCallSignalPublish(ConnectionInterface $conn, RealtimeEnvelope $envelope): void
    {
        $room = $this->requiredString($envelope->room, 'room');
        $claims = $this->claims($conn);

        if (!$claims->hasCapability('call.signal')) {
            $this->sendError($conn, 'auth.missing-capability', 'The realtime token does not allow call signaling.', $envelope);
            return;
        }

        if (!$this->authorizeRoomJoin($claims, $room) || !str_starts_with($room, 'call.session.')) {
            $this->sendError($conn, 'auth.room-denied', 'Room access denied.', $envelope);
            return;
        }

        if (!$this->isInRoom($conn, $room)) {
            $this->sendError($conn, 'room.not-joined', 'Join the room before publishing call signals.', $envelope);
            return;
        }

        $signalType = $this->requiredString($envelope->payload['signal_type'] ?? null, 'payload.signal_type');
        $targetUserId = $this->nullableString($envelope->payload['target_user_id'] ?? null, 'payload.target_user_id');
        $sdp = $this->nullableRawString($envelope->payload['sdp'] ?? null, 'payload.sdp');
        $candidateJson = $this->nullableString($envelope->payload['candidate_json'] ?? null, 'payload.candidate_json');
        $metaJson = $this->nullableString($envelope->payload['meta_json'] ?? null, 'payload.meta_json');

        $event = [
            'signal_type' => $signalType,
            'target_user_id' => $targetUserId,
            'sender' => [
                'user_id' => $claims->userId,
                'display_name' => $claims->displayName,
            ],
            'sdp' => $sdp,
            'candidate_json' => $candidateJson,
            'meta_json' => $metaJson,
            'sent_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ];

        $fanoutCount = $this->broadcast($room, 'call.signal.event', $event);
        $this->metrics->increment('call.signal');
        $this->telemetry->record(
            'call.signal',
            $claims,
            bytesIn: strlen((string) ($sdp ?? '')) + strlen((string) ($candidateJson ?? '')) + strlen((string) ($metaJson ?? '')) + strlen($signalType),
            bytesOut: $fanoutCount * $this->measureEventBytes('call.signal.event', $room, $event)
        );
        $this->sessionRecorder->touch($this->sessionId($conn), 'connected', null, count($this->connectionRooms($conn)));
        $this->sendAck($conn, $envelope, [
            'published' => true,
        ]);
    }

    private function handleMediaChunkPublish(ConnectionInterface $conn, RealtimeEnvelope $envelope): void
    {
        $room = $this->requiredString($envelope->room, 'room');
        $claims = $this->claims($conn);

        if (!$this->authorizeRoomJoin($claims, $room)) {
            $this->sendError($conn, 'auth.room-denied', 'Room access denied.', $envelope);
            return;
        }

        if (!$this->isInRoom($conn, $room)) {
            $this->sendError($conn, 'room.not-joined', 'Join the room before publishing media chunks.', $envelope);
            return;
        }

        try {
            $payload = $this->normalizeMediaChunkPayload($envelope->payload);
        } catch (InvalidArgumentException $e) {
            $this->sendError($conn, 'validation.invalid-payload', $e->getMessage(), $envelope);
            return;
        }

        $mediaIngestSettings = $this->mediaIngestSettings($claims->projectCode);
        if (!is_array($mediaIngestSettings)) {
            $this->sendError($conn, 'media.ingest-unavailable', 'Media chunk ingest is not configured for this integration.', $envelope);
            return;
        }

        $queued = $this->mediaChunkQueue->enqueue($claims, $room, $this->sessionId($conn), $payload);
        $this->sendAck($conn, $envelope, [
            'accepted' => true,
            'delivery' => 'queued',
            'chunk_id' => $queued->chunk_id,
            'media_id' => $payload['media_id'] ?? null,
            'segment_key' => $payload['segment_key'] ?? null,
            'chunk_index' => $payload['chunk_index'],
            'correlation_id' => $payload['correlation_id'] ?? null,
        ]);

        $chunkBytes = $this->decodedBase64Length((string) $payload['chunk_data'], 'payload.chunk_data');
        $this->metrics->increment('media.chunk.publish');
        $this->telemetry->record(
            'media.chunk.publish',
            $claims,
            bytesIn: $chunkBytes
        );
        $this->sessionRecorder->touch($this->sessionId($conn), 'connected', null, count($this->connectionRooms($conn)));
    }

    private function handleMediaChunkPrepare(ConnectionInterface $conn, RealtimeEnvelope $envelope): void
    {
        $room = $this->requiredString($envelope->room, 'room');
        $claims = $this->claims($conn);

        if (!$this->authorizeRoomJoin($claims, $room)) {
            $this->sendError($conn, 'auth.room-denied', 'Room access denied.', $envelope);
            return;
        }

        if (!$this->isInRoom($conn, $room)) {
            $this->sendError($conn, 'room.not-joined', 'Join the room before preparing media chunks.', $envelope);
            return;
        }

        try {
            $payload = $this->normalizeMediaChunkPayload($envelope->payload, requireChunkData: false);
            $transferId = $this->requiredString($payload['transfer_id'] ?? null, 'payload.transfer_id');
            $declaredBytes = $this->requiredInteger($payload['total_bytes'] ?? null, 'payload.total_bytes');
            if ($declaredBytes < 0) {
                throw new InvalidArgumentException('Missing or invalid payload.total_bytes.');
            }
        } catch (InvalidArgumentException $e) {
            $this->sendError($conn, 'validation.invalid-payload', $e->getMessage(), $envelope);
            return;
        }

        $mediaIngestSettings = $this->mediaIngestSettings($claims->projectCode);
        if (!is_array($mediaIngestSettings)) {
            $this->sendError($conn, 'media.ingest-unavailable', 'Media chunk ingest is not configured for this integration.', $envelope);
            return;
        }

        if (!((bool) ($mediaIngestSettings['binary_enabled'] ?? false))) {
            $this->sendError($conn, 'media.binary-unavailable', 'Binary media chunk ingest is not enabled for this integration.', $envelope);
            return;
        }

        $maxBytes = (int) ($mediaIngestSettings['max_binary_chunk_bytes'] ?? config('realtime.media_chunk_binary_max_bytes', 2 * 1024 * 1024));
        if ($maxBytes > 0 && $declaredBytes > $maxBytes) {
            $this->sendError($conn, 'media.binary-too-large', 'The binary media chunk exceeds the configured size limit.', $envelope);
            return;
        }

        $state = $this->connectionState($conn);
        $pending = $this->activeBinaryMediaTransfers($state);
        if (isset($pending[$transferId])) {
            $this->sendError($conn, 'media.duplicate-transfer', 'A binary media transfer with this transfer_id is already pending.', $envelope);
            return;
        }

        $pending[$transferId] = [
            'request_id' => $envelope->id,
            'room' => $room,
            'payload' => $payload,
            'expected_bytes' => $declaredBytes,
            'expires_at' => time() + (int) config('realtime.media_chunk_binary_prepare_ttl_seconds', 30),
        ];
        $state['binary_media_transfers'] = $pending;
        $this->connections[$conn] = $state;

        $this->sendAck($conn, $envelope, [
            'accepted' => true,
            'delivery' => 'awaiting_binary',
            'transfer_id' => $transferId,
            'media_id' => $payload['media_id'] ?? null,
            'segment_key' => $payload['segment_key'] ?? null,
            'chunk_index' => $payload['chunk_index'],
            'correlation_id' => $payload['correlation_id'] ?? null,
            'expected_bytes' => $declaredBytes,
            'expires_at' => (new DateTimeImmutable('@' . $pending[$transferId]['expires_at']))->format(DATE_ATOM),
        ]);
    }

    private function handleSandboxAttachmentChunkPublish(ConnectionInterface $conn, RealtimeEnvelope $envelope): void
    {
        $room = $this->requiredString($envelope->room, 'room');
        $claims = $this->claims($conn);

        if (!$claims->hasCapability('chat.publish')) {
            $this->sendError($conn, 'auth.missing-capability', 'The realtime token does not allow sandbox attachment publishing.', $envelope);
            return;
        }

        if (!$this->authorizeRoomJoin($claims, $room) || !str_starts_with($room, 'chat.thread.')) {
            $this->sendError($conn, 'auth.room-denied', 'Room access denied.', $envelope);
            return;
        }

        if (!$this->isInRoom($conn, $room)) {
            $this->sendError($conn, 'room.not-joined', 'Join the room before publishing sandbox attachment chunks.', $envelope);
            return;
        }

        $transferId = $this->requiredString($envelope->payload['transfer_id'] ?? null, 'payload.transfer_id');
        $attachmentId = $this->requiredString($envelope->payload['attachment_id'] ?? null, 'payload.attachment_id');
        $name = $this->requiredString($envelope->payload['name'] ?? null, 'payload.name');
        $kind = $this->nullableString($envelope->payload['kind'] ?? null, 'payload.kind') ?? 'file';
        $mimeType = $this->nullableString($envelope->payload['mime_type'] ?? null, 'payload.mime_type');
        $sizeLabel = $this->nullableString($envelope->payload['size_label'] ?? null, 'payload.size_label');
        $totalBytes = $this->requiredInteger($envelope->payload['total_bytes'] ?? null, 'payload.total_bytes');
        $chunkIndex = $this->requiredInteger($envelope->payload['chunk_index'] ?? null, 'payload.chunk_index');
        $chunkTotal = $this->requiredInteger($envelope->payload['chunk_total'] ?? null, 'payload.chunk_total');
        $chunkData = $this->requiredString($envelope->payload['chunk_data'] ?? null, 'payload.chunk_data');
        $chunkBytes = $this->decodedBase64Length($chunkData, 'payload.chunk_data');

        if (!$this->validateAttachmentChunkAgainstPolicy($conn, $claims, $transferId, $attachmentId, $totalBytes, $chunkBytes, $envelope)) {
            return;
        }

        $event = [
            'transfer_id' => $transferId,
            'attachment_id' => $attachmentId,
            'name' => $name,
            'kind' => $kind,
            'mime_type' => $mimeType,
            'size_label' => $sizeLabel,
            'byte_size' => $totalBytes,
            'chunk_index' => $chunkIndex,
            'chunk_total' => $chunkTotal,
            'chunk_data' => $chunkData,
            'sender' => [
                'user_id' => $claims->userId,
                'display_name' => $claims->displayName,
            ],
            'sent_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ];

        $fanoutCount = $this->broadcast($room, 'sandbox.attachment.chunk.event', $event);
        $this->metrics->increment('sandbox.attachment.chunk');
        $this->telemetry->record(
            'sandbox.attachment.chunk',
            $claims,
            bytesIn: $chunkBytes,
            bytesOut: $fanoutCount * $this->measureEventBytes('sandbox.attachment.chunk.event', $room, $event)
        );
        $this->sessionRecorder->touch($this->sessionId($conn), 'connected', null, count($this->connectionRooms($conn)));
        $this->sendAck($conn, $envelope, [
            'published' => true,
            'transfer_id' => $transferId,
            'attachment_id' => $attachmentId,
            'chunk_index' => $chunkIndex,
        ]);
    }

    private function joinRoom(ConnectionInterface $conn, string $room): void
    {
        $key = spl_object_hash($conn);
        $state = $this->connectionState($conn);
        $state['rooms'][$room] = true;
        $this->connections[$conn] = $state;
        $this->roomMembers[$room][$key] = true;
    }

    /**
     * @param mixed $value
     * @return array<int, string|array<string, string|null>>
     */
    private function normalizeChatAttachments(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $attachments = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $attachments[] = $item;
                continue;
            }

            if (!is_array($item)) {
                continue;
            }

            $name = $this->nullableString($item['name'] ?? null, 'payload.attachments.name');
            if ($name === null || $name === '') {
                continue;
            }

            $attachments[] = [
                'transfer_id' => $this->nullableString($item['transfer_id'] ?? null, 'payload.attachments.transfer_id'),
                'attachment_id' => $this->nullableString($item['attachment_id'] ?? null, 'payload.attachments.attachment_id'),
                'kind' => $this->nullableString($item['kind'] ?? null, 'payload.attachments.kind') ?? 'file',
                'name' => $name,
                'mime_type' => $this->nullableString($item['mime_type'] ?? null, 'payload.attachments.mime_type'),
                'url' => $this->nullableString($item['url'] ?? null, 'payload.attachments.url'),
                'preview_url' => $this->nullableString($item['preview_url'] ?? null, 'payload.attachments.preview_url'),
                'poster_url' => $this->nullableString($item['poster_url'] ?? null, 'payload.attachments.poster_url'),
                'size_label' => $this->nullableString($item['size_label'] ?? null, 'payload.attachments.size_label'),
                'byte_size' => $this->nullableInteger($item['byte_size'] ?? null, 'payload.attachments.byte_size'),
            ];
        }

        return $attachments;
    }

    private function leaveRoom(ConnectionInterface $conn, string $room): void
    {
        $key = spl_object_hash($conn);
        $state = $this->connectionState($conn);
        unset($state['rooms'][$room]);
        $this->connections[$conn] = $state;
        unset($this->roomMembers[$room][$key]);
    }

    private function broadcast(string $room, string $type, array $payload): int
    {
        return $this->broadcastWithMeta($room, $type, $payload, []);
    }

    public function publishServerEvent(string $room, string $type, array $payload, array $meta = []): int
    {
        return $this->broadcastWithMeta($room, $type, $payload, $meta);
    }

    public function measureServerEventBytes(string $type, ?string $room, array $payload, array $meta = []): int
    {
        return $this->measureEventBytes($type, $room, $payload, $meta);
    }

    private function broadcastWithMeta(string $room, string $type, array $payload, array $meta): int
    {
        $count = 0;

        foreach ($this->roomMembers[$room] ?? [] as $key => $_) {
            $conn = $this->connectionByKey($key);
            if ($conn instanceof ConnectionInterface) {
                $this->sendEvent($conn, $type, $room, $payload, $meta);
                $count += 1;
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $request
     */
    private function broadcastProductQueryFailure(string $room, array $request, string $code, string $message): void
    {
        $this->broadcastWithMeta($room, 'product.query.response', [
            'schema_version' => (int) ($request['schema_version'] ?? 1),
            'request_id' => (string) ($request['request_id'] ?? ''),
            'query' => (string) ($request['query'] ?? ''),
            'context' => is_array($request['context'] ?? null) ? $request['context'] : (object) [],
            'status' => 'error',
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], [
            'source' => 'realtime',
            'source_module' => 'product-query-forwarder',
        ]);
    }

    private function sendAck(ConnectionInterface $conn, RealtimeEnvelope $request, array $payload): void
    {
        $this->sendEnvelope($conn, new RealtimeEnvelope(
            namespace: 'pbb.realtime.v1',
            phase: 'ack',
            id: $request->id,
            type: $request->type,
            room: $request->room,
            payload: $payload,
            meta: [
                'service' => $this->serviceName,
            ],
        ));

        $this->cacheResponse($conn, $request->id, RealtimeEnvelope::encode([
            'namespace' => 'pbb.realtime.v1',
            'phase' => 'ack',
            'id' => $request->id,
            'type' => $request->type,
            'room' => $request->room,
            'payload' => $payload,
            'meta' => [
                'service' => $this->serviceName,
            ],
        ]));
    }

    private function sendEvent(ConnectionInterface $conn, string $type, ?string $room, array $payload, array $meta = []): void
    {
        $this->sendEnvelope($conn, new RealtimeEnvelope(
            namespace: 'pbb.realtime.v1',
            phase: 'event',
            id: 'evt_' . bin2hex(random_bytes(8)),
            type: $type,
            room: $room,
            payload: $payload,
            meta: [
                'service' => $this->serviceName,
                ...$meta,
            ],
        ));
    }

    private function measureEventBytes(string $type, ?string $room, array $payload, array $meta = []): int
    {
        return strlen(RealtimeEnvelope::encode([
            'namespace' => 'pbb.realtime.v1',
            'phase' => 'event',
            'id' => 'evt_measure',
            'type' => $type,
            'room' => $room,
            'payload' => $payload,
            'meta' => [
                'service' => $this->serviceName,
                ...$meta,
            ],
        ]));
    }

    private function sendSystem(ConnectionInterface $conn, string $type, array $payload): void
    {
        $this->sendEnvelope($conn, new RealtimeEnvelope(
            namespace: 'pbb.realtime.v1',
            phase: 'system',
            id: 'sys_' . bin2hex(random_bytes(8)),
            type: $type,
            room: null,
            payload: $payload,
            meta: [
                'service' => $this->serviceName,
            ],
        ));
    }

    private function sendError(ConnectionInterface $conn, string $code, string $message, ?RealtimeEnvelope $request = null): void
    {
        $this->telemetry->record(
            $request?->type ?? 'system.error',
            $this->optionalClaims($conn),
            errorCount: 1,
            rateLimitedCount: $code === 'rate-limited' ? 1 : 0
        );

        $this->sendEnvelope($conn, new RealtimeEnvelope(
            namespace: 'pbb.realtime.v1',
            phase: 'error',
            id: $request?->id ?? 'err_' . bin2hex(random_bytes(8)),
            type: $request?->type ?? 'system.error',
            room: $request?->room,
            payload: [
                'code' => $code,
                'message' => $message,
            ],
            meta: [
                'service' => $this->serviceName,
            ],
        ));

        if ($request !== null) {
            $this->cacheResponse($conn, $request->id, RealtimeEnvelope::encode([
                'namespace' => 'pbb.realtime.v1',
                'phase' => 'error',
                'id' => $request->id,
                'type' => $request->type,
                'room' => $request->room,
                'payload' => [
                    'code' => $code,
                    'message' => $message,
                ],
                'meta' => [
                    'service' => $this->serviceName,
                ],
            ]));
        }
    }

    private function sendEnvelope(ConnectionInterface $conn, RealtimeEnvelope $envelope): void
    {
        $conn->send(RealtimeEnvelope::encode($envelope->toArray()));
    }

    private function cacheResponse(ConnectionInterface $conn, string $requestId, string $responseJson): void
    {
        $state = $this->connectionState($conn);
        $cache = is_array($state['request_cache'] ?? null) ? $state['request_cache'] : [];
        $cache[$requestId] = $responseJson;
        $state['request_cache'] = $cache;
        $this->connections[$conn] = $state;
    }

    private function hasCachedResponse(ConnectionInterface $conn, string $requestId): bool
    {
        $state = $this->connectionState($conn);
        $cache = is_array($state['request_cache'] ?? null) ? $state['request_cache'] : [];

        return isset($cache[$requestId]) && is_string($cache[$requestId]);
    }

    private function cachedResponse(ConnectionInterface $conn, string $requestId): string
    {
        $state = $this->connectionState($conn);
        $cache = is_array($state['request_cache'] ?? null) ? $state['request_cache'] : [];

        return (string) ($cache[$requestId] ?? '');
    }

    private function rejectConnection(ConnectionInterface $conn, string $reason, string $message): void
    {
        $this->sendError($conn, $this->errorCodeForReason($reason), $message);
        $conn->close();
    }

    private function errorCodeForReason(string $reason): string
    {
        return match ($reason) {
            'expired-token' => 'auth.expired-token',
            'invalid-audience' => 'auth.invalid-audience',
            'invalid-issuer', 'invalid-token', 'invalid-claims', 'missing-signing-secret' => 'auth.invalid-token',
            default => 'auth.invalid-token',
        };
    }

    private function attachConnection(ConnectionInterface $conn): void
    {
        if (!$this->connections->contains($conn)) {
            $this->connections->attach($conn, [
                'claims' => null,
                'session_id' => null,
                'rooms' => [],
                'presence' => [],
                'rate_window' => [
                    'window' => 0,
                    'count' => 0,
                ],
                'join_rate_window' => [
                    'window' => 0,
                    'count' => 0,
                ],
                'request_cache' => [],
                'binary_media_transfers' => [],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionState(ConnectionInterface $conn): array
    {
        $this->attachConnection($conn);
        $state = $this->connections[$conn];

        return is_array($state) ? $state : [];
    }

    private function sessionId(ConnectionInterface $conn): string
    {
        $state = $this->connectionState($conn);

        return is_string($state['session_id'] ?? null) ? $state['session_id'] : '';
    }

    private function claims(ConnectionInterface $conn): RealtimeTokenClaims
    {
        $state = $this->connectionState($conn);
        $claims = $state['claims'] ?? null;

        if (!$claims instanceof RealtimeTokenClaims) {
            throw new InvalidArgumentException('The connection is not authenticated.');
        }

        return $claims;
    }

    private function optionalClaims(ConnectionInterface $conn): ?RealtimeTokenClaims
    {
        $state = $this->connectionState($conn);
        $claims = $state['claims'] ?? null;

        return $claims instanceof RealtimeTokenClaims ? $claims : null;
    }

    private function isAuthenticated(ConnectionInterface $conn): bool
    {
        try {
            $this->claims($conn);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private function connectionRooms(ConnectionInterface $conn): array
    {
        $state = $this->connectionState($conn);
        return is_array($state['rooms'] ?? null) ? array_keys($state['rooms']) : [];
    }

    private function isInRoom(ConnectionInterface $conn, string $room): bool
    {
        return in_array($room, $this->connectionRooms($conn), true);
    }

    private function connectionByKey(string $key): ?ConnectionInterface
    {
        foreach ($this->connections as $conn) {
            if (spl_object_hash($conn) === $key) {
                return $conn;
            }
        }

        return null;
    }

    private function authorizeRoomJoin(RealtimeTokenClaims $claims, string $room): bool
    {
        if ($room === '') {
            return false;
        }

        if (!$claims->hasCapability('room.join')) {
            return false;
        }

        return $this->roomPolicy->allows($claims, $room);
    }

    private function checkRateLimit(ConnectionInterface $conn, string $type, int $byteCost = 0): bool
    {
        $state = $this->connectionState($conn);
        $now = time();
        $window = intdiv($now, 60);
        if (str_starts_with($type, 'room.join')) {
            $rateKey = 'join_rate_window';
            $rate = is_array($state[$rateKey] ?? null) ? $state[$rateKey] : ['window' => $window, 'count' => 0];

            if (($rate['window'] ?? 0) !== $window) {
                $rate = ['window' => $window, 'count' => 0];
            }

            if ($this->roomJoinRateLimitPerMinute > 0 && ($rate['count'] ?? 0) >= $this->roomJoinRateLimitPerMinute) {
                return true;
            }

            $rate['count'] = ($rate['count'] ?? 0) + 1;
            $state[$rateKey] = $rate;
            $this->connections[$conn] = $state;

            return false;
        }

        if ($type === 'sandbox.attachment.chunk.publish' && $this->isAuthenticated($conn)) {
            $policy = $this->attachmentPolicy($this->claims($conn));
            $eventLimit = (int) ($policy['chunk_events_per_minute'] ?? 0);
            $byteLimit = (int) ($policy['chunk_bytes_per_minute'] ?? 0);

            $eventRate = is_array($state['attachment_rate_window'] ?? null) ? $state['attachment_rate_window'] : ['window' => $window, 'count' => 0];
            if (($eventRate['window'] ?? 0) !== $window) {
                $eventRate = ['window' => $window, 'count' => 0];
            }

            if ($eventLimit > 0 && ($eventRate['count'] ?? 0) >= $eventLimit) {
                return true;
            }

            $byteRate = is_array($state['attachment_byte_window'] ?? null) ? $state['attachment_byte_window'] : ['window' => $window, 'bytes' => 0];
            if (($byteRate['window'] ?? 0) !== $window) {
                $byteRate = ['window' => $window, 'bytes' => 0];
            }

            if ($byteLimit > 0 && (($byteRate['bytes'] ?? 0) + max(0, $byteCost)) > $byteLimit) {
                return true;
            }

            $eventRate['count'] = ($eventRate['count'] ?? 0) + 1;
            $byteRate['bytes'] = ($byteRate['bytes'] ?? 0) + max(0, $byteCost);
            $state['attachment_rate_window'] = $eventRate;
            $state['attachment_byte_window'] = $byteRate;
            $this->connections[$conn] = $state;

            return false;
        }

        $rateKey = 'rate_window';
        $rate = is_array($state[$rateKey] ?? null) ? $state[$rateKey] : ['window' => $window, 'count' => 0];

        if (($rate['window'] ?? 0) !== $window) {
            $rate = ['window' => $window, 'count' => 0];
        }

        if ($this->messageRateLimitPerMinute > 0 && ($rate['count'] ?? 0) >= $this->messageRateLimitPerMinute) {
            return true;
        }

        $rate['count'] = ($rate['count'] ?? 0) + 1;
        $state[$rateKey] = $rate;
        $this->connections[$conn] = $state;

        return false;
    }

    private function checkProductQueryRateLimit(ConnectionInterface $conn, int $limit): bool
    {
        if ($limit <= 0) {
            return false;
        }

        $state = $this->connectionState($conn);
        $window = intdiv(time(), 60);
        $rate = is_array($state['product_query_rate_window'] ?? null)
            ? $state['product_query_rate_window']
            : ['window' => $window, 'count' => 0];

        if (($rate['window'] ?? 0) !== $window) {
            $rate = ['window' => $window, 'count' => 0];
        }

        if (($rate['count'] ?? 0) >= $limit) {
            return true;
        }

        $rate['count'] = ($rate['count'] ?? 0) + 1;
        $state['product_query_rate_window'] = $rate;
        $this->connections[$conn] = $state;

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function currentPresenceRoster(string $room): array
    {
        $entries = $this->roomPresence[$room] ?? [];
        if ($entries === []) {
            return [];
        }

        $now = time();
        $active = [];

        foreach ($entries as $sessionId => $presence) {
            if (!is_array($presence)) {
                continue;
            }

            $expiresAt = strtotime((string) ($presence['expires_at'] ?? ''));
            if ($expiresAt !== false && $expiresAt < $now) {
                unset($this->roomPresence[$room][$sessionId]);
                continue;
            }

            $active[] = $presence;
        }

        if (($this->roomPresence[$room] ?? []) === []) {
            unset($this->roomPresence[$room]);
        }

        return $active;
    }

    private function isBinaryMediaFrame(string $message): bool
    {
        return str_starts_with($message, self::BINARY_MEDIA_MAGIC);
    }

    private function handleBinaryMediaFrame(ConnectionInterface $conn, string $message): void
    {
        if (!$this->isAuthenticated($conn)) {
            $this->sendError($conn, 'auth.required', 'Authenticate before sending binary media chunks.');
            return;
        }

        try {
            [$transferId, $binaryPayload] = $this->decodeBinaryMediaFrame($message);
        } catch (InvalidArgumentException $e) {
            $this->sendError($conn, 'media.invalid-binary-frame', $e->getMessage());
            return;
        }

        $state = $this->connectionState($conn);
        $pending = $this->activeBinaryMediaTransfers($state);
        $transfer = $pending[$transferId] ?? null;
        if (!is_array($transfer)) {
            $state['binary_media_transfers'] = $pending;
            $this->connections[$conn] = $state;
            $this->sendError($conn, 'media.unknown-transfer', 'No active binary media transfer matches this transfer_id.');
            return;
        }

        $expectedBytes = (int) ($transfer['expected_bytes'] ?? -1);
        if ($expectedBytes !== strlen($binaryPayload)) {
            unset($pending[$transferId]);
            $state['binary_media_transfers'] = $pending;
            $this->connections[$conn] = $state;
            $this->sendError($conn, 'media.byte-length-mismatch', 'Binary media chunk byte length does not match the prepared transfer.');
            return;
        }

        $claims = $this->claims($conn);
        $room = (string) ($transfer['room'] ?? '');
        $payload = is_array($transfer['payload'] ?? null) ? $transfer['payload'] : [];

        $queued = $this->mediaChunkQueue->enqueueBinary($claims, $room, $this->sessionId($conn), $payload, $binaryPayload);
        unset($pending[$transferId]);
        $state['binary_media_transfers'] = $pending;
        $this->connections[$conn] = $state;

        $queuedPayload = [
            'accepted' => true,
            'delivery' => 'queued',
            'chunk_id' => $queued->chunk_id,
            'transfer_id' => $transferId,
            'media_id' => $payload['media_id'] ?? null,
            'segment_key' => $payload['segment_key'] ?? null,
            'chunk_index' => $payload['chunk_index'] ?? null,
            'correlation_id' => $payload['correlation_id'] ?? null,
            'bytes' => strlen($binaryPayload),
        ];

        $this->sendEvent($conn, 'media.chunk.queued', $room, $queuedPayload, [
            'source' => 'realtime',
        ]);

        $this->metrics->increment('media.chunk.publish');
        $this->telemetry->record(
            'media.chunk.publish',
            $claims,
            bytesIn: strlen($binaryPayload)
        );
        $this->sessionRecorder->touch($this->sessionId($conn), 'connected', null, count($this->connectionRooms($conn)));
    }

    /**
     * @return array{0:string,1:string}
     */
    private function decodeBinaryMediaFrame(string $message): array
    {
        if (strlen($message) < 9) {
            throw new InvalidArgumentException('Binary media frame is too short.');
        }

        $magic = substr($message, 0, 4);
        if ($magic !== self::BINARY_MEDIA_MAGIC) {
            throw new InvalidArgumentException('Binary media frame magic is invalid.');
        }

        $version = ord($message[4]);
        if ($version !== self::BINARY_MEDIA_VERSION) {
            throw new InvalidArgumentException('Binary media frame version is not supported.');
        }

        $headerLength = unpack('N', substr($message, 5, 4))[1] ?? 0;
        if (!is_int($headerLength) || $headerLength < 2 || $headerLength > 4096) {
            throw new InvalidArgumentException('Binary media frame header length is invalid.');
        }

        if (strlen($message) < 9 + $headerLength) {
            throw new InvalidArgumentException('Binary media frame header is incomplete.');
        }

        $headerJson = substr($message, 9, $headerLength);
        try {
            $header = json_decode($headerJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('Binary media frame header is invalid JSON.');
        }

        if (!is_array($header)) {
            throw new InvalidArgumentException('Binary media frame header must be an object.');
        }

        $transferId = $this->requiredString($header['transfer_id'] ?? null, 'header.transfer_id');
        $binaryPayload = substr($message, 9 + $headerLength);

        return [$transferId, $binaryPayload];
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, array<string, mixed>>
     */
    private function activeBinaryMediaTransfers(array $state): array
    {
        $pending = is_array($state['binary_media_transfers'] ?? null) ? $state['binary_media_transfers'] : [];
        $now = time();

        foreach ($pending as $transferId => $transfer) {
            if (!is_string($transferId) || !is_array($transfer) || (int) ($transfer['expires_at'] ?? 0) <= $now) {
                unset($pending[$transferId]);
            }
        }

        return $pending;
    }

    private function requiredString(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Missing or invalid {$field}.");
        }

        return trim($value);
    }

    private function requiredInteger(mixed $value, string $field): int
    {
        if (!is_int($value) && !(is_string($value) && is_numeric($value))) {
            throw new InvalidArgumentException("Missing or invalid {$field}.");
        }

        return (int) $value;
    }

    /**
     * @return array<mixed>
     */
    private function requiredArray(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException("Missing or invalid {$field}.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeProductQueryPayload(array $payload): array
    {
        $schemaVersion = $this->requiredInteger($payload['schema_version'] ?? null, 'payload.data.schema_version');
        if ($schemaVersion < 1) {
            throw new InvalidArgumentException('Missing or invalid payload.data.schema_version.');
        }

        $requestId = $this->requiredString($payload['request_id'] ?? null, 'payload.data.request_id');
        $query = $this->requiredString($payload['query'] ?? null, 'payload.data.query');

        $context = $this->optionalObject($payload['context'] ?? null, 'payload.data.context');
        $projection = $this->optionalObject($payload['projection'] ?? null, 'payload.data.projection');
        $clientState = $this->optionalObject($payload['client_state'] ?? null, 'payload.data.client_state');

        return array_filter([
            'schema_version' => $schemaVersion,
            'request_id' => $requestId,
            'query' => $query,
            'context' => $context,
            'projection' => $projection,
            'client_state' => $clientState,
        ], static fn ($value) => $value !== null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function optionalObject(mixed $value, string $field): ?array
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException("Missing or invalid {$field}.");
        }

        return $value;
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function normalizeMediaChunkPayload(mixed $value, bool $requireChunkData = true): array
    {
        $payload = $this->requiredArray($value, 'payload');
        $mediaId = $this->nullableString($payload['media_id'] ?? null, 'payload.media_id');
        $segmentKey = $this->nullableString($payload['segment_key'] ?? null, 'payload.segment_key');

        if ($mediaId === null && $segmentKey === null) {
            throw new InvalidArgumentException('Missing or invalid payload.media_id or payload.segment_key.');
        }

        $chunkIndex = $this->requiredInteger($payload['chunk_index'] ?? null, 'payload.chunk_index');
        if ($chunkIndex < 0) {
            throw new InvalidArgumentException('Missing or invalid payload.chunk_index.');
        }

        $chunkTotal = $this->nullableInteger($payload['chunk_total'] ?? null, 'payload.chunk_total');
        if ($chunkTotal !== null && $chunkTotal < 1) {
            throw new InvalidArgumentException('Missing or invalid payload.chunk_total.');
        }

        $totalBytes = $this->nullableInteger($payload['total_bytes'] ?? null, 'payload.total_bytes');
        if ($totalBytes !== null && $totalBytes < 0) {
            throw new InvalidArgumentException('Missing or invalid payload.total_bytes.');
        }

        $chunkData = null;
        if ($requireChunkData) {
            $chunkData = $this->requiredString($payload['chunk_data'] ?? null, 'payload.chunk_data');
            $this->decodedBase64Length($chunkData, 'payload.chunk_data');
        }

        return array_filter([
            'transfer_id' => $this->nullableString($payload['transfer_id'] ?? null, 'payload.transfer_id'),
            'incident_id' => $this->nullableString($payload['incident_id'] ?? null, 'payload.incident_id'),
            'call_session_id' => $this->nullableString($payload['call_session_id'] ?? null, 'payload.call_session_id'),
            'media_id' => $mediaId,
            'segment_key' => $segmentKey,
            'type' => $this->requiredString($payload['type'] ?? null, 'payload.type'),
            'peer_user_id' => $this->nullableString($payload['peer_user_id'] ?? null, 'payload.peer_user_id'),
            'peer_role' => $this->nullableString($payload['peer_role'] ?? null, 'payload.peer_role'),
            'track_kind' => $this->requiredString($payload['track_kind'] ?? null, 'payload.track_kind'),
            'mime_type' => $this->requiredString($payload['mime_type'] ?? null, 'payload.mime_type'),
            'extension' => $this->nullableString($payload['extension'] ?? null, 'payload.extension'),
            'chunk_index' => $chunkIndex,
            'chunk_total' => $chunkTotal,
            'total_bytes' => $totalBytes,
            'chunk_data' => $chunkData,
            'correlation_id' => $this->nullableString($payload['correlation_id'] ?? null, 'payload.correlation_id'),
        ], static fn ($value) => $value !== null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mediaIngestSettings(string $projectCode): ?array
    {
        $project = \App\Models\RealtimeProject::query()
            ->where('project_code', $projectCode)
            ->first();

        $settings = is_array($project?->media_ingest_settings) ? $project->media_ingest_settings : null;
        if (!is_array($settings) || !((bool) ($settings['enabled'] ?? false))) {
            return null;
        }

        return $settings;
    }

    private function nullableInteger(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_int($value) && !(is_string($value) && is_numeric($value))) {
            throw new InvalidArgumentException("Missing or invalid {$field}.");
        }

        return (int) $value;
    }

    private function nullableString(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException("Missing or invalid {$field}.");
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function nullableRawString(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException("Missing or invalid {$field}.");
        }

        return $value === '' ? null : $value;
    }

    /**
     * @param mixed $value
     * @return array<string, string|int|float|bool|null>|null
     */
    private function nullablePresenceMeta(mixed $value, string $field): ?array
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException("Missing or invalid {$field}.");
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) || trim($key) === '') {
                throw new InvalidArgumentException("Missing or invalid {$field}.");
            }

            if (is_array($item) || is_object($item)) {
                throw new InvalidArgumentException("Missing or invalid {$field}.");
            }

            if (!is_string($item) && !is_int($item) && !is_float($item) && !is_bool($item) && $item !== null) {
                throw new InvalidArgumentException("Missing or invalid {$field}.");
            }

            $normalized[trim($key)] = is_string($item) ? trim($item) : $item;
        }

        if ($normalized === []) {
            return null;
        }

        $encoded = json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || strlen($encoded) > 1024) {
            throw new InvalidArgumentException("Missing or invalid {$field}.");
        }

        return $normalized;
    }

    private function decodedBase64Length(string $value, string $field): int
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            throw new InvalidArgumentException("Missing or invalid {$field}.");
        }

        return strlen($decoded);
    }

    /**
     * @return array<string, int>
     */
    private function attachmentPolicy(RealtimeTokenClaims $claims): array
    {
        $policy = is_array($claims->attachmentPolicy) ? $claims->attachmentPolicy : [];

        return [
            'max_attachment_count' => max(0, (int) ($policy['max_attachment_count'] ?? 0)),
            'max_attachment_bytes' => max(0, (int) ($policy['max_attachment_bytes'] ?? 0)),
            'max_total_bytes_per_message' => max(0, (int) ($policy['max_total_bytes_per_message'] ?? 0)),
            'chunk_events_per_minute' => max(0, (int) ($policy['chunk_events_per_minute'] ?? 0)),
            'chunk_bytes_per_minute' => max(0, (int) ($policy['chunk_bytes_per_minute'] ?? 0)),
        ];
    }

    /**
     * @param array<int, string|array<string, string|int|null>> $attachments
     */
    private function validateChatAttachmentsAgainstPolicy(array $attachments, RealtimeTokenClaims $claims, RealtimeEnvelope $envelope, ConnectionInterface $conn): bool
    {
        $policy = $this->attachmentPolicy($claims);
        $count = count($attachments);
        $totalBytes = 0;

        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }

            $byteSize = max(0, (int) ($attachment['byte_size'] ?? 0));
            $totalBytes += $byteSize;

            if (($policy['max_attachment_bytes'] ?? 0) > 0 && $byteSize > $policy['max_attachment_bytes']) {
                $this->sendError($conn, 'attachment-too-large', 'Attachment exceeds the maximum allowed size.', $envelope);
                return false;
            }
        }

        if (($policy['max_attachment_count'] ?? 0) > 0 && $count > $policy['max_attachment_count']) {
            $this->sendError($conn, 'attachment-count-exceeded', 'Too many attachments were supplied.', $envelope);
            return false;
        }

        if (($policy['max_total_bytes_per_message'] ?? 0) > 0 && $totalBytes > $policy['max_total_bytes_per_message']) {
            $this->sendError($conn, 'attachment-total-bytes-exceeded', 'Total attachment bytes exceed the message limit.', $envelope);
            return false;
        }

        return true;
    }

    private function validateAttachmentChunkAgainstPolicy(
        ConnectionInterface $conn,
        RealtimeTokenClaims $claims,
        string $transferId,
        string $attachmentId,
        int $totalBytes,
        int $chunkBytes,
        RealtimeEnvelope $envelope
    ): bool {
        $policy = $this->attachmentPolicy($claims);
        if (($policy['max_attachment_bytes'] ?? 0) > 0 && $totalBytes > $policy['max_attachment_bytes']) {
            $this->sendError($conn, 'attachment-too-large', 'Attachment exceeds the maximum allowed size.', $envelope);
            return false;
        }

        $state = $this->connectionState($conn);
        $transfers = is_array($state['sandbox_transfers'] ?? null) ? $state['sandbox_transfers'] : [];
        $transfer = is_array($transfers[$transferId] ?? null) ? $transfers[$transferId] : [
            'attachment_id' => $attachmentId,
            'total_bytes' => $totalBytes,
            'bytes_received' => 0,
        ];

        if (($transfer['total_bytes'] ?? $totalBytes) !== $totalBytes) {
            $this->sendError($conn, 'attachment-transfer-mismatch', 'Attachment transfer bytes changed during upload.', $envelope);
            return false;
        }

        $transfer['bytes_received'] = (int) ($transfer['bytes_received'] ?? 0) + $chunkBytes;
        if ($transfer['bytes_received'] > $totalBytes) {
            $this->sendError($conn, 'attachment-transfer-overflow', 'Attachment transfer exceeded declared bytes.', $envelope);
            return false;
        }

        $transfers[$transferId] = $transfer;
        $state['sandbox_transfers'] = $transfers;
        $this->connections[$conn] = $state;

        return true;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $result[] = trim($item);
            }
        }

        return array_values(array_unique($result));
    }

    private function buildPresencePayload(
        RealtimeTokenClaims $claims,
        string $sessionId,
        string $state,
        ?string $statusText,
        string $updatedAt,
        ?array $presenceMeta = null
    ): array
    {
        $allowedStates = ['online', 'offline', 'idle', 'busy', 'in_call'];

        if (!in_array($state, $allowedStates, true)) {
            throw new InvalidArgumentException('Missing or invalid payload.state.');
        }

        $expiresAt = (new DateTimeImmutable())->modify('+' . $this->presenceStaleSeconds . ' seconds')->format(DATE_ATOM);

        $payload = [
            'subject' => [
                'project_code' => $claims->projectCode,
                'app_code' => $claims->appCode,
                'user_id' => $claims->userId,
                'tenant_id' => $claims->tenantId,
                'org_id' => $claims->orgId,
                'workspace_id' => $claims->workspaceId,
                'session_id' => $sessionId,
            ],
            'state' => $state,
            'status_text' => $statusText,
            'updated_at' => $updatedAt,
            'expires_at' => $expiresAt,
        ];

        if ($presenceMeta !== null) {
            $payload['meta'] = $presenceMeta;
        }

        return $payload;
    }
}
