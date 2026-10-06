<?php

namespace Tests\Unit;

use Tests\TestCase;

class RealtimeDispatchDefaultsTest extends TestCase
{
    public function test_embedded_dispatch_requires_explicit_opt_in(): void
    {
        $key = 'REALTIME_EMBEDDED_MEDIA_CHUNK_DISPATCH_ENABLED';
        $previous = $_ENV[$key] ?? null;
        $serverPrevious = $_SERVER[$key] ?? null;
        $processPrevious = getenv($key);
        try {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
            $this->assertFalse((require config_path('realtime.php'))['embedded_media_chunk_dispatch_enabled']);
            $_ENV[$key] = 'false';
            $this->assertFalse((require config_path('realtime.php'))['embedded_media_chunk_dispatch_enabled']);
            $_ENV[$key] = 'true';
            $this->assertTrue((require config_path('realtime.php'))['embedded_media_chunk_dispatch_enabled']);
        } finally {
            if ($previous === null) { unset($_ENV[$key]); } else { $_ENV[$key] = $previous; }
            if ($serverPrevious === null) { unset($_SERVER[$key]); } else { $_SERVER[$key] = $serverPrevious; }
            putenv($processPrevious === false ? $key : $key . '=' . $processPrevious);
        }
    }
}
