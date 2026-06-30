<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\InteractsWithClientAccess;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Realtime\Admin\RealtimeAdminAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserController extends Controller
{
    use InteractsWithClientAccess;

    public function index(Request $request): View
    {
        $this->ensureAdminAccess($request);

        return view('admin.app');
    }

    public function store(Request $request, RealtimeAdminAuditLogger $audit): JsonResponse|RedirectResponse
    {
        $this->ensureAdminAccess($request);

        $data = $this->validateUser($request);
        $clientIds = $data['client_ids'] ?? [];
        unset($data['client_ids']);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'user_type' => $data['user_type'],
            'status' => 'active',
            'is_operator' => true,
        ]);

        $user->realtimeClients()->sync($clientIds);
        $user->load('realtimeClients');

        $audit->record(
            $request->user(),
            'create',
            'admin_user',
            (string) $user->email,
            [],
            $this->auditUserState($user),
            'Created admin user from realtime surface'
        );

        if ($request->expectsJson()) {
            return response()->json([
                'status' => true,
                'data' => [
                    'user' => $this->responseUserState($user),
                ],
            ]);
        }

        return redirect()->route('admin.users.index')->with('status', 'User created.');
    }

    public function update(Request $request, User $user, RealtimeAdminAuditLogger $audit): JsonResponse|RedirectResponse
    {
        $this->ensureAdminAccess($request);

        $before = $this->auditUserState($user->loadMissing('realtimeClients'));
        $data = $this->validateUser($request, $user);
        $clientIds = $data['client_ids'] ?? [];
        unset($data['client_ids']);

        $payload = [
            'name' => $data['name'],
            'email' => $data['email'],
            'user_type' => $data['user_type'],
            'status' => $user->status ?: 'active',
            'is_operator' => true,
        ];

        if (!empty($data['password'])) {
            $payload['password'] = Hash::make($data['password']);
        }

        $user->fill($payload);
        $user->save();
        $user->realtimeClients()->sync($clientIds);
        $user->load('realtimeClients');

        $audit->record(
            $request->user(),
            'update',
            'admin_user',
            (string) $user->email,
            $before,
            $this->auditUserState($user),
            'Updated admin user from realtime surface'
        );

        if ($request->expectsJson()) {
            return response()->json([
                'status' => true,
                'data' => [
                    'user' => $this->responseUserState($user),
                ],
            ]);
        }

        return redirect()->route('admin.users.index')->with('status', 'User updated.');
    }

    protected function validateUser(Request $request, ?User $user = null): array
    {
        $passwordRules = $user
            ? ['nullable', 'confirmed', Password::defaults()]
            : ['required', 'confirmed', Password::defaults()];

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'user_type' => ['required', Rule::in(['admin', 'regular'])],
            'password' => $passwordRules,
            'client_ids' => ['nullable', 'array'],
            'client_ids.*' => ['integer', Rule::exists('realtime_clients', 'id')],
        ]);
    }

    protected function responseUserState(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'user_type' => (string) $user->user_type,
            'status' => (string) ($user->status ?: 'active'),
            'is_operator' => (bool) $user->is_operator,
            'is_admin' => $user->isAdmin(),
            'assigned_client_ids' => $user->realtimeClients->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'assigned_clients' => $user->realtimeClients
                ->map(fn ($client) => [
                    'id' => $client->id,
                    'client_code' => $client->client_code,
                    'name' => $client->name,
                ])
                ->values()
                ->all(),
        ];
    }

    protected function auditUserState(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'user_type' => (string) $user->user_type,
            'status' => (string) ($user->status ?: 'active'),
            'is_operator' => (bool) $user->is_operator,
            'assigned_client_ids' => $user->realtimeClients->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
        ];
    }
}
