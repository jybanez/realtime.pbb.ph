<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RealtimeProject extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'name',
        'status',
        'description',
        'scope_notes',
        'allowed_origins',
        'media_ingest_settings',
        'product_query_forwarding_settings',
        'origin_policy_mode',
        'policy_profile_code',
        'capability_profile_code',
        'room_policy_profile_code',
        'created_by_user_id',
        'updated_by_user_id',
        'last_reviewed_by_user_id',
        'last_reviewed_at',
    ];

    protected $casts = [
        'allowed_origins' => 'array',
        'media_ingest_settings' => 'array',
        'product_query_forwarding_settings' => 'array',
        'last_reviewed_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'project_code';
    }

    protected static function booted(): void
    {
        static::creating(function (RealtimeProject $project): void {
            if (! filled($project->project_code)) {
                $project->project_code = static::generateOpaqueCode('prj_');
            }
        });
    }

    protected static function generateOpaqueCode(string $prefix): string
    {
        do {
            $code = $prefix . Str::ulid()->toBase32();
        } while (
            static::query()->where('project_code', $code)->exists()
            || DB::table('realtime_clients')->where('project_code', $code)->exists()
            || DB::table('realtime_clients')->where('client_code', $code)->exists()
            || DB::table('realtime_policies')->where('policy_code', $code)->exists()
        );

        return $code;
    }

    public function client()
    {
        return $this->belongsTo(RealtimeClient::class, 'client_id');
    }

    public function policyProfile()
    {
        return $this->belongsTo(RealtimePolicy::class, 'policy_profile_code', 'policy_code');
    }
}
