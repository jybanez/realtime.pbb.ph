<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! method_exists($user, 'canAccessAdminSurface') || ! $user->canAccessAdminSurface()) {
            abort(403, 'Operator access required.');
        }

        return $next($request);
    }
}
