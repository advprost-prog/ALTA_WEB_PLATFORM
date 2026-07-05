<?php

namespace App\Services\Images\Contracts;

use App\Models\Product;

interface ImageSearchProvider
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(Product $product, int $limit = 5): array;
}
