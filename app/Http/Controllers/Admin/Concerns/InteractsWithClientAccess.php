<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Models\RealtimeClient;
use App\Models\RealtimePolicy;
use App\Models\RealtimeProject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

trait InteractsWithClientAccess
{
    protected function ensureAdminAccess(Request $request, string $message = 'Admin access required.'): void
    {
        abort_unless($this->isAdminRequest($request), 403, $message);
    }

    protected function isAdminRequest(Request $request): bool
    {
        $user = $request->user();

        return $user && method_exists($user, 'isAdmin') && $user->isAdmin();
    }

    /**
     * @return array<int, int>
     */
    protected function visibleClientIds(Request $request): array
    {
        $user = $request->user();

        if (! $user || ! method_exists($user, 'assignedClientIds')) {
            return [];
        }

        return $user->assignedClientIds();
    }

    /**
     * @return array<int, string>
     */
    protected function visibleClientCodes(Request $request): array
    {
        if ($this->isAdminRequest($request)) {
            return RealtimeClient::query()->pluck('client_code')->all();
        }

        return RealtimeClient::query()
            ->whereKey($this->visibleClientIds($request))
            ->pluck('client_code')
            ->all();
    }

    protected function scopeVisibleClients(Builder $query, Request $request): Builder
    {
        if ($this->isAdminRequest($request)) {
            return $query;
        }

        return $query->whereKey($this->visibleClientIds($request));
    }

    protected function ensureClientAccess(Request $request, RealtimeClient $client): void
    {
        if ($this->isAdminRequest($request)) {
            return;
        }

        abort_unless(in_array((int) $client->getKey(), $this->visibleClientIds($request), true), 403, 'Client access denied.');
    }

    protected function ensurePolicyAccess(Request $request, RealtimePolicy $policy): void
    {
        if ($this->isAdminRequest($request)) {
            return;
        }

        abort_unless(in_array((int) $policy->client_id, $this->visibleClientIds($request), true), 403, 'Policy access denied.');
    }

    protected function ensureProjectAccess(Request $request, RealtimeProject $project): void
    {
        if ($this->isAdminRequest($request)) {
            return;
        }

        abort_unless(in_array((int) $project->client_id, $this->visibleClientIds($request), true), 403, 'Project access denied.');
    }

    protected function ensureCanCreateClient(Request $request): void
    {
        $this->ensureAdminAccess($request, 'Only admins can create clients.');
    }
}
