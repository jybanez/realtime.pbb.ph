<?php

namespace App\Http;

use Illuminate\Support\Facades\Facade;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Routing\Pipeline;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * These middleware are run during every request to your application.
     *
     * @var array<int, class-string|string>
     */
    protected $middleware = [
        // \App\Http\Middleware\TrustHosts::class,
        \App\Http\Middleware\TrustProxies::class,
        \Illuminate\Http\Middleware\HandleCors::class,
        \App\Http\Middleware\PreventRequestsDuringMaintenance::class,
        \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
        \App\Http\Middleware\TrimStrings::class,
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array<string, array<int, class-string|string>>
     */
    protected $middlewareGroups = [
        'web' => [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],

        'api' => [
            // \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class.':api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],
    ];

    /**
     * The application's middleware aliases.
     *
     * Aliases may be used instead of class names to conveniently assign middleware to routes and groups.
     *
     * @var array<string, class-string|string>
     */
    protected $middlewareAliases = [
        'auth' => \App\Http\Middleware\Authenticate::class,
        'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
        'auth.session' => \Illuminate\Session\Middleware\AuthenticateSession::class,
        'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
        'can' => \Illuminate\Auth\Middleware\Authorize::class,
        'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
        'password.confirm' => \Illuminate\Auth\Middleware\RequirePassword::class,
        'precognitive' => \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
        'signed' => \App\Http\Middleware\ValidateSignature::class,
        'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        'operator' => \App\Http\Middleware\EnsureOperator::class,
    ];

    protected function sendRequestThroughRouter($request)
    {
        $this->recordBootstrapMark('kernel.send_request.start');

        $this->app->instance('request', $request);
        $this->recordBootstrapMark('kernel.request.bound');

        Facade::clearResolvedInstance('request');
        $this->recordBootstrapMark('kernel.request.cleared');

        $this->bootstrap();
        $this->recordBootstrapMark('kernel.bootstrap.completed');

        $pipeline = new Pipeline($this->app);
        $this->recordBootstrapMark('kernel.pipeline.created');

        return $pipeline
            ->send($request)
            ->through($this->app->shouldSkipMiddleware() ? [] : $this->middleware)
            ->then($this->dispatchToRouter());
    }

    public function bootstrap()
    {
        if ($this->app->hasBeenBootstrapped()) {
            $this->recordBootstrapMark('kernel.bootstrap.skipped');

            return;
        }

        foreach ($this->bootstrappers() as $bootstrapper) {
            $this->recordBootstrapMark('bootstrapper.start', [
                'bootstrapper' => $bootstrapper,
            ]);

            $this->app->bootstrapWith([$bootstrapper]);

            $this->recordBootstrapMark('bootstrapper.done', [
                'bootstrapper' => $bootstrapper,
            ]);
        }
    }

    protected function recordBootstrapMark(string $stage, array $extra = []): void
    {
        $trace = &$GLOBALS['realtime_bootstrap_trace'];

        if (!is_array($trace)) {
            return;
        }

        $mark = array_merge([
            'stage' => $stage,
            'elapsed_ms' => defined('LARAVEL_START')
                ? round((microtime(true) - LARAVEL_START) * 1000, 3)
                : null,
        ], $extra);

        $trace[] = $mark;
    }
}
