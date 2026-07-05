<?php

namespace App\Services\Themes;

class ThemePresetMapper
{
    /**
     * @param  array<string, mixed>  $styleProfile
     * @return array<string, mixed>
     */
    public function mapStyleProfileToThemeDefaults(array $styleProfile): array
    {
        $preset = $this->selectPreset($styleProfile);
        $payload = match ($preset) {
            'light_woocommerce_boutique' => $this->lightWoocommerceBoutique(),
            'dark_automotive_bold' => $this->darkAutomotiveBold(),
            'premium_spacious_catalog' => $this->premiumSpaciousCatalog(),
            'promo_discount_grid' => $this->promoDiscountGrid(),
            'clean_minimal_catalog' => $this->cleanMinimalCatalog(),
            'technical_b2b_catalog' => $this->technicalB2bCatalog(),
            default => $this->lightMarketplaceCompact(),
        };

        $payload['selected_preset'] = $preset;
        $payload['style_profile'] = $styleProfile;
        $payload['css_variables'] = ThemeSchema::cssVariables($payload['tokens']);
        $payload['custom_css'] = $payload['custom_css'] ?? null;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $styleProfile
     */
    private function selectPreset(array $styleProfile): string
    {
        $fingerprint = is_array($styleProfile['style_fingerprint'] ?? null) ? $styleProfile['style_fingerprint'] : [];
        $styleLock = is_array($styleProfile['style_lock'] ?? null) ? $styleProfile['style_lock'] : [];

        $visualMode = (string) (($fingerprint['visual_mode'] ?? $styleProfile['visual_mode'] ?? 'mixed'));
        $density = (string) (($fingerprint['spacing_density'] ?? $styleProfile['density'] ?? 'normal'));
        $cta = (string) (($fingerprint['primary_cta_style'] ?? $styleProfile['cta_style'] ?? 'neutral'));
        $productCardStyle = (string) (($fingerprint['product_card_style'] ?? $styleProfile['card_style'] ?? 'detailed_grid'));
        $sectionRhythm = (string) (($fingerprint['section_rhythm'] ?? $styleProfile['homepage_structure'] ?? 'hero_first'));
        $headerStyle = (string) (($fingerprint['header_style'] ?? $styleProfile['header_style'] ?? 'marketplace_search'));
        $mood = (string) ($fingerprint['mood'] ?? '');

        if ($visualMode === 'light' && $cta === 'black_solid' && $productCardStyle === 'borderless_catalog' && $sectionRhythm === 'product_sections') {
            return 'light_woocommerce_boutique';
        }

        if ($visualMode === 'dark' && (in_array($mood, ['dark_automotive_bold', 'clean_automotive'], true) || $headerStyle === 'automotive_bold' || $styleProfile['ecommerce_type'] === 'automotive_parts' || ($styleProfile['business_profile']['domain'] ?? null) === 'auto_goods')) {
            return 'dark_automotive_bold';
        }

        if ($density === 'airy' || $mood === 'premium_spacious_catalog' || $productCardStyle === 'premium_card') {
            return 'premium_spacious_catalog';
        }

        if (($cta === 'yellow_solid' || $cta === 'red_solid') && ($sectionRhythm === 'promo_first' || $mood === 'promo_discount')) {
            return 'promo_discount_grid';
        }

        if ($sectionRhythm === 'minimal' || $headerStyle === 'minimal_clean' || $mood === 'technical') {
            return 'clean_minimal_catalog';
        }

        if ($mood === 'technical_b2b') {
            return 'technical_b2b_catalog';
        }

        return 'light_marketplace_compact';
    }

    /**
     * @return array<string, mixed>
     */
    private function lightWoocommerceBoutique(): array
    {
        $tokens = ThemeSchema::normalizeTokens([
            'colors' => [
                'primary' => '#000000',
                'primaryContrast' => '#ffffff',
                'secondary' => '#111111',
                'accent' => '#e53935',
                'background' => '#ffffff',
                'surface' => '#ffffff',
                'surfaceAlt' => '#f7f7f7',
                'text' => '#111111',
                'mutedText' => '#777777',
                'border' => '#eeeeee',
                'success' => '#16a34a',
                'warning' => '#ef4444',
                'danger' => '#dc2626',
            ],
            'radius' => ['sm' => '2px', 'md' => '3px', 'lg' => '4px', 'xl' => '6px'],
            'spacing' => ['sectionY' => '3rem', 'cardPadding' => '1rem', 'gridGap' => '1.25rem'],
            'shadows' => [
                'card' => '0 8px 24px rgb(17 17 17 / 0.06)',
                'dropdown' => '0 16px 40px rgb(17 17 17 / 0.08)',
                'hero' => 'none',
            ],
            'buttons' => ['radius' => '2px', 'uppercase' => true, 'shadow' => 'none'],
            'badges' => ['radius' => '999px', 'uppercase' => true],
        ]);

        return [
            'style_family' => 'light woocommerce boutique catalog',
            'tokens' => $tokens,
            'layout_config' => ThemeSchema::normalizeLayoutConfig([
                'headerVariant' => 'logo_left_center_nav_icons_right',
                'topBarVariant' => 'slim',
                'heroVariant' => 'none',
                'categoryGridVariant' => 'clean_tiles',
                'productCardVariant' => 'light_woocommerce_boutique',
                'productPageVariant' => 'marketplace',
                'footerVariant' => 'light_columns',
                'containerWidth' => 'wide',
                'density' => 'airy',
                'mobileNavVariant' => 'drawer',
            ]),
            'component_config' => ThemeSchema::normalizeComponentConfig([
                'showWishlist' => true,
                'showQuickView' => true,
                'showRating' => true,
                'showSaleBadges' => true,
                'showAddToCart' => true,
                'showTopBar' => true,
                'showSearch' => true,
                'stickyHeader' => true,
                'showCategoryMenu' => true,
                'showBadges' => true,
                'showBrandInCard' => true,
                'showSkuInCard' => false,
                'showQuickBuy' => true,
                'showProductShortSpecs' => false,
                'heroOverlay' => false,
                'cardImageRatio' => 'contain',
                'cardAlignment' => 'center',
                'uppercaseNav' => true,
                'addToCartStyle' => 'black_solid',
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lightMarketplaceCompact(): array
    {
        $tokens = ThemeSchema::normalizeTokens([
            'colors' => [
                'primary' => '#f5b400',
                'primaryContrast' => '#1f2933',
                'secondary' => '#1f2933',
                'accent' => '#e5484d',
                'background' => '#ffffff',
                'surface' => '#ffffff',
                'surfaceAlt' => '#f5f6f7',
                'text' => '#1f2933',
                'mutedText' => '#68707d',
                'border' => '#e4e7eb',
                'success' => '#16a34a',
                'warning' => '#f59e0b',
                'danger' => '#dc2626',
            ],
            'radius' => ['sm' => '2px', 'md' => '3px', 'lg' => '4px', 'xl' => '6px'],
            'spacing' => ['sectionY' => '2.25rem', 'cardPadding' => '0.75rem', 'gridGap' => '0.875rem'],
            'shadows' => [
                'card' => '0 8px 24px rgb(31 41 51 / 0.08)',
                'dropdown' => '0 18px 44px rgb(31 41 51 / 0.14)',
                'hero' => 'none',
            ],
            'buttons' => ['radius' => '2px', 'uppercase' => true, 'shadow' => 'none'],
            'badges' => ['radius' => '2px', 'uppercase' => true],
        ]);

        return [
            'style_family' => 'light marketplace compact catalog',
            'tokens' => $tokens,
            'layout_config' => ThemeSchema::normalizeLayoutConfig([
                'headerVariant' => 'marketplace',
                'topBarVariant' => 'contact',
                'heroVariant' => 'minimal',
                'categoryGridVariant' => 'icons',
                'productCardVariant' => 'compact',
                'productPageVariant' => 'marketplace',
                'footerVariant' => 'columns',
                'containerWidth' => 'wide',
                'density' => 'compact',
                'mobileNavVariant' => 'drawer',
            ]),
            'component_config' => ThemeSchema::normalizeComponentConfig([
                'showTopBar' => true,
                'showSearch' => true,
                'stickyHeader' => true,
                'showCategoryMenu' => true,
                'showBadges' => true,
                'showBrandInCard' => false,
                'showSkuInCard' => false,
                'showQuickBuy' => true,
                'showProductShortSpecs' => false,
                'heroOverlay' => false,
                'cardImageRatio' => 'contain',
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function darkAutomotiveBold(): array
    {
        $tokens = ThemeSchema::defaultTokens();

        return [
            'style_family' => 'dark automotive bold catalog',
            'tokens' => $tokens,
            'layout_config' => ThemeSchema::normalizeLayoutConfig([
                'headerVariant' => 'automotive',
                'topBarVariant' => 'contact',
                'heroVariant' => 'dark_promo',
                'categoryGridVariant' => 'cards',
                'productCardVariant' => 'dark',
                'productPageVariant' => 'two_column',
                'footerVariant' => 'dark',
                'containerWidth' => 'normal',
                'density' => 'normal',
                'mobileNavVariant' => 'drawer',
            ]),
            'component_config' => ThemeSchema::defaultComponentConfig(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function premiumSpaciousCatalog(): array
    {
        $tokens = ThemeSchema::normalizeTokens([
            'colors' => [
                'primary' => '#d6b25e',
                'primaryContrast' => '#111827',
                'secondary' => '#94a3b8',
                'accent' => '#60a5fa',
                'background' => '#0b0f14',
                'surface' => '#151b23',
                'surfaceAlt' => '#1f2937',
                'text' => '#f8fafc',
                'mutedText' => '#a8b3c2',
                'border' => '#ffffff1f',
            ],
            'radius' => ['sm' => '6px', 'md' => '8px', 'lg' => '8px', 'xl' => '12px'],
            'spacing' => ['sectionY' => '4.25rem', 'cardPadding' => '1.25rem', 'gridGap' => '1.5rem'],
            'buttons' => ['radius' => '8px', 'uppercase' => false, 'shadow' => '0 12px 36px rgb(214 178 94 / 0.18)'],
        ]);

        return [
            'style_family' => 'premium spacious catalog',
            'tokens' => $tokens,
            'layout_config' => ThemeSchema::normalizeLayoutConfig([
                'headerVariant' => 'bold',
                'topBarVariant' => 'contact',
                'heroVariant' => 'split',
                'categoryGridVariant' => 'editorial',
                'productCardVariant' => 'premium',
                'productPageVariant' => 'premium',
                'footerVariant' => 'corporate',
                'containerWidth' => 'normal',
                'density' => 'spacious',
            ]),
            'component_config' => ThemeSchema::normalizeComponentConfig([
                'showProductShortSpecs' => true,
                'cardImageRatio' => '16/9',
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function promoDiscountGrid(): array
    {
        $tokens = ThemeSchema::normalizeTokens([
            'colors' => [
                'primary' => '#facc15',
                'primaryContrast' => '#171717',
                'secondary' => '#ef4444',
                'accent' => '#22d3ee',
                'background' => '#18181b',
                'surface' => '#27272a',
                'surfaceAlt' => '#3f3f46',
                'text' => '#fafafa',
                'mutedText' => '#d4d4d8',
                'border' => '#facc1533',
            ],
            'radius' => ['sm' => '2px', 'md' => '4px', 'lg' => '4px', 'xl' => '6px'],
            'spacing' => ['sectionY' => '2.5rem', 'cardPadding' => '0.875rem', 'gridGap' => '1rem'],
            'buttons' => ['radius' => '3px', 'uppercase' => true, 'shadow' => '0 10px 28px rgb(250 204 21 / 0.18)'],
            'badges' => ['radius' => '3px', 'uppercase' => true],
        ]);

        return [
            'style_family' => 'promo discount grid',
            'tokens' => $tokens,
            'layout_config' => ThemeSchema::normalizeLayoutConfig([
                'headerVariant' => 'automotive',
                'topBarVariant' => 'promo',
                'heroVariant' => 'banner',
                'categoryGridVariant' => 'tiles',
                'productCardVariant' => 'compact',
                'productPageVariant' => 'two_column',
                'footerVariant' => 'dark',
                'containerWidth' => 'wide',
                'density' => 'compact',
                'mobileNavVariant' => 'dropdown',
            ]),
            'component_config' => ThemeSchema::normalizeComponentConfig([
                'showBrandInCard' => false,
                'showSkuInCard' => true,
                'showProductShortSpecs' => false,
                'heroOverlay' => true,
                'cardImageRatio' => '4/3',
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cleanMinimalCatalog(): array
    {
        $tokens = ThemeSchema::normalizeTokens([
            'colors' => [
                'primary' => '#2563eb',
                'primaryContrast' => '#ffffff',
                'secondary' => '#0f172a',
                'accent' => '#f59e0b',
                'background' => '#f8fafc',
                'surface' => '#ffffff',
                'surfaceAlt' => '#eef2f7',
                'text' => '#0f172a',
                'mutedText' => '#64748b',
                'border' => '#d8dee8',
            ],
            'radius' => ['md' => '8px', 'lg' => '8px', 'xl' => '10px'],
            'spacing' => ['sectionY' => '3rem', 'gridGap' => '1.25rem'],
            'buttons' => ['radius' => '6px', 'uppercase' => false],
            'badges' => ['radius' => '999px', 'uppercase' => false],
        ]);

        return [
            'style_family' => 'clean minimal catalog',
            'tokens' => $tokens,
            'layout_config' => ThemeSchema::normalizeLayoutConfig([
                'headerVariant' => 'centered',
                'topBarVariant' => 'slim',
                'heroVariant' => 'category_focus',
                'categoryGridVariant' => 'tiles',
                'productCardVariant' => 'marketplace',
                'productPageVariant' => 'marketplace',
                'footerVariant' => 'columns',
                'containerWidth' => 'normal',
                'density' => 'normal',
            ]),
            'component_config' => ThemeSchema::normalizeComponentConfig([
                'showBrandInCard' => true,
                'showSkuInCard' => false,
                'showProductShortSpecs' => false,
                'heroOverlay' => false,
                'cardImageRatio' => 'square',
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function technicalB2bCatalog(): array
    {
        $tokens = ThemeSchema::normalizeTokens([
            'colors' => [
                'primary' => '#1d4ed8',
                'primaryContrast' => '#ffffff',
                'background' => '#f8fafc',
                'surface' => '#ffffff',
                'surfaceAlt' => '#eef2ff',
                'text' => '#111827',
                'mutedText' => '#64748b',
                'border' => '#dbe3f0',
            ],
        ]);

        return [
            'style_family' => 'technical b2b catalog',
            'tokens' => $tokens,
            'layout_config' => ThemeSchema::normalizeLayoutConfig([
                'headerVariant' => 'minimal_clean',
                'topBarVariant' => 'none',
                'heroVariant' => 'minimal',
                'categoryGridVariant' => 'tiles',
                'productCardVariant' => 'premium',
                'productPageVariant' => 'marketplace',
                'footerVariant' => 'columns',
                'containerWidth' => 'wide',
                'density' => 'normal',
            ]),
            'component_config' => ThemeSchema::normalizeComponentConfig([
                'showWishlist' => false,
                'showQuickView' => false,
                'showRating' => true,
                'showSaleBadges' => false,
                'showAddToCart' => true,
            ]),
        ];
    }
}
