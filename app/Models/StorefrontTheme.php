<?php

namespace App\Models;

use App\Services\Themes\ThemePayloadValidator;
use App\Services\Themes\ThemeSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StorefrontTheme extends Model
{
    use HasFactory;

    public const TYPE_SYSTEM = 'system';

    public const TYPE_CUSTOM = 'custom';

    public const TYPE_AI_GENERATED = 'ai_generated';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    public const ACTIVE_CACHE_KEY = 'active_storefront_theme';

    public const TYPES = [
        self::TYPE_SYSTEM => 'System',
        self::TYPE_CUSTOM => 'Custom',
        self::TYPE_AI_GENERATED => 'AI generated',
    ];

    public const STATUSES = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_PUBLISHED => 'Published',
        self::STATUS_ARCHIVED => 'Archived',
    ];

    protected $fillable = [
        'name',
        'slug',
        'description',
        'type',
        'status',
        'is_active',
        'source',
        'source_url',
        'style_family',
        'style_profile',
        'selected_preset',
        'guardrails_applied',
        'generation_warnings',
        'tokens',
        'layout_config',
        'component_config',
        'css_variables',
        'custom_css',
        'preview_image',
        'created_by',
        'generated_by_ai',
        'ai_run_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'generated_by_ai' => 'boolean',
            'style_profile' => 'array',
            'guardrails_applied' => 'array',
            'generation_warnings' => 'array',
            'tokens' => 'array',
            'layout_config' => 'array',
            'component_config' => 'array',
            'css_variables' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (StorefrontTheme $theme): void {
            if (! $theme->isDirty(['tokens', 'layout_config', 'component_config', 'css_variables', 'custom_css', 'style_profile', 'selected_preset', 'guardrails_applied', 'generation_warnings'])) {
                return;
            }

            $theme->createVersionFromOriginal('Auto snapshot before theme payload update.');
        });

        static::saved(function (): void {
            Cache::forget(self::ACTIVE_CACHE_KEY);
        });

        static::deleted(function (): void {
            Cache::forget(self::ACTIVE_CACHE_KEY);
        });
    }

    public function setCustomCssAttribute(?string $value): void
    {
        $this->attributes['custom_css'] = blank($value)
            ? null
            : app(ThemePayloadValidator::class)->sanitizeCustomCss((string) $value);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(StorefrontThemeVersion::class)->latest('version');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(ThemeGenerationRun::class, 'ai_run_id');
    }

    public function activate(): void
    {
        DB::transaction(function (): void {
            self::query()->where('is_active', true)->update(['is_active' => false]);

            $this->forceFill([
                'status' => self::STATUS_PUBLISHED,
                'is_active' => true,
            ])->save();
        });

        Cache::forget(self::ACTIVE_CACHE_KEY);
    }

    public function createVersion(?string $notes = null): StorefrontThemeVersion
    {
        return $this->versions()->create([
            'version' => $this->nextVersionNumber(),
            'tokens' => ThemeSchema::normalizeTokens($this->tokens ?? []),
            'layout_config' => ThemeSchema::normalizeLayoutConfig($this->layout_config ?? []),
            'component_config' => ThemeSchema::normalizeComponentConfig($this->component_config ?? []),
            'style_profile' => $this->style_profile ?? [],
            'selected_preset' => $this->selected_preset,
            'guardrails_applied' => $this->guardrails_applied ?? [],
            'generation_warnings' => $this->generation_warnings ?? [],
            'css_variables' => $this->css_variables ?? [],
            'custom_css' => $this->custom_css,
            'notes' => $notes,
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function getCssVariables(): array
    {
        return ThemeSchema::cssVariables($this->tokens ?? [], $this->css_variables ?? []);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    private function createVersionFromOriginal(string $notes): void
    {
        if (! $this->exists) {
            return;
        }

        $this->versions()->create([
            'version' => $this->nextVersionNumber(),
            'tokens' => ThemeSchema::normalizeTokens($this->originalArray('tokens')),
            'layout_config' => ThemeSchema::normalizeLayoutConfig($this->originalArray('layout_config')),
            'component_config' => ThemeSchema::normalizeComponentConfig($this->originalArray('component_config')),
            'style_profile' => $this->originalArray('style_profile'),
            'selected_preset' => $this->getOriginal('selected_preset'),
            'guardrails_applied' => $this->originalArray('guardrails_applied'),
            'generation_warnings' => $this->originalArray('generation_warnings'),
            'css_variables' => $this->originalArray('css_variables'),
            'custom_css' => $this->getOriginal('custom_css'),
            'notes' => $notes,
            'created_by' => Auth::id(),
        ]);
    }

    private function nextVersionNumber(): int
    {
        return ((int) $this->versions()->max('version')) + 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function originalArray(string $key): array
    {
        $value = $this->getOriginal($key);

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
