<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RealtimePolicy extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'name',
        'status',
        'description',
        'policy_category',
        'owner_team',
        'capability_profile',
        'room_policy_profile',
        'rate_limit_profile',
        'session_limit_profile',
        'allow_deny_mode',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'client_id' => 'integer',
        'capability_profile' => 'array',
        'room_policy_profile' => 'array',
        'rate_limit_profile' => 'array',
        'session_limit_profile' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'policy_code';
    }

    protected static function booted(): void
    {
        static::creating(function (RealtimePolicy $policy): void {
            if (! filled($policy->policy_code)) {
                $policy->policy_code = static::generateOpaqueCode('pol_');
            }
        });
    }

    protected static function generateOpaqueCode(string $prefix): string
    {
        do {
            $code = $prefix . Str::ulid()->toBase32();
        } while (
            static::query()->where('policy_code', $code)->exists()
            || DB::table('realtime_clients')->where('project_code', $code)->exists()
            || DB::table('realtime_projects')->where('project_code', $code)->exists()
            || DB::table('realtime_clients')->where('client_code', $code)->exists()
        );

        return $code;
    }

    public function client()
    {
        return $this->belongsTo(RealtimeClient::class, 'client_id');
    }
}
