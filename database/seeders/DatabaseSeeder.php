<?php

namespace Database\Seeders;

use App\Models\RealtimeAuditEvent;
use App\Models\RealtimeClient;
use App\Models\RealtimePolicy;
use App\Models\RealtimeProject;
use App\Models\RealtimeSession;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'operator@pbb.ph'],
            [
                'name' => 'PBB Realtime Operator',
                'password' => Hash::make('password'),
                'is_operator' => true,
                'user_type' => 'regular',
                'email_verified_at' => now(),
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'admin@pbb.ph'],
            [
                'name' => 'PBB Realtime Admin',
                'password' => Hash::make('password'),
                'is_operator' => true,
                'user_type' => 'admin',
                'email_verified_at' => now(),
            ]
        );

        $operator = User::query()->where('email', 'operator@pbb.ph')->first();
        $admin = User::query()->where('email', 'admin@pbb.ph')->first();

        $hqClient = RealtimeClient::query()->updateOrCreate(
            ['name' => 'PBB HQ'],
            [
                'name' => 'PBB HQ',
                'status' => 'active',
                'description' => 'Primary PBB browser app for HQ operations, management, and account workflows.',
                'integration_owner' => 'PBB HQ',
                'integration_notes' => 'Sample client seeded for local admin and browser-data testing.',
                'issuer_identity' => 'hq-auth@pbb.ph',
                'token_issuance_mode' => 'app_backend_signed',
                'trusted_signing_profile' => 'hq-app-backend',
                'trust_notes' => 'Uses the HQ app backend as the trust source for realtime token issuance.',
                'allowed_origins' => [],
                'origin_policy_mode' => 'allowlist',
                'policy_profile_code' => null,
                'capability_profile_code' => null,
                'room_policy_profile_code' => null,
                'created_by_user_id' => $operator?->id,
                'updated_by_user_id' => $operator?->id,
                'last_reviewed_by_user_id' => $operator?->id,
                'last_reviewed_at' => now(),
            ]
        );

        $hqClient = $hqClient?->refresh();

        if ($hqClient && $admin) {
            $hqClient->users()->syncWithoutDetaching([
                $admin->id => ['assignment_role' => 'owner'],
            ]);
        }

        $hqPolicy = RealtimePolicy::query()->updateOrCreate(
            ['name' => 'Default HQ Policy'],
            [
                'name' => 'Default HQ Policy',
                'client_id' => $hqClient?->id,
                'status' => 'active',
                'description' => 'Baseline policy bundle for HQ browser and realtime access.',
                'policy_category' => 'access',
                'owner_team' => 'PBB HQ',
                'capability_profile' => [
                    'rooms' => ['join', 'leave', 'publish'],
                    'presence' => ['publish', 'subscribe'],
                    'chat' => ['publish', 'subscribe'],
                    'calls' => ['signal'],
                ],
                'room_policy_profile' => [
                    'mode' => 'allowlist',
                    'prefixes' => ['hq.', 'workspace.hq.'],
                ],
                'rate_limit_profile' => [
                    'session_pings_per_minute' => 60,
                    'joins_per_minute' => 30,
                    'signals_per_minute' => 60,
                    'attachment_transport' => [
                        'max_attachment_count' => 4,
                        'max_attachment_bytes' => 1024 * 1024,
                        'max_total_bytes_per_message' => 4 * 1024 * 1024,
                        'chunk_events_per_minute' => 240,
                        'chunk_bytes_per_minute' => 8 * 1024 * 1024,
                    ],
                ],
                'session_limit_profile' => [
                    'max_concurrent_sessions' => 3,
                    'idle_timeout_minutes' => 15,
                ],
                'allow_deny_mode' => 'allowlist',
                'created_by_user_id' => $operator?->id,
                'updated_by_user_id' => $operator?->id,
            ]
        );

        $hqProject = null;

        if ($hqClient) {
            $hqProject = RealtimeProject::query()->updateOrCreate(
                ['client_id' => $hqClient->id, 'name' => 'PBB HQ'],
                [
                    'client_id' => $hqClient->id,
                    'name' => 'PBB HQ',
                    'status' => 'active',
                    'description' => 'HQ browser app scope for the realtime gateway.',
                    'scope_notes' => 'Seeded project scope for local admin and browser-data testing.',
                    'allowed_origins' => ['https://hq.pbb.ph'],
                    'origin_policy_mode' => 'allowlist',
                    'policy_profile_code' => $hqPolicy?->policy_code,
                    'capability_profile_code' => 'hq-default-capabilities',
                    'room_policy_profile_code' => 'hq-default-rooms',
                    'created_by_user_id' => $operator?->id,
                    'updated_by_user_id' => $operator?->id,
                    'last_reviewed_by_user_id' => $operator?->id,
                    'last_reviewed_at' => now(),
                ]
            )?->refresh();
        }

        RealtimeSession::query()->updateOrCreate(
            ['session_id' => 'rt_session_hq_001'],
            [
                'client_code' => $hqClient?->client_code,
                'project_code' => $hqProject?->project_code,
                'app_code' => 'pbb-hq',
                'user_identity' => 'admin@pbb.ph',
                'status' => 'connected',
                'connected_at' => now()->subMinutes(4),
                'last_activity_at' => now()->subMinute(),
                'disconnect_reason' => null,
                'room_count' => 2,
            ]
        );

        RealtimeAuditEvent::query()->updateOrCreate(
            ['audit_id' => 'audit_hq_policy_seed'],
            [
                'actor_user_id' => $operator?->id,
                'actor_identity' => 'operator@pbb.ph',
                'action_type' => 'seed',
                'target_type' => 'realtime_policy',
                'target_code' => $hqPolicy->policy_code,
                'client_code' => $hqClient?->client_code,
                'project_code' => $hqProject?->project_code,
                'before_state' => null,
                'after_state' => [
                    'policy_code' => $hqPolicy->policy_code,
                    'status' => 'active',
                    'policy_category' => 'access',
                ],
                'reason' => 'Seeded sample audit trail for local admin testing.',
                'occurred_at' => now()->subMinutes(2),
            ]
        );

        RealtimeClient::query()->updateOrCreate(
            ['name' => 'PBB Hotline'],
            [
                'name' => 'PBB Hotline',
                'status' => 'active',
                'description' => 'Emergency response client for voice, chat, media, and dispatch workflows.',
                'integration_owner' => 'PBB Hotline',
                'integration_notes' => 'Seeded Hotline client for transport and signal testing.',
                'issuer_identity' => 'hotline-auth@pbb.ph',
                'token_issuance_mode' => 'app_backend_signed',
                'trusted_signing_profile' => 'hotline-app-backend',
                'trust_notes' => 'Hotline backend issues trusted realtime tokens for caller and operator scopes.',
                'allowed_origins' => [],
                'origin_policy_mode' => 'allowlist',
                'policy_profile_code' => null,
                'capability_profile_code' => null,
                'room_policy_profile_code' => null,
                'created_by_user_id' => $operator?->id,
                'updated_by_user_id' => $operator?->id,
                'last_reviewed_by_user_id' => $operator?->id,
                'last_reviewed_at' => now(),
            ]
        );

        $hotlineClient = RealtimeClient::query()->where('name', 'PBB Hotline')->first();

        if ($hotlineClient) {
            $assignments = [];

            if ($admin) {
                $assignments[$admin->id] = ['assignment_role' => 'owner'];
            }

            if ($operator) {
                $assignments[$operator->id] = ['assignment_role' => 'manager'];
            }

            if ($assignments !== []) {
                $hotlineClient->users()->syncWithoutDetaching($assignments);
            }
        }

        $hotlineCallerPolicy = RealtimePolicy::query()->updateOrCreate(
            ['name' => 'Hotline Caller Policy V1'],
            [
                'name' => 'Hotline Caller Policy V1',
                'client_id' => $hotlineClient?->id,
                'status' => 'active',
                'description' => 'Caller-side realtime policy for emergency initiation and media signaling.',
                'policy_category' => 'hotline',
                'owner_team' => 'PBB Hotline',
                'capability_profile' => [
                    'rooms' => ['join', 'leave'],
                    'presence' => ['publish', 'subscribe'],
                    'chat' => ['publish', 'subscribe'],
                    'media' => ['request', 'stream'],
                    'call' => ['signal', 'reconnect'],
                ],
                'room_policy_profile' => [
                    'mode' => 'allowlist',
                    'prefixes' => ['hotline.call.', 'hotline.queue.'],
                ],
                'rate_limit_profile' => [
                    'session_pings_per_minute' => 60,
                    'call_signals_per_minute' => 120,
                    'media_events_per_minute' => 180,
                    'attachment_transport' => [
                        'max_attachment_count' => 6,
                        'max_attachment_bytes' => 2 * 1024 * 1024,
                        'max_total_bytes_per_message' => 6 * 1024 * 1024,
                        'chunk_events_per_minute' => 480,
                        'chunk_bytes_per_minute' => 12 * 1024 * 1024,
                    ],
                ],
                'session_limit_profile' => [
                    'max_concurrent_sessions' => 1,
                    'idle_timeout_minutes' => 15,
                ],
                'allow_deny_mode' => 'allowlist',
                'created_by_user_id' => $operator?->id,
                'updated_by_user_id' => $operator?->id,
            ]
        );

        $hotlineOperatorPolicy = RealtimePolicy::query()->updateOrCreate(
            ['name' => 'Hotline Operator Policy V1'],
            [
                'name' => 'Hotline Operator Policy V1',
                'client_id' => $hotlineClient?->id,
                'status' => 'active',
                'description' => 'Operator-side realtime policy for live answer, media review, and incident coordination.',
                'policy_category' => 'hotline',
                'owner_team' => 'PBB Hotline',
                'capability_profile' => [
                    'rooms' => ['join', 'leave', 'publish'],
                    'presence' => ['publish', 'subscribe'],
                    'chat' => ['publish', 'subscribe'],
                    'media' => ['request', 'review', 'persist'],
                    'call' => ['signal', 'answer', 'transfer', 'disconnect'],
                    'incidents' => ['create', 'update'],
                ],
                'room_policy_profile' => [
                    'mode' => 'allowlist',
                    'prefixes' => ['hotline.call.', 'hotline.incident.', 'hotline.dispatch.'],
                ],
                'rate_limit_profile' => [
                    'session_pings_per_minute' => 60,
                    'call_signals_per_minute' => 120,
                    'media_events_per_minute' => 240,
                    'attachment_transport' => [
                        'max_attachment_count' => 8,
                        'max_attachment_bytes' => 3 * 1024 * 1024,
                        'max_total_bytes_per_message' => 10 * 1024 * 1024,
                        'chunk_events_per_minute' => 720,
                        'chunk_bytes_per_minute' => 18 * 1024 * 1024,
                    ],
                ],
                'session_limit_profile' => [
                    'max_concurrent_sessions' => 2,
                    'idle_timeout_minutes' => 15,
                ],
                'allow_deny_mode' => 'allowlist',
                'created_by_user_id' => $operator?->id,
                'updated_by_user_id' => $operator?->id,
            ]
        );

        $hotlineDispatchPolicy = RealtimePolicy::query()->updateOrCreate(
            ['name' => 'Hotline Dispatch Policy V1'],
            [
                'name' => 'Hotline Dispatch Policy V1',
                'client_id' => $hotlineClient?->id,
                'status' => 'active',
                'description' => 'Dispatch-side realtime policy for escalation and routing oversight.',
                'policy_category' => 'hotline',
                'owner_team' => 'PBB Hotline',
                'capability_profile' => [
                    'rooms' => ['join', 'leave', 'publish'],
                    'presence' => ['publish', 'subscribe'],
                    'chat' => ['publish', 'subscribe'],
                    'incidents' => ['observe', 'route', 'update'],
                ],
                'room_policy_profile' => [
                    'mode' => 'allowlist',
                    'prefixes' => ['hotline.dispatch.', 'hotline.incident.'],
                ],
                'rate_limit_profile' => [
                    'session_pings_per_minute' => 30,
                    'signal_events_per_minute' => 60,
                ],
                'session_limit_profile' => [
                    'max_concurrent_sessions' => 2,
                    'idle_timeout_minutes' => 15,
                ],
                'allow_deny_mode' => 'allowlist',
                'created_by_user_id' => $operator?->id,
                'updated_by_user_id' => $operator?->id,
            ]
        );

        if ($hotlineClient) {
            $hotlineCallerProject = RealtimeProject::query()->updateOrCreate(
                ['client_id' => $hotlineClient->id, 'name' => 'PBB Hotline Caller'],
                [
                    'client_id' => $hotlineClient->id,
                    'name' => 'PBB Hotline Caller',
                    'status' => 'active',
                    'description' => 'Citizen-facing Hotline call scope for emergencies and reconnects.',
                    'scope_notes' => 'Caller project scope for Hotline transport and signaling tests.',
                    'allowed_origins' => [],
                    'origin_policy_mode' => 'no_browser',
                    'policy_profile_code' => $hotlineCallerPolicy?->policy_code,
                    'capability_profile_code' => 'hotline-caller-capabilities',
                    'room_policy_profile_code' => 'hotline-caller-rooms',
                    'created_by_user_id' => $operator?->id,
                    'updated_by_user_id' => $operator?->id,
                    'last_reviewed_by_user_id' => $operator?->id,
                    'last_reviewed_at' => now(),
                ]
            );

            $hotlineOperatorProject = RealtimeProject::query()->updateOrCreate(
                ['client_id' => $hotlineClient->id, 'name' => 'PBB Hotline Operator'],
                [
                    'client_id' => $hotlineClient->id,
                    'name' => 'PBB Hotline Operator',
                    'status' => 'active',
                    'description' => 'Operator console scope for live answer, media coordination, and incident handling.',
                    'scope_notes' => 'Operator project scope for Hotline transport and signaling tests.',
                    'allowed_origins' => ['https://hotline.pbb.ph', 'https://ops.pbb.ph'],
                    'origin_policy_mode' => 'allowlist',
                    'policy_profile_code' => $hotlineOperatorPolicy?->policy_code,
                    'capability_profile_code' => 'hotline-operator-capabilities',
                    'room_policy_profile_code' => 'hotline-operator-rooms',
                    'created_by_user_id' => $operator?->id,
                    'updated_by_user_id' => $operator?->id,
                    'last_reviewed_by_user_id' => $operator?->id,
                    'last_reviewed_at' => now(),
                ]
            );

            $hotlineDispatchProject = RealtimeProject::query()->updateOrCreate(
                ['client_id' => $hotlineClient->id, 'name' => 'PBB Hotline Dispatch'],
                [
                    'client_id' => $hotlineClient->id,
                    'name' => 'PBB Hotline Dispatch',
                    'status' => 'active',
                    'description' => 'Dispatch and escalation scope for Hotline incident routing.',
                    'scope_notes' => 'Optional dispatch project scope for later testing.',
                    'allowed_origins' => ['https://dispatch.pbb.ph'],
                    'origin_policy_mode' => 'allowlist',
                    'policy_profile_code' => $hotlineDispatchPolicy?->policy_code,
                    'capability_profile_code' => 'hotline-dispatch-capabilities',
                    'room_policy_profile_code' => 'hotline-dispatch-rooms',
                    'created_by_user_id' => $operator?->id,
                    'updated_by_user_id' => $operator?->id,
                    'last_reviewed_by_user_id' => $operator?->id,
                    'last_reviewed_at' => now(),
                ]
            );

            RealtimeSession::query()->updateOrCreate(
                ['session_id' => 'rt_session_hotline_caller_001'],
                [
                    'client_code' => $hotlineClient->client_code,
                    'project_code' => $hotlineCallerProject->project_code,
                    'app_code' => 'pbb-hotline',
                    'user_identity' => 'caller@pbb.ph',
                    'status' => 'connected',
                    'connected_at' => now()->subMinutes(1),
                    'last_activity_at' => now(),
                    'disconnect_reason' => null,
                    'room_count' => 1,
                ]
            );

            RealtimeSession::query()->updateOrCreate(
                ['session_id' => 'rt_session_hotline_operator_001'],
                [
                    'client_code' => $hotlineClient->client_code,
                    'project_code' => $hotlineOperatorProject->project_code,
                    'app_code' => 'pbb-hotline',
                    'user_identity' => 'operator@pbb.ph',
                    'status' => 'connected',
                    'connected_at' => now()->subMinutes(6),
                    'last_activity_at' => now()->subMinute(),
                    'disconnect_reason' => null,
                    'room_count' => 3,
                ]
            );

            RealtimeAuditEvent::query()->updateOrCreate(
                ['audit_id' => 'audit_hotline_seed_client'],
                [
                    'actor_user_id' => $operator?->id,
                    'actor_identity' => 'operator@pbb.ph',
                    'action_type' => 'seed',
                    'target_type' => 'realtime_client',
                    'target_code' => $hotlineClient->client_code,
                    'client_code' => $hotlineClient->client_code,
                    'project_code' => null,
                    'before_state' => null,
                    'after_state' => [
                        'client_code' => $hotlineClient->client_code,
                        'status' => 'active',
                    ],
                    'reason' => 'Seeded Hotline client for later transport and signaling tests.',
                    'occurred_at' => now()->subMinutes(5),
                ]
            );

            RealtimeAuditEvent::query()->updateOrCreate(
                ['audit_id' => 'audit_hotline_seed_operator'],
                [
                    'actor_user_id' => $operator?->id,
                    'actor_identity' => 'operator@pbb.ph',
                    'action_type' => 'seed',
                    'target_type' => 'realtime_project',
                    'target_code' => $hotlineOperatorProject->project_code,
                    'client_code' => $hotlineClient->client_code,
                    'project_code' => $hotlineOperatorProject->project_code,
                    'before_state' => null,
                    'after_state' => [
                        'status' => 'active',
                        'scope' => 'operator console',
                    ],
                    'reason' => 'Seeded Hotline operator scope for later transport and signaling tests.',
                    'occurred_at' => now()->subMinutes(3),
                ]
            );
        }
    }
}
