<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\InteractsWithClientAccess;
use App\Http\Controllers\Controller;
use App\Models\RealtimeClient;
use App\Models\RealtimePolicy;
use App\Realtime\Admin\RealtimeAdminAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PolicyController extends Controller
{
    use InteractsWithClientAccess;

    public function index(): RedirectResponse
    {
        return redirect()->route('admin.clients.index');
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('admin.clients.index');
    }

    public function store(Request $request, RealtimeAdminAuditLogger $audit): JsonResponse|RedirectResponse
    {
        $data = $this->validatePolicy($request);
        $client = RealtimeClient::query()->findOrFail($data['client_id']);
        $this->ensureClientAccess($request, $client);
        $data['created_by_user_id'] = $request->user()->id;
        $data['updated_by_user_id'] = $request->user()->id;
        $data['owner_team'] = $client->name;

        $policy = RealtimePolicy::create($data);

        $audit->record(
            $request->user(),
            'create',
            'realtime_policy',
            $policy->policy_code,
            [],
            $policy->toArray(),
            'Created via admin policy management',
            $client->client_code,
            null
        );

        if ($request->expectsJson()) {
            return response()->json([
                'status' => true,
                'data' => [
                'policy' => $policy->fresh()->toArray(),
                    'show_url' => route('admin.policies.show', $policy),
                    'edit_url' => route('admin.policies.edit', $policy),
                ],
            ]);
        }

        return redirect()->route('admin.clients.show', $client)->with('status', 'Policy created.');
    }

    public function show(RealtimePolicy $policy): View
    {
        $this->ensurePolicyAccess(request(), $policy);
        return view('admin.app');
    }

    public function edit(RealtimePolicy $policy): RedirectResponse
    {
        $this->ensurePolicyAccess(request(), $policy);
        if ($policy->client) {
            return redirect()->route('admin.clients.show', $policy->client);
        }

        return redirect()->route('admin.clients.index');
    }

    public function update(Request $request, RealtimePolicy $policy, RealtimeAdminAuditLogger $audit): JsonResponse|RedirectResponse
    {
        $this->ensurePolicyAccess($request, $policy);
        $before = $policy->toArray();
        $data = $this->validatePolicy($request, $policy);
        $client = RealtimeClient::query()->findOrFail($data['client_id']);
        $this->ensureClientAccess($request, $client);
        $data['updated_by_user_id'] = $request->user()->id;
        $data['owner_team'] = $client->name;

        $policy->update($data);

        $audit->record(
            $request->user(),
            'policy_change',
            'realtime_policy',
            $policy->policy_code,
            $before,
            $policy->fresh()->toArray(),
            'Updated via admin policy management',
            $client->client_code,
            null
        );

        if ($request->expectsJson()) {
            return response()->json([
                'status' => true,
                'data' => [
                'policy' => $policy->fresh()->toArray(),
                    'show_url' => route('admin.policies.show', $policy),
                    'edit_url' => route('admin.policies.edit', $policy),
                ],
            ]);
        }

        return redirect()->route('admin.clients.show', $client)->with('status', 'Policy updated.');
    }

    public function destroy(Request $request, RealtimePolicy $policy, RealtimeAdminAuditLogger $audit): JsonResponse|RedirectResponse
    {
        $this->ensurePolicyAccess($request, $policy);
        $before = $policy->toArray();
        $policy->update([
            'status' => 'deprecated',
            'updated_by_user_id' => $request->user()->id,
        ]);

        $audit->record(
            $request->user(),
            'policy_change',
            'realtime_policy',
            $policy->policy_code,
            $before,
            $policy->fresh()->toArray(),
            'Policy deprecated from admin surface',
            $policy->client?->client_code,
            null
        );

        if ($request->expectsJson()) {
            return response()->json([
                'status' => true,
                'data' => [
                'policy' => $policy->fresh()->toArray(),
                    'show_url' => route('admin.policies.show', $policy),
                    'edit_url' => route('admin.policies.edit', $policy),
                ],
            ]);
        }

        if ($policy->client) {
            return redirect()->route('admin.clients.show', $policy->client)->with('status', 'Policy deprecated.');
        }

        return redirect()->route('admin.clients.index')->with('status', 'Policy deprecated.');
    }

    protected function validatePolicy(Request $request, ?RealtimePolicy $policy = null): array
    {
        $validated = $request->validate([
            'policy_code' => ['nullable', 'string', 'max:255'],
            'client_id' => ['required', 'integer', Rule::exists('realtime_clients', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'inactive', 'draft', 'deprecated'])],
            'description' => ['nullable', 'string'],
            'policy_category' => ['nullable', 'string', 'max:255'],
            'owner_team' => ['nullable', 'string', 'max:255'],
            'capability_profile_text' => ['nullable', 'string'],
            'room_policy_profile_text' => ['nullable', 'string'],
            'rate_limit_profile_text' => ['nullable', 'string'],
            'session_limit_profile_text' => ['nullable', 'string'],
            'allow_deny_mode' => ['required', Rule::in(['allowlist', 'denylist', 'mixed'])],
        ]);

        unset($validated['policy_code']);

        $validated['capability_profile'] = $this->normalizeStructuredBlock($validated['capability_profile_text'] ?? null);
        $validated['room_policy_profile'] = $this->normalizeStructuredBlock($validated['room_policy_profile_text'] ?? null);
        $validated['rate_limit_profile'] = $this->normalizeStructuredBlock($validated['rate_limit_profile_text'] ?? null);
        $validated['session_limit_profile'] = $this->normalizeStructuredBlock($validated['session_limit_profile_text'] ?? null);

        unset(
            $validated['capability_profile_text'],
            $validated['room_policy_profile_text'],
            $validated['rate_limit_profile_text'],
            $validated['session_limit_profile_text']
        );

        return $validated;
    }

    protected function normalizeStructuredBlock(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return collect(preg_split('/\r\n|\r|\n/', $value))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->values()
            ->all();
    }
}
