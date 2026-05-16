<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\InteractsWithClientAccess;
use App\Http\Controllers\Controller;
use App\Models\RealtimeClient;
use App\Models\RealtimePolicy;
use App\Realtime\Admin\RealtimeAdminAuditLogger;
use App\Realtime\Ingress\BackendIngressSecret;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClientController extends Controller
{
    use InteractsWithClientAccess;

    public function index(): View
    {
        return view('admin.app');
    }

    public function create(): RedirectResponse
    {
        $this->ensureCanCreateClient(request());
        return redirect()->route('admin.clients.index', ['form' => 'create']);
    }

    public function store(Request $request, RealtimeAdminAuditLogger $audit): JsonResponse|RedirectResponse
    {
        $this->ensureCanCreateClient($request);
        $data = $this->validateClient($request);
        $data['created_by_user_id'] = $request->user()->id;
        $data['updated_by_user_id'] = $request->user()->id;
        $data['last_reviewed_by_user_id'] = $request->user()->id;
        $data['last_reviewed_at'] = now();

        $client = RealtimeClient::create($data);

        $audit->record(
            $request->user(),
            'create',
            'realtime_client',
            $client->client_code,
            [],
            $client->toArray(),
            'Created via admin client management',
            $client->client_code,
            $client->project_code
        );

        if (array_key_exists('backend_ingress_secret_hash', $data)) {
            $audit->record(
                $request->user(),
                'backend_ingress_secret.created',
                'realtime_client',
                $client->client_code,
                ['has_backend_ingress_secret' => false],
                ['has_backend_ingress_secret' => true],
                'Created backend ingress secret for client',
                $client->client_code,
                $client->project_code
            );
        }

        if ($request->expectsJson()) {
            return response()->json([
                'status' => true,
                'data' => [
                'client' => $client->fresh()->toArray(),
                    'show_url' => route('admin.clients.show', $client),
                    'edit_url' => route('admin.clients.edit', $client),
                ],
            ]);
        }

        return redirect()->route('admin.clients.show', $client)->with('status', 'Client created.');
    }

    public function show(RealtimeClient $client): View
    {
        $this->ensureClientAccess(request(), $client);
        return view('admin.app');
    }

    public function edit(RealtimeClient $client): RedirectResponse
    {
        $this->ensureClientAccess(request(), $client);
        return redirect()->route('admin.clients.index', [
            'form' => 'edit',
            'client' => $client->getKey(),
        ]);
    }

    public function update(Request $request, RealtimeClient $client, RealtimeAdminAuditLogger $audit): JsonResponse|RedirectResponse
    {
        $this->ensureClientAccess($request, $client);
        $before = $client->toArray();
        $data = $this->validateClient($request, $client);
        $data['updated_by_user_id'] = $request->user()->id;
        $data['last_reviewed_by_user_id'] = $request->user()->id;
        $data['last_reviewed_at'] = now();

        $client->update($data);

        $audit->record(
            $request->user(),
            'update',
            'realtime_client',
            $client->client_code,
            $before,
            $client->fresh()->toArray(),
            'Updated via admin client management',
            $client->client_code,
            $client->project_code
        );

        if (array_key_exists('backend_ingress_secret_hash', $data)) {
            $audit->record(
                $request->user(),
                filled($before['backend_ingress_secret_hash'] ?? null)
                    ? 'backend_ingress_secret.rotated'
                    : 'backend_ingress_secret.created',
                'realtime_client',
                $client->client_code,
                ['has_backend_ingress_secret' => filled($before['backend_ingress_secret_hash'] ?? null)],
                ['has_backend_ingress_secret' => true],
                filled($before['backend_ingress_secret_hash'] ?? null)
                    ? 'Rotated backend ingress secret for client'
                    : 'Created backend ingress secret for client',
                $client->client_code,
                $client->project_code
            );
        }

        if ($request->expectsJson()) {
            return response()->json([
                'status' => true,
                'data' => [
                'client' => $client->fresh()->toArray(),
                    'show_url' => route('admin.clients.show', $client),
                    'edit_url' => route('admin.clients.edit', $client),
                ],
            ]);
        }

        return redirect()->route('admin.clients.show', $client)->with('status', 'Client updated.');
    }

    public function destroy(Request $request, RealtimeClient $client, RealtimeAdminAuditLogger $audit): JsonResponse|RedirectResponse
    {
        $this->ensureClientAccess($request, $client);
        $before = $client->toArray();
        $client->update([
            'status' => 'inactive',
            'updated_by_user_id' => $request->user()->id,
            'last_reviewed_by_user_id' => $request->user()->id,
            'last_reviewed_at' => now(),
        ]);

        $audit->record(
            $request->user(),
            'status_change',
            'realtime_client',
            $client->client_code,
            $before,
            $client->fresh()->toArray(),
            'Client disabled from admin surface',
            $client->client_code,
            $client->project_code
        );

        if ($request->expectsJson()) {
            return response()->json([
                'status' => true,
                'data' => [
                'client' => $client->fresh()->toArray(),
                    'show_url' => route('admin.clients.show', $client),
                    'edit_url' => route('admin.clients.edit', $client),
                ],
            ]);
        }

        return redirect()->route('admin.clients.show', $client)->with('status', 'Client disabled.');
    }

    protected function validateClient(Request $request, ?RealtimeClient $client = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'inactive', 'quarantined', 'pending'])],
            'description' => ['nullable', 'string'],
            'integration_owner' => ['nullable', 'string', 'max:255'],
            'integration_notes' => ['nullable', 'string'],
            'issuer_identity' => ['nullable', 'string', 'max:255'],
            'token_issuance_mode' => ['required', Rule::in(['app_backend_signed', 'realtime_issued_fallback', 'manual_review'])],
            'trusted_signing_profile' => ['nullable', 'string', 'max:255'],
            'backend_ingress_secret' => ['nullable', 'string', 'min:12', 'max:255'],
            'trust_notes' => ['nullable', 'string'],
            'allowed_origins_text' => ['nullable', 'string'],
            'origin_policy_mode' => ['required', Rule::in(['allowlist', 'disabled', 'no_browser'])],
            'policy_profile_code' => ['nullable', 'string', 'max:255', Rule::exists('realtime_policies', 'policy_code')],
            'capability_profile_code' => ['nullable', 'string', 'max:255'],
            'room_policy_profile_code' => ['nullable', 'string', 'max:255'],
        ]);

        $validated['allowed_origins'] = $this->normalizeLines($validated['allowed_origins_text'] ?? null);
        unset($validated['allowed_origins_text']);

        $backendIngressSecret = trim((string) ($validated['backend_ingress_secret'] ?? ''));
        unset($validated['backend_ingress_secret']);

        if ($backendIngressSecret !== '') {
            $validated = array_merge($validated, BackendIngressSecret::attributesForStorage($backendIngressSecret));
        }

        return $validated;
    }

    protected function normalizeLines(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return collect(preg_split('/\r\n|\r|\n/', $value))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->values()
            ->all();
    }
}
