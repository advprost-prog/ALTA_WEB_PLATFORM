<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class DiagnoseProductImages extends Command
{
    protected $signature = 'alta:diagnose-product-images {slug? : Optional product slug}';

    protected $description = 'Diagnose product image paths, storage files, and storefront image URLs.';

    public function handle(): int
    {
        $slug = $this->argument('slug');

        $query = Product::query()->orderBy('id');

        if ($slug) {
            $query->where('slug', $slug);
        }

        $products = $query->get();

        if ($products->isEmpty()) {
            $this->warn($slug ? 'Product not found: ' . $slug : 'No products found.');

            return self::FAILURE;
        }

        $this->line('Public storage root: ' . Storage::disk('public')->path(''));
        $this->line('Public storage URL: ' . Storage::disk('public')->url(''));
        $this->newLine();

        $this->table(
            [
                'id',
                'name',
                'slug',
                'main_image',
                'image_url',
                'empty',
                'remote',
                '/images',
                '/storage',
                'public_exists',
                'storage_exists',
                'storage_url',
                'placeholder',
                'reason',
            ],
            $products->map(fn (Product $product): array => $this->row($product))->all(),
        );

        return self::SUCCESS;
    }

    /**
     * @return array<int, string|int>
     */
    private function row(Product $product): array
    {
        $mainImage = trim((string) $product->getRawOriginal('main_image'));
        $imageUrl = $product->image_url;
        $publicPath = $this->publicPathFor($mainImage);
        $storagePath = $this->storagePathFor($mainImage);
        $storageExists = $storagePath && Storage::disk('public')->exists($storagePath);
        $publicExists = $publicPath && is_file(public_path($publicPath));

        return [
            $product->id,
            $product->name,
            $product->slug,
            $mainImage !== '' ? $mainImage : '-',
            $imageUrl,
            $mainImage === '' ? 'yes' : 'no',
            preg_match('/^https?:\/\//i', $mainImage) === 1 ? 'yes' : 'no',
            str_starts_with(ltrim($mainImage, '/'), 'images/') ? 'yes' : 'no',
            str_starts_with(ltrim($mainImage, '/'), 'storage/') ? 'yes' : 'no',
            $publicExists ? 'yes' : 'no',
            $storageExists ? 'yes' : 'no',
            $storagePath ? Storage::disk('public')->url($storagePath) : '-',
            str_contains($imageUrl, '/images/placeholders/') ? 'yes' : 'no',
            $this->reason($mainImage, (bool) $publicExists, (bool) $storageExists),
        ];
    }

    private function reason(string $path, bool $publicExists, bool $storageExists): string
    {
        $normalizedPath = ltrim($path, '/');

        if ($path === '') {
            return 'empty path';
        }

        if (preg_match('/^https?:\/\//i', $path) === 1) {
            return 'remote URL is returned as-is';
        }

        if (str_starts_with($path, '//') || str_contains($path, "\0")) {
            return 'invalid path';
        }

        if (str_starts_with($normalizedPath, 'images/')) {
            return $publicExists ? 'public images file found' : 'public images file missing';
        }

        if (str_starts_with($normalizedPath, 'storage/')) {
            return $publicExists ? 'public storage symlink file found' : 'public storage symlink file missing';
        }

        return $storageExists ? 'public disk file found' : 'public disk file missing';
    }

    private function publicPathFor(string $path): ?string
    {
        $path = ltrim($path, '/');

        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'images/') || str_starts_with($path, 'storage/')) {
            return $path;
        }

        return 'storage/' . $path;
    }

    private function storagePathFor(string $path): ?string
    {
        $path = ltrim($path, '/');

        if ($path === '' || preg_match('/^https?:\/\//i', $path) === 1) {
            return null;
        }

        if (str_starts_with($path, 'storage/')) {
            return substr($path, strlen('storage/'));
        }

        if (str_starts_with($path, 'images/')) {
            return null;
        }

        return $path;
    }
}
