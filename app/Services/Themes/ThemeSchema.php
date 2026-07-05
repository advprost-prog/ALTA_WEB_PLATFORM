<?php

namespace App\Services\Themes;

class ThemeSchema
{
    public const HEADER_VARIANTS = ['compact', 'bold', 'centered', 'marketplace', 'automotive', 'logo_left_center_nav_icons_right', 'marketplace_search', 'minimal_clean'];

    public const TOP_BAR_VARIANTS = ['none', 'slim', 'promo', 'contact'];

    public const HERO_VARIANTS = ['split', 'banner', 'dark_promo', 'category_focus', 'minimal', 'none', 'low_dominance_section_header'];

    public const CATEGORY_GRID_VARIANTS = ['cards', 'tiles', 'icons', 'editorial', 'clean_tiles'];

    public const PRODUCT_CARD_VARIANTS = ['compact', 'detailed', 'dark', 'marketplace', 'premium', 'light_woocommerce_boutique', 'borderless_catalog', 'promo_discount', 'premium_spacious'];

    public const PRODUCT_PAGE_VARIANTS = ['two_column', 'gallery_left', 'marketplace', 'premium'];

    public const FOOTER_VARIANTS = ['simple', 'columns', 'dark', 'corporate', 'light_columns'];

    public const CONTAINER_WIDTHS = ['narrow', 'normal', 'wide', 'full'];

    public const DENSITIES = ['compact', 'normal', 'spacious'];

    public const MOBILE_NAV_VARIANTS = ['drawer', 'bottom', 'dropdown'];

    public const CARD_IMAGE_RATIOS = ['square', '4/3', '16/9', 'contain'];

    /**
     * @return array<string, mixed>
     */
    public static function defaultTokens(): array
    {
        return [
            'colors' => [
                'primary' => '#ffb703',
                'primaryContrast' => '#101114',
                'secondary' => '#18d7ff',
                'accent' => '#b9f23f',
                'background' => '#101114',
                'surface' => '#17191d',
                'surfaceAlt' => '#202329',
                'text' => '#f8fafc',
                'mutedText' => '#9ca3af',
                'border' => '#ffffff1a',
                'success' => '#b9f23f',
                'warning' => '#ffb703',
                'danger' => '#ef4444',
            ],
            'typography' => [
                'fontFamily' => 'Instrument Sans, ui-sans-serif, system-ui, sans-serif',
                'headingFamily' => 'Instrument Sans, ui-sans-serif, system-ui, sans-serif',
                'baseSize' => '16px',
                'scale' => 1.18,
                'headingWeight' => 900,
                'bodyWeight' => 500,
                'letterSpacing' => '0',
            ],
            'radius' => [
                'sm' => '4px',
                'md' => '6px',
                'lg' => '8px',
                'xl' => '12px',
                'full' => '999px',
            ],
            'shadows' => [
                'card' => '0 20px 50px rgb(0 0 0 / 0.24)',
                'dropdown' => '0 24px 60px rgb(0 0 0 / 0.42)',
                'hero' => '0 30px 90px rgb(255 183 3 / 0.16)',
            ],
            'spacing' => [
                'sectionY' => '3rem',
                'containerX' => '1rem',
                'cardPadding' => '1rem',
                'gridGap' => '1.25rem',
            ],
            'buttons' => [
                'radius' => '4px',
                'weight' => 900,
                'uppercase' => true,
                'shadow' => 'none',
            ],
            'badges' => [
                'radius' => '4px',
                'uppercase' => true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultLayoutConfig(): array
    {
        return [
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultComponentConfig(): array
    {
        return [
            'showTopBar' => true,
            'showSearch' => true,
            'stickyHeader' => true,
            'showCategoryMenu' => true,
            'showBadges' => true,
            'showBrandInCard' => true,
            'showSkuInCard' => true,
            'showQuickBuy' => true,
            'showProductShortSpecs' => true,
            'showWishlist' => true,
            'showQuickView' => true,
            'showRating' => true,
            'showSaleBadges' => true,
            'showAddToCart' => true,
            'heroOverlay' => true,
            'cardImageRatio' => '4/3',
            'cardAlignment' => 'center',
            'uppercaseNav' => true,
            'addToCartStyle' => 'black_solid',
        ];
    }

    /**
     * @param  array<string, mixed>  $tokens
     * @return array<string, mixed>
     */
    public static function normalizeTokens(array $tokens): array
    {
        return array_replace_recursive(self::defaultTokens(), $tokens);
    }

    /**
     * @param  array<string, mixed>  $layoutConfig
     * @return array<string, mixed>
     */
    public static function normalizeLayoutConfig(array $layoutConfig): array
    {
        return array_replace(self::defaultLayoutConfig(), $layoutConfig);
    }

    /**
     * @param  array<string, mixed>  $componentConfig
     * @return array<string, mixed>
     */
    public static function normalizeComponentConfig(array $componentConfig): array
    {
        return array_replace(self::defaultComponentConfig(), $componentConfig);
    }

    /**
     * @param  array<string, mixed>  $tokens
     * @param  array<string, mixed>|null  $explicit
     * @return array<string, string>
     */
    public static function cssVariables(array $tokens, ?array $explicit = null): array
    {
        $tokens = self::normalizeTokens($tokens);

        $map = [
            '--color-primary' => $tokens['colors']['primary'],
            '--color-primary-contrast' => $tokens['colors']['primaryContrast'],
            '--color-secondary' => $tokens['colors']['secondary'],
            '--color-accent' => $tokens['colors']['accent'],
            '--color-background' => $tokens['colors']['background'],
            '--color-surface' => $tokens['colors']['surface'],
            '--color-surface-alt' => $tokens['colors']['surfaceAlt'],
            '--color-text' => $tokens['colors']['text'],
            '--color-muted-text' => $tokens['colors']['mutedText'],
            '--color-border' => $tokens['colors']['border'],
            '--color-success' => $tokens['colors']['success'],
            '--color-warning' => $tokens['colors']['warning'],
            '--color-danger' => $tokens['colors']['danger'],
            '--font-body' => $tokens['typography']['fontFamily'],
            '--font-heading' => $tokens['typography']['headingFamily'],
            '--font-base-size' => (string) $tokens['typography']['baseSize'],
            '--font-heading-weight' => (string) $tokens['typography']['headingWeight'],
            '--font-body-weight' => (string) $tokens['typography']['bodyWeight'],
            '--letter-spacing' => (string) $tokens['typography']['letterSpacing'],
            '--radius-sm' => $tokens['radius']['sm'],
            '--radius-md' => $tokens['radius']['md'],
            '--radius-lg' => $tokens['radius']['lg'],
            '--radius-xl' => $tokens['radius']['xl'],
            '--radius-full' => $tokens['radius']['full'],
            '--shadow-card' => $tokens['shadows']['card'],
            '--shadow-dropdown' => $tokens['shadows']['dropdown'],
            '--shadow-hero' => $tokens['shadows']['hero'],
            '--section-y' => $tokens['spacing']['sectionY'],
            '--container-x' => $tokens['spacing']['containerX'],
            '--card-padding' => $tokens['spacing']['cardPadding'],
            '--grid-gap' => $tokens['spacing']['gridGap'],
            '--button-radius' => $tokens['buttons']['radius'],
            '--button-weight' => (string) $tokens['buttons']['weight'],
            '--button-shadow' => $tokens['buttons']['shadow'],
            '--badge-radius' => $tokens['badges']['radius'],
        ];

        foreach (($explicit ?? []) as $key => $value) {
            $property = str_starts_with((string) $key, '--') ? (string) $key : '--'.(string) $key;

            if (preg_match('/^--[a-z0-9-]+$/', $property) === 1 && is_scalar($value)) {
                $map[$property] = (string) $value;
            }
        }

        return $map;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function systemThemes(): array
    {
        $base = [
            'type' => 'system',
            'status' => 'published',
            'source' => 'system_seed',
            'generated_by_ai' => false,
            'custom_css' => null,
            'preview_image' => null,
        ];

        return [
            array_replace_recursive($base, [
                'name' => 'Alta Trade Dark Automotive',
                'slug' => 'alta-trade-dark-automotive',
                'description' => 'Поточний графітово-жовтий automotive стиль Alta-Trade.',
                'style_family' => 'dark automotive commerce',
                'tokens' => self::defaultTokens(),
                'layout_config' => self::defaultLayoutConfig(),
                'component_config' => self::defaultComponentConfig(),
            ]),
            array_replace_recursive($base, [
                'name' => 'Clean Marketplace',
                'slug' => 'clean-marketplace',
                'description' => 'Світла marketplace-тема з великим пошуком, чистими картками і акцентом на каталозі.',
                'style_family' => 'clean light marketplace',
                'tokens' => [
                    'colors' => [
                        'primary' => '#2563eb',
                        'primaryContrast' => '#ffffff',
                        'secondary' => '#0891b2',
                        'accent' => '#f59e0b',
                        'background' => '#f7f8fb',
                        'surface' => '#ffffff',
                        'surfaceAlt' => '#eef2f7',
                        'text' => '#111827',
                        'mutedText' => '#64748b',
                        'border' => '#d8dee8',
                        'success' => '#16a34a',
                        'warning' => '#f59e0b',
                        'danger' => '#dc2626',
                    ],
                    'radius' => ['md' => '8px', 'lg' => '8px', 'xl' => '10px'],
                    'shadows' => [
                        'card' => '0 12px 32px rgb(15 23 42 / 0.10)',
                        'dropdown' => '0 20px 48px rgb(15 23 42 / 0.16)',
                        'hero' => '0 24px 70px rgb(37 99 235 / 0.13)',
                    ],
                    'buttons' => ['radius' => '6px', 'uppercase' => false],
                    'badges' => ['radius' => '999px', 'uppercase' => false],
                ],
                'layout_config' => [
                    'headerVariant' => 'marketplace',
                    'topBarVariant' => 'slim',
                    'heroVariant' => 'category_focus',
                    'categoryGridVariant' => 'tiles',
                    'productCardVariant' => 'marketplace',
                    'productPageVariant' => 'marketplace',
                    'footerVariant' => 'columns',
                    'containerWidth' => 'wide',
                    'density' => 'compact',
                    'mobileNavVariant' => 'drawer',
                ],
                'component_config' => [
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
                    'cardImageRatio' => 'square',
                ],
            ]),
            array_replace_recursive($base, [
                'name' => 'Premium Parts',
                'slug' => 'premium-parts',
                'description' => 'Темна преміальна тема з великими фото, стриманим акцентом і більшою кількістю повітря.',
                'style_family' => 'premium dark parts',
                'tokens' => [
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
                        'success' => '#22c55e',
                        'warning' => '#d6b25e',
                        'danger' => '#fb7185',
                    ],
                    'radius' => ['sm' => '6px', 'md' => '8px', 'lg' => '8px', 'xl' => '12px'],
                    'spacing' => ['sectionY' => '4.25rem', 'cardPadding' => '1.25rem', 'gridGap' => '1.5rem'],
                    'shadows' => [
                        'card' => '0 24px 70px rgb(0 0 0 / 0.36)',
                        'dropdown' => '0 30px 80px rgb(0 0 0 / 0.48)',
                        'hero' => '0 34px 100px rgb(214 178 94 / 0.12)',
                    ],
                    'buttons' => ['radius' => '8px', 'uppercase' => false, 'shadow' => '0 12px 36px rgb(214 178 94 / 0.18)'],
                ],
                'layout_config' => [
                    'headerVariant' => 'bold',
                    'topBarVariant' => 'contact',
                    'heroVariant' => 'split',
                    'categoryGridVariant' => 'editorial',
                    'productCardVariant' => 'premium',
                    'productPageVariant' => 'premium',
                    'footerVariant' => 'corporate',
                    'containerWidth' => 'normal',
                    'density' => 'spacious',
                    'mobileNavVariant' => 'drawer',
                ],
                'component_config' => [
                    'showTopBar' => true,
                    'showSearch' => true,
                    'stickyHeader' => true,
                    'showCategoryMenu' => true,
                    'showBadges' => true,
                    'showBrandInCard' => true,
                    'showSkuInCard' => true,
                    'showQuickBuy' => true,
                    'showProductShortSpecs' => true,
                    'heroOverlay' => true,
                    'cardImageRatio' => '16/9',
                ],
            ]),
            array_replace_recursive($base, [
                'name' => 'Discount Auto',
                'slug' => 'discount-auto',
                'description' => 'Промо-тема з різкими CTA, контрастними бейджами і щільною товарною сіткою.',
                'style_family' => 'bright promo discount',
                'tokens' => [
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
                        'success' => '#84cc16',
                        'warning' => '#facc15',
                        'danger' => '#ef4444',
                    ],
                    'radius' => ['sm' => '2px', 'md' => '4px', 'lg' => '4px', 'xl' => '6px'],
                    'spacing' => ['sectionY' => '2.5rem', 'cardPadding' => '0.875rem', 'gridGap' => '1rem'],
                    'shadows' => [
                        'card' => '0 16px 40px rgb(239 68 68 / 0.18)',
                        'dropdown' => '0 24px 50px rgb(0 0 0 / 0.44)',
                        'hero' => '0 28px 80px rgb(250 204 21 / 0.18)',
                    ],
                    'buttons' => ['radius' => '3px', 'uppercase' => true, 'shadow' => '0 10px 28px rgb(250 204 21 / 0.18)'],
                    'badges' => ['radius' => '3px', 'uppercase' => true],
                ],
                'layout_config' => [
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
                ],
                'component_config' => [
                    'showTopBar' => true,
                    'showSearch' => true,
                    'stickyHeader' => true,
                    'showCategoryMenu' => true,
                    'showBadges' => true,
                    'showBrandInCard' => false,
                    'showSkuInCard' => true,
                    'showQuickBuy' => true,
                    'showProductShortSpecs' => false,
                    'heroOverlay' => true,
                    'cardImageRatio' => '4/3',
                ],
            ]),
        ];
    }
}
