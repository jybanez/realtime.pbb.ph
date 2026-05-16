<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View
    {
        return view('admin.app');
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => __('The provided credentials are incorrect.'),
            ]);
        }

        $request->session()->regenerate();

        if (! $request->user()?->is_operator) {
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => __('This account is not authorized for the PBB Realtime admin surface.'),
            ]);
        }

        $request->user()->loadMissing('realtimeClients');

        if ($request->expectsJson()) {
            return new JsonResponse([
                'status' => true,
                'data' => [
                    'account' => [
                        'id' => $request->user()->id,
                        'name' => $request->user()->name,
                        'email' => $request->user()->email,
                        'is_operator' => (bool) $request->user()->is_operator,
                        'user_type' => (string) ($request->user()->user_type ?? ''),
                        'is_admin' => method_exists($request->user(), 'isAdmin') ? $request->user()->isAdmin() : false,
                        'assigned_clients' => $request->user()->realtimeClients
                            ->map(fn ($client) => [
                                'id' => $client->id,
                                'client_code' => $client->client_code,
                                'name' => $client->name,
                            ])
                            ->values()
                            ->all(),
                    ],
                    'csrf_token' => csrf_token(),
                    'session_lifetime_minutes' => max(1, (int) config('session.lifetime', 120)),
                ],
            ]);
        }

        return redirect()->intended(route('admin.dashboard'));
    }

    public function destroy(Request $request): JsonResponse|RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return new JsonResponse([
                'status' => true,
                'data' => [
                    'csrf_token' => csrf_token(),
                ],
            ]);
        }

        return redirect()->route('status');
    }
}
