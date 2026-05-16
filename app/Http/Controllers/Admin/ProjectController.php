<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\InteractsWithClientAccess;
use App\Http\Controllers\Controller;
use App\Models\RealtimeClient;
use App\Models\RealtimePolicy;
use App\Models\RealtimeProject;
use App\Realtime\Admin\RealtimeAdminAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProjectController extends Controller
{
    use InteractsWithClientAccess;

    public function index(): View
    {
        return view('admin.app');
    }

    public function show(RealtimeProject $project): View
    {
        $this->ensureProjectAccess(request(), $project);
        return view('admin.app');
    }

    public function store(Request $request, RealtimeAdminAuditLogger $audit): JsonResponse|RedirectResponse
    {
        $data = $this->validateProject($request);
        $client = RealtimeClient::query()->findOrFail($data['client_id']);
        $this->ensureClientAccess($request, $client);

        $data['created_by_user_id'] = $request->user()->id;
        $data['updated_by_user_id'] = $request->user()->id;
        $data['last_reviewed_by_user_id'] = $request->user()->id;
        $data['last_reviewed_at'] = now();

        $project = RealtimeProject::create($data);

        $audit->record(
            $request->user(),
            'create',
            'realtime_project',
            $project->project_code,
            [],
            $project->toArray(),
            'Created via admin project scope management',
            $client->client_code,
            $project->project_code
        );

        if ($request->expectsJson()) {
            return response()->json([
                'status' => true,
                'data' => [
                    'project' => $project->fresh()->toArray(),
                    'show_url' => route('admin.projects.show', $project),
                    'edit_url' => route('admin.projects.edit', $project),
                ],
            ]);
        }

        return redirect()->route('admin.projects.show', $project)->with('status', 'Project created.');
    }

    public function edit(RealtimeProject $project): RedirectResponse
    {
        $this->ensureProjectAccess(request(), $project);
        return redirect()->route('admin.projects.index');
    }

    public function update(Request $request, RealtimeProject $project, RealtimeAdminAuditLogger $audit): JsonResponse|RedirectResponse
    {
        $this->ensureProjectAccess($request, $project);
        $before = $project->toArray();
        $data = $this->validateProject($request, $project);
        $client = RealtimeClient::query()->findOrFail($data['client_id']);
        $this->ensureClientAccess($request, $client);

        $data['updated_by_user_id'] = $request->user()->id;
        $data['last_reviewed_by_user_id'] = $request->user()->id;
        $data['last_reviewed_at'] = now();

        $project->update($data);

        $audit->record(
            $request->user(),
            'update',
            'realtime_project',
            $project->project_code,
            $before,
            $project->fresh()->toArray(),
            'Updated via admin project scope management',
            $client->client_code,
            $project->project_code
        );

        if ($request->expectsJson()) {
            return response()->json([
                'status' => true,
                'data' => [
                    'project' => $project->fresh()->toArray(),
                    'show_url' => route('admin.projects.show', $project),
                    'edit_url' => route('admin.projects.edit', $project),
                ],
            ]);
        }

        return redirect()->route('admin.projects.show', $project)->with('status', 'Project updated.');
    }

    public function destroy(Request $request, RealtimeProject $project, RealtimeAdminAuditLogger $audit): JsonResponse|RedirectResponse
    {
        $this->ensureProjectAccess($request, $project);
        $before = $project->toArray();
        $project->update([
            'status' => 'inactive',
            'updated_by_user_id' => $request->user()->id,
            'last_reviewed_by_user_id' => $request->user()->id,
            'last_reviewed_at' => now(),
        ]);

        $audit->record(
            $request->user(),
            'status_change',
            'realtime_project',
            $project->project_code,
            $before,
            $project->fresh()->toArray(),
            'Project deactivated from admin surface',
            $project->client?->client_code,
            $project->project_code
        );

        if ($request->expectsJson()) {
            return response()->json([
                'status' => true,
                'data' => [
                    'project' => $project->fresh()->toArray(),
                    'show_url' => route('admin.projects.show', $project),
                    'edit_url' => route('admin.projects.edit', $project),
                ],
            ]);
        }

        return redirect()->route('admin.projects.show', $project)->with('status', 'Project deactivated.');
    }

    protected function validateProject(Request $request, ?RealtimeProject $project = null): array
    {
        $validated = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('realtime_clients', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'inactive', 'quarantined', 'pending'])],
            'description' => ['nullable', 'string'],
            'scope_notes' => ['nullable', 'string'],
            'allowed_origins_text' => ['nullable', 'string'],
            'media_ingest_enabled' => ['nullable', 'boolean'],
            'media_ingest_base_url' => ['nullable', 'url', 'max:255'],
            'media_ingest_path' => ['nullable', 'string', 'max:255'],
            'media_ingest_auth_header' => ['nullable', 'string', 'max:255'],
            'media_ingest_auth_token' => ['nullable', 'string', 'max:4096'],
            'media_ingest_connect_timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:60'],
            'media_ingest_timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:120'],
            'media_ingest_verify_tls' => ['nullable', 'boolean'],
            'media_ingest_binary_enabled' => ['nullable', 'boolean'],
            'media_ingest_max_binary_chunk_bytes' => ['nullable', 'integer', 'min:1', 'max:104857600'],
            'product_query_forwarding_enabled' => ['nullable', 'boolean'],
            'product_query_forwarding_base_url' => ['nullable', 'url', 'max:255'],
            'product_query_forwarding_path' => ['nullable', 'string', 'max:255'],
            'product_query_forwarding_auth_header' => ['nullable', 'string', 'max:255'],
            'product_query_forwarding_auth_token' => ['nullable', 'string', 'max:4096'],
            'product_query_forwarding_allowed_queries_text' => ['nullable', 'string'],
            'product_query_forwarding_max_payload_bytes' => ['nullable', 'integer', 'min:1', 'max:65536'],
            'product_query_forwarding_rate_limit_per_minute' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'product_query_forwarding_connect_timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:60'],
            'product_query_forwarding_timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:120'],
            'product_query_forwarding_verify_tls' => ['nullable', 'boolean'],
            'origin_policy_mode' => ['required', Rule::in(['allowlist', 'disabled', 'no_browser'])],
            'policy_profile_code' => ['nullable', 'string', 'max:255', Rule::exists('realtime_policies', 'policy_code')],
            'capability_profile_code' => ['nullable', 'string', 'max:255'],
            'room_policy_profile_code' => ['nullable', 'string', 'max:255'],
        ]);

        $validated['allowed_origins'] = $this->normalizeLines($validated['allowed_origins_text'] ?? null);
        unset($validated['allowed_origins_text']);
        $validated['media_ingest_settings'] = $this->normalizeMediaIngestSettings($validated, $project);
        unset(
            $validated['media_ingest_enabled'],
            $validated['media_ingest_base_url'],
            $validated['media_ingest_path'],
            $validated['media_ingest_auth_header'],
            $validated['media_ingest_auth_token'],
            $validated['media_ingest_connect_timeout_seconds'],
            $validated['media_ingest_timeout_seconds'],
            $validated['media_ingest_verify_tls'],
            $validated['media_ingest_binary_enabled'],
            $validated['media_ingest_max_binary_chunk_bytes'],
        );
        $validated['product_query_forwarding_settings'] = $this->normalizeProductQueryForwardingSettings($validated, $project);
        unset(
            $validated['product_query_forwarding_enabled'],
            $validated['product_query_forwarding_base_url'],
            $validated['product_query_forwarding_path'],
            $validated['product_query_forwarding_auth_header'],
            $validated['product_query_forwarding_auth_token'],
            $validated['product_query_forwarding_allowed_queries_text'],
            $validated['product_query_forwarding_max_payload_bytes'],
            $validated['product_query_forwarding_rate_limit_per_minute'],
            $validated['product_query_forwarding_connect_timeout_seconds'],
            $validated['product_query_forwarding_timeout_seconds'],
            $validated['product_query_forwarding_verify_tls'],
        );

        if (! empty($validated['policy_profile_code'])) {
            $policy = RealtimePolicy::query()
                ->where('policy_code', $validated['policy_profile_code'])
                ->first();

            if (! $policy || (int) $policy->client_id !== (int) $validated['client_id']) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'policy_profile_code' => 'The selected policy must belong to the same client.',
                ]);
            }
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

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>|null
     */
    protected function normalizeMediaIngestSettings(array $validated, ?RealtimeProject $project = null): ?array
    {
        $enabled = filter_var($validated['media_ingest_enabled'] ?? false, FILTER_VALIDATE_BOOL);

        if (!$enabled) {
            return null;
        }

        $baseUrl = trim((string) ($validated['media_ingest_base_url'] ?? ''));
        if ($baseUrl === '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'media_ingest_base_url' => 'Media ingest base URL is required when media ingest is enabled.',
            ]);
        }

        $existing = is_array($project?->media_ingest_settings) ? $project->media_ingest_settings : [];
        $authToken = trim((string) ($validated['media_ingest_auth_token'] ?? ''));

        return [
            'enabled' => true,
            'base_url' => $baseUrl,
            'path' => trim((string) ($validated['media_ingest_path'] ?? '/api/internal/media/chunks')) ?: '/api/internal/media/chunks',
            'auth_header' => trim((string) ($validated['media_ingest_auth_header'] ?? 'X-Realtime-Media-Ingest-Secret')) ?: 'X-Realtime-Media-Ingest-Secret',
            'auth_token' => $authToken !== '' ? $authToken : (string) ($existing['auth_token'] ?? ''),
            'connect_timeout_seconds' => (int) ($validated['media_ingest_connect_timeout_seconds'] ?? 3),
            'timeout_seconds' => (int) ($validated['media_ingest_timeout_seconds'] ?? 10),
            'verify_tls' => array_key_exists('media_ingest_verify_tls', $validated)
                ? (bool) $validated['media_ingest_verify_tls']
                : true,
            'binary_enabled' => array_key_exists('media_ingest_binary_enabled', $validated)
                ? (bool) $validated['media_ingest_binary_enabled']
                : (bool) ($existing['binary_enabled'] ?? false),
            'max_binary_chunk_bytes' => (int) (
                $validated['media_ingest_max_binary_chunk_bytes']
                ?? $existing['max_binary_chunk_bytes']
                ?? config('realtime.media_chunk_binary_max_bytes', 2 * 1024 * 1024)
            ),
        ];
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>|null
     */
    protected function normalizeProductQueryForwardingSettings(array $validated, ?RealtimeProject $project = null): ?array
    {
        $enabled = filter_var($validated['product_query_forwarding_enabled'] ?? false, FILTER_VALIDATE_BOOL);

        if (!$enabled) {
            return null;
        }

        $baseUrl = trim((string) ($validated['product_query_forwarding_base_url'] ?? ''));
        if ($baseUrl === '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'product_query_forwarding_base_url' => 'Product query forwarding base URL is required when forwarding is enabled.',
            ]);
        }

        $allowedQueries = $this->normalizeLines($validated['product_query_forwarding_allowed_queries_text'] ?? null);
        if ($allowedQueries === []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'product_query_forwarding_allowed_queries_text' => 'At least one allowed product query is required when forwarding is enabled.',
            ]);
        }

        $existing = is_array($project?->product_query_forwarding_settings) ? $project->product_query_forwarding_settings : [];
        $authToken = trim((string) ($validated['product_query_forwarding_auth_token'] ?? ''));

        return [
            'enabled' => true,
            'base_url' => $baseUrl,
            'path' => trim((string) ($validated['product_query_forwarding_path'] ?? '/api/internal/realtime/product-query')) ?: '/api/internal/realtime/product-query',
            'auth_header' => trim((string) ($validated['product_query_forwarding_auth_header'] ?? 'X-Realtime-Backend-Secret')) ?: 'X-Realtime-Backend-Secret',
            'auth_token' => $authToken !== '' ? $authToken : (string) ($existing['auth_token'] ?? ''),
            'allowed_event_types' => ['product.query.request'],
            'allowed_queries' => $allowedQueries,
            'max_payload_bytes' => (int) ($validated['product_query_forwarding_max_payload_bytes'] ?? 4096),
            'rate_limit_per_minute' => (int) ($validated['product_query_forwarding_rate_limit_per_minute'] ?? 12),
            'connect_timeout_seconds' => (int) ($validated['product_query_forwarding_connect_timeout_seconds'] ?? 3),
            'timeout_seconds' => (int) ($validated['product_query_forwarding_timeout_seconds'] ?? 8),
            'verify_tls' => array_key_exists('product_query_forwarding_verify_tls', $validated)
                ? (bool) $validated['product_query_forwarding_verify_tls']
                : true,
        ];
    }
}
