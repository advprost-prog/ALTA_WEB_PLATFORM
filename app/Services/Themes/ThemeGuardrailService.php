<?php

namespace App\Services\Themes;

use Illuminate\Support\Str;

class ThemeGuardrailService
{
    public function __construct(private readonly ThemePayloadValidator $validator)
    {
        //
    }

    /**
     * @param  array<string, mixed>  $themePayload
     * @param  array<string, mixed>  $styleProfile
     * @return array<string, mixed>
     */
    public function apply(array $themePayload, array $styleProfile): array
    {
        $applied = [];
        $warnings = [];
        $tokens = ThemeSchema::normalizeTokens((array) ($themePayload['tokens'] ?? []));
        $layout = ThemeSchema::normalizeLayoutConfig((array) ($themePayload['layout_config'] ?? []));
        $components = ThemeSchema::normalizeComponentConfig((array) ($themePayload['component_config'] ?? []));
        $styleLock = is_array($styleProfile['style_lock'] ?? null) ? $styleProfile['style_lock'] : [];
        $fingerprint = is_array($styleProfile['style_fingerprint'] ?? null) ? $styleProfile['style_fingerprint'] : [];
        $businessProfile = is_array($styleProfile['business_profile'] ?? null) ? $styleProfile['business_profile'] : [];
        $confidence = (float) ($styleProfile['confidence'] ?? 0);
        $visualMode = (string) ($styleLock['visual_mode'] ?? $fingerprint['visual_mode'] ?? $styleProfile['visual_mode'] ?? 'mixed');
        $ctaStyle = (string) ($styleLock['primary_cta_style'] ?? $fingerprint['primary_cta_style'] ?? $styleProfile['cta_style'] ?? 'neutral');
        $backgroundSystem = (string) ($styleLock['background_system'] ?? $fingerprint['background_system'] ?? 'white');
        $productCardStyle = (string) ($styleLock['product_card_style'] ?? $fingerprint['product_card_style'] ?? $styleProfile['card_style'] ?? 'detailed_grid');
        $headerStyle = (string) ($styleLock['header_style'] ?? $fingerprint['header_style'] ?? $styleProfile['header_style'] ?? 'marketplace_search');
        $sectionRhythm = (string) ($styleLock['section_rhythm'] ?? $fingerprint['section_rhythm'] ?? $styleProfile['homepage_structure'] ?? 'hero_first');

        if ($confidence >= 0.75) {
            $this->applyVisualModeGuardrail($tokens, $visualMode, $applied, $warnings);
        }

        $this->applyBackgroundSystemGuardrail($tokens, $backgroundSystem, $applied);
        $this->applyCtaGuardrail($tokens, $ctaStyle, $applied);
        $this->applyDensityGuardrail($tokens, $layout, (string) ($styleProfile['density'] ?? 'normal'), $applied);
        $this->applyStructureGuardrail($layout, $sectionRhythm, $productCardStyle, $applied);
        $this->applyCardGuardrail($layout, $components, $productCardStyle, $applied);
        $this->applyHeaderGuardrail($layout, $headerStyle, $applied);
        $this->applyTypographyMoodGuardrail($tokens, $layout, $components, $fingerprint, $applied);
        $this->applyBadgeGuardrail($tokens, $components, (string) ($styleProfile['badge_style'] ?? 'minimal'), $applied);
        $this->preventDomainStyleLeak($tokens, $layout, $components, $businessProfile, $visualMode, $ctaStyle, $productCardStyle, $sectionRhythm, $applied, $warnings);
        $this->applyContrastGuardrail($tokens, $applied, $warnings);

        $customCss = $this->validator->sanitizeCustomCss((string) ($themePayload['custom_css'] ?? ''));
        if ($customCss !== trim((string) ($themePayload['custom_css'] ?? ''))) {
            $applied[] = 'custom_css_sanitized';
            $warnings[] = 'Unsafe or remote CSS fragments were removed from custom CSS.';
        }

        $name = $this->sanitizeGeneratedName((string) ($themePayload['name'] ?? 'AI Commerce Style Draft'));
        if ($name !== (string) ($themePayload['name'] ?? '')) {
            $applied[] = 'unsafe_name_terms_sanitized';
        }

        $tokens = ThemeSchema::normalizeTokens($tokens);

        return array_replace($themePayload, [
            'name' => $name,
            'tokens' => $tokens,
            'layout_config' => $layout,
            'component_config' => $components,
            'css_variables' => ThemeSchema::cssVariables($tokens),
            'custom_css' => $customCss !== '' ? $customCss : null,
            'style_profile' => $styleProfile,
            'guardrails_applied' => array_values(array_unique(array_merge((array) ($themePayload['guardrails_applied'] ?? []), $applied))),
            'generation_warnings' => array_values(array_unique(array_merge((array) ($themePayload['generation_warnings'] ?? []), $warnings))),
        ]);
    }

    /**
     * @param  array<string, mixed>  $tokens
     * @param  array<int, string>  $applied
     * @param  array<int, string>  $warnings
     */
    private function applyVisualModeGuardrail(array &$tokens, string $visualMode, array &$applied, array &$warnings): void
    {
        if ($visualMode === 'light') {
            if (! $this->isLight((string) ($tokens['colors']['background'] ?? ''))) {
                $tokens['colors']['background'] = '#ffffff';
                $applied[] = 'light_background_enforced';
            }

            if (! $this->isLight((string) ($tokens['colors']['surface'] ?? ''))) {
                $tokens['colors']['surface'] = '#ffffff';
                $applied[] = 'light_surface_enforced';
            }

            if (! $this->isLight((string) ($tokens['colors']['surfaceAlt'] ?? ''))) {
                $tokens['colors']['surfaceAlt'] = '#f5f6f7';
                $applied[] = 'light_surface_alt_enforced';
            }

            if (! $this->isDark((string) ($tokens['colors']['text'] ?? ''))) {
                $tokens['colors']['text'] = '#1f2933';
                $applied[] = 'dark_text_for_light_theme_enforced';
                $warnings[] = 'AI returned low-contrast text for a light style profile.';
            }
        }

        if ($visualMode === 'dark') {
            if (! $this->isDark((string) ($tokens['colors']['background'] ?? ''))) {
                $tokens['colors']['background'] = '#101114';
                $applied[] = 'dark_background_enforced';
            }

            if (! $this->isDark((string) ($tokens['colors']['surface'] ?? ''))) {
                $tokens['colors']['surface'] = '#17191d';
                $applied[] = 'dark_surface_enforced';
            }

            if (! $this->isDark((string) ($tokens['colors']['surfaceAlt'] ?? ''))) {
                $tokens['colors']['surfaceAlt'] = '#202329';
                $applied[] = 'dark_surface_alt_enforced';
            }

            if (! $this->isLight((string) ($tokens['colors']['text'] ?? ''))) {
                $tokens['colors']['text'] = '#f8fafc';
                $applied[] = 'light_text_for_dark_theme_enforced';
                $warnings[] = 'AI returned low-contrast text for a dark style profile.';
            }
        }
    }

    /**
     * @param  array<string, mixed>  $tokens
     * @param  array<int, string>  $applied
     */
    private function applyCtaGuardrail(array &$tokens, string $ctaStyle, array &$applied): void
    {
        $targets = [
            'black_solid' => '#000000',
            'yellow_cta' => '#f5b400',
            'yellow_solid' => '#f5b400',
            'orange_cta' => '#f97316',
            'red_cta' => '#ef4444',
            'red_solid' => '#ef4444',
            'blue_cta' => '#2563eb',
            'blue_solid' => '#2563eb',
        ];

        if (! array_key_exists($ctaStyle, $targets)) {
            return;
        }

        if (! $this->matchesCtaStyle((string) ($tokens['colors']['primary'] ?? ''), $ctaStyle)) {
            $tokens['colors']['primary'] = $targets[$ctaStyle];
            $tokens['colors']['primaryContrast'] = $this->isLight($targets[$ctaStyle]) ? '#1f2933' : '#ffffff';
            $applied[] = $ctaStyle.'_primary_enforced';
        }

        if (! $this->matchesCtaStyle((string) ($tokens['colors']['accent'] ?? ''), $ctaStyle)) {
            $tokens['colors']['accent'] = $targets[$ctaStyle] === '#000000' ? '#e53935' : $targets[$ctaStyle];
            $applied[] = $ctaStyle.'_accent_enforced';
        }
    }

    /**
     * @param  array<string, mixed>  $tokens
     * @param  array<string, mixed>  $layout
     * @param  array<int, string>  $applied
     */
    private function applyDensityGuardrail(array &$tokens, array &$layout, string $density, array &$applied): void
    {
        if ($density !== 'compact') {
            return;
        }

        $tokens['spacing']['sectionY'] = '2.25rem';
        $tokens['spacing']['cardPadding'] = '0.75rem';
        $tokens['spacing']['gridGap'] = '0.875rem';
        $layout['density'] = 'compact';

        if (! in_array($layout['productCardVariant'] ?? null, ['compact', 'marketplace'], true)) {
            $layout['productCardVariant'] = 'compact';
        }

        $applied[] = 'compact_density_enforced';
    }

    /**
     * @param  array<string, mixed>  $layout
     * @param  array<int, string>  $applied
     */
    private function applyStructureGuardrail(array &$layout, string $homepageStructure, string $productCardStyle, array &$applied): void
    {
        if ($homepageStructure !== 'product_sections' && $homepageStructure !== 'sectional') {
            return;
        }

        $layout['containerWidth'] = 'wide';
        $defaultHeroVariant = $productCardStyle === 'borderless_catalog' || ($layout['headerVariant'] ?? null) === 'logo_left_center_nav_icons_right'
            ? 'none'
            : 'minimal';
        $layout['heroVariant'] = in_array($layout['heroVariant'] ?? null, ['none', 'minimal', 'category_focus', 'banner'], true)
            ? $layout['heroVariant']
            : $defaultHeroVariant;
        $layout['categoryGridVariant'] = in_array($layout['categoryGridVariant'] ?? null, ['icons', 'tiles', 'clean_tiles'], true)
            ? $layout['categoryGridVariant']
            : 'clean_tiles';

        $applied[] = 'sectional_homepage_layout_enforced';
    }

    /**
     * @param  array<string, mixed>  $layout
     * @param  array<string, mixed>  $components
     * @param  array<int, string>  $applied
     */
    private function applyCardGuardrail(array &$layout, array &$components, string $cardStyle, array &$applied): void
    {
        if ($cardStyle === 'borderless_catalog') {
            $layout['productCardVariant'] = 'light_woocommerce_boutique';
            $components['showQuickBuy'] = true;
            $components['showBadges'] = true;
            $components['showProductShortSpecs'] = false;
            $components['showAddToCart'] = true;
            $components['cardImageRatio'] = 'contain';
            $applied[] = 'borderless_catalog_components_enforced';

            return;
        }

        if ($cardStyle !== 'marketplace_card') {
            return;
        }

        if (! in_array($layout['productCardVariant'] ?? null, ['compact', 'marketplace'], true)) {
            $layout['productCardVariant'] = 'compact';
        }

        $components['showQuickBuy'] = true;
        $components['showBadges'] = true;
        $components['showProductShortSpecs'] = false;

        if (($components['cardImageRatio'] ?? null) === '16/9') {
            $components['cardImageRatio'] = 'contain';
        }

        $applied[] = 'marketplace_card_components_enforced';
    }

    /**
     * @param  array<string, mixed>  $tokens
     * @param  array<string, mixed>  $components
     * @param  array<int, string>  $applied
     */
    private function applyBadgeGuardrail(array &$tokens, array &$components, string $badgeStyle, array &$applied): void
    {
        if ($badgeStyle !== 'sale_heavy') {
            return;
        }

        $components['showBadges'] = true;
        $tokens['badges']['radius'] = in_array($tokens['badges']['radius'] ?? null, ['2px', '3px', '4px'], true)
            ? $tokens['badges']['radius']
            : '3px';
        $tokens['badges']['uppercase'] = true;
        $applied[] = 'sale_badges_enabled';
    }

    /**
     * @param  array<string, mixed>  $layout
     * @param  array<int, string>  $applied
     */
    private function applyHeaderGuardrail(array &$layout, string $headerStyle, array &$applied): void
    {
        if ($headerStyle === 'logo_left_center_nav_icons_right') {
            $layout['headerVariant'] = 'logo_left_center_nav_icons_right';
            $applied[] = 'header_style_enforced';
        }
    }

    /**
     * @param  array<string, mixed>  $tokens
     * @param  array<string, mixed>  $layout
     * @param  array<string, mixed>  $components
     * @param  array<string, mixed>  $fingerprint
     * @param  array<int, string>  $applied
     */
    private function applyTypographyMoodGuardrail(array &$tokens, array &$layout, array &$components, array $fingerprint, array &$applied): void
    {
        if (($fingerprint['visual_mode'] ?? null) === 'light' && ($fingerprint['mood'] ?? null) === 'clean_woocommerce_boutique') {
            $tokens['buttons']['uppercase'] = true;
            $tokens['buttons']['shadow'] = 'none';
            $tokens['buttons']['radius'] = '2px';
            $components['showQuickBuy'] = true;
            $components['showAddToCart'] = true;
            $layout['heroVariant'] = 'none';
            $applied[] = 'boutique_typography_guardrail';
        }
    }

    /**
     * @param  array<string, mixed>  $tokens
     * @param  array<string, mixed>  $layout
     * @param  array<string, mixed>  $components
     * @param  array<string, string>  $businessProfile
     * @param  string  $visualMode
     * @param  string  $ctaStyle
     * @param  string  $productCardStyle
     * @param  string  $sectionRhythm
     * @param  array<int, string>  $applied
     * @param  array<int, string>  $warnings
     */
    private function preventDomainStyleLeak(array &$tokens, array &$layout, array &$components, array $businessProfile, string $visualMode, string $ctaStyle, string $productCardStyle, string $sectionRhythm, array &$applied, array &$warnings): void
    {
        if (($businessProfile['domain'] ?? null) !== 'auto_goods' || $visualMode !== 'light') {
            return;
        }

        if ($ctaStyle === 'black_solid') {
            $tokens['colors']['primary'] = '#000000';
            $tokens['colors']['primaryContrast'] = '#ffffff';
            $tokens['colors']['accent'] = '#e53935';
            $applied[] = 'prevented_domain_style_leak';
        }

        if ($productCardStyle === 'borderless_catalog' && $sectionRhythm === 'product_sections') {
            $layout['heroVariant'] = 'none';
            $layout['productCardVariant'] = 'light_woocommerce_boutique';
            $components['showAddToCart'] = true;
            $warnings[] = 'Business domain was not allowed to force a dark automotive layout.';
        }
    }

    /**
     * @param  array<string, mixed>  $tokens
     * @param  string  $backgroundSystem
     * @param  array<int, string>  $applied
     */
    private function applyBackgroundSystemGuardrail(array &$tokens, string $backgroundSystem, array &$applied): void
    {
        if ($backgroundSystem === 'white') {
            $tokens['colors']['background'] = '#ffffff';
            $tokens['colors']['surface'] = '#ffffff';
            $tokens['colors']['surfaceAlt'] = '#f7f7f7';
            $tokens['colors']['text'] = '#1f2933';
            $tokens['colors']['mutedText'] = '#68707d';
            $applied[] = 'white_background_system_enforced';
        }
    }

    /**
     * @param  array<string, mixed>  $tokens
     * @param  array<int, string>  $applied
     * @param  array<int, string>  $warnings
     */
    private function applyContrastGuardrail(array &$tokens, array &$applied, array &$warnings): void
    {
        $background = (string) ($tokens['colors']['background'] ?? '');
        $text = (string) ($tokens['colors']['text'] ?? '');

        if ($this->isLight($background) && $this->isLight($text)) {
            $tokens['colors']['text'] = '#1f2933';
            $tokens['colors']['mutedText'] = '#68707d';
            $applied[] = 'light_theme_text_contrast_fixed';
            $warnings[] = 'Light text on light background was corrected.';
        }

        if ($this->isDark($background) && $this->isDark($text)) {
            $tokens['colors']['text'] = '#f8fafc';
            $tokens['colors']['mutedText'] = '#a1a1aa';
            $applied[] = 'dark_theme_text_contrast_fixed';
            $warnings[] = 'Dark text on dark background was corrected.';
        }
    }

    private function sanitizeGeneratedName(string $name): string
    {
        $name = trim($name) !== '' ? trim($name) : 'AI Commerce Style Draft';
        $name = preg_replace('/https?:\/\/\S+/i', 'source style', $name) ?? $name;
        $name = preg_replace('/\b[a-z0-9-]+\.(?:com|net|org|ua|co|io|dev)\b/i', 'source style', $name) ?? $name;
        $name = str_ireplace([' clone', ' replica', ' copied'], [' style', ' style', ' original'], $name);

        return trim(Str::limit($name, 255, ''));
    }

    private function matchesCtaStyle(string $color, string $ctaStyle): bool
    {
        $rgb = $this->rgb($color);

        if (! $rgb) {
            return false;
        }

        [$r, $g, $b] = $rgb;

        return match ($ctaStyle) {
            'black_solid' => $r <= 70 && $g <= 70 && $b <= 70,
            'yellow_cta', 'yellow_solid' => $r >= 180 && $g >= 135 && $b <= 95,
            'orange_cta' => $r >= 180 && $g >= 70 && $g < 155 && $b <= 100,
            'red_cta', 'red_solid' => $r >= 170 && $g <= 115 && $b <= 120,
            'blue_cta', 'blue_solid' => $b >= 145 && $r <= 120,
            default => true,
        };
    }

    private function isLight(string $color): bool
    {
        $rgb = $this->rgb($color);

        return $rgb !== null && $this->brightness($rgb) >= 185;
    }

    private function isDark(string $color): bool
    {
        $rgb = $this->rgb($color);

        return $rgb !== null && $this->brightness($rgb) <= 115;
    }

    /**
     * @param  array{0:int,1:int,2:int}  $rgb
     */
    private function brightness(array $rgb): float
    {
        return (($rgb[0] * 299) + ($rgb[1] * 587) + ($rgb[2] * 114)) / 1000;
    }

    /**
     * @return array{0:int,1:int,2:int}|null
     */
    private function rgb(string $color): ?array
    {
        if (preg_match('/^#([0-9a-f]{6})(?:[0-9a-f]{2})?$/i', $color, $match) !== 1) {
            return null;
        }

        return [
            hexdec(substr($match[1], 0, 2)),
            hexdec(substr($match[1], 2, 2)),
            hexdec(substr($match[1], 4, 2)),
        ];
    }
}
