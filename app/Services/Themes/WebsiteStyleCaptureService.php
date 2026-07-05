<?php

namespace App\Services\Themes;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class WebsiteStyleCaptureService
{
    /**
     * @return array<string, mixed>
     */
    public function capture(string $url): array
    {
        $url = $this->assertPublicHttpUrl($url);

        $response = Http::timeout(10)
            ->withHeaders([
                'User-Agent' => 'Alta-Trade Theme Studio/1.0',
                'Accept' => 'text/html,application/xhtml+xml',
            ])
            ->get($url);

        if ($response->failed()) {
            throw new RuntimeException('Source website could not be loaded.');
        }

        $html = Str::limit($response->body(), 120000, '');
        $colors = $this->extractColors($html);
        $commercePatterns = $this->commercePatterns($html);

        return [
            'source_url' => $url,
            'final_url' => method_exists($response, 'effectiveUri') ? ($response->effectiveUri()?->__toString() ?? $url) : $url,
            'title' => $this->extractTitle($html),
            'dominant_colors' => $colors,
            'background_tendency' => $this->backgroundTendency($colors),
            'button_colors' => array_slice($colors, 0, 3),
            'typography_hints' => $this->typographyHints($html),
            'layout_density' => $this->layoutDensity($html),
            'header_style' => $this->headerStyle($html),
            'product_card_style' => $this->productCardStyle($html),
            'ecommerce_components_visible' => $this->visibleCommerceComponents($html),
            'commerce_patterns' => $commercePatterns,
            'visual_signature' => $this->visualSignature($commercePatterns),
            'screenshots' => [],
            'capture_mode' => 'http_html_fallback',
            'do_not_copy_notes' => [
                'HTML, CSS, logos, product photos and marketing copy are not reused as theme assets.',
                'Only stylistic signals are used to create Alta-Trade tokens and layout presets.',
            ],
        ];
    }

    public function assertPublicHttpUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);

        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)) {
            throw new InvalidArgumentException('URL має бути http або https.');
        }

        $host = Str::lower((string) ($parts['host'] ?? ''));

        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true) || str_ends_with($host, '.local')) {
            throw new InvalidArgumentException('Local/private URLs are blocked.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        if ($ips === []) {
            throw new InvalidArgumentException('Source host could not be resolved.');
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new InvalidArgumentException('Local/private URLs are blocked.');
            }
        }

        return $url;
    }

    /**
     * @return array<int, string>
     */
    private function extractColors(string $html): array
    {
        preg_match_all('/#[0-9a-f]{3,8}\b/i', $html, $matches);

        $colors = collect($matches[0] ?? [])
            ->map(fn (string $color): ?string => $this->normalizeColor($color))
            ->filter()
            ->countBy()
            ->sortDesc()
            ->keys()
            ->take(8)
            ->values()
            ->all();

        return $colors ?: ['#111827', '#f8fafc', '#f59e0b'];
    }

    private function extractTitle(string $html): ?string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $match) !== 1) {
            return null;
        }

        return trim(html_entity_decode(strip_tags($match[1])));
    }

    private function normalizeColor(string $color): ?string
    {
        $color = Str::lower($color);

        if (preg_match('/^#[0-9a-f]{3}$/', $color) === 1) {
            return '#'.$color[1].$color[1].$color[2].$color[2].$color[3].$color[3];
        }

        if (preg_match('/^#[0-9a-f]{6}([0-9a-f]{2})?$/', $color) === 1) {
            return $color;
        }

        return null;
    }

    /**
     * @param  array<int, string>  $colors
     */
    private function backgroundTendency(array $colors): string
    {
        $dark = collect($colors)->contains(fn (string $color): bool => hexdec(substr($color, 1, 2)) < 80
            && hexdec(substr($color, 3, 2)) < 80
            && hexdec(substr($color, 5, 2)) < 80);

        return $dark ? 'dark_or_high_contrast' : 'light_or_neutral';
    }

    /**
     * @return array<string, mixed>
     */
    private function typographyHints(string $html): array
    {
        return [
            'font_mentions' => collect(['Inter', 'Roboto', 'Montserrat', 'Arial', 'Helvetica', 'Open Sans'])
                ->filter(fn (string $font): bool => stripos($html, $font) !== false)
                ->values()
                ->all(),
            'uppercase_energy' => preg_match('/text-transform\s*:\s*uppercase|uppercase/i', $html) === 1 ? 'noticeable' : 'normal',
        ];
    }

    private function layoutDensity(string $html): string
    {
        $linkCount = substr_count(Str::lower($html), '<a ');
        $buttonCount = substr_count(Str::lower($html), '<button');

        return ($linkCount + $buttonCount) > 120 ? 'compact' : 'normal';
    }

    private function headerStyle(string $html): string
    {
        $lower = Str::lower($html);

        if (str_contains($lower, 'mega-menu') || str_contains($lower, 'catalog')) {
            return 'marketplace_catalog';
        }

        if (str_contains($lower, 'sticky') || str_contains($lower, 'fixed')) {
            return 'sticky_commerce_header';
        }

        return 'standard_header';
    }

    private function productCardStyle(string $html): string
    {
        $lower = Str::lower($html);

        if (str_contains($lower, 'woocommerce') && (str_contains($lower, 'quick view') || str_contains($lower, 'списку бажань'))) {
            return 'woocommerce_image_first_cards_with_meta_actions';
        }

        if (str_contains($lower, 'product-card') || str_contains($lower, 'product_card')) {
            return 'explicit_product_cards';
        }

        if (str_contains($lower, 'grid') && (str_contains($lower, 'price') || str_contains($lower, 'cart'))) {
            return 'grid_with_price_cta';
        }

        return 'generic_commerce_cards';
    }

    /**
     * @return array<int, string>
     */
    private function visibleCommerceComponents(string $html): array
    {
        $lower = Str::lower($html);
        $components = [];

        foreach ([
            'search' => ['search', 'пошук'],
            'cart' => ['cart', 'basket', 'кошик'],
            'catalog' => ['catalog', 'category', 'каталог'],
            'promotion' => ['sale', 'discount', 'акц'],
            'filters' => ['filter', 'фільтр'],
        ] as $name => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($lower, $needle)) {
                    $components[] = $name;
                    break;
                }
            }
        }

        return array_values(array_unique($components));
    }

    /**
     * @return array<string, mixed>
     */
    private function commercePatterns(string $html): array
    {
        $lower = Str::lower($html);
        $plain = Str::lower(html_entity_decode(strip_tags($html)));
        $productActionCount = substr_count($plain, 'додати в кошик') + substr_count($lower, 'add to cart');
        $quickViewCount = substr_count($lower, 'quick view');
        $wishlistCount = substr_count($plain, 'списку бажань') + substr_count($lower, 'wishlist');
        $sectionMarkers = [
            'new_arrivals' => ['наші новинки', 'нові надходження'],
            'discounts' => ['знижки та акції', 'акції'],
            'how_to_order' => ['як замовити'],
            'how_to_pay' => ['як оплатити'],
            'how_to_receive' => ['як отримати'],
            'brands' => ['бренди', 'торгові марки'],
            'bestsellers' => ['хіти продажів'],
            'reviews' => ['відгуки споживачів'],
            'newsletter' => ['підписатись', 'будь в курсі'],
            'blog' => ['детальніше »', 'коментарів немає'],
        ];

        return [
            'platform_hints' => array_values(array_filter([
                str_contains($lower, 'woocommerce') ? 'woocommerce' : null,
                str_contains($lower, 'fusion-') || str_contains($lower, 'avada') ? 'avada_fusion' : null,
            ])),
            'product_action_count' => $productActionCount,
            'quick_view_count' => $quickViewCount,
            'wishlist_count' => $wishlistCount,
            'tax_excluded_prices' => str_contains($plain, 'без пдв'),
            'uah_currency' => str_contains($plain, '₴'),
            'sale_badges' => preg_match('/-\d{1,2}%/', $plain) === 1,
            'section_markers' => collect($sectionMarkers)
                ->filter(fn (array $needles): bool => collect($needles)->contains(fn (string $needle): bool => str_contains($plain, $needle)))
                ->keys()
                ->values()
                ->all(),
            'catalog_taxonomy_hints' => array_values(array_filter([
                str_contains($plain, 'екстер’єр') || str_contains($plain, 'екстер\'єр') ? 'exterior_care' : null,
                str_contains($plain, 'інтер’єр') || str_contains($plain, 'інтер\'єр') ? 'interior_care' : null,
                str_contains($plain, 'мастила') ? 'oils' : null,
                str_contains($plain, 'технічні рідини') ? 'technical_fluids' : null,
                str_contains($plain, 'аксесуари') ? 'accessories' : null,
            ])),
        ];
    }

    /**
     * @param  array<string, mixed>  $patterns
     */
    private function visualSignature(array $patterns): string
    {
        $platformHints = (array) ($patterns['platform_hints'] ?? []);
        $sections = (array) ($patterns['section_markers'] ?? []);

        if (in_array('woocommerce', $platformHints, true) && count($sections) >= 5) {
            return 'light sectioned platform-backed marketplace storefront with compact product rails, utility header, sale badges, wishlist/quick-view actions and service/info blocks';
        }

        if (($patterns['product_action_count'] ?? 0) > 20) {
            return 'dense product-grid storefront with repeated commerce actions and promotional sections';
        }

        return 'standard ecommerce storefront';
    }
}
