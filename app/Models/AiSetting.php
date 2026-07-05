<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class AiSetting extends Model
{
    protected $fillable = [
        'provider',
        'enabled',
        'mode',
        'encrypted_api_key',
        'encrypted_admin_api_key',
        'model',
        'timeout',
        'max_input_chars',
        'max_output_tokens',
        'monthly_budget',
        'warning_threshold_percent',
        'hard_limit_enabled',
        'image_search_enabled',
        'image_search_provider',
        'encrypted_image_search_api_key',
        'image_search_safe_mode',
        'image_search_max_candidates',
        'image_search_min_width',
        'image_search_min_height',
        'image_search_preferred_format',
        'image_search_max_download_size_mb',
        'allow_manual_url_candidates',
        'current_month_spend_estimate',
        'last_health_status',
        'last_health_message',
        'last_health_checked_at',
        'last_usage_synced_at',
    ];

    protected $hidden = [
        'encrypted_api_key',
        'encrypted_admin_api_key',
        'encrypted_image_search_api_key',
        'api_key',
        'admin_api_key',
        'image_search_api_key',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'timeout' => 'integer',
            'max_input_chars' => 'integer',
            'max_output_tokens' => 'integer',
            'monthly_budget' => 'decimal:6',
            'warning_threshold_percent' => 'integer',
            'hard_limit_enabled' => 'boolean',
            'image_search_enabled' => 'boolean',
            'image_search_safe_mode' => 'boolean',
            'image_search_max_candidates' => 'integer',
            'image_search_min_width' => 'integer',
            'image_search_min_height' => 'integer',
            'image_search_max_download_size_mb' => 'integer',
            'allow_manual_url_candidates' => 'boolean',
            'current_month_spend_estimate' => 'decimal:6',
            'last_health_checked_at' => 'datetime',
            'last_usage_synced_at' => 'datetime',
        ];
    }

    protected function apiKey(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->decryptSecret($this->encrypted_api_key),
            set: fn (?string $value): array => [
                'encrypted_api_key' => filled($value) ? Crypt::encryptString((string) $value) : null,
            ],
        );
    }

    protected function adminApiKey(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->decryptSecret($this->encrypted_admin_api_key),
            set: fn (?string $value): array => [
                'encrypted_admin_api_key' => filled($value) ? Crypt::encryptString((string) $value) : null,
            ],
        );
    }

    protected function imageSearchApiKey(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->decryptSecret($this->encrypted_image_search_api_key),
            set: fn (?string $value): array => [
                'encrypted_image_search_api_key' => filled($value) ? Crypt::encryptString((string) $value) : null,
            ],
        );
    }

    public static function getActive(): self
    {
        return self::query()->first() ?? self::query()->create([
            'provider' => config('ai.provider', 'openai'),
            'enabled' => (bool) config('ai.enabled', false),
            'model' => config('ai.openai.model', 'gpt-4.1-mini'),
            'timeout' => (int) config('ai.openai.timeout', 60),
            'max_input_chars' => (int) config('ai.max_input_chars', 12000),
            'max_output_tokens' => (int) config('ai.max_output_tokens', 2000),
            'hard_limit_enabled' => true,
            'image_search_enabled' => (bool) config('ai.image_search.enabled', false),
            'image_search_provider' => (string) config('ai.image_search.provider', 'manual_url'),
            'image_search_safe_mode' => (bool) config('ai.image_search.safe_mode', true),
            'image_search_max_candidates' => (int) config('ai.image_search.max_candidates', 5),
            'image_search_min_width' => (int) config('ai.image_search.min_width', 600),
            'image_search_min_height' => (int) config('ai.image_search.min_height', 600),
            'image_search_preferred_format' => (string) config('ai.image_search.preferred_format', 'webp'),
            'image_search_max_download_size_mb' => (int) config('ai.image_search.max_download_size_mb', 5),
            'allow_manual_url_candidates' => (bool) config('ai.image_search.allow_manual_url_candidates', true),
        ]);
    }

    public function hasApiKey(): bool
    {
        return filled($this->api_key);
    }

    public function hasAdminApiKey(): bool
    {
        return filled($this->admin_api_key);
    }

    public function hasImageSearchApiKey(): bool
    {
        return filled($this->image_search_api_key);
    }

    public function maskedApiKey(): string
    {
        return $this->maskSecret($this->api_key);
    }

    public function maskedAdminApiKey(): string
    {
        return $this->maskSecret($this->admin_api_key);
    }

    public function maskedImageSearchApiKey(): string
    {
        return $this->maskSecret($this->image_search_api_key);
    }

    private function decryptSecret(?string $encrypted): ?string
    {
        if (blank($encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (Throwable) {
            return null;
        }
    }

    private function maskSecret(?string $secret): string
    {
        if (blank($secret)) {
            return 'Не задано';
        }

        $secret = (string) $secret;
        $prefix = str_starts_with($secret, 'sk-') ? 'sk-' : substr($secret, 0, min(3, strlen($secret)));
        $suffix = substr($secret, -4);

        return $prefix . '...' . $suffix;
    }
}
