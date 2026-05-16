<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RealtimeMediaChunk extends Model
{
    use HasFactory;

    protected $fillable = [
        'chunk_id',
        'client_code',
        'project_code',
        'room',
        'session_id',
        'user_id',
        'display_name',
        'status',
        'attempts',
        'payload',
        'meta',
        'queued_at',
        'forwarded_at',
        'failed_at',
        'failure_reason',
        'downstream_status',
    ];

    protected $casts = [
        'payload' => 'array',
        'meta' => 'array',
        'queued_at' => 'datetime',
        'forwarded_at' => 'datetime',
        'failed_at' => 'datetime',
    ];
}
