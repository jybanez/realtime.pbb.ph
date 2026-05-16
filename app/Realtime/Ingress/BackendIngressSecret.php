<?php

namespace App\Realtime\Ingress;

class BackendIngressSecret
{
    public static function digest(string $secret): string
    {
        $normalized = trim($secret);

        return hash_hmac('sha256', $normalized, self::pepper());
    }

    public static function matchesDigest(string $providedSecret, ?string $storedDigest): bool
    {
        $normalizedDigest = trim((string) $storedDigest);
        if ($normalizedDigest === '') {
            return false;
        }

        return hash_equals($normalizedDigest, self::digest($providedSecret));
    }

    /**
     * @return array{backend_ingress_secret_hash: string, backend_ingress_secret_digest: string}
     */
    public static function attributesForStorage(string $secret): array
    {
        $normalized = trim($secret);

        return [
            'backend_ingress_secret_hash' => bcrypt($normalized),
            'backend_ingress_secret_digest' => self::digest($normalized),
        ];
    }

    private static function pepper(): string
    {
        $configured = trim((string) config('realtime.backend_ingress_secret_pepper', ''));
        if ($configured !== '') {
            return $configured;
        }

        return (string) config('app.key', 'pbb-realtime-backend-ingress-secret');
    }
}
