<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RealtimeUsageBucket extends Model
{
    protected $fillable = [
        'bucket_start',
        'bucket_granularity',
        'client_code',
        'project_code',
        'event_type',
        'event_count',
        'bytes_in',
        'bytes_out',
        'error_count',
        'rate_limited_count',
    ];

    protected $casts = [
        'bucket_start' => 'datetime',
        'event_count' => 'integer',
        'bytes_in' => 'integer',
        'bytes_out' => 'integer',
        'error_count' => 'integer',
        'rate_limited_count' => 'integer',
    ];
}

