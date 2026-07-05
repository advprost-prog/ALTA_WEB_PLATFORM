<?php

namespace App\Services\Images;

use App\Models\AiSetting;
use App\Models\Product;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Str;
use RuntimeException;

class ProductPageImageExtractor
{
    public function __construct(private readonly ImageDownloadService $downloadService)
    {
        //
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function extract(string $pageUrl, Product $product): array
    {
        $pageUrl = trim($pageUrl);
        try {
            $download = $this->downloadService->downloadHtml($pageUrl);
        } catch (RuntimeException $exception) {
            return [$this->rejectedEntry($pageUrl, $exception->getMessage())];
        } catch (\Throwable) {
            return [$this->rejectedEntry($pageUrl, 'connection_failed: Не вдалося прочитати сторінку товару.')];
        }

        $html = $download['body'];

        if (trim($html) === '') {
            return [$this->rejectedEntry($pageUrl, 'empty_html: HTML сторінка порожня.')];
        }

        $dom = new DOMDocument();

        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return [$this->rejectedEntry($pageUrl, 'invalid_html: Не вдалося прочитати HTML сторінки.')];
        }

        $title = $this->pageTitle($dom);
        $candidates = [];

        foreach ($this->metaImages($dom) as $image) {
            $this->remember($candidates, $pageUrl, $image, 'meta', $title, null, null);
        }

        foreach ($this->jsonLdImages($dom) as $image) {
            $this->remember($candidates, $pageUrl, $image, 'json_ld', $title, null, null);
        }

        foreach ($dom->getElementsByTagName('source') as $source) {
            if ($source instanceof DOMElement) {
                $image = $this->largestSrcsetImage($source->getAttribute('srcset'));

                if ($image !== null) {
                    $this->remember($candidates, $pageUrl, $image['url'], 'picture_srcset', $title, null, null, $image['width'], $source->getAttribute('class'));
                }
            }
        }

        foreach ($dom->getElementsByTagName('img') as $img) {
            if (! $img instanceof DOMElement) {
                continue;
            }

            $imageTitle = trim($img->getAttribute('alt') ?: $img->getAttribute('title')) ?: $title;
            $width = $this->intAttribute($img, 'width');
            $height = $this->intAttribute($img, 'height');
            $context = implode(' ', [
                $img->getAttribute('class'),
                $img->getAttribute('id'),
                $img->getAttribute('loading'),
                $img->getAttribute('role'),
            ]);

            foreach (['src', 'data-src', 'data-original', 'data-large', 'data-zoom-image', 'data-lazy-src'] as $attribute) {
                $this->remember($candidates, $pageUrl, $img->getAttribute($attribute), 'img_'.$attribute, $imageTitle, $width, $height, null, $context);
            }

            $image = $this->largestSrcsetImage($img->getAttribute('srcset'));

            if ($image !== null) {
                $this->remember($candidates, $pageUrl, $image['url'], 'img_srcset', $imageTitle, $width ?: $image['width'], $height, $image['width'], $context);
            }
        }

        return collect($candidates)
            ->unique('image_url')
            ->sortByDesc('score')
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function metaImages(DOMDocument $dom): array
    {
        $images = [];

        foreach ($dom->getElementsByTagName('meta') as $meta) {
            if (! $meta instanceof DOMElement) {
                continue;
            }

            $name = Str::lower($meta->getAttribute('property') ?: $meta->getAttribute('name'));

            if (in_array($name, ['og:image', 'og:image:secure_url', 'twitter:image', 'twitter:image:src'], true)) {
                $images[] = $meta->getAttribute('content');
            }
        }

        return array_values(array_filter($images));
    }

    /**
     * @return array<int, string>
     */
    private function jsonLdImages(DOMDocument $dom): array
    {
        $images = [];

        foreach ($dom->getElementsByTagName('script') as $script) {
            if (! $script instanceof DOMElement || ! str_contains(Str::lower($script->getAttribute('type')), 'ld+json')) {
                continue;
            }

            $json = json_decode(trim($script->textContent), true);

            if (is_array($json)) {
                $this->collectJsonImages($json, $images);
            }
        }

        return array_values(array_unique(array_filter($images)));
    }

    /**
     * @param  array<mixed>  $value
     * @param  array<int, string>  $images
     */
    private function collectJsonImages(array $value, array &$images): void
    {
        foreach ($value as $key => $item) {
            if (in_array($key, ['image', 'thumbnailUrl', 'contentUrl'], true)) {
                $this->collectImageValue($item, $images);
            }

            if (is_array($item)) {
                $this->collectJsonImages($item, $images);
            }
        }
    }

    /**
     * @param  array<int, string>  $images
     */
    private function collectImageValue(mixed $value, array &$images): void
    {
        if (is_string($value)) {
            $images[] = $value;

            return;
        }

        if (! is_array($value)) {
            return;
        }

        foreach (['url', '@id', 'contentUrl'] as $key) {
            if (isset($value[$key]) && is_string($value[$key])) {
                $images[] = $value[$key];
            }
        }

        foreach ($value as $item) {
            $this->collectImageValue($item, $images);
        }
    }

    /**
     * @return array<int, array{url: string, width: ?int}>
     */
    private function srcsetImages(string $srcset): array
    {
        return collect(explode(',', $srcset))
            ->map(function (string $part): ?array {
                $part = trim($part);

                if ($part === '') {
                    return null;
                }

                [$url, $descriptor] = array_pad(preg_split('/\s+/', $part, 2) ?: [], 2, null);

                return [
                    'url' => trim((string) $url),
                    'width' => is_string($descriptor) && preg_match('/^(\d+)w$/i', trim($descriptor), $matches) === 1
                        ? (int) $matches[1]
                        : null,
                ];
            })
            ->filter()
            ->sortByDesc('width')
            ->values()
            ->all();
    }

    /**
     * @return array{url: string, width: ?int}|null
     */
    private function largestSrcsetImage(string $srcset): ?array
    {
        return $this->srcsetImages($srcset)[0] ?? null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     */
    private function remember(array &$candidates, string $pageUrl, string $imageUrl, string $source, string $title, ?int $width, ?int $height, ?int $srcsetWidth = null, string $context = ''): void
    {
        $imageUrl = trim(html_entity_decode($imageUrl));

        if ($imageUrl === '' || str_starts_with($imageUrl, 'data:')) {
            return;
        }

        $absoluteUrl = $this->absoluteUrl($pageUrl, $imageUrl);

        if (! $this->isCandidateImage($absoluteUrl, $title, $context, $width, $height, $srcsetWidth)) {
            return;
        }

        try {
            $this->downloadService->assertSafeUrl($absoluteUrl);
        } catch (RuntimeException) {
            return;
        }

        $pageHost = parse_url($pageUrl, PHP_URL_HOST);

        $candidates[] = [
            'provider' => 'page_url',
            'query' => null,
            'source_url' => $pageUrl,
            'thumbnail_url' => $absoluteUrl,
            'image_url' => $absoluteUrl,
            'source_domain' => is_string($pageHost) ? strtolower($pageHost) : null,
            'title' => $title ?: null,
            'width' => $width ?: $srcsetWidth,
            'height' => $height,
            'mime_type' => null,
            'score' => $this->score($absoluteUrl, $title, $source, $width ?: $srcsetWidth, $height),
            'warnings' => [
                'Фото витягнуто з HTML сторінки; права має підтвердити оператор.',
            ],
            'license_note' => 'URL сторінки додано оператором; права на фото має підтвердити оператор.',
            'can_import' => false,
            'rejection_reason' => null,
            'metadata' => [
                'mode' => 'page_url',
                'extraction_source' => $source,
                'page_url' => $pageUrl,
            ],
        ];
    }

    private function isCandidateImage(string $url, string $title, string $context, ?int $width, ?int $height, ?int $srcsetWidth): bool
    {
        $haystack = Str::lower($url.' '.$title.' '.$context);
        $settings = AiSetting::getActive();
        $minWidth = max(300, (int) $settings->image_search_min_width);
        $minHeight = max(300, (int) $settings->image_search_min_height);

        if (preg_match('/\.(svg|gif|pdf)(?:$|\?)/i', $url) === 1) {
            return false;
        }

        foreach (['favicon', 'logo', 'icon', 'sprite', 'pixel', 'tracking', 'banner', 'advert', 'ads/', 'social', 'avatar', 'payment', 'delivery', 'placeholder', 'no-image', 'loading', 'blank', '/thumb/', 'thumbnail'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return false;
            }
        }

        if (($width !== null && $width < $minWidth) || ($height !== null && $height < $minHeight) || ($srcsetWidth !== null && $srcsetWidth < $minWidth)) {
            return false;
        }

        if ($width !== null && $height !== null && $this->looksLikeBannerRatio($width, $height)) {
            return false;
        }

        return true;
    }

    private function score(string $url, string $title, string $source, ?int $width, ?int $height): int
    {
        $score = match ($source) {
            'meta', 'json_ld' => 88,
            'picture_srcset', 'img_srcset' => 76,
            default => 68,
        };

        if (($width ?? 0) >= 600 && ($height ?? 0) >= 600) {
            $score += 8;
        }

        $haystack = Str::lower($url.' '.$title);

        foreach (['watermark', 'marketplace', 'prom', 'rozetka'] as $needle) {
            if (str_contains($haystack, $needle)) {
                $score -= 12;
            }
        }

        return max(1, min(100, $score));
    }

    private function looksLikeBannerRatio(int $width, int $height): bool
    {
        if ($width <= 0 || $height <= 0) {
            return true;
        }

        $ratio = $width / $height;

        return $ratio > 3 || $ratio < 0.33;
    }

    private function absoluteUrl(string $pageUrl, string $url): string
    {
        if (preg_match('/^https?:\/\//i', $url) === 1) {
            return $url;
        }

        $base = parse_url($pageUrl);
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'] ?? '';

        if (str_starts_with($url, '//')) {
            return $scheme.':'.$url;
        }

        if (str_starts_with($url, '/')) {
            return $scheme.'://'.$host.$url;
        }

        $path = $base['path'] ?? '/';
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');

        return $scheme.'://'.$host.($directory === '' ? '' : $directory).'/'.$url;
    }

    private function pageTitle(DOMDocument $dom): string
    {
        $titles = $dom->getElementsByTagName('title');

        if ($titles->length === 0) {
            return '';
        }

        return trim($titles->item(0)?->textContent ?? '');
    }

    private function intAttribute(DOMElement $element, string $attribute): ?int
    {
        $value = $element->getAttribute($attribute);

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function rejectedEntry(string $pageUrl, string $message): array
    {
        $host = parse_url($pageUrl, PHP_URL_HOST);

        return [
            'provider' => 'page_url',
            'query' => null,
            'source_url' => $pageUrl,
            'thumbnail_url' => null,
            'image_url' => $pageUrl,
            'source_domain' => is_string($host) ? strtolower($host) : null,
            'title' => null,
            'width' => null,
            'height' => null,
            'mime_type' => null,
            'score' => 0,
            'warnings' => [$message],
            'license_note' => null,
            'status' => 'rejected',
            'can_import' => false,
            'rejection_reason' => $message,
            'metadata' => [
                'mode' => 'page_url',
                'page_url' => $pageUrl,
                'extraction_failed' => true,
                'rejection_category' => $this->rejectionCategory($message),
                'http_status' => $this->httpStatusFromMessage($message),
            ],
        ];
    }

    private function httpStatusFromMessage(string $message): ?int
    {
        if (preg_match('/HTTP\s+(\d{3})/i', $message, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function rejectionCategory(string $message): string
    {
        $message = Str::lower($message);

        return match (true) {
            str_contains($message, 'blocked_domain') => 'blocked_domain',
            str_contains($message, 'connection_timeout') || str_contains($message, 'timeout') => 'connection_timeout',
            str_contains($message, 'connection_failed') => 'connection_failed',
            preg_match('/http\s+468/i', $message) === 1 => 'HTTP 468',
            preg_match('/http\s+403/i', $message) === 1 => 'HTTP 403',
            preg_match('/http\s+404/i', $message) === 1 => 'HTTP 404',
            preg_match('/http\s+5\d\d/i', $message) === 1 => 'HTTP 5xx',
            default => 'source_page_unavailable',
        };
    }
}
