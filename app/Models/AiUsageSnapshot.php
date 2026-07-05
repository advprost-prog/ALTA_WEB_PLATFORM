<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsageSnapshot extends Model
{
    protected $fillable = [
        'period_start',
        'period_end',
        'provider',
        'currency',
        'cost_value',
        'raw_payload',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'cost_value' => 'decimal:6',
            'raw_payload' => 'array',
            'synced_at' => 'datetime',
        ];
    }
}
