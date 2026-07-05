<?php

namespace App\Services\Images;

use App\Models\AiSetting;
use App\Models\Product;
use Illuminate\Support\Str;
use RuntimeException;

class ImageCandidateValidator
{
    public function __construct(private readonly ImageDownloadService $downloadService)
    {
        //
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    public function validate(Product $product, array $candidate): array
    {
        $warnings = array_values(array_filter((array) ($candidate['warnings'] ?? [])));
        $nonCriticalWarnings = $warnings;
        $criticalWarnings = [];
        $settings = AiSetting::getActive();
        $candidate['status'] = 'pending';
        $candidate['can_import'] = false;
        $candidate['rejection_reason'] = null;

        try {
            $url = (string) ($candidate['image_url'] ?: $candidate['source_url'] ?? '');
            $sourceUrl = (string) ($candidate['source_url'] ?? '');

            if ($url === '') {
                throw new RuntimeException('URL фото порожній.');
            }

            if ($sourceUrl !== '' && preg_match('/^https?:\/\//i', $sourceUrl) === 1) {
                $this->downloadService->assertSafeUrl($sourceUrl);
            }

            $download = $this->downloadService->download($url);
            $mime = strtolower((string) ($download['mime_type'] ?? $download['content_type'] ?? ''));
            $width = (int) ($download['width'] ?? 0);
            $height = (int) ($download['height'] ?? 0);

            $candidate['width'] = $width;
            $candidate['height'] = $height;
            $candidate['mime_type'] = $mime;

            if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                if ($mime === 'text/html' && $this->mode($candidate) === 'direct_image_url') {
                    throw new RuntimeException('Це HTML-сторінка, а не пряме посилання на фото. Використайте вкладку URL сторінки товару або натисніть "Витягнути фото зі сторінки".');
                }

                throw new RuntimeException('Непідтримуваний формат фото: '.($mime ?: 'unknown').'.');
            }

            if ($width < (int) $settings->image_search_min_width || $height < (int) $settings->image_search_min_height) {
                throw new RuntimeException('Зображення замале: '.$width.'x'.$height.'.');
            }

            if ($this->looksLikeBannerRatio($width, $height)) {
                throw new RuntimeException('Зображення має непридатне співвідношення сторін для фото товару: '.$width.'x'.$height.'.');
            }

            if ($this->looksLikeNonProductImage($candidate)) {
                throw new RuntimeException('URL або title схожий на placeholder/logo/banner/watermark, потрібна ручна перевірка іншого фото.');
            }

            $warnings[] = 'AI-перевірка водяних знаків/тексту недоступна; остаточне рішення приймає оператор.';
            $nonCriticalWarnings[] = 'AI-перевірка водяних знаків/тексту недоступна; остаточне рішення приймає оператор.';

            $candidate['can_import'] = true;
            $candidate['quality_score'] = $this->qualityScore($product, $candidate, $width, $height);
            $candidate['metadata'] = array_merge((array) ($candidate['metadata'] ?? []), [
                'validated_at' => now()->toIso8601String(),
                'http_status' => $download['http_status'] ?? 200,
                'download_size' => $download['size'],
                'technical_validation' => 'passed',
                'vision_validation' => 'unavailable',
                'critical_warnings' => [],
                'non_critical_warnings' => array_values(array_unique($nonCriticalWarnings)),
                'product_context' => [
                    'name' => $product->name,
                    'brand' => $product->brand?->name,
                    'sku' => $product->sku,
                    'category' => $product->category?->name,
                ],
            ]);
        } catch (RuntimeException $exception) {
            $candidate['status'] = 'rejected';
            $candidate['can_import'] = false;
            $candidate['quality_score'] = 0;
            $candidate['rejection_reason'] = $exception->getMessage();
            $criticalWarnings[] = $exception->getMessage();
            $warnings[] = $exception->getMessage();
            $candidate['metadata'] = array_merge((array) ($candidate['metadata'] ?? []), [
                'validated_at' => now()->toIso8601String(),
                'technical_validation' => 'failed',
                'critical_warnings' => array_values(array_unique($criticalWarnings)),
                'non_critical_warnings' => array_values(array_unique($nonCriticalWarnings)),
                'http_status' => $this->httpStatusFromMessage($exception->getMessage()),
                'rejection_category' => $this->rejectionCategory($exception->getMessage()),
            ]);
        }

        $candidate['warnings'] = array_values(array_unique($warnings));

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function looksLikeNonProductImage(array $candidate): bool
    {
        $haystack = strtolower(implode(' ', array_filter([
            $candidate['source_url'] ?? null,
            $candidate['image_url'] ?? null,
            $candidate['title'] ?? null,
        ])));

        foreach (['placeholder', 'no-image', 'banner', 'collage', 'infographic', 'logo-only', '/logo.', '/icon.', 'favicon', 'sprite', 'avatar', 'payment', 'delivery'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function qualityScore(Product $product, array $candidate, int $width, int $height): int
    {
        $score = (int) ($candidate['score'] ?? 70);
        $haystack = Str::lower(implode(' ', array_filter([
            $candidate['source_url'] ?? null,
            $candidate['image_url'] ?? null,
            $candidate['title'] ?? null,
            $candidate['source_domain'] ?? null,
        ])));

        foreach ([$product->brand?->name, $product->sku] as $needle) {
            $needle = Str::lower(trim((string) $needle));

            if ($needle !== '' && str_contains($haystack, $needle)) {
                $score += 8;
            }
        }

        foreach ($this->importantNameTokens($product) as $token) {
            if (str_contains($haystack, $token)) {
                $score += 3;
            }
        }

        if ($width >= 600 && $height >= 600) {
            $score += 8;
        }

        if (($candidate['metadata']['has_original'] ?? false) === true || $this->mode($candidate) === 'direct_image_url') {
            $score += 6;
        }

        foreach (['watermark', 'logo', 'icon', 'banner', 'advert', 'placeholder', 'marketplace'] as $needle) {
            if (str_contains($haystack, $needle)) {
                $score -= 15;
            }
        }

        return max(1, min(100, $score));
    }

    /**
     * @return array<int, string>
     */
    private function importantNameTokens(Product $product): array
    {
        return collect(preg_split('/\s+/u', Str::lower((string) $product->name)) ?: [])
            ->map(fn (string $token): string => trim($token, " \t\n\r\0\x0B.,;:()[]{}"))
            ->filter(fn (string $token): bool => mb_strlen($token) >= 4 && ! in_array($token, ['купити', 'ціна', 'доставка'], true))
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function mode(array $candidate): string
    {
        return (string) ($candidate['metadata']['mode'] ?? $candidate['provider'] ?? 'direct_image_url');
    }

    private function looksLikeBannerRatio(int $width, int $height): bool
    {
        if ($width <= 0 || $height <= 0) {
            return true;
        }

        $ratio = $width / $height;

        return $ratio > 3 || $ratio < 0.33;
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
            preg_match('/http\s+410/i', $message) === 1 => 'HTTP 410',
            preg_match('/http\s+5\d\d/i', $message) === 1 => 'HTTP 5xx',
            str_contains($message, 'замале') => 'too small',
            str_contains($message, 'непідтримуваний формат') => 'unsupported mime',
            str_contains($message, 'html-сторінка') || str_contains($message, 'text/html') => 'not image',
            str_contains($message, 'private') || str_contains($message, 'localhost') || str_contains($message, 'internal') || str_contains($message, 'заборонено') => 'unsafe url',
            str_contains($message, 'співвідношення') => 'bad ratio',
            default => 'no downloadable image',
        };
    }
}
