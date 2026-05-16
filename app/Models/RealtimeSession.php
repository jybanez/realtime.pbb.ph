<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class RealtimeSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'client_code',
        'project_code',
        'app_code',
        'display_name',
        'user_identity',
        'status',
        'connected_at',
        'last_activity_at',
        'disconnect_reason',
        'room_count',
    ];

    protected $casts = [
        'connected_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(RealtimeClient::class, 'client_code', 'client_code');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(RealtimeProject::class, 'project_code', 'project_code');
    }
}
