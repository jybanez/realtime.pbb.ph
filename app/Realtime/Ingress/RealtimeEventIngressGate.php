<?php

namespace App\Realtime\Ingress;

use App\Models\RealtimeClient;
use App\Models\RealtimePolicy;
use App\Models\RealtimeProject;
use App\Realtime\Ingress\BackendIngressSecret;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class RealtimeEventIngressGate
{
    public function authorize(
        string $clientCode,
        string $projectCode,
        string $room,
        string $backendSecret
    ): RealtimeEventIngressAuthorizationResult {
        $this->markStage('authorize.start');

        $client = RealtimeClient::query()
            ->where('client_code', trim($clientCode))
            ->first();
        $this->markStage('authorize.client_lookup');

        if (!$client) {
            return RealtimeEventIngressAuthorizationResult::reject('unknown-client', 'Unknown client.');
        }

        if ($client->status !== 'active') {
            return RealtimeEventIngressAuthorizationResult::reject('inactive-client', 'The client is not active.');
        }

        $storedHash = (string) ($client->backend_ingress_secret_hash ?? '');
        $storedDigest = (string) ($client->backend_ingress_secret_digest ?? '');
        $this->markStage('authorize.secret_prepare');

        $normalizedSecret = trim($backendSecret);
        $secretAccepted = false;

        if ($normalizedSecret !== '' && $storedDigest !== '') {
            $secretAccepted = BackendIngressSecret::matchesDigest($normalizedSecret, $storedDigest);
            $this->markStage('authorize.secret_checked_fast', [
                'fast_path_used' => true,
                'accepted' => $secretAccepted,
            ]);
        } elseif ($normalizedSecret !== '' && $storedHash !== '') {
            $secretAccepted = Hash::check($normalizedSecret, $storedHash);
            $this->markStage('authorize.secret_checked_legacy', [
                'fast_path_used' => false,
                'accepted' => $secretAccepted,
            ]);
        }

        $this->markStage('authorize.secret_checked');

        if (!$secretAccepted) {
            return RealtimeEventIngressAuthorizationResult::reject('invalid-backend-secret', 'The backend ingress secret is invalid.');
        }

        $project = RealtimeProject::query()
            ->with(['policyProfile'])
            ->where('project_code', trim($projectCode))
            ->first();
        $this->markStage('authorize.project_lookup');

        if (!$project) {
            return RealtimeEventIngressAuthorizationResult::reject('unknown-project', 'Unknown project scope.');
        }

        if ((int) $project->client_id !== (int) $client->getKey()) {
            return RealtimeEventIngressAuthorizationResult::reject('client-project-mismatch', 'The project scope does not belong to the client.');
        }

        if ($project->status !== 'active') {
            return RealtimeEventIngressAuthorizationResult::reject('inactive-project', 'The project scope is not active.');
        }

        $policy = $project->policyProfile;
        if (!$policy instanceof RealtimePolicy || $policy->status !== 'active') {
            return RealtimeEventIngressAuthorizationResult::reject('missing-policy', 'The project scope does not resolve to an active policy.');
        }

        if (!$this->allowsEventPublish($policy)) {
            return RealtimeEventIngressAuthorizationResult::reject('missing-capability', 'The project scope policy does not allow server-originated event publishing.');
        }

        if (!$this->allowsRoom($policy, trim($room))) {
            return RealtimeEventIngressAuthorizationResult::reject('room-not-allowed', 'The requested room is not allowed for this project scope.');
        }

        $this->markStage('authorize.completed');

        return RealtimeEventIngressAuthorizationResult::accept($client, $project, $policy);
    }

    public function publishLimitPerMinute(RealtimePolicy $policy): int
    {
        $profile = is_array($policy->rate_limit_profile) ? $policy->rate_limit_profile : [];

        return max(
            0,
            (int) (
                $profile['event_publish_per_minute']
                ?? $profile['server_event_publish_per_minute']
                ?? config('realtime.event_publish_rate_limit_per_minute', 60)
            )
        );
    }

    private function allowsEventPublish(RealtimePolicy $policy): bool
    {
        $profile = is_array($policy->capability_profile) ? $policy->capability_profile : [];

        $events = $this->stringList($profile['events'] ?? []);
        $root = $this->stringList($profile['capabilities'] ?? []);
        $rooms = $this->stringList($profile['rooms'] ?? []);

        return in_array('publish', $events, true)
            || in_array('event.publish', $events, true)
            || in_array('event.publish', $root, true)
            || in_array('publish', $rooms, true);
    }

    private function allowsRoom(RealtimePolicy $policy, string $room): bool
    {
        if ($room === '') {
            return false;
        }

        $profile = is_array($policy->room_policy_profile) ? $policy->room_policy_profile : [];
        $mode = strtolower(trim((string) ($profile['mode'] ?? 'allowlist')));

        if ($mode === 'disabled') {
            return false;
        }

        $exactRooms = $this->stringList($profile['rooms'] ?? $profile['allowed_rooms'] ?? []);
        if (in_array($room, $exactRooms, true)) {
            return true;
        }

        foreach ($this->stringList($profile['prefixes'] ?? $profile['allowed_prefixes'] ?? []) as $prefix) {
            if ($room === $prefix || str_starts_with($room, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $item = trim($item);
                if ($item !== '') {
                    $result[] = $item;
                }
            }
        }

        return array_values(array_unique($result));
    }

    private function markStage(string $stage, array $context = []): void
    {
        $request = request();
        if (!$request instanceof Request) {
            return;
        }

        $startedAt = (float) $request->attributes->get('event_publish_started_at', 0.0);
        if ($startedAt <= 0.0) {
            return;
        }

        $marks = $request->attributes->get('event_publish_stage_marks', []);
        $marks[] = array_merge([
            'stage' => $stage,
            'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 3),
        ], $context);
        $request->attributes->set('event_publish_stage_marks', $marks);
    }
}
