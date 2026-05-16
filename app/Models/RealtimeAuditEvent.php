<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RealtimeAuditEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'audit_id',
        'actor_user_id',
        'actor_identity',
        'action_type',
        'target_type',
        'target_code',
        'client_code',
        'project_code',
        'before_state',
        'after_state',
        'reason',
        'occurred_at',
    ];

    protected $casts = [
        'before_state' => 'array',
        'after_state' => 'array',
        'occurred_at' => 'datetime',
    ];
}
