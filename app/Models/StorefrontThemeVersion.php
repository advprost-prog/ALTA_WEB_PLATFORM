<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorefrontThemeVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'storefront_theme_id',
        'version',
        'tokens',
        'layout_config',
        'component_config',
        'style_profile',
        'selected_preset',
        'guardrails_applied',
        'generation_warnings',
        'css_variables',
        'custom_css',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'tokens' => 'array',
            'layout_config' => 'array',
            'component_config' => 'array',
            'style_profile' => 'array',
            'guardrails_applied' => 'array',
            'generation_warnings' => 'array',
            'css_variables' => 'array',
        ];
    }

    public function theme(): BelongsTo
    {
        return $this->belongsTo(StorefrontTheme::class, 'storefront_theme_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
