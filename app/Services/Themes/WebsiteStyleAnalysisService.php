<?php

namespace App\Services\Themes;

use App\Services\Ai\AiClient;

class WebsiteStyleAnalysisService
{
    public function __construct(private readonly AiClient $aiClient)
    {
        //
    }

    /**
     * @param  array<string, mixed>  $capture
     * @return array<string, mixed>
     */
    public function analyze(array $capture): array
    {
        if (! $this->aiClient->isEnabled()) {
            return $this->heuristicAnalysis($capture, 'heuristic_ai_disabled');
        }

        return $this->aiClient->generateStructuredWithSchema(
            'You analyze ecommerce visual style for a tokenized theme engine. Do not copy HTML, CSS, text, logos, images, brand names, or layout exactly. Return stylistic traits only.',
            'Analyze this captured ecommerce style summary and return JSON. Use only high-level style signals, never copied assets or exact text: '.json_encode($capture, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $this->analysisSchema(),
            'theme_style_analysis',
        );
    }

    /**
     * @param  array<string, mixed>  $capture
     * @return array<string, mixed>
     */
    public function heuristicAnalysis(array $capture, string $method = 'heuristic'): array
    {
        $background = (string) ($capture['background_tendency'] ?? 'light_or_neutral');
        $density = (string) ($capture['layout_density'] ?? 'normal');
        $components = (array) ($capture['ecommerce_components_visible'] ?? []);
        $patterns = (array) ($capture['commerce_patterns'] ?? []);
        $platformHints = (array) ($patterns['platform_hints'] ?? []);
        $sectionMarkers = (array) ($patterns['section_markers'] ?? []);
        $isSectionedMarketplace = in_array('woocommerce', $platformHints, true) && count($sectionMarkers) >= 5;
        $sourceStyleTraits = array_values(array_filter([
            $isSectionedMarketplace ? 'sectioned_light_marketplace_homepage' : null,
            ($patterns['tax_excluded_prices'] ?? false) ? 'tax_excluded_price_microcopy' : null,
            ($patterns['wishlist_count'] ?? 0) > 0 ? 'wishlist_actions' : null,
            ($patterns['quick_view_count'] ?? 0) > 0 ? 'quick_view_actions' : null,
            ($patterns['sale_badges'] ?? false) ? 'percentage_sale_badges' : null,
            count((array) ($patterns['catalog_taxonomy_hints'] ?? [])) >= 3 ? 'car_care_category_taxonomy' : null,
            in_array('reviews', $sectionMarkers, true) ? 'reviews_after_product_rails' : null,
            in_array('newsletter', $sectionMarkers, true) ? 'newsletter_footer_cta' : null,
        ]));

        return [
            'analysis_method' => $method,
            'style_family' => $isSectionedMarketplace
                ? 'light marketplace compact catalog'
                : ($background === 'dark_or_high_contrast'
                ? 'high contrast ecommerce'
                : 'clean marketplace ecommerce'),
            'color_palette' => $isSectionedMarketplace
                ? ['#ffffff', '#f5b400', '#1f2933', '#f5f6f7', '#e4e7eb', '#ef4444']
                : array_values((array) ($capture['dominant_colors'] ?? ['#111827', '#f8fafc', '#f59e0b'])),
            'typography_mood' => $isSectionedMarketplace ? 'clean retail headings with compact product meta' : (in_array('promotion', $components, true) ? 'bold commercial' : 'neutral commercial'),
            'layout_density' => $isSectionedMarketplace ? 'compact' : $density,
            'header_pattern' => $isSectionedMarketplace ? 'white utility topbar with category navigation and search' : (string) ($capture['header_style'] ?? 'standard_header'),
            'product_card_pattern' => $isSectionedMarketplace ? 'minimal image-first marketplace cards with category/rating/price/tax/action stack' : (string) ($capture['product_card_style'] ?? 'generic_commerce_cards'),
            'category_pattern' => $isSectionedMarketplace ? 'horizontal catalog taxonomy and repeated product rails' : (in_array('catalog', $components, true) ? 'catalog-forward' : 'standard grid'),
            'button_style' => $isSectionedMarketplace ? 'compact yellow cart CTA with quiet secondary actions' : (in_array('promotion', $components, true) ? 'high contrast CTA' : 'clear commerce CTA'),
            'ecommerce_energy' => $isSectionedMarketplace ? 'sectioned_catalog' : (in_array('promotion', $components, true) ? 'promo' : 'balanced'),
            'mobile_style' => 'drawer navigation with searchable catalog',
            'visual_signature' => (string) ($capture['visual_signature'] ?? 'standard ecommerce storefront'),
            'section_rhythm' => $isSectionedMarketplace ? 'many full-width product rails separated by small editorial/service sections' : 'standard commerce sections',
            'product_meta_pattern' => $isSectionedMarketplace ? 'category links, rating row, price with tax note, add-to-cart and secondary micro-actions' : 'standard title, price and CTA',
            'source_style_traits' => $sourceStyleTraits,
            'do_not_copy_notes' => [
                'No logos, photos, copy, source CSS or exact HTML structure should be reused.',
                'Generate only Alta-Trade theme tokens and allowed layout/component variants.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function analysisSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'analysis_method' => ['type' => 'string'],
                'style_family' => ['type' => 'string'],
                'color_palette' => ['type' => 'array', 'items' => ['type' => 'string']],
                'typography_mood' => ['type' => 'string'],
                'layout_density' => ['type' => 'string'],
                'header_pattern' => ['type' => 'string'],
                'product_card_pattern' => ['type' => 'string'],
                'category_pattern' => ['type' => 'string'],
                'button_style' => ['type' => 'string'],
                'ecommerce_energy' => ['type' => 'string'],
                'mobile_style' => ['type' => 'string'],
                'visual_signature' => ['type' => 'string'],
                'section_rhythm' => ['type' => 'string'],
                'product_meta_pattern' => ['type' => 'string'],
                'source_style_traits' => ['type' => 'array', 'items' => ['type' => 'string']],
                'do_not_copy_notes' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => [
                'analysis_method',
                'style_family',
                'color_palette',
                'typography_mood',
                'layout_density',
                'header_pattern',
                'product_card_pattern',
                'category_pattern',
                'button_style',
                'ecommerce_energy',
                'mobile_style',
                'visual_signature',
                'section_rhythm',
                'product_meta_pattern',
                'source_style_traits',
                'do_not_copy_notes',
            ],
        ];
    }
}
