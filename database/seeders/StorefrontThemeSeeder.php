<?php

namespace Database\Seeders;

use App\Models\StorefrontTheme;
use App\Services\Themes\ThemePayloadValidator;
use App\Services\Themes\ThemeSchema;
use Illuminate\Database\Seeder;

class StorefrontThemeSeeder extends Seeder
{
    public function run(): void
    {
        $validator = app(ThemePayloadValidator::class);
        $hasActiveTheme = StorefrontTheme::query()->where('is_active', true)->exists();

        foreach (ThemeSchema::systemThemes() as $theme) {
            $existing = StorefrontTheme::query()->where('slug', $theme['slug'])->first();

            if ($existing && $existing->type !== StorefrontTheme::TYPE_SYSTEM) {
                continue;
            }

            $payload = $validator->validate($theme);
            $isActive = $existing?->is_active
                ?? (! $hasActiveTheme && $theme['slug'] === 'alta-trade-dark-automotive');

            $record = StorefrontTheme::updateOrCreate(
                ['slug' => $theme['slug']],
                [
                    'name' => $theme['name'],
                    'description' => $theme['description'],
                    'type' => StorefrontTheme::TYPE_SYSTEM,
                    'status' => StorefrontTheme::STATUS_PUBLISHED,
                    'is_active' => $isActive,
                    'source' => 'system_seed',
                    'source_url' => null,
                    'style_family' => $theme['style_family'],
                    'tokens' => $payload['tokens'],
                    'layout_config' => $payload['layout_config'],
                    'component_config' => $payload['component_config'],
                    'css_variables' => $payload['css_variables'],
                    'custom_css' => $payload['custom_css'] ?? null,
                    'preview_image' => null,
                    'generated_by_ai' => false,
                    'ai_run_id' => null,
                ],
            );

            if ($record->versions()->doesntExist()) {
                $record->createVersion('Initial system theme seed.');
            }
        }
    }
}
