<?php

namespace App\Services\Images\Providers;

use App\Models\Product;
use App\Services\Images\Contracts\ImageSearchProvider;

class ManualUrlImageSearchProvider implements ImageSearchProvider
{
    /**
     * @param  array<int, string>  $urls
     */
    public function __construct(
        private readonly array $urls,
        private readonly string $provider = 'direct_image_url',
    )
    {
        //
    }

    public function search(Product $product, int $limit = 5): array
    {
        return collect($this->urls)
            ->map(fn (string $url): string => trim($url))
            ->filter()
            ->unique()
            ->take($limit)
            ->map(function (string $url): array {
                $host = parse_url($url, PHP_URL_HOST);

                return [
                    'provider' => $this->provider,
                    'query' => null,
                    'source_url' => $url,
                    'thumbnail_url' => $url,
                    'image_url' => $url,
                    'title' => null,
                    'source_domain' => is_string($host) ? strtolower($host) : null,
                    'width' => null,
                    'height' => null,
                    'mime_type' => null,
                    'score' => null,
                    'warnings' => [
                        'Manual URL. Перевірте право використання фото перед імпортом.',
                    ],
                    'license_note' => 'URL додано оператором вручну; права має підтвердити оператор.',
                    'can_import' => false,
                    'rejection_reason' => null,
                    'metadata' => [
                        'mode' => 'direct_image_url',
                    ],
                ];
            })
            ->values()
            ->all();
    }
}
