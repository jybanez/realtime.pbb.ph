<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\InteractsWithClientAccess;
use App\Http\Controllers\Controller;
use App\Models\RealtimeAuditEvent;
use App\Models\RealtimeClient;
use App\Models\RealtimeProject;
use App\Models\RealtimePolicy;
use App\Models\RealtimeSession;
use App\Models\User;
use App\Realtime\Observability\RealtimeUsageTelemetry;
use App\Realtime\Settings\RealtimeRuntimeSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;
use RuntimeException;

class BrowserDataController extends Controller
{
    use InteractsWithClientAccess;

    public function dashboard(): JsonResponse
    {
        $request = request();
        $visibleClientIds = $this->visibleClientIds($request);
        $visibleClientCodes = $this->visibleClientCodes($request);

        $clientQuery = RealtimeClient::query();
        $projectQuery = RealtimeProject::query();
        $policyQuery = RealtimePolicy::query();
        $sessionQuery = RealtimeSession::query();
        $auditQuery = RealtimeAuditEvent::query();

        if (! $this->isAdminRequest($request)) {
            $clientQuery->whereKey($visibleClientIds);
            $projectQuery->whereIn('client_id', $visibleClientIds);
            $policyQuery->whereIn('client_id', $visibleClientIds);
            $sessionQuery->whereIn('client_code', $visibleClientCodes);
            $auditQuery->where(function (Builder $query) use ($visibleClientCodes): void {
                $query->whereIn('client_code', $visibleClientCodes)
                    ->orWhereNull('client_code');
            });
        }

        return response()->json([
            'status' => true,
            'data' => [
                'counts' => [
                    'clients' => $clientQuery->count(),
                    'projects' => $projectQuery->count(),
                    'policies' => $policyQuery->count(),
                    'sessions' => $sessionQuery->count(),
                    'audit' => $auditQuery->count(),
                ],
                'sessionSummary' => [
                    'connected' => (clone $sessionQuery)->where('status', 'connected')->count(),
                    'disconnected' => (clone $sessionQuery)->where('status', 'disconnected')->count(),
                ],
                'recentClients' => $this->scopeVisibleClients(RealtimeClient::query(), $request)
                    ->latest()
                    ->withCount('projects')
                    ->limit(5)
                    ->get()
                    ->map(fn (RealtimeClient $client) => $this->clientRow($client)),
                'recentProjects' => RealtimeProject::query()
                    ->when(! $this->isAdminRequest($request), fn ($query) => $query->whereIn('client_id', $visibleClientIds))
                    ->latest()
                    ->limit(5)
                    ->get()
                    ->map(fn (RealtimeProject $project) => $this->projectRow($project)),
                'recentAudit' => RealtimeAuditEvent::query()
                    ->when(! $this->isAdminRequest($request), function ($query) use ($visibleClientCodes): void {
                        $query->where(function (Builder $scoped) use ($visibleClientCodes): void {
                            $scoped->whereIn('client_code', $visibleClientCodes)
                                ->orWhereNull('client_code');
                        });
                    })
                    ->latest('occurred_at')
                    ->limit(8)
                    ->get()
                    ->map(fn (RealtimeAuditEvent $event) => $this->auditRow($event)),
            ],
        ]);
    }

    public function clients(Request $request): JsonResponse
    {
        $page = $this->scopeVisibleClients(RealtimeClient::query(), $request)
            ->latest()
            ->withCount('projects')
            ->paginate(20);

        return response()->json([
            'status' => true,
            'data' => $this->paginated(
                $page,
                fn (RealtimeClient $client) => $this->clientRow($client)
            ),
        ]);
    }

    public function clientOptions(): JsonResponse
    {
        $request = request();

        $issuerIdentities = $this->scopeVisibleClients(RealtimeClient::query(), $request)
            ->whereNotNull('issuer_identity')
            ->where('issuer_identity', '<>', '')
            ->distinct()
            ->orderBy('issuer_identity')
            ->pluck('issuer_identity')
            ->map(fn (string $value) => [
                'label' => $value,
                'value' => $value,
            ])
            ->values();

        $trustedSigningProfiles = $this->scopeVisibleClients(RealtimeClient::query(), $request)
            ->whereNotNull('trusted_signing_profile')
            ->where('trusted_signing_profile', '<>', '')
            ->distinct()
            ->orderBy('trusted_signing_profile')
            ->pluck('trusted_signing_profile')
            ->map(fn (string $value) => [
                'label' => $value,
                'value' => $value,
            ])
            ->values();

        return response()->json([
            'status' => true,
            'data' => [
                'issuer_identities' => $issuerIdentities,
                'trusted_signing_profiles' => $trustedSigningProfiles,
            ],
        ]);
    }

    public function client(RealtimeClient $client): JsonResponse
    {
        $this->ensureClientAccess(request(), $client);
        $client->loadMissing(['policies', 'projects']);

        return response()->json([
            'status' => true,
            'data' => [
                'client' => $this->clientRow($client) + [
                    'id' => $client->getKey(),
                    'description' => $client->description,
                    'integration_owner' => $client->integration_owner,
                    'integration_notes' => $client->integration_notes,
                    'issuer_identity' => $client->issuer_identity,
                    'trusted_signing_profile' => $client->trusted_signing_profile,
                    'has_backend_ingress_secret' => filled($client->backend_ingress_secret_digest) || filled($client->backend_ingress_secret_hash),
                    'trust_notes' => $client->trust_notes,
                    'policies' => $client->policies()
                        ->latest()
                        ->get()
                        ->map(fn (RealtimePolicy $policy) => $this->policyRow($policy)),
                    'policy_count' => $client->policies()->count(),
                    'projects' => $client->projects()
                        ->latest()
                        ->get()
                        ->map(fn (RealtimeProject $project) => $this->projectRow($project)),
                    'project_count' => $client->projects()->count(),
                ],
            ],
        ]);
    }

    public function policies(Request $request): JsonResponse
    {
        $page = RealtimePolicy::query()
            ->with('client')
            ->when(! $this->isAdminRequest($request), fn ($query) => $query->whereIn('client_id', $this->visibleClientIds($request)))
            ->when($request->filled('client_id'), fn ($query) => $query->where('client_id', (int) $request->integer('client_id')))
            ->latest()
            ->paginate(20);

        return response()->json([
            'status' => true,
            'data' => $this->paginated(
                $page,
                fn (RealtimePolicy $policy) => $this->policyRow($policy)
            ),
        ]);
    }

    public function policy(RealtimePolicy $policy): JsonResponse
    {
        $this->ensurePolicyAccess(request(), $policy);
        $policy->loadMissing('client');

        return response()->json([
            'status' => true,
            'data' => [
                'policy' => $this->policyRow($policy) + [
                    'id' => $policy->getKey(),
                    'description' => $policy->description,
                    'owner_team' => $policy->owner_team,
                    'client_id' => $policy->client_id,
                    'client_code' => $policy->client?->client_code,
                    'client_name' => $policy->client?->name,
                    'capability_profile' => $policy->capability_profile,
                    'room_policy_profile' => $policy->room_policy_profile,
                    'rate_limit_profile' => $policy->rate_limit_profile,
                    'session_limit_profile' => $policy->session_limit_profile,
                ],
            ],
        ]);
    }

    public function projects(Request $request): JsonResponse
    {
        $page = RealtimeProject::query()
            ->when(! $this->isAdminRequest($request), fn ($query) => $query->whereIn('client_id', $this->visibleClientIds($request)))
            ->latest()
            ->paginate(20);

        return response()->json([
            'status' => true,
            'data' => $this->paginated(
                $page,
                fn (RealtimeProject $project) => $this->projectRow($project)
            ),
        ]);
    }

    public function project(RealtimeProject $project): JsonResponse
    {
        $this->ensureProjectAccess(request(), $project);
        return response()->json([
            'status' => true,
            'data' => [
                'project' => $this->projectRow($project) + [
                    'id' => $project->getKey(),
                    'client_id' => $project->client_id,
                    'description' => $project->description,
                    'scope_notes' => $project->scope_notes,
                    'allowed_origins' => $project->allowed_origins,
                    'media_ingest_settings' => $this->mediaIngestSettingsRow($project),
                    'product_query_forwarding_settings' => $this->productQueryForwardingSettingsRow($project),
                ],
            ],
        ]);
    }

    public function sessions(Request $request): JsonResponse
    {
        $page = RealtimeSession::query()
            ->with(['client', 'project'])
            ->when(! $this->isAdminRequest($request), fn ($query) => $query->whereIn('client_code', $this->visibleClientCodes($request)))
            ->latest('last_activity_at')
            ->paginate(20);

        return response()->json([
            'status' => true,
            'data' => $this->paginated(
                $page,
                fn (RealtimeSession $session) => $this->sessionRow($session)
            ),
        ]);
    }

    public function audit(Request $request): JsonResponse
    {
        $page = RealtimeAuditEvent::query()
            ->when(! $this->isAdminRequest($request), function ($query) use ($request): void {
                $visibleClientCodes = $this->visibleClientCodes($request);
                $query->where(function (Builder $scoped) use ($visibleClientCodes): void {
                    $scoped->whereIn('client_code', $visibleClientCodes)
                        ->orWhereNull('client_code');
                });
            })
            ->latest('occurred_at')
            ->paginate(20);

        return response()->json([
            'status' => true,
            'data' => $this->paginated(
                $page,
                fn (RealtimeAuditEvent $event) => $this->auditRow($event)
            ),
        ]);
    }

    public function operations(RealtimeUsageTelemetry $telemetry, RealtimeRuntimeSettings $settings): JsonResponse
    {
        abort_unless($this->isAdminRequest(request()), 403, 'Admin access required.');

        return response()->json([
            'status' => true,
            'data' => [
                'clients' => [
                    'total' => RealtimeClient::count(),
                    'active' => RealtimeClient::query()->where('status', 'active')->count(),
                    'quarantined' => RealtimeClient::query()->where('status', 'quarantined')->count(),
                    'inactive' => RealtimeClient::query()->where('status', 'inactive')->count(),
                ],
                'projects' => [
                    'total' => RealtimeProject::count(),
                    'active' => RealtimeProject::query()->where('status', 'active')->count(),
                    'quarantined' => RealtimeProject::query()->where('status', 'quarantined')->count(),
                    'inactive' => RealtimeProject::query()->where('status', 'inactive')->count(),
                ],
                'sessions' => [
                    'total' => RealtimeSession::count(),
                    'connected' => RealtimeSession::query()->where('status', 'connected')->count(),
                    'disconnected' => RealtimeSession::query()->where('status', 'disconnected')->count(),
                ],
                'gateway' => [
                    'status' => 'ready',
                ],
                'runtime_settings' => [
                    'maestro_telemetry' => $settings->maestroTelemetry(),
                ],
            ],
        ]);
    }

    public function telemetry(RealtimeUsageTelemetry $telemetry): JsonResponse
    {
        $this->ensureAdminAccess(request());

        $topClients = $this->hydrateTelemetryClientNames($telemetry->topClientsLastHours());
        $topProjects = $this->hydrateTelemetryProjectNames($telemetry->topProjectsLastHours());

        return response()->json([
            'status' => true,
            'data' => [
                'window_hours' => 24,
                'summary' => $telemetry->summarizeLastHours(),
                'top_clients' => $topClients,
                'top_projects' => $topProjects,
                'event_mix' => $telemetry->eventMixLastHours(),
                'retention_days' => (int) config('realtime.usage_telemetry_retention_days', 90),
            ],
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $page = User::query()
            ->with('realtimeClients')
            ->latest()
            ->paginate(20);

        return response()->json([
            'status' => true,
            'data' => $this->paginated(
                $page,
                fn (User $user) => $this->userRow($user)
            ),
        ]);
    }

    public function userOptions(Request $request): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $clients = RealtimeClient::query()
            ->orderBy('name')
            ->get()
            ->map(fn (RealtimeClient $client) => [
                'id' => $client->id,
                'value' => (string) $client->id,
                'client_code' => $client->client_code,
                'name' => $client->name,
                'label' => sprintf('%s (%s)', $client->name, $client->client_code),
            ])
            ->values();

        return response()->json([
            'status' => true,
            'data' => [
                'clients' => $clients,
            ],
        ]);
    }

    public function user(Request $request, User $user): JsonResponse
    {
        $this->ensureAdminAccess($request);
        $user->loadMissing('realtimeClients');

        return response()->json([
            'status' => true,
            'data' => [
                'user' => $this->userRow($user) + [
                    'assigned_client_ids' => $user->realtimeClients
                        ->pluck('id')
                        ->map(fn ($id) => (int) $id)
                        ->values()
                        ->all(),
                ],
            ],
        ]);
    }

    public function userAudit(Request $request, User $user): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $events = RealtimeAuditEvent::query()
            ->where('target_type', 'admin_user')
            ->where('target_code', (string) $user->email)
            ->latest('occurred_at')
            ->limit(25)
            ->get()
            ->map(fn (RealtimeAuditEvent $event) => [
                'occurred_at' => optional($event->occurred_at)->toIso8601String(),
                'action_type' => $event->action_type,
                'actor_identity' => $event->actor_identity,
                'reason' => $event->reason,
                'before_state' => $event->before_state,
                'after_state' => $event->after_state,
            ])
            ->values();

        return response()->json([
            'status' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'events' => $events,
            ],
        ]);
    }

    public function sdkDoc(string $doc): JsonResponse
    {
        $map = [
            'integration-guide' => [
                'title' => 'SDK Integration Guide',
                'path' => base_path('docs/pbb-realtime-sdk-integration-guide.md'),
            ],
            'hotline-reference-flow' => [
                'title' => 'SDK Hotline Reference Flow',
                'path' => base_path('docs/pbb-realtime-sdk-hotline-reference-flow.md'),
            ],
            'versioning-strategy' => [
                'title' => 'SDK Versioning Strategy',
                'path' => base_path('docs/pbb-realtime-sdk-versioning-strategy.md'),
            ],
            'proposal' => [
                'title' => 'SDK Proposal',
                'path' => base_path('docs/pbb-realtime-sdk-proposal.md'),
            ],
            'implementation-checklist' => [
                'title' => 'SDK Implementation Checklist',
                'path' => base_path('docs/pbb-realtime-sdk-implementation-checklist.md'),
            ],
            'backend-sdk-proposal' => [
                'title' => 'Backend SDK Proposal',
                'path' => base_path('docs/pbb-realtime-backend-sdk-proposal.md'),
            ],
            'backend-sdk-checklist' => [
                'title' => 'Backend SDK Implementation Checklist',
                'path' => base_path('docs/pbb-realtime-backend-sdk-implementation-checklist.md'),
            ],
            'backend-sdk-quickstart' => [
                'title' => 'Backend SDK Quickstart',
                'path' => base_path('docs/pbb-realtime-backend-sdk-quickstart.md'),
            ],
            'backend-sdk-hotline-example' => [
                'title' => 'Backend SDK Hotline Example',
                'path' => base_path('docs/pbb-realtime-backend-sdk-hotline-example.md'),
            ],
            'backend-sdk-arguments-reference' => [
                'title' => 'Backend SDK Arguments Reference',
                'path' => base_path('docs/pbb-realtime-backend-sdk-arguments-reference.md'),
            ],
            'backend-sdk-return-values-reference' => [
                'title' => 'Backend SDK Return Values Reference',
                'path' => base_path('docs/pbb-realtime-backend-sdk-return-values-reference.md'),
            ],
            'backend-sdk-trust-boundary' => [
                'title' => 'Backend SDK Trust Boundary',
                'path' => base_path('docs/pbb-realtime-backend-sdk-trust-boundary.md'),
            ],
            'backend-sdk-migration-guide' => [
                'title' => 'Backend SDK Migration Guide',
                'path' => base_path('docs/pbb-realtime-backend-sdk-migration-guide.md'),
            ],
            'sdk-demo-app' => [
                'title' => 'SDK Demo App',
                'path' => base_path('docs/pbb-realtime-sdk-demo-app.md'),
            ],
            'sdk-demo-attachments-app' => [
                'title' => 'SDK Attachment Demo App',
                'path' => base_path('docs/pbb-realtime-sdk-demo-attachments-app.md'),
            ],
            'sdk-demo-conference-app' => [
                'title' => 'SDK Conference Demo App',
                'path' => base_path('docs/pbb-realtime-sdk-demo-conference-app.md'),
            ],
        ];

        $selected = $map[$doc] ?? null;
        if (!$selected) {
            return response()->json([
                'status' => false,
                'message' => 'SDK document not found.',
            ], 404);
        }

        try {
            $markdown = @file_get_contents($selected['path']);
            if ($markdown === false) {
                throw new RuntimeException('Unable to read SDK document.');
            }
        } catch (\Throwable $error) {
            return response()->json([
                'status' => false,
                'message' => $error->getMessage(),
            ], 500);
        }

        return response()->json([
            'status' => true,
            'data' => [
                'id' => $doc,
                'title' => $selected['title'],
                'markdown' => $markdown,
            ],
        ]);
    }

    public function downloadBackendSdk(): BinaryFileResponse
    {
        return $this->zipDirectoriesForDownload(
            'pbb-realtime-backend-sdk',
            [
                base_path('sdk/php') => 'sdk/php',
            ],
            'pbb-realtime-backend-sdk.zip'
        );
    }

    public function downloadSdkDemoBundle(): BinaryFileResponse
    {
        return $this->zipDirectoriesForDownload(
            'pbb-realtime-sdk-demo-bundle',
            [
                public_path('sdk-demo') => 'public/sdk-demo',
                public_path('sdk-demo-attachments') => 'public/sdk-demo-attachments',
                public_path('sdk-demo-conference') => 'public/sdk-demo-conference',
                base_path('sdk/php') => 'sdk/php',
                base_path('docs/pbb-realtime-sdk-demo-app.md') => 'docs/pbb-realtime-sdk-demo-app.md',
                base_path('docs/pbb-realtime-sdk-demo-attachments-app.md') => 'docs/pbb-realtime-sdk-demo-attachments-app.md',
                base_path('docs/pbb-realtime-sdk-demo-conference-app.md') => 'docs/pbb-realtime-sdk-demo-conference-app.md',
            ],
            'pbb-realtime-sdk-demo-bundle.zip'
        );
    }

    protected function paginated($page, callable $mapper): array
    {
        return [
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'items' => $page->getCollection()->map($mapper)->values(),
        ];
    }

    /**
     * @param array<string, string> $sources
     */
    protected function zipDirectoriesForDownload(string $rootPrefix, array $sources, string $downloadName): BinaryFileResponse
    {
        foreach (array_keys($sources) as $sourceRoot) {
            if (!file_exists($sourceRoot)) {
                abort(404, 'SDK archive source not found.');
            }
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'pbb-realtime-sdk-');
        if ($tempFile === false) {
            throw new RuntimeException('Unable to allocate temporary file for SDK archive.');
        }

        $zipPath = $tempFile . '.zip';
        @unlink($tempFile);

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create SDK archive.');
        }

        foreach ($sources as $absoluteSource => $targetPath) {
            if (is_file($absoluteSource)) {
                $zip->addFile($absoluteSource, $rootPrefix . '/' . str_replace('\\', '/', $targetPath));
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absoluteSource, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }

                $absolutePath = $fileInfo->getPathname();
                $relativePath = str_replace('\\', '/', substr($absolutePath, strlen($absoluteSource) + 1));
                $zip->addFile($absolutePath, $rootPrefix . '/' . trim($targetPath, '/') . '/' . $relativePath);
            }
        }

        $zip->close();

        return response()->download($zipPath, $downloadName)->deleteFileAfterSend(true);
    }

    protected function clientRow(RealtimeClient $client): array
    {
        return [
            'client_code' => $client->client_code,
            'name' => $client->name,
            'status' => $client->status,
            'token_issuance_mode' => $client->token_issuance_mode,
            'has_backend_ingress_secret' => filled($client->backend_ingress_secret_digest) || filled($client->backend_ingress_secret_hash),
            'project_count' => $client->projects_count ?? $client->projects()->count(),
            'show_url' => route('admin.clients.show', $client),
            'edit_url' => route('admin.clients.edit', $client),
        ];
    }

    protected function projectRow(RealtimeProject $project): array
    {
        $project->loadMissing(['client', 'policyProfile']);

        return [
            'project_code' => $project->project_code,
            'name' => $project->name,
            'client_id' => $project->client_id,
            'client_code' => $project->client?->client_code,
            'client_name' => $project->client?->name,
            'status' => $project->status,
            'origin_policy_mode' => $project->origin_policy_mode,
            'policy_profile_code' => $project->policy_profile_code,
            'policy_profile_name' => $project->policyProfile?->name,
            'capability_profile_code' => $project->capability_profile_code,
            'room_policy_profile_code' => $project->room_policy_profile_code,
            'media_ingest_settings' => $this->mediaIngestSettingsRow($project),
            'product_query_forwarding_settings' => $this->productQueryForwardingSettingsRow($project),
            'show_url' => route('admin.projects.show', $project),
            'edit_url' => route('admin.projects.edit', $project),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function mediaIngestSettingsRow(RealtimeProject $project): ?array
    {
        $settings = is_array($project->media_ingest_settings) ? $project->media_ingest_settings : null;

        if (! is_array($settings)) {
            return null;
        }

        return [
            'enabled' => (bool) ($settings['enabled'] ?? false),
            'base_url' => (string) ($settings['base_url'] ?? ''),
            'path' => (string) ($settings['path'] ?? '/api/internal/media/chunks'),
            'auth_header' => (string) ($settings['auth_header'] ?? 'X-Realtime-Media-Ingest-Secret'),
            'auth_token_configured' => filled($settings['auth_token'] ?? null),
            'connect_timeout_seconds' => (int) ($settings['connect_timeout_seconds'] ?? 3),
            'timeout_seconds' => (int) ($settings['timeout_seconds'] ?? 10),
            'verify_tls' => (bool) ($settings['verify_tls'] ?? true),
            'binary_enabled' => (bool) ($settings['binary_enabled'] ?? false),
            'max_binary_chunk_bytes' => (int) ($settings['max_binary_chunk_bytes'] ?? config('realtime.media_chunk_binary_max_bytes', 2 * 1024 * 1024)),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function productQueryForwardingSettingsRow(RealtimeProject $project): ?array
    {
        $settings = is_array($project->product_query_forwarding_settings) ? $project->product_query_forwarding_settings : null;

        if (! is_array($settings)) {
            return null;
        }

        return [
            'enabled' => (bool) ($settings['enabled'] ?? false),
            'base_url' => (string) ($settings['base_url'] ?? ''),
            'path' => (string) ($settings['path'] ?? '/api/internal/realtime/product-query'),
            'auth_header' => (string) ($settings['auth_header'] ?? 'X-Realtime-Backend-Secret'),
            'auth_token_configured' => filled($settings['auth_token'] ?? null),
            'allowed_event_types' => is_array($settings['allowed_event_types'] ?? null) ? array_values($settings['allowed_event_types']) : ['product.query.request'],
            'allowed_queries' => is_array($settings['allowed_queries'] ?? null) ? array_values($settings['allowed_queries']) : [],
            'max_payload_bytes' => (int) ($settings['max_payload_bytes'] ?? 4096),
            'rate_limit_per_minute' => (int) ($settings['rate_limit_per_minute'] ?? 12),
            'connect_timeout_seconds' => (int) ($settings['connect_timeout_seconds'] ?? 3),
            'timeout_seconds' => (int) ($settings['timeout_seconds'] ?? 8),
            'verify_tls' => (bool) ($settings['verify_tls'] ?? true),
        ];
    }

    protected function policyRow(RealtimePolicy $policy): array
    {
        $policy->loadMissing('client');

        return [
            'policy_code' => $policy->policy_code,
            'client_id' => $policy->client_id,
            'client_code' => $policy->client?->client_code,
            'client_name' => $policy->client?->name,
            'name' => $policy->name,
            'status' => $policy->status,
            'policy_category' => $policy->policy_category,
            'allow_deny_mode' => $policy->allow_deny_mode,
            'show_url' => route('admin.policies.show', $policy),
            'edit_url' => route('admin.policies.edit', $policy),
        ];
    }

    protected function sessionRow(RealtimeSession $session): array
    {
        $session->loadMissing(['client', 'project']);

        return [
            'session_id' => $session->session_id,
            'client_code' => $session->client_code,
            'client_name' => $session->client?->name,
            'project_code' => $session->project_code,
            'project_name' => $session->project?->name,
            'display_name' => $session->display_name,
            'user_identity' => $session->user_identity,
            'status' => $session->status,
            'room_count' => $session->room_count,
            'last_activity_at' => optional($session->last_activity_at)->toIso8601String(),
        ];
    }

    protected function auditRow(RealtimeAuditEvent $event): array
    {
        return [
            'occurred_at' => optional($event->occurred_at)->toIso8601String(),
            'action_type' => $event->action_type,
            'target_type' => $event->target_type,
            'target_code' => $event->target_code,
            'actor_identity' => $event->actor_identity,
        ];
    }

    protected function userRow(User $user): array
    {
        $user->loadMissing('realtimeClients');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'user_type' => (string) $user->user_type,
            'is_operator' => (bool) $user->is_operator,
            'is_admin' => method_exists($user, 'isAdmin') ? $user->isAdmin() : false,
            'assigned_clients' => $user->realtimeClients
                ->map(fn (RealtimeClient $client) => [
                    'id' => $client->id,
                    'client_code' => $client->client_code,
                    'name' => $client->name,
                ])
                ->values()
                ->all(),
            'assigned_client_count' => $user->realtimeClients->count(),
            'edit_url' => route('admin.users.index', ['form' => 'edit', 'user' => $user->id]),
        ];
    }

    /**
     * @param  array<int, array<string, int|string>>  $rows
     * @return array<int, array<string, int|string|null>>
     */
    protected function hydrateTelemetryClientNames(array $rows): array
    {
        $codes = collect($rows)
            ->pluck('client_code')
            ->filter(fn ($code) => is_string($code) && trim($code) !== '')
            ->values();

        $nameMap = RealtimeClient::query()
            ->whereIn('client_code', $codes)
            ->pluck('name', 'client_code');

        return array_map(function (array $row) use ($nameMap): array {
            $code = (string) ($row['client_code'] ?? '');
            $row['client_name'] = $nameMap[$code] ?? null;
            return $row;
        }, $rows);
    }

    /**
     * @param  array<int, array<string, int|string>>  $rows
     * @return array<int, array<string, int|string|null>>
     */
    protected function hydrateTelemetryProjectNames(array $rows): array
    {
        $codes = collect($rows)
            ->pluck('project_code')
            ->filter(fn ($code) => is_string($code) && trim($code) !== '')
            ->values();

        $nameMap = RealtimeProject::query()
            ->whereIn('project_code', $codes)
            ->pluck('name', 'project_code');

        return array_map(function (array $row) use ($nameMap): array {
            $code = (string) ($row['project_code'] ?? '');
            $row['project_name'] = $nameMap[$code] ?? null;
            return $row;
        }, $rows);
    }
}
