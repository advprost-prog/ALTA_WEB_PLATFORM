<?php

namespace App\Services\Themes;

class WebsiteStyleClassifier
{
    public function __construct(private readonly StyleFingerprintExtractor $fingerprintExtractor)
    {
        //
    }

    /**
     * @param  array<string, mixed>  $capturePayload
     * @param  array<string, mixed>  $analysisPayload
     * @return array<string, mixed>
     */
    public function classify(array $capturePayload, array $analysisPayload): array
    {
        $fingerprint = $this->fingerprintExtractor->extract($capturePayload, $analysisPayload);
        $styleFingerprint = $fingerprint['style_fingerprint'];

        return [
            'business_profile' => $fingerprint['business_profile'],
            'style_fingerprint' => $styleFingerprint,
            'style_lock' => $fingerprint['style_lock'],
            'ecommerce_type' => $this->ecommerceType($fingerprint['business_profile']),
            'visual_mode' => $styleFingerprint['visual_mode'],
            'density' => $this->density($styleFingerprint['spacing_density']),
            'card_style' => $this->normalizeCardStyle((string) ($styleFingerprint['product_card_style'] ?? 'detailed_grid')),
            'cta_style' => $this->normalizeCtaStyle((string) ($styleFingerprint['primary_cta_style'] ?? 'neutral')),
            'homepage_structure' => $this->normalizeHomepageStructure((string) ($styleFingerprint['section_rhythm'] ?? 'hero_first')),
            'header_style' => $styleFingerprint['header_style'],
            'badge_style' => $this->normalizeBadgeStyle((string) ($styleFingerprint['secondary_accent'] ?? 'neutral')),
            'confidence' => $styleFingerprint['confidence'],
            'evidence' => $styleFingerprint['evidence'],
        ];
    }

    /**
     * @param  array<string, string>  $businessProfile
     */
    private function ecommerceType(array $businessProfile): string
    {
        return match ($businessProfile['domain'] ?? 'universal') {
            'auto_goods' => 'automotive_parts',
            'electronics' => 'electronics',
            'fashion' => 'fashion',
            'grocery' => 'grocery',
            default => 'marketplace',
        };
    }

    private function density(string $spacingDensity): string
    {
        return match ($spacingDensity) {
            'airy' => 'spacious',
            'compact' => 'compact',
            default => 'normal',
        };
    }

    private function normalizeCardStyle(string $cardStyle): string
    {
        return match ($cardStyle) {
            'borderless_catalog', 'light_woocommerce_boutique' => 'marketplace_card',
            'premium_card' => 'premium_card',
            'marketplace_card' => 'marketplace_card',
            default => 'detailed_grid',
        };
    }

    private function normalizeCtaStyle(string $ctaStyle): string
    {
        return match ($ctaStyle) {
            'black_solid' => 'black_solid',
            'yellow_cta', 'yellow_solid' => 'yellow_cta',
            'blue_solid' => 'blue_cta',
            'red_solid' => 'red_cta',
            'outline' => 'neutral_cta',
            default => 'neutral_cta',
        };
    }

    private function normalizeHomepageStructure(string $sectionRhythm): string
    {
        return $sectionRhythm === 'product_sections' ? 'sectional' : 'hero_focused';
    }

    private function normalizeBadgeStyle(string $secondaryAccent): string
    {
        return $secondaryAccent === 'red_badges' ? 'sale_heavy' : 'minimal';
    }
}
