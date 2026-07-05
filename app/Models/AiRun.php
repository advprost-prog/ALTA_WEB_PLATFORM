<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiRun extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_PENDING => 'Очікує',
        self::STATUS_RUNNING => 'Виконується',
        self::STATUS_COMPLETED => 'Завершено',
        self::STATUS_FAILED => 'Помилка',
    ];

    protected $fillable = [
        'user_id',
        'entity_type',
        'entity_id',
        'task_type',
        'provider',
        'model',
        'input_payload',
        'output_payload',
        'status',
        'error',
        'tokens_input',
        'tokens_output',
        'cost_estimate',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'entity_id' => 'integer',
            'input_payload' => 'array',
            'output_payload' => 'array',
            'tokens_input' => 'integer',
            'tokens_output' => 'integer',
            'cost_estimate' => 'decimal:6',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function suggestions(): HasMany
    {
        return $this->hasMany(AiSuggestion::class);
    }
}
