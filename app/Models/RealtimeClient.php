<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RealtimeClient extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'status',
        'description',
        'integration_owner',
        'integration_notes',
        'issuer_identity',
        'token_issuance_mode',
        'trusted_signing_profile',
        'backend_ingress_secret_hash',
        'backend_ingress_secret_digest',
        'trust_notes',
        'allowed_origins',
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
        'last_reviewed_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'client_code';
    }

    protected static function booted(): void
    {
        static::creating(function (RealtimeClient $client): void {
            if (! filled($client->client_code)) {
                $client->client_code = static::generateOpaqueCode('clt_');
            }

            if (! filled($client->project_code)) {
                $client->project_code = static::generateOpaqueCode('prj_');
            }
        });
    }

    protected static function generateOpaqueCode(string $prefix): string
    {
        do {
            $code = $prefix . Str::ulid()->toBase32();
        } while (
            static::query()->where('client_code', $code)->exists()
            || DB::table('realtime_clients')->where('project_code', $code)->exists()
            || DB::table('realtime_projects')->where('project_code', $code)->exists()
            || DB::table('realtime_policies')->where('policy_code', $code)->exists()
        );

        return $code;
    }

    public function projects()
    {
        return $this->hasMany(RealtimeProject::class, 'client_id');
    }

    public function policies()
    {
        return $this->hasMany(RealtimePolicy::class, 'client_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'realtime_client_user', 'client_id', 'user_id')
            ->withPivot('assignment_role')
            ->withTimestamps();
    }
}
