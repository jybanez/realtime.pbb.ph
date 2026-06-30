<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Realtime\Admin\RealtimeAdminAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AccountAdminController extends Controller
{
    private const ROLES = ['admin', 'regular'];
    private const STATUSES = ['active', 'disabled'];

    public function meta(): JsonResponse
    {
        return $this->ok([
            'app' => [
                'id' => 'pbb-realtime',
                'name' => 'PBB Realtime',
            ],
            'roles' => [
                ['value' => 'admin', 'label' => 'Admin'],
                ['value' => 'regular', 'label' => 'Regular'],
            ],
            'statuses' => [
                ['value' => 'active', 'label' => 'Active'],
                ['value' => 'disabled', 'label' => 'Disabled'],
            ],
            'capabilities' => [
                'provisionUser' => true,
                'updateRole' => true,
                'blockLogin' => true,
                'suspendLogin' => false,
                'operatorCapability' => [
                    'field' => 'is_operator',
                    'managedByDefault' => true,
                    'notes' => 'is_operator is a Realtime admin-surface access flag, not an Account role.',
                ],
            ],
        ]);
    }

    public function show(string $pbbUserId): JsonResponse
    {
        $user = $this->findLinkedUser($pbbUserId);
        if (!$user) {
            return $this->accountFail('linked_user_not_found', 'Linked user not found.', 404);
        }

        return $this->ok(['user' => $this->accountUserPayload($user)]);
    }

    public function provision(Request $request): JsonResponse
    {
        $pbbUserId = (string) $request->route('pbbUserId');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:40'],
            'defaultRole' => ['nullable', 'string', Rule::in(self::ROLES)],
        ]);

        $role = $data['defaultRole'] ?? 'regular';
        if (!in_array($role, self::ROLES, true)) {
            $role = 'regular';
        }

        $linked = $this->findLinkedUser($pbbUserId);
        if ($linked) {
            $linked->forceFill([
                'name' => $data['name'],
                'email' => mb_strtolower($data['email']),
            ])->save();

            return $this->ok(['user' => $this->accountUserPayload($linked)]);
        }

        $emailUser = User::query()->where('email', mb_strtolower($data['email']))->first();
        if ($emailUser && $emailUser->pbb_user_id && $emailUser->pbb_user_id !== $pbbUserId) {
            return $this->accountFail('identity_conflict', 'A user with this email is linked to a different Account identity.', 409, [
                'email' => $data['email'],
            ]);
        }

        if ($emailUser) {
            $emailUser->forceFill([
                'pbb_user_id' => $pbbUserId,
                'name' => $data['name'],
                'email' => mb_strtolower($data['email']),
                'status' => $emailUser->status ?: 'active',
                'is_operator' => true,
            ])->save();

            return $this->ok(['user' => $this->accountUserPayload($emailUser)]);
        }

        $user = User::query()->create([
            'pbb_user_id' => $pbbUserId,
            'name' => $data['name'],
            'email' => mb_strtolower($data['email']),
            'password' => Hash::make(Str::random(64)),
            'user_type' => $role,
            'status' => 'active',
            'is_operator' => true,
        ]);

        return $this->ok(['user' => $this->accountUserPayload($user)], 201);
    }

    public function updateRole(Request $request, string $pbbUserId, RealtimeAdminAuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'role' => ['required', 'string', Rule::in(self::ROLES)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $this->findLinkedUser($pbbUserId);
        if (!$user) {
            return $this->accountFail('linked_user_not_found', 'Linked user not found.', 404);
        }

        $before = $this->auditUserState($user);
        $user->forceFill([
            'user_type' => $data['role'],
            'is_operator' => true,
        ])->save();

        $audit->record(null, 'account_admin_role_updated', 'admin_user', (string) $user->email, $before, $this->auditUserState($user), $data['reason'] ?? null);

        return $this->ok(['user' => $this->accountUserPayload($user)]);
    }

    public function updateStatus(Request $request, string $pbbUserId, RealtimeAdminAuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(self::STATUSES)],
            'durationMinutes' => ['nullable', 'integer', 'min:1', 'max:5256000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $this->findLinkedUser($pbbUserId);
        if (!$user) {
            return $this->accountFail('linked_user_not_found', 'Linked user not found.', 404);
        }

        $before = $this->auditUserState($user);
        $payload = ['status' => $data['status']];
        if ($data['status'] === 'disabled') {
            $payload['remember_token'] = Str::random(60);
        }

        $user->forceFill($payload)->save();

        $audit->record(null, 'account_admin_status_updated', 'admin_user', (string) $user->email, $before, $this->auditUserState($user), $data['reason'] ?? null);

        return $this->ok(['user' => $this->accountUserPayload($user)]);
    }

    private function findLinkedUser(string $pbbUserId): ?User
    {
        return User::query()->where('pbb_user_id', $pbbUserId)->first();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function ok(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function accountUserPayload(User $user): array
    {
        return [
            'pbbUserId' => $user->pbb_user_id,
            'localUserId' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => (string) $user->user_type,
            'status' => (string) ($user->status ?: 'active'),
            'isOperator' => (bool) $user->is_operator,
            'blockedAt' => $user->isActive() ? null : $user->updated_at?->toIso8601String(),
            'suspendedUntil' => null,
            'updatedAt' => $user->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditUserState(User $user): array
    {
        return [
            'id' => $user->id,
            'pbb_user_id' => $user->pbb_user_id,
            'name' => $user->name,
            'email' => $user->email,
            'user_type' => (string) $user->user_type,
            'status' => (string) ($user->status ?: 'active'),
            'is_operator' => (bool) $user->is_operator,
        ];
    }

    /**
     * @param array<string, mixed> $details
     */
    private function accountFail(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'error' => [
                'code' => $code,
                'details' => $details,
            ],
        ], $status, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
