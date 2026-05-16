<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use RuntimeException;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $cachedConfig = __DIR__.'/../bootstrap/cache/config.php';
        if (is_file($cachedConfig)) {
            @unlink($cachedConfig);
        }

        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        if ($app->environment() !== 'testing') {
            throw new RuntimeException('Tests must boot with APP_ENV=testing.');
        }

        $defaultConnection = (string) $app['config']->get('database.default');
        $sqliteDatabase = (string) $app['config']->get('database.connections.sqlite.database');

        if ($defaultConnection !== 'sqlite' || $sqliteDatabase !== ':memory:') {
            throw new RuntimeException(sprintf(
                'Unsafe test database configuration detected. Expected sqlite/:memory:, got %s/%s.',
                $defaultConnection,
                $sqliteDatabase
            ));
        }

        return $app;
    }
}
