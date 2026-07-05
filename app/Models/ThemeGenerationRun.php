<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ThemeGenerationRun extends Model
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
        'source_url',
        'status',
        'input_payload',
        'analysis_payload',
        'style_profile',
        'selected_preset',
        'guardrails_applied',
        'generation_warnings',
        'generated_theme_payload',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'input_payload' => 'array',
            'analysis_payload' => 'array',
            'style_profile' => 'array',
            'guardrails_applied' => 'array',
            'generation_warnings' => 'array',
            'generated_theme_payload' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function themes(): HasMany
    {
        return $this->hasMany(StorefrontTheme::class, 'ai_run_id');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }
}
