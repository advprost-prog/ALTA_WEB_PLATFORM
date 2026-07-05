<?php

namespace App\Services\Themes;

use Illuminate\Support\Str;

class StyleFingerprintExtractor
{
    /**
     * @param  array<string, mixed>  $capturePayload
     * @param  array<string, mixed>  $analysisPayload
     * @return array<string, mixed>
     */
    public function extract(array $capturePayload, array $analysisPayload): array
    {
        $analysisText = Str::lower(implode(' ', array_filter([
            $analysisPayload['style_family'] ?? null,
            $analysisPayload['header_pattern'] ?? null,
            $analysisPayload['product_card_pattern'] ?? null,
            $analysisPayload['button_style'] ?? null,
            $analysisPayload['ecommerce_energy'] ?? null,
            $analysisPayload['section_rhythm'] ?? null,
            $analysisPayload['visual_signature'] ?? null,
            $capturePayload['title'] ?? null,
        ], is_scalar(...))));

        $businessProfile = $this->businessProfile($capturePayload, $analysisText);
        $visualFingerprint = $this->visualFingerprint($capturePayload, $analysisPayload, $analysisText);

        return [
            'business_profile' => $businessProfile,
            'style_fingerprint' => $visualFingerprint,
            'style_lock' => [
                'visual_mode' => $visualFingerprint['visual_mode'],
                'primary_cta_style' => $visualFingerprint['primary_cta_style'],
                'background_system' => $visualFingerprint['background_system'],
                'product_card_style' => $visualFingerprint['product_card_style'],
                'header_style' => $visualFingerprint['header_style'],
                'section_rhythm' => $visualFingerprint['section_rhythm'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $capturePayload
     * @param  string  $analysisText
     * @return array<string, string>
     */
    private function businessProfile(array $capturePayload, string $analysisText): array
    {
        $domain = 'universal';
        $productType = 'general';

        $sourceSignals = Str::lower((string) ($capturePayload['source_url'] ?? '') . ' ' . (string) ($capturePayload['title'] ?? ''));

        if (preg_match('/\b(auto|automotive|car|parts|spares|forsage|motor|wheel)\b/i', $analysisText . ' ' . $sourceSignals) === 1) {
            $domain = 'auto_goods';
            $productType = 'parts';
        } elseif (str_contains($analysisText, 'electronics')) {
            $domain = 'electronics';
            $productType = 'gadgets';
        } elseif (str_contains($analysisText, 'fashion')) {
            $domain = 'fashion';
            $productType = 'apparel';
        } elseif (str_contains($analysisText, 'grocery')) {
            $domain = 'grocery';
            $productType = 'consumables';
        }

        $audience = 'retail';
        if (str_contains($analysisText, 'premium')) {
            $audience = 'premium';
        } elseif (str_contains($analysisText, 'discount') || str_contains($analysisText, 'promo')) {
            $audience = 'discount';
        }

        return [
            'domain' => $domain,
            'product_type' => $productType,
            'audience' => $audience,
        ];
    }

    /**
     * @param  array<string, mixed>  $capturePayload
     * @param  array<string, mixed>  $analysisPayload
     * @param  string  $analysisText
     * @return array<string, mixed>
     */
    private function visualFingerprint(array $capturePayload, array $analysisPayload, string $analysisText): array
    {
        $dominantColors = array_values(array_filter((array) ($capturePayload['dominant_colors'] ?? []), is_string(...)));
        $buttonColors = array_values(array_filter((array) ($capturePayload['button_colors'] ?? []), is_string(...)));
        $allColors = array_values(array_filter(array_merge($dominantColors, $buttonColors, (array) ($analysisPayload['color_palette'] ?? [])), is_string(...)));
        $components = array_values(array_filter((array) ($capturePayload['ecommerce_components_visible'] ?? []), is_string(...)));
        $patterns = (array) ($capturePayload['commerce_patterns'] ?? []);

        $visualMode = $this->visualMode($capturePayload, $allColors, $analysisText);
        $backgroundSystem = $this->backgroundSystem($capturePayload, $visualMode, $allColors);
        $primaryCta = $this->ctaSystem($capturePayload, $analysisPayload, $analysisText, $buttonColors);
        $secondaryAccent = $this->secondaryAccent($patterns, $analysisText);
        $productCardStyle = $this->productCardStyle($capturePayload, $analysisPayload, $analysisText);
        $imageStyle = $this->imageStyle($analysisText, $productCardStyle);
        $headerStyle = $this->headerStyle($capturePayload, $analysisPayload, $analysisText);
        $spacingDensity = $this->spacingDensity($capturePayload, $analysisPayload);
        $sectionRhythm = $this->sectionRhythm($analysisPayload, $patterns, $components);
        $ecommerceControls = $this->ecommerceControls($components, $patterns);
        $brandWeight = $this->brandWeight($capturePayload, $headerStyle, $analysisText);
        $mood = $this->mood($visualMode, $backgroundSystem, $primaryCta, $productCardStyle, $sectionRhythm);

        $evidence = array_values(array_unique(array_filter([
            $visualMode === 'light' ? 'Переважає світлий фон' : 'Переважає темний фон',
            $primaryCta === 'black_solid' ? 'Основні CTA чорні' : null,
            $productCardStyle === 'borderless_catalog' ? 'Картки товарів майже без рамок' : null,
            $imageStyle === 'product_cutout_white_bg' ? 'Товарні фото на білому фоні' : null,
            $headerStyle === 'logo_left_center_nav_icons_right' ? 'Меню з логотипом, навігацією і іконками' : null,
            $ecommerceControls !== [] ? 'Є wishlist/cart/quick view' : null,
            $sectionRhythm === 'product_sections' ? 'Секційна головна з товарними блоками' : null,
        ], fn (?string $value): bool => $value !== null && $value !== '')));

        return [
            'visual_mode' => $visualMode,
            'background_system' => $backgroundSystem,
            'primary_cta_style' => $primaryCta,
            'secondary_accent' => $secondaryAccent,
            'typography_style' => $this->typographyStyle($analysisText),
            'product_card_style' => $productCardStyle,
            'image_style' => $imageStyle,
            'header_style' => $headerStyle,
            'nav_style' => $this->navStyle($headerStyle, $analysisText),
            'spacing_density' => $spacingDensity,
            'section_rhythm' => $sectionRhythm,
            'ecommerce_controls' => $ecommerceControls,
            'brand_weight' => $brandWeight,
            'mood' => $mood,
            'confidence' => $this->confidence($evidence),
            'evidence' => $evidence,
            'anti_clone_constraints' => [
                'Do not copy the source HTML/CSS/JS.',
                'Do not reuse source logos, banners, texts, product photos or brand names.',
                'Generate an original composition that preserves the visual mood only.',
            ],
        ];
    }

    private function visualMode(array $capturePayload, array $colors, string $analysisText): string
    {
        $background = (string) ($capturePayload['background_tendency'] ?? '');

        if ($background === 'dark_or_high_contrast') {
            return 'dark';
        }

        if ($background === 'light_or_neutral' || str_contains($analysisText, 'light') || str_contains($analysisText, 'white background')) {
            return 'light';
        }

        $lightColors = count(array_filter($colors, fn (string $color): bool => $this->isLightColor($color)));
        $darkColors = count(array_filter($colors, fn (string $color): bool => $this->isDarkColor($color)));

        if ($lightColors >= 2 && $lightColors >= $darkColors) {
            return 'light';
        }

        if ($darkColors >= 2 && $darkColors > $lightColors) {
            return 'dark';
        }

        return 'mixed';
    }

    private function backgroundSystem(array $capturePayload, string $visualMode, array $colors): string
    {
        if ($visualMode === 'dark') {
            return 'dark';
        }

        if (count($colors) === 0) {
            return 'white';
        }

        if (collect($colors)->contains(fn (string $color): bool => str_contains($color, 'f7') || str_contains($color, 'f5'))) {
            return 'light_gray';
        }

        return 'white';
    }

    private function ctaSystem(array $capturePayload, array $analysisPayload, string $analysisText, array $buttonColors): string
    {
        $buttonStyle = Str::lower((string) (($analysisPayload['button_style'] ?? '') . ' ' . ($capturePayload['button_style'] ?? '')));

        if (str_contains($buttonStyle, 'black') || str_contains($buttonStyle, 'solid') || collect($buttonColors)->contains(fn (string $color): bool => str_contains($color, '111') || str_contains($color, '000'))) {
            return 'black_solid';
        }

        if (str_contains($buttonStyle, 'yellow') || collect($buttonColors)->contains(fn (string $color): bool => $this->isYellowColor($color))) {
            return 'yellow_cta';
        }

        if ((string) ($capturePayload['background_tendency'] ?? '') === 'light_or_neutral' && (str_contains($buttonStyle, 'commerce') || str_contains($buttonStyle, 'clear') || str_contains($analysisText, 'commerce') || str_contains($analysisText, 'marketplace'))) {
            return 'yellow_cta';
        }

        if (str_contains($buttonStyle, 'blue')) {
            return 'blue_solid';
        }

        if (str_contains($buttonStyle, 'red')) {
            return 'red_solid';
        }

        if (str_contains($analysisText, 'outline') || str_contains($analysisText, 'ghost')) {
            return 'outline';
        }

        return 'neutral';
    }

    private function secondaryAccent(array $patterns, string $analysisText): string
    {
        if (! empty($patterns['sale_badges']) || str_contains($analysisText, 'sale') || str_contains($analysisText, 'badge')) {
            return 'red_badges';
        }

        return 'neutral';
    }

    private function productCardStyle(array $capturePayload, array $analysisPayload, string $analysisText): string
    {
        if (str_contains($analysisText, 'boutique') || str_contains($analysisText, 'white background') || str_contains($analysisText, 'black solid') || str_contains($analysisText, 'image first') || (str_contains($analysisText, 'woocommerce') && (str_contains($analysisText, 'boutique') || str_contains($analysisText, 'white background') || str_contains($analysisText, 'black solid')))) {
            return 'borderless_catalog';
        }

        if (str_contains($analysisText, 'premium')) {
            return 'premium_card';
        }

        if (str_contains($analysisText, 'marketplace') || str_contains($analysisText, 'image-first')) {
            return 'marketplace_card';
        }

        return 'detailed_grid';
    }

    private function imageStyle(string $analysisText, string $productCardStyle): string
    {
        if ($productCardStyle === 'borderless_catalog' || str_contains($analysisText, 'white background')) {
            return 'product_cutout_white_bg';
        }

        return 'mixed';
    }

    private function headerStyle(array $capturePayload, array $analysisPayload, string $analysisText): string
    {
        if (str_contains($analysisText, 'logo left centered nav with icons right') || str_contains($analysisText, 'logo left center nav icons right') || str_contains((string) ($capturePayload['header_style'] ?? ''), 'logo_left_center_nav_icons_right')) {
            return 'logo_left_center_nav_icons_right';
        }

        if (str_contains($analysisText, 'marketplace') || str_contains($analysisText, 'search')) {
            return 'marketplace_search';
        }

        return 'compact_nav';
    }

    private function navStyle(string $headerStyle, string $analysisText): string
    {
        if ($headerStyle === 'logo_left_center_nav_icons_right' || str_contains($analysisText, 'uppercase')) {
            return 'uppercase_centered';
        }

        return 'minimal_links';
    }

    private function spacingDensity(array $capturePayload, array $analysisPayload): string
    {
        $density = (string) ($analysisPayload['layout_density'] ?? $capturePayload['layout_density'] ?? 'normal');

        if ($density === 'airy' || $density === 'spacious') {
            return 'airy';
        }

        if ($density === 'compact') {
            return 'compact';
        }

        return 'normal';
    }

    private function sectionRhythm(array $analysisPayload, array $patterns, array $components): string
    {
        $text = Str::lower(implode(' ', array_filter([(string) ($analysisPayload['ecommerce_energy'] ?? ''), (string) ($analysisPayload['section_rhythm'] ?? ''), (string) ($analysisPayload['visual_signature'] ?? '')], is_scalar(...))));

        if (str_contains($text, 'product_sections') || str_contains($text, 'sectioned') || count((array) ($patterns['section_markers'] ?? [])) >= 3) {
            return 'product_sections';
        }

        if (str_contains($text, 'promo') || in_array('promotion', $components, true)) {
            return 'promo_first';
        }

        return 'hero_first';
    }

    private function ecommerceControls(array $components, array $patterns): array
    {
        $controls = [];

        if (in_array('wishlist', $components, true) || ! empty($patterns['wishlist_count'])) {
            $controls[] = 'wishlist';
        }

        if (in_array('quick_view', $components, true) || ! empty($patterns['quick_view_count'])) {
            $controls[] = 'quick_view';
        }

        if (in_array('cart', $components, true)) {
            $controls[] = 'cart_icon';
        }

        if (in_array('rating', $components, true)) {
            $controls[] = 'rating';
        }

        if (! empty($patterns['sale_badges']) || in_array('promotion', $components, true)) {
            $controls[] = 'sale_badges';
        }

        return $controls;
    }

    private function brandWeight(array $capturePayload, string $headerStyle, string $analysisText): string
    {
        if ($headerStyle === 'logo_left_center_nav_icons_right' || str_contains($analysisText, 'brand') || str_contains((string) ($capturePayload['header_style'] ?? ''), 'logo')) {
            return 'dominant_logo';
        }

        return 'balanced';
    }

    private function mood(string $visualMode, string $backgroundSystem, string $primaryCta, string $productCardStyle, string $sectionRhythm): string
    {
        if ($visualMode === 'light' && $backgroundSystem === 'white' && $primaryCta === 'black_solid' && $productCardStyle === 'borderless_catalog' && $sectionRhythm === 'product_sections') {
            return 'clean_woocommerce_boutique';
        }

        if ($visualMode === 'dark') {
            return 'dark_automotive_bold';
        }

        return 'clean_catalog';
    }

    private function typographyStyle(string $analysisText): string
    {
        if (str_contains($analysisText, 'condensed')) {
            return 'condensed_bold';
        }

        if (str_contains($analysisText, 'minimal')) {
            return 'minimal';
        }

        return 'geometric';
    }

    private function confidence(array $evidence): float
    {
        return round(min(0.99, 0.6 + (count($evidence) * 0.06)), 2);
    }

    private function isLightColor(string $color): bool
    {
        if (preg_match('/^#([0-9a-f]{6})(?:[0-9a-f]{2})?$/i', $color, $matches) !== 1) {
            return false;
        }

        $r = hexdec(substr($matches[1], 0, 2));
        $g = hexdec(substr($matches[1], 2, 2));
        $b = hexdec(substr($matches[1], 4, 2));
        $brightness = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;

        return $brightness >= 170;
    }

    private function isDarkColor(string $color): bool
    {
        return ! $this->isLightColor($color);
    }

    private function isYellowColor(string $color): bool
    {
        if (preg_match('/^#([0-9a-f]{6})(?:[0-9a-f]{2})?$/i', $color, $matches) !== 1) {
            return false;
        }

        $r = hexdec(substr($matches[1], 0, 2));
        $g = hexdec(substr($matches[1], 2, 2));
        $b = hexdec(substr($matches[1], 4, 2));

        return $r >= 180 && $g >= 120 && $b <= 120;
    }
}
