<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RealtimeServerEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'publish_id',
        'client_code',
        'project_code',
        'room',
        'event_type',
        'event_id',
        'status',
        'attempts',
        'payload',
        'meta',
        'fanout_count',
        'queued_at',
        'published_at',
        'failed_at',
        'failure_reason',
    ];

    protected $casts = [
        'payload' => 'array',
        'meta' => 'array',
        'queued_at' => 'datetime',
        'published_at' => 'datetime',
        'failed_at' => 'datetime',
    ];
}
