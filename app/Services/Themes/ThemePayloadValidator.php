<?php

namespace App\Services\Themes;

use InvalidArgumentException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ThemePayloadValidator
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function validate(array $payload, ?string $sourceUrl = null): array
    {
        foreach (['name', 'tokens', 'layout_config'] as $field) {
            if (! array_key_exists($field, $payload)) {
                throw new InvalidArgumentException("Theme payload is missing [{$field}].");
            }
        }

        $this->assertSafePayload($payload);
        $this->assertNoSourceBrandLeak((string) ($payload['name'] ?? ''), (string) ($payload['custom_css'] ?? ''), $sourceUrl);

        $tokens = ThemeSchema::normalizeTokens((array) $payload['tokens']);
        $layoutConfig = ThemeSchema::normalizeLayoutConfig((array) $payload['layout_config']);
        $componentConfig = ThemeSchema::normalizeComponentConfig((array) ($payload['component_config'] ?? []));

        $this->assertValidColors($tokens);
        $this->assertAllowedVariant('headerVariant', $layoutConfig['headerVariant'], ThemeSchema::HEADER_VARIANTS);
        $this->assertAllowedVariant('topBarVariant', $layoutConfig['topBarVariant'], ThemeSchema::TOP_BAR_VARIANTS);
        $this->assertAllowedVariant('heroVariant', $layoutConfig['heroVariant'], ThemeSchema::HERO_VARIANTS);
        $this->assertAllowedVariant('categoryGridVariant', $layoutConfig['categoryGridVariant'], ThemeSchema::CATEGORY_GRID_VARIANTS);
        $this->assertAllowedVariant('productCardVariant', $layoutConfig['productCardVariant'], ThemeSchema::PRODUCT_CARD_VARIANTS);
        $this->assertAllowedVariant('productPageVariant', $layoutConfig['productPageVariant'], ThemeSchema::PRODUCT_PAGE_VARIANTS);
        $this->assertAllowedVariant('footerVariant', $layoutConfig['footerVariant'], ThemeSchema::FOOTER_VARIANTS);
        $this->assertAllowedVariant('containerWidth', $layoutConfig['containerWidth'], ThemeSchema::CONTAINER_WIDTHS);
        $this->assertAllowedVariant('density', $layoutConfig['density'], ThemeSchema::DENSITIES);
        $this->assertAllowedVariant('mobileNavVariant', $layoutConfig['mobileNavVariant'], ThemeSchema::MOBILE_NAV_VARIANTS);
        $this->assertAllowedVariant('cardImageRatio', $componentConfig['cardImageRatio'], ThemeSchema::CARD_IMAGE_RATIOS);

        return array_replace($payload, [
            'tokens' => $tokens,
            'layout_config' => $layoutConfig,
            'component_config' => $componentConfig,
            'css_variables' => ThemeSchema::cssVariables($tokens, (array) ($payload['css_variables'] ?? [])),
            'custom_css' => $this->sanitizeCustomCss((string) ($payload['custom_css'] ?? '')) ?: null,
        ]);
    }

    public function sanitizeCustomCss(string $css): string
    {
        $css = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $css) ?? '';
        $css = preg_replace('/<\/?(?:script|style)\b[^>]*>/is', '', $css) ?? '';
        $css = preg_replace('/@import\s+[^;]+;?/i', '', $css) ?? '';
        $css = preg_replace('/url\(\s*[\'"]?\s*https?:\/\/[^)]+?\)/i', '', $css) ?? '';
        $css = preg_replace('/url\(\s*[\'"]?\s*\/\/[^)]+?\)/i', '', $css) ?? '';
        $css = preg_replace('/javascript\s*:/i', '', $css) ?? '';
        $css = preg_replace('/expression\s*\(/i', '(', $css) ?? '';
        $css = preg_replace('/behavior\s*:/i', '', $css) ?? '';
        $css = preg_replace('/<\/?[^>]+>/', '', $css) ?? '';

        return trim($css);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertSafePayload(array $payload): void
    {
        $strings = Arr::flatten($payload);

        foreach ($strings as $value) {
            if (! is_string($value)) {
                continue;
            }

            if (preg_match('/<\s*\/?\s*(script|style)\b/i', $value) === 1) {
                throw new InvalidArgumentException('Theme payload contains script/style tags.');
            }

            if (preg_match('/@import\s+/i', $value) === 1) {
                throw new InvalidArgumentException('Theme payload contains external CSS imports.');
            }

            if (preg_match('/url\(\s*[\'"]?\s*(https?:)?\/\//i', $value) === 1) {
                throw new InvalidArgumentException('Theme payload contains remote CSS URLs.');
            }

            if (preg_match('/https?:\/\/\S+\.(?:jpe?g|png|gif|webp|svg)(?:[?#]\S*)?/i', $value) === 1) {
                throw new InvalidArgumentException('Theme payload contains remote image URLs.');
            }

            if (preg_match('/(?:javascript\s*:|expression\s*\(|behavior\s*:)/i', $value) === 1) {
                throw new InvalidArgumentException('Theme payload contains unsafe CSS.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $tokens
     */
    private function assertValidColors(array $tokens): void
    {
        foreach ((array) ($tokens['colors'] ?? []) as $name => $color) {
            if (! is_string($color) || preg_match('/^#[0-9a-f]{6}([0-9a-f]{2})?$/i', $color) !== 1) {
                throw new InvalidArgumentException("Theme color [{$name}] must be a valid hex value.");
            }
        }
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function assertAllowedVariant(string $field, mixed $value, array $allowed): void
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException("Theme layout variant [{$field}] is not allowed.");
        }
    }

    private function assertNoSourceBrandLeak(string $name, string $customCss, ?string $sourceUrl): void
    {
        if (! $sourceUrl) {
            return;
        }

        $host = parse_url($sourceUrl, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return;
        }

        $fragments = collect(preg_split('/[.\-]+/', Str::lower($host)) ?: [])
            ->reject(fn (string $part): bool => in_array($part, ['www', 'shop', 'store', 'com', 'net', 'org', 'ua', 'co'], true))
            ->filter(fn (string $part): bool => mb_strlen($part) >= 4)
            ->values();

        $haystack = Str::lower($name.' '.$customCss);

        foreach ($fragments as $fragment) {
            if (str_contains($haystack, $fragment)) {
                throw new InvalidArgumentException('Theme payload appears to contain source website brand/domain text.');
            }
        }
    }
}
