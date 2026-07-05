<?php

namespace App\Services\Themes;

use App\Enums\UserRole;
use App\Models\StorefrontTheme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class ThemeResolver
{
    public function getActiveTheme(): StorefrontTheme
    {
        if (! Schema::hasTable('storefront_themes')) {
            return $this->fallbackTheme();
        }

        return Cache::remember(StorefrontTheme::ACTIVE_CACHE_KEY, now()->addMinutes(30), function (): StorefrontTheme {
            return StorefrontTheme::query()
                ->published()
                ->active()
                ->first()
                ?? StorefrontTheme::query()
                    ->published()
                    ->where('slug', 'alta-trade-dark-automotive')
                    ->first()
                ?? $this->fallbackTheme();
        });
    }

    public function getThemeBySlug(string $slug): ?StorefrontTheme
    {
        if (! Schema::hasTable('storefront_themes')) {
            return null;
        }

        return StorefrontTheme::query()->where('slug', $slug)->first();
    }

    public function getPreviewTheme(?string $slug): StorefrontTheme
    {
        if ($slug && $this->canPreviewThemes()) {
            $theme = $this->getThemeBySlug($slug);

            if ($theme && $theme->status !== StorefrontTheme::STATUS_ARCHIVED) {
                return $theme;
            }
        }

        return $this->getActiveTheme();
    }

    public function resolveForRequest(Request $request): StorefrontTheme
    {
        $slug = $request->query('theme');

        return $this->getPreviewTheme(is_string($slug) ? $slug : null);
    }

    public function isPreview(StorefrontTheme $theme, Request $request): bool
    {
        $queryTheme = $request->query('theme');
        $slug = is_string($queryTheme) ? $queryTheme : '';

        return $slug !== ''
            && $this->canPreviewThemes()
            && $theme->slug === $slug
            && ! $theme->is_active;
    }

    public function getCssVariables(StorefrontTheme $theme): string
    {
        return collect($theme->getCssVariables())
            ->map(fn (string $value, string $property): string => $property.': '.$this->safeCssValue($value).';')
            ->implode("\n        ");
    }

    /**
     * @return array<string, mixed>
     */
    public function getLayoutConfig(?StorefrontTheme $theme = null): array
    {
        return ThemeSchema::normalizeLayoutConfig(($theme ?? $this->getActiveTheme())->layout_config ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function getComponentConfig(?StorefrontTheme $theme = null): array
    {
        return ThemeSchema::normalizeComponentConfig(($theme ?? $this->getActiveTheme())->component_config ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function getStyleProfile(?StorefrontTheme $theme = null): array
    {
        $theme ??= $this->getActiveTheme();
        $profile = is_array($theme->style_profile ?? null) ? $theme->style_profile : [];
        $tokens = ThemeSchema::normalizeTokens($theme->tokens ?? []);

        return array_replace([
            'ecommerce_type' => 'universal',
            'visual_mode' => $this->inferVisualMode((string) ($tokens['colors']['background'] ?? '#101114')),
            'density' => ThemeSchema::normalizeLayoutConfig($theme->layout_config ?? [])['density'] ?? 'normal',
            'card_style' => 'detailed_grid',
            'cta_style' => 'neutral_cta',
            'homepage_structure' => 'hero_focused',
            'header_style' => 'marketplace_search',
            'badge_style' => 'minimal',
            'confidence' => 0.5,
            'evidence' => ['Inferred from active theme tokens.'],
        ], $profile);
    }

    public function canPreviewThemes(): bool
    {
        $role = Auth::user()?->role;

        return Auth::user()?->isAdmin()
            || $role === UserRole::Manager
            || (is_string($role) && $role === UserRole::Manager->value);
    }

    private function fallbackTheme(): StorefrontTheme
    {
        $payload = ThemeSchema::systemThemes()[0];

        return new StorefrontTheme([
            'name' => $payload['name'],
            'slug' => $payload['slug'],
            'description' => $payload['description'],
            'type' => StorefrontTheme::TYPE_SYSTEM,
            'status' => StorefrontTheme::STATUS_PUBLISHED,
            'is_active' => true,
            'source' => 'runtime_fallback',
            'style_family' => $payload['style_family'],
            'tokens' => ThemeSchema::normalizeTokens($payload['tokens']),
            'layout_config' => ThemeSchema::normalizeLayoutConfig($payload['layout_config']),
            'component_config' => ThemeSchema::normalizeComponentConfig($payload['component_config']),
            'css_variables' => ThemeSchema::cssVariables($payload['tokens']),
        ]);
    }

    private function safeCssValue(string $value): string
    {
        return trim(str_replace(["\n", "\r", ';', '{', '}'], '', $value));
    }

    private function inferVisualMode(string $background): string
    {
        if (preg_match('/^#([0-9a-f]{6})(?:[0-9a-f]{2})?$/i', $background, $match) !== 1) {
            return 'mixed';
        }

        $r = hexdec(substr($match[1], 0, 2));
        $g = hexdec(substr($match[1], 2, 2));
        $b = hexdec(substr($match[1], 4, 2));
        $brightness = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;

        return $brightness >= 170 ? 'light' : 'dark';
    }
}
