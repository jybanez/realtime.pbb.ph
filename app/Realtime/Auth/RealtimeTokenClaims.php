<?php

namespace App\Realtime\Auth;

use DateTimeImmutable;

readonly class RealtimeTokenClaims
{
    /**
     * @param array<int, string> $roles
     * @param array<int, string> $capabilities
     * @param array<int, string> $allowedRooms
     * @param array<int, string> $allowedRoomPrefixes
     * @param array<string, mixed> $attachmentPolicy
     */
    public function __construct(
        public string $issuer,
        public string $subject,
        public string $audience,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $issuedAt,
        public ?string $tokenId,
        public string $projectCode,
        public string $appCode,
        public string $userId,
        public ?string $email,
        public ?string $displayName,
        public array $roles,
        public array $capabilities,
        public ?string $tenantId,
        public ?string $orgId,
        public ?string $workspaceId,
        public array $allowedRooms,
        public array $allowedRoomPrefixes,
        public ?string $origin,
        public array $attachmentPolicy,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        foreach (['iss', 'sub', 'aud', 'project_code', 'app_code', 'user_id'] as $key) {
            if (!array_key_exists($key, $payload) || !is_string($payload[$key]) || $payload[$key] === '') {
                throw new RealtimeTokenValidationException(
                    'invalid-claims',
                    "Missing or invalid {$key} claim."
                );
            }
        }

        if (!isset($payload['exp']) || !is_numeric($payload['exp'])) {
            throw new RealtimeTokenValidationException('invalid-claims', 'Missing or invalid exp claim.');
        }

        $roles = self::normalizeStringList($payload['roles'] ?? []);
        $capabilities = self::normalizeStringList($payload['capabilities'] ?? []);
        $allowedRooms = self::normalizeStringList($payload['allowed_rooms'] ?? []);
        $allowedRoomPrefixes = self::normalizeStringList($payload['allowed_room_prefixes'] ?? []);

        return new self(
            issuer: $payload['iss'],
            subject: $payload['sub'],
            audience: $payload['aud'],
            expiresAt: (new DateTimeImmutable())->setTimestamp((int) $payload['exp']),
            issuedAt: isset($payload['iat']) && is_numeric($payload['iat'])
                ? (new DateTimeImmutable())->setTimestamp((int) $payload['iat'])
                : null,
            tokenId: isset($payload['jti']) && is_string($payload['jti']) ? $payload['jti'] : null,
            projectCode: $payload['project_code'],
            appCode: $payload['app_code'],
            userId: $payload['user_id'],
            email: isset($payload['email']) && is_string($payload['email']) ? $payload['email'] : null,
            displayName: isset($payload['display_name']) && is_string($payload['display_name']) ? $payload['display_name'] : null,
            roles: $roles,
            capabilities: $capabilities,
            tenantId: isset($payload['tenant_id']) && is_string($payload['tenant_id']) ? $payload['tenant_id'] : null,
            orgId: isset($payload['org_id']) && is_string($payload['org_id']) ? $payload['org_id'] : null,
            workspaceId: isset($payload['workspace_id']) && is_string($payload['workspace_id']) ? $payload['workspace_id'] : null,
            allowedRooms: $allowedRooms,
            allowedRoomPrefixes: $allowedRoomPrefixes,
            origin: isset($payload['origin']) && is_string($payload['origin']) ? $payload['origin'] : null,
            attachmentPolicy: self::normalizeAssocArray($payload['attachment_policy'] ?? []),
        );
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private static function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $result[] = $item;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeAssocArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }
}
