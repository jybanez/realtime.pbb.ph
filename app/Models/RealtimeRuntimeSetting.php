<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RealtimeRuntimeSetting extends Model
{
    protected $fillable = [
        'setting_key',
        'setting_value',
    ];

    protected $casts = [
        'setting_value' => 'encrypted',
    ];
}
