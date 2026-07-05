<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AiSetting;
use App\Models\StorefrontTheme;
use App\Models\ThemeGenerationRun;
use App\Services\Ai\AiClient;
use App\Services\Themes\AiThemeGenerationService;
use App\Services\Themes\StyleFingerprintExtractor;
use App\Services\Themes\ThemeGuardrailService;
use App\Services\Themes\ThemePayloadValidator;
use App\Services\Themes\ThemePresetMapper;
use App\Services\Themes\ThemeResolver;
use App\Services\Themes\ThemeSchema;
use App\Services\Themes\WebsiteStyleAnalysisService;
use App\Services\Themes\WebsiteStyleClassifier;
use App\Services\Themes\WebsiteStyleCaptureService;
use Database\Seeders\StorefrontThemeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use ReflectionMethod;
use Tests\Feature\Concerns\CreatesCommerceData;
use Tests\TestCase;

class ThemeEngineTest extends TestCase
{
    use CreatesCommerceData;
    use RefreshDatabase;

    public function test_system_themes_are_seeded_idempotently_with_default_active_theme(): void
    {
        $this->seed(StorefrontThemeSeeder::class);
        $this->seed(StorefrontThemeSeeder::class);

        $this->assertSame(4, StorefrontTheme::query()->where('type', StorefrontTheme::TYPE_SYSTEM)->count());
        $this->assertTrue(StorefrontTheme::query()->where('slug', 'alta-trade-dark-automotive')->where('is_active', true)->exists());
    }

    public function test_theme_resolver_uses_active_theme_and_falls_back_to_default(): void
    {
        $this->seed(StorefrontThemeSeeder::class);
        $theme = $this->createTheme(['slug' => 'custom-active']);
        $theme->activate();

        $this->assertSame($theme->slug, app(ThemeResolver::class)->getActiveTheme()->slug);

        StorefrontTheme::query()->update(['is_active' => false]);
        Cache::forget(StorefrontTheme::ACTIVE_CACHE_KEY);

        $this->assertSame('alta-trade-dark-automotive', app(ThemeResolver::class)->getActiveTheme()->slug);
    }

    public function test_theme_activation_deactivates_other_themes(): void
    {
        $first = $this->createTheme(['slug' => 'first-theme', 'is_active' => true]);
        $second = $this->createTheme(['slug' => 'second-theme', 'is_active' => false]);

        $second->activate();

        $this->assertFalse($first->refresh()->is_active);
        $this->assertTrue($second->refresh()->is_active);
        $this->assertSame(StorefrontTheme::STATUS_PUBLISHED, $second->status);
    }

    public function test_admin_or_manager_preview_does_not_activate_theme(): void
    {
        $this->createTheme(['slug' => 'active-theme', 'is_active' => true]);
        $preview = $this->createTheme([
            'name' => 'Preview Theme',
            'slug' => 'preview-theme',
            'status' => StorefrontTheme::STATUS_DRAFT,
            'is_active' => false,
        ]);

        $this->actingAs($this->createUserWithRole(UserRole::Manager))
            ->get(route('home', ['theme' => $preview->slug]))
            ->assertOk()
            ->assertSee('Preview theme: Preview Theme')
            ->assertSee('data-theme="preview-theme"', false);

        $this->assertFalse($preview->refresh()->is_active);
    }

    public function test_storefront_includes_active_theme_css_variables_and_variant_hooks(): void
    {
        $this->createProduct();
        $this->createTheme([
            'slug' => 'marketplace-blue',
            'is_active' => true,
            'tokens' => ThemeSchema::normalizeTokens([
                'colors' => ['primary' => '#123456'],
            ]),
            'layout_config' => ThemeSchema::normalizeLayoutConfig([
                'headerVariant' => 'marketplace',
                'productCardVariant' => 'premium',
            ]),
            'style_profile' => [
                'visual_mode' => 'light',
                'density' => 'compact',
                'card_style' => 'marketplace_card',
                'homepage_structure' => 'sectional',
            ],
            'selected_preset' => 'light_marketplace_compact',
        ]);

        $this->get(route('catalog'))
            ->assertOk()
            ->assertSee('--color-primary: #123456', false)
            ->assertSee('data-theme-visual-mode="light"', false)
            ->assertSee('data-theme-preset="light_marketplace_compact"', false)
            ->assertSee('storefront-header--marketplace', false)
            ->assertSee('product-card--premium', false);
    }

    public function test_theme_policy_allows_admin_activation_but_not_manager(): void
    {
        $theme = $this->createTheme();

        $this->assertTrue($this->createUserWithRole(UserRole::Admin)->can('activate', $theme));
        $this->assertFalse($this->createUserWithRole(UserRole::Manager)->can('activate', $theme));
        $this->assertFalse($this->createUserWithRole(UserRole::ContentManager)->can('viewAny', StorefrontTheme::class));
    }

    public function test_admin_can_access_theme_resource_and_ai_theme_studio_only_for_admin(): void
    {
        $admin = $this->createUserWithRole(UserRole::Admin);

        $this->actingAs($admin)
            ->get('/admin/storefront-themes')
            ->assertOk();

        $this->actingAs($admin)
            ->get('/admin/ai-theme-studio')
            ->assertOk();

        $managerResponse = $this->actingAs($this->createUserWithRole(UserRole::Manager))
            ->get('/admin/ai-theme-studio');

        $this->assertContains($managerResponse->getStatusCode(), [302, 403]);
    }

    public function test_theme_payload_validator_blocks_unsafe_payloads(): void
    {
        $validator = app(ThemePayloadValidator::class);
        $payload = $this->validThemePayload();

        $this->assertIsArray($validator->validate($payload));

        $this->expectException(InvalidArgumentException::class);
        $validator->validate(array_replace_recursive($payload, [
            'layout_config' => ['headerVariant' => 'clone_this_header'],
        ]));
    }

    public function test_theme_payload_validator_rejects_scripts_imports_and_remote_images(): void
    {
        $validator = app(ThemePayloadValidator::class);

        foreach ([
            ['custom_css' => '<script>alert(1)</script>'],
            ['custom_css' => '@import url("https://example.com/a.css");'],
            ['tokens' => ['colors' => ['primary' => 'https://example.com/logo.png']]],
        ] as $override) {
            try {
                $validator->validate(array_replace_recursive($this->validThemePayload(), $override));
                $this->fail('Unsafe theme payload was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_theme_payload_validator_rejects_source_domain_fragments_in_theme_name(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ThemePayloadValidator::class)->validate(array_replace_recursive($this->validThemePayload(), [
            'name' => 'Neutral Origin clone',
        ]), 'https://neutral-origin.example.com');
    }

    public function test_custom_css_is_sanitized_on_theme_model(): void
    {
        $theme = $this->createTheme([
            'custom_css' => '.x { color: red; } <script>alert(1)</script>',
        ]);

        $this->assertSame('.x { color: red; }', $theme->custom_css);
        $this->assertStringNotContainsString('<script', $theme->custom_css);
    }

    public function test_style_classifier_detects_generic_light_compact_marketplace_profile(): void
    {
        $profile = app(WebsiteStyleClassifier::class)->classify($this->lightMarketplaceCapture(), [
            'style_family' => 'clean marketplace ecommerce',
            'color_palette' => ['#ffffff', '#f5b400', '#1f2933'],
            'layout_density' => 'compact',
            'header_pattern' => 'white utility topbar with category navigation and search',
            'product_card_pattern' => 'minimal image-first marketplace cards with category/rating/price/tax/action stack',
            'button_style' => 'compact yellow cart CTA',
            'ecommerce_energy' => 'sectioned_catalog',
            'section_rhythm' => 'many full-width product rails separated by service sections',
        ]);

        $this->assertContains($profile['ecommerce_type'], ['marketplace', 'automotive_parts']);
        $this->assertSame('light', $profile['visual_mode']);
        $this->assertSame('compact', $profile['density']);
        $this->assertSame('marketplace_card', $profile['card_style']);
        $this->assertSame('yellow_cta', $profile['cta_style']);
        $this->assertSame('sectional', $profile['homepage_structure']);
        $this->assertSame('marketplace_search', $profile['header_style']);
        $this->assertSame('sale_heavy', $profile['badge_style']);
        $this->assertGreaterThanOrEqual(0.75, $profile['confidence']);
        $this->assertStringNotContainsString('neutral-origin', json_encode($profile));
    }

    public function test_style_fingerprint_extractor_keeps_business_domain_separate_from_visual_style(): void
    {
        $fingerprint = app(StyleFingerprintExtractor::class)->extract([
            'source_url' => 'https://forsage-1360.com.ua/',
            'dominant_colors' => ['#ffffff', '#111111', '#e53935'],
            'button_colors' => ['#111111'],
            'background_tendency' => 'light_or_neutral',
            'layout_density' => 'airy',
            'header_style' => 'logo_left_center_nav_icons_right',
            'product_card_style' => 'woocommerce_image_first_cards_with_meta_actions',
            'ecommerce_components_visible' => ['search', 'cart', 'wishlist', 'quick_view', 'rating', 'promotion'],
            'commerce_patterns' => [
                'section_markers' => ['new_arrivals', 'discounts', 'bestsellers'],
                'sale_badges' => true,
                'product_action_count' => 18,
            ],
        ], [
            'style_family' => 'clean woocommerce boutique catalog',
            'header_pattern' => 'logo left centered nav with icons right',
            'product_card_pattern' => 'image first cards on white background',
            'button_style' => 'black solid add to cart',
            'ecommerce_energy' => 'product_sections',
            'section_rhythm' => 'product sections with airy spacing',
            'visual_signature' => 'clean boutique catalog with white background',
        ]);

        $this->assertSame('auto_goods', $fingerprint['business_profile']['domain']);
        $this->assertSame('light', $fingerprint['style_fingerprint']['visual_mode']);
        $this->assertSame('white', $fingerprint['style_fingerprint']['background_system']);
        $this->assertSame('black_solid', $fingerprint['style_fingerprint']['primary_cta_style']);
        $this->assertSame('borderless_catalog', $fingerprint['style_fingerprint']['product_card_style']);
        $this->assertSame('logo_left_center_nav_icons_right', $fingerprint['style_fingerprint']['header_style']);
        $this->assertSame('product_sections', $fingerprint['style_fingerprint']['section_rhythm']);
    }

    public function test_theme_preset_mapper_maps_generic_profiles_to_presets(): void
    {
        $mapper = app(ThemePresetMapper::class);

        $light = $mapper->mapStyleProfileToThemeDefaults([
            'ecommerce_type' => 'marketplace',
            'visual_mode' => 'light',
            'density' => 'compact',
            'card_style' => 'marketplace_card',
            'cta_style' => 'yellow_cta',
            'homepage_structure' => 'sectional',
            'header_style' => 'marketplace_search',
            'badge_style' => 'sale_heavy',
            'confidence' => 0.9,
            'evidence' => [],
        ]);

        $boutique = $mapper->mapStyleProfileToThemeDefaults([
            'business_profile' => ['domain' => 'auto_goods'],
            'style_fingerprint' => [
                'visual_mode' => 'light',
                'background_system' => 'white',
                'primary_cta_style' => 'black_solid',
                'product_card_style' => 'borderless_catalog',
                'header_style' => 'logo_left_center_nav_icons_right',
                'section_rhythm' => 'product_sections',
                'spacing_density' => 'airy',
                'mood' => 'clean_woocommerce_boutique',
            ],
            'confidence' => 0.92,
            'evidence' => [],
        ]);

        $dark = $mapper->mapStyleProfileToThemeDefaults([
            'ecommerce_type' => 'automotive_parts',
            'visual_mode' => 'dark',
            'density' => 'normal',
            'card_style' => 'detailed_grid',
            'cta_style' => 'yellow_cta',
            'homepage_structure' => 'hero_focused',
            'header_style' => 'automotive_bold',
            'badge_style' => 'technical',
            'confidence' => 0.85,
            'evidence' => [],
        ]);

        $premium = $mapper->mapStyleProfileToThemeDefaults([
            'ecommerce_type' => 'premium_catalog',
            'visual_mode' => 'dark',
            'density' => 'spacious',
            'card_style' => 'premium_card',
            'cta_style' => 'neutral_cta',
            'homepage_structure' => 'hero_focused',
            'header_style' => 'centered_brand',
            'badge_style' => 'minimal',
            'confidence' => 0.85,
            'evidence' => [],
        ]);

        $this->assertSame('light_marketplace_compact', $light['selected_preset']);
        $this->assertSame('light_woocommerce_boutique', $boutique['selected_preset']);
        $this->assertSame('dark_automotive_bold', $dark['selected_preset']);
        $this->assertSame('premium_spacious_catalog', $premium['selected_preset']);
    }

    public function test_theme_guardrails_enforce_style_lock_for_light_boutique_theme(): void
    {
        $payload = array_replace_recursive($this->validThemePayload(), [
            'tokens' => [
                'colors' => [
                    'primary' => '#2563eb',
                    'accent' => '#2563eb',
                    'background' => '#101114',
                    'surface' => '#17191d',
                    'surfaceAlt' => '#202329',
                    'text' => '#f8fafc',
                ],
            ],
            'layout_config' => [
                'headerVariant' => 'marketplace',
                'heroVariant' => 'dark_promo',
                'productCardVariant' => 'dark',
            ],
            'component_config' => ['showQuickBuy' => false],
        ]);

        $guarded = app(ThemeGuardrailService::class)->apply($payload, [
            'visual_mode' => 'light',
            'cta_style' => 'black_solid',
            'homepage_structure' => 'sectional',
            'card_style' => 'borderless_catalog',
            'header_style' => 'logo_left_center_nav_icons_right',
            'style_lock' => [
                'visual_mode' => 'light',
                'primary_cta_style' => 'black_solid',
                'background_system' => 'white',
                'product_card_style' => 'borderless_catalog',
                'header_style' => 'logo_left_center_nav_icons_right',
                'section_rhythm' => 'product_sections',
            ],
            'confidence' => 0.9,
        ]);

        $this->assertSame('#ffffff', $guarded['tokens']['colors']['background']);
        $this->assertSame('#000000', $guarded['tokens']['colors']['primary']);
        $this->assertSame('#ffffff', $guarded['tokens']['colors']['primaryContrast']);
        $this->assertSame('logo_left_center_nav_icons_right', $guarded['layout_config']['headerVariant']);
        $this->assertSame('none', $guarded['layout_config']['heroVariant']);
        $this->assertSame('light_woocommerce_boutique', $guarded['layout_config']['productCardVariant']);
        $this->assertTrue($guarded['component_config']['showQuickBuy']);
        $this->assertTrue($guarded['component_config']['showAddToCart']);
    }

    public function test_theme_guardrails_correct_visual_mode_and_contrast(): void
    {
        $payload = array_replace_recursive($this->validThemePayload(), [
            'tokens' => [
                'colors' => [
                    'primary' => '#2563eb',
                    'accent' => '#2563eb',
                    'background' => '#101114',
                    'surface' => '#17191d',
                    'surfaceAlt' => '#202329',
                    'text' => '#f8fafc',
                    'mutedText' => '#e5e7eb',
                ],
            ],
            'layout_config' => [
                'density' => 'spacious',
                'productCardVariant' => 'premium',
            ],
            'component_config' => [
                'showBadges' => false,
                'showQuickBuy' => false,
                'showProductShortSpecs' => true,
            ],
            'custom_css' => '.x{background:url(https://example.com/a.png)}<script>alert(1)</script>',
        ]);

        $guarded = app(ThemeGuardrailService::class)->apply($payload, [
            'visual_mode' => 'light',
            'density' => 'compact',
            'card_style' => 'marketplace_card',
            'cta_style' => 'yellow_cta',
            'homepage_structure' => 'sectional',
            'badge_style' => 'sale_heavy',
            'confidence' => 0.9,
        ]);

        $this->assertSame('#ffffff', $guarded['tokens']['colors']['background']);
        $this->assertSame('#1f2933', $guarded['tokens']['colors']['text']);
        $this->assertSame('#f5b400', $guarded['tokens']['colors']['primary']);
        $this->assertSame('compact', $guarded['layout_config']['density']);
        $this->assertContains($guarded['layout_config']['productCardVariant'], ['compact', 'marketplace']);
        $this->assertTrue($guarded['component_config']['showBadges']);
        $this->assertTrue($guarded['component_config']['showQuickBuy']);
        $this->assertFalse($guarded['component_config']['showProductShortSpecs']);
        $this->assertStringNotContainsString('https://', (string) $guarded['custom_css']);
        $this->assertStringNotContainsString('<script', (string) $guarded['custom_css']);
        $this->assertContains('light_background_enforced', $guarded['guardrails_applied']);
    }

    public function test_ai_theme_generation_creates_draft_theme_without_real_network(): void
    {
        config(['ai.enabled' => false, 'ai.openai.api_key' => null]);
        AiSetting::getActive()->forceFill([
            'enabled' => false,
            'encrypted_api_key' => null,
        ])->save();

        $this->mock(WebsiteStyleCaptureService::class, function ($mock): void {
            $mock->shouldReceive('capture')->once()->andReturn([
                'source_url' => 'https://shop.example.com',
                'title' => 'Example Shop',
                'dominant_colors' => ['#111827', '#f59e0b', '#ffffff'],
                'background_tendency' => 'dark_or_high_contrast',
                'layout_density' => 'compact',
                'header_style' => 'marketplace_catalog',
                'product_card_style' => 'grid_with_price_cta',
                'ecommerce_components_visible' => ['search', 'cart', 'catalog', 'promotion'],
            ]);
        });

        $run = app(AiThemeGenerationService::class)->generateFromUrl(
            'https://shop.example.com',
            $this->createUserWithRole(UserRole::Admin),
            [
                'theme_name' => 'Original Promo Draft',
                'base_layout' => 'promo',
                'avoid_dark_theme' => false,
            ],
        );

        $this->assertSame(ThemeGenerationRun::STATUS_COMPLETED, $run->status);
        $theme = $run->themes()->first();
        $this->assertNotNull($theme);
        $this->assertSame(StorefrontTheme::STATUS_DRAFT, $theme->status);
        $this->assertFalse($theme->is_active);
        $this->assertStringNotContainsString('https://', json_encode($theme->tokens));
    }

    public function test_ai_theme_generation_preserves_generic_light_marketplace_profile(): void
    {
        config(['ai.enabled' => false, 'ai.openai.api_key' => null]);
        AiSetting::getActive()->forceFill([
            'enabled' => false,
            'encrypted_api_key' => null,
        ])->save();

        $this->mock(WebsiteStyleCaptureService::class, function ($mock): void {
            $mock->shouldReceive('capture')->once()->andReturn([
                'source_url' => 'https://neutral-origin.example.com',
                'title' => 'Source storefront',
                'dominant_colors' => ['#ffffff', '#f5b400', '#1f2933'],
                'button_colors' => ['#f5b400'],
                'background_tendency' => 'light_or_neutral',
                'layout_density' => 'compact',
                'header_style' => 'marketplace_catalog',
                'product_card_style' => 'woocommerce_image_first_cards_with_meta_actions',
                'ecommerce_components_visible' => ['search', 'cart', 'catalog', 'promotion'],
                'commerce_patterns' => [
                    'platform_hints' => ['woocommerce', 'avada_fusion'],
                    'product_action_count' => 42,
                    'quick_view_count' => 28,
                    'wishlist_count' => 28,
                    'tax_excluded_prices' => true,
                    'uah_currency' => true,
                    'sale_badges' => true,
                    'section_markers' => ['new_arrivals', 'discounts', 'how_to_order', 'how_to_pay', 'how_to_receive', 'brands', 'bestsellers', 'reviews', 'newsletter'],
                    'catalog_taxonomy_hints' => ['exterior_care', 'interior_care', 'oils', 'technical_fluids', 'accessories'],
                ],
                'visual_signature' => 'light sectioned marketplace storefront with compact product rails',
            ]);
        });

        $run = app(AiThemeGenerationService::class)->generateFromUrl(
            'https://neutral-origin.example.com',
            $this->createUserWithRole(UserRole::Admin),
            [
                'theme_name' => 'Generic Marketplace Rhythm',
                'base_layout' => 'marketplace',
                'avoid_dark_theme' => false,
            ],
        );

        $theme = $run->themes()->firstOrFail();

        $this->assertSame(ThemeGenerationRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('light marketplace compact catalog', $theme->style_family);
        $this->assertSame('light_marketplace_compact', $theme->selected_preset);
        $this->assertSame('light', $theme->style_profile['visual_mode']);
        $this->assertSame('compact', $theme->style_profile['density']);
        $this->assertSame('yellow_cta', $theme->style_profile['cta_style']);
        $this->assertSame('sectional', $theme->style_profile['homepage_structure']);
        $this->assertSame('#f5b400', $theme->tokens['colors']['primary']);
        $this->assertSame('#ffffff', $theme->tokens['colors']['background']);
        $this->assertSame('minimal', $theme->layout_config['heroVariant']);
        $this->assertSame('compact', $theme->layout_config['productCardVariant']);
        $this->assertSame('contain', $theme->component_config['cardImageRatio']);
        $this->assertNull($theme->custom_css);
        $this->assertContains('sectioned_light_marketplace_homepage', $run->analysis_payload['source_style_traits']);
    }

    public function test_openai_theme_generation_is_guardrailed_for_generic_light_marketplace_source(): void
    {
        config(['ai.enabled' => true, 'ai.openai.api_key' => 'test-key']);
        AiSetting::getActive()->forceFill([
            'enabled' => true,
        ])->save();

        $this->mock(WebsiteStyleCaptureService::class, function ($mock): void {
            $mock->shouldReceive('capture')->once()->andReturn([
                'source_url' => 'https://neutral-origin.example.com',
                'title' => 'Source storefront',
                'dominant_colors' => ['#ffffff', '#f5b400', '#1f2933'],
                'button_colors' => ['#f5b400'],
                'background_tendency' => 'light_or_neutral',
                'layout_density' => 'compact',
                'ecommerce_components_visible' => ['search', 'cart', 'catalog', 'promotion'],
                'commerce_patterns' => [
                    'platform_hints' => ['woocommerce', 'avada_fusion'],
                    'product_action_count' => 42,
                    'quick_view_count' => 28,
                    'wishlist_count' => 28,
                    'tax_excluded_prices' => true,
                    'uah_currency' => true,
                    'sale_badges' => true,
                    'section_markers' => ['new_arrivals', 'discounts', 'how_to_order', 'how_to_pay', 'how_to_receive', 'brands', 'bestsellers', 'reviews', 'newsletter'],
                    'catalog_taxonomy_hints' => ['exterior_care', 'interior_care', 'oils', 'technical_fluids', 'accessories'],
                ],
            ]);
        });

        $this->mock(WebsiteStyleAnalysisService::class, function ($mock): void {
            $mock->shouldReceive('analyze')->once()->andReturn([
                'analysis_method' => 'ai',
                'style_family' => 'clean marketplace ecommerce',
                'color_palette' => ['#2563eb', '#ffffff', '#111827'],
                'typography_mood' => 'neutral commercial',
                'layout_density' => 'normal',
                'header_pattern' => 'standard_header',
                'product_card_pattern' => 'generic_commerce_cards',
                'category_pattern' => 'standard grid',
                'button_style' => 'clear commerce CTA',
                'ecommerce_energy' => 'balanced',
                'mobile_style' => 'drawer navigation with searchable catalog',
                'visual_signature' => 'standard ecommerce storefront',
                'section_rhythm' => 'standard commerce sections',
                'product_meta_pattern' => 'standard title, price and CTA',
                'source_style_traits' => [],
                'do_not_copy_notes' => [],
            ]);
        });

        $this->bindFakeThemeAiClient(array_replace_recursive($this->validThemePayload(), [
            'name' => 'AI Generic Draft',
            'style_family' => 'generic dark market',
            'tokens' => ThemeSchema::normalizeTokens([
                'colors' => [
                    'primary' => '#2563eb',
                    'background' => '#101114',
                    'surface' => '#17191d',
                    'text' => '#f8fafc',
                ],
            ]),
            'layout_config' => ThemeSchema::normalizeLayoutConfig([
                'heroVariant' => 'dark_promo',
                'productCardVariant' => 'premium',
                'density' => 'spacious',
            ]),
            'component_config' => ThemeSchema::normalizeComponentConfig([
                'cardImageRatio' => '16/9',
                'showProductShortSpecs' => true,
            ]),
            'custom_css' => '.storefront-body { background: #101114; }',
        ]));

        $run = app(AiThemeGenerationService::class)->generateFromUrl(
            'https://neutral-origin.example.com',
            $this->createUserWithRole(UserRole::Admin),
            [
                'theme_name' => 'OpenAI Marketplace Rhythm',
                'base_layout' => 'marketplace',
                'avoid_dark_theme' => false,
            ],
        );

        $theme = $run->themes()->firstOrFail();

        $this->assertSame(ThemeGenerationRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('light_marketplace_compact', $theme->selected_preset);
        $this->assertSame('light', $theme->style_profile['visual_mode']);
        $this->assertSame('#f5b400', $theme->tokens['colors']['primary']);
        $this->assertSame('#ffffff', $theme->tokens['colors']['background']);
        $this->assertSame('minimal', $theme->layout_config['heroVariant']);
        $this->assertSame('compact', $theme->layout_config['productCardVariant']);
        $this->assertSame('contain', $theme->component_config['cardImageRatio']);
        $this->assertFalse($theme->component_config['showProductShortSpecs']);
        $this->assertStringContainsString('.storefront-body { background: #101114; }', (string) $theme->custom_css);
        $this->assertStringNotContainsString('.product-card--compact', (string) $theme->custom_css);
        $this->assertContains('light_background_enforced', $theme->guardrails_applied);
    }

    public function test_private_source_urls_are_blocked(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(WebsiteStyleCaptureService::class)->assertPublicHttpUrl('http://localhost/catalog');
    }

    public function test_style_capture_ignores_invalid_hex_color_matches(): void
    {
        $method = new ReflectionMethod(WebsiteStyleCaptureService::class, 'extractColors');
        $colors = $method->invoke(app(WebsiteStyleCaptureService::class), '<style>.a{color:#ffff}.b{color:#abc}.c{color:#123456}</style>');

        $this->assertContains('#aabbcc', $colors);
        $this->assertContains('#123456', $colors);
        $this->assertNotContains('#ffff', $colors);
    }

    public function test_style_capture_detects_woocommerce_sectioned_storefront_patterns(): void
    {
        $html = 'woocommerce fusion- Наші новинки Знижки та акції Як замовити Як оплатити Як отримати Бренди Хіти продажів Відгуки споживачів ПІДПИСАТИСЬ Екстер’єр Інтер’єр Мастила Технічні рідини Аксесуари Без ПДВ ₴ Quick View Додати до списку бажань -15% '.str_repeat('Додати в кошик ', 24);
        $method = new ReflectionMethod(WebsiteStyleCaptureService::class, 'commercePatterns');
        $patterns = $method->invoke(app(WebsiteStyleCaptureService::class), $html);

        $this->assertContains('woocommerce', $patterns['platform_hints']);
        $this->assertContains('avada_fusion', $patterns['platform_hints']);
        $this->assertContains('new_arrivals', $patterns['section_markers']);
        $this->assertContains('technical_fluids', $patterns['catalog_taxonomy_hints']);
        $this->assertTrue($patterns['tax_excluded_prices']);
        $this->assertTrue($patterns['sale_badges']);
        $this->assertGreaterThan(20, $patterns['product_action_count']);
    }

    public function test_regenerate_from_source_updates_ai_draft_and_keeps_versions(): void
    {
        config(['ai.enabled' => false, 'ai.openai.api_key' => null]);
        AiSetting::getActive()->forceFill([
            'enabled' => false,
            'encrypted_api_key' => null,
        ])->save();

        $theme = $this->createTheme([
            'name' => 'Old AI Draft',
            'slug' => 'old-ai-draft',
            'type' => StorefrontTheme::TYPE_AI_GENERATED,
            'status' => StorefrontTheme::STATUS_DRAFT,
            'generated_by_ai' => true,
            'source_url' => 'https://neutral-origin.example.com',
            'tokens' => ThemeSchema::normalizeTokens([
                'colors' => ['background' => '#101114', 'text' => '#f8fafc'],
            ]),
        ]);
        $theme->createVersion('Initial old draft.');

        $this->mock(WebsiteStyleCaptureService::class, function ($mock): void {
            $mock->shouldReceive('capture')->once()->andReturn($this->lightMarketplaceCapture());
        });

        $run = app(AiThemeGenerationService::class)->regenerateTheme(
            $theme,
            $this->createUserWithRole(UserRole::Admin),
        );

        $theme = $theme->refresh();

        $this->assertSame(ThemeGenerationRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(StorefrontTheme::STATUS_DRAFT, $theme->status);
        $this->assertFalse($theme->is_active);
        $this->assertSame('light_marketplace_compact', $theme->selected_preset);
        $this->assertSame('light', $theme->style_profile['visual_mode']);
        $this->assertSame('#ffffff', $theme->tokens['colors']['background']);
        $this->assertGreaterThanOrEqual(3, $theme->versions()->count());
    }

    public function test_ai_theme_payload_schema_is_strict_for_openai(): void
    {
        $method = new ReflectionMethod(AiThemeGenerationService::class, 'themePayloadSchema');
        $schema = $method->invoke(app(AiThemeGenerationService::class));

        $this->assertSchemaDoesNotAllowAdditionalProperties($schema);
    }

    private function createTheme(array $attributes = []): StorefrontTheme
    {
        $tokens = $attributes['tokens'] ?? ThemeSchema::defaultTokens();
        $layoutConfig = $attributes['layout_config'] ?? ThemeSchema::defaultLayoutConfig();
        $componentConfig = $attributes['component_config'] ?? ThemeSchema::defaultComponentConfig();

        unset($attributes['tokens'], $attributes['layout_config'], $attributes['component_config']);

        return StorefrontTheme::create($attributes + [
            'name' => 'Test Theme',
            'slug' => 'test-theme',
            'description' => 'Theme for tests',
            'type' => StorefrontTheme::TYPE_CUSTOM,
            'status' => StorefrontTheme::STATUS_PUBLISHED,
            'is_active' => false,
            'tokens' => ThemeSchema::normalizeTokens($tokens),
            'layout_config' => ThemeSchema::normalizeLayoutConfig($layoutConfig),
            'component_config' => ThemeSchema::normalizeComponentConfig($componentConfig),
            'css_variables' => ThemeSchema::cssVariables($tokens),
            'generated_by_ai' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function lightMarketplaceCapture(): array
    {
        return [
            'source_url' => 'https://neutral-origin.example.com',
            'title' => 'Generic source storefront',
            'dominant_colors' => ['#ffffff', '#f5b400', '#1f2933'],
            'button_colors' => ['#f5b400'],
            'background_tendency' => 'light_or_neutral',
            'layout_density' => 'compact',
            'header_style' => 'marketplace_catalog',
            'product_card_style' => 'woocommerce_image_first_cards_with_meta_actions',
            'ecommerce_components_visible' => ['search', 'cart', 'catalog', 'promotion'],
            'commerce_patterns' => [
                'platform_hints' => ['woocommerce', 'avada_fusion'],
                'product_action_count' => 42,
                'quick_view_count' => 28,
                'wishlist_count' => 28,
                'tax_excluded_prices' => true,
                'uah_currency' => true,
                'sale_badges' => true,
                'section_markers' => ['new_arrivals', 'discounts', 'how_to_order', 'how_to_pay', 'how_to_receive', 'brands', 'bestsellers', 'reviews', 'newsletter'],
                'catalog_taxonomy_hints' => ['exterior_care', 'interior_care', 'oils', 'technical_fluids', 'accessories'],
            ],
            'visual_signature' => 'light sectioned marketplace storefront with compact product rails',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validThemePayload(): array
    {
        return [
            'name' => 'Valid Theme',
            'tokens' => ThemeSchema::defaultTokens(),
            'layout_config' => ThemeSchema::defaultLayoutConfig(),
            'component_config' => ThemeSchema::defaultComponentConfig(),
            'custom_css' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function bindFakeThemeAiClient(array $payload): void
    {
        app()->instance(AiClient::class, new class($payload) extends AiClient
        {
            /**
             * @param  array<string, mixed>  $payload
             */
            public function __construct(private readonly array $payload)
            {
                //
            }

            public function isEnabled(): bool
            {
                return true;
            }

            /**
             * @param  array<string, mixed>  $schema
             * @return array<string, mixed>
             */
            public function generateStructuredWithSchema(
                string $systemPrompt,
                string $userPrompt,
                array $schema,
                string $schemaName,
                bool $allowDisabled = false,
            ): array {
                return $this->payload;
            }
        });
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function assertSchemaDoesNotAllowAdditionalProperties(array $schema): void
    {
        if (($schema['type'] ?? null) === 'object') {
            $this->assertArrayHasKey('additionalProperties', $schema);
            $this->assertFalse($schema['additionalProperties']);
        }

        foreach ($schema as $value) {
            if (is_array($value)) {
                $this->assertSchemaDoesNotAllowAdditionalProperties($value);
            }
        }
    }
}
