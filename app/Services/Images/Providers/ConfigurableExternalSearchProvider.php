<?php

namespace App\Services\Images\Providers;

use App\Models\Product;
use App\Services\Images\Contracts\ImageSearchProvider;

class ConfigurableExternalSearchProvider implements ImageSearchProvider
{
    public function search(Product $product, int $limit = 5): array
    {
        return [[
            'provider' => 'external_stub',
            'source_url' => '',
            'thumbnail_url' => null,
            'image_url' => '',
            'title' => 'External image search provider is not configured',
            'source_domain' => null,
            'width' => null,
            'height' => null,
            'mime_type' => null,
            'score' => null,
            'warnings' => [
                'Підключіть зовнішній image search provider у налаштуваннях. HTML scraping і Google Images scraping не використовуються.',
            ],
            'license_note' => null,
            'can_import' => false,
            'rejection_reason' => 'External provider stub не повертає фото без окремої інтеграції.',
        ]];
    }
}
