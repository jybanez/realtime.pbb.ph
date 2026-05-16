<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class CurrentUserController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('realtimeClients');

        return response()->json([
            'status' => true,
            'data' => [
                'account' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_operator' => (bool) $user->is_operator,
                    'user_type' => (string) ($user->user_type ?? ''),
                    'is_admin' => method_exists($user, 'isAdmin') ? $user->isAdmin() : false,
                    'assigned_clients' => $user->realtimeClients
                        ->map(fn ($client) => [
                            'id' => $client->id,
                            'client_code' => $client->client_code,
                            'name' => $client->name,
                        ])
                        ->values()
                        ->all(),
                ],
            ],
        ]);
    }

    public function update(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,' . $user->id],
        ]);

        $user->fill($validated);
        $user->save();
        $user->loadMissing('realtimeClients');

        if ($request->expectsJson()) {
            return response()->json([
                'status' => true,
                'data' => [
                    'account' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'is_operator' => (bool) $user->is_operator,
                        'user_type' => (string) ($user->user_type ?? ''),
                        'is_admin' => method_exists($user, 'isAdmin') ? $user->isAdmin() : false,
                        'assigned_clients' => $user->realtimeClients
                            ->map(fn ($client) => [
                                'id' => $client->id,
                                'client_code' => $client->client_code,
                                'name' => $client->name,
                            ])
                            ->values()
                            ->all(),
                    ],
                    'csrf_token' => csrf_token(),
                ],
            ]);
        }

        return redirect()->route('admin.dashboard')->with('status', 'Account updated.');
    }

    public function password(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user->forceFill([
            'password' => Hash::make($validated['password']),
        ])->save();

        if ($request->expectsJson()) {
            return response()->json([
                'status' => true,
                'data' => [
                    'csrf_token' => csrf_token(),
                ],
            ]);
        }

        return redirect()->route('admin.dashboard')->with('status', 'Password updated.');
    }
}
