<?php

namespace App\Services\Catalog;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

class ProductCompletenessService
{
    /**
     * @return array<string, array{label: string, passed: bool, weight: int}>
     */
    public function checks(Product $product): array
    {
        $product->loadMissing(['images', 'specifications']);

        return [
            'name' => ['label' => 'Назва', 'passed' => filled($product->name), 'weight' => 5],
            'slug' => ['label' => 'Slug', 'passed' => filled($product->slug), 'weight' => 5],
            'sku' => ['label' => 'Артикул', 'passed' => filled($product->sku), 'weight' => 5],
            'brand' => ['label' => 'Бренд', 'passed' => filled($product->brand_id), 'weight' => 7],
            'category' => ['label' => 'Категорія', 'passed' => filled($product->category_id), 'weight' => 8],
            'price' => ['label' => 'Ціна', 'passed' => (float) $product->price > 0, 'weight' => 10],
            'stock_status' => ['label' => 'Статус наявності', 'passed' => filled($product->stock_status), 'weight' => 5],
            'stock' => ['label' => 'Залишок', 'passed' => (int) $product->stock > 0, 'weight' => 5],
            'main_image' => ['label' => 'Основне фото', 'passed' => $this->hasRealProductPhoto($product), 'weight' => 10],
            'gallery' => ['label' => 'Галерея', 'passed' => $this->hasRealGalleryImage($product), 'weight' => 5],
            'image_alt_text' => ['label' => 'Alt-текст фото', 'passed' => filled($product->image_alt_text), 'weight' => 5],
            'short_description' => ['label' => 'Короткий опис', 'passed' => filled($product->short_description), 'weight' => 5],
            'description' => ['label' => 'Повний опис', 'passed' => filled($product->description), 'weight' => 5],
            'seo_title' => ['label' => 'SEO title', 'passed' => filled($product->seo_title), 'weight' => 5],
            'seo_description' => ['label' => 'SEO description', 'passed' => filled($product->seo_description), 'weight' => 5],
            'specifications' => ['label' => 'Характеристики', 'passed' => $product->specifications->isNotEmpty(), 'weight' => 10],
        ];
    }

    public function score(Product $product): int
    {
        return (int) collect($this->checks($product))
            ->filter(fn (array $check): bool => $check['passed'])
            ->sum('weight');
    }

    public function status(Product $product): string
    {
        return match (true) {
            $this->score($product) < 40 => 'critical',
            $this->score($product) < 70 => 'warning',
            $this->score($product) < 90 => 'info',
            default => 'success',
        };
    }

    public function statusLabel(Product $product): string
    {
        return match ($this->status($product)) {
            'critical' => 'Погано',
            'warning' => 'Потребує заповнення',
            'info' => 'Майже готово',
            default => 'Готово',
        };
    }

    public function color(Product $product): string
    {
        return match ($this->status($product)) {
            'critical' => 'danger',
            'warning' => 'warning',
            'info' => 'info',
            default => 'success',
        };
    }

    public function missingSummary(Product $product): string
    {
        $missing = collect($this->checks($product))
            ->reject(fn (array $check): bool => $check['passed'])
            ->pluck('label')
            ->take(4)
            ->implode(', ');

        return $missing === '' ? 'Ключові реквізити заповнені' : 'Немає: '.$missing;
    }

    public function applyLowCompletenessScope(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        $scoreSql = implode(' + ', [
            "case when {$table}.name is not null and {$table}.name <> '' then 5 else 0 end",
            "case when {$table}.slug is not null and {$table}.slug <> '' then 5 else 0 end",
            "case when {$table}.sku is not null and {$table}.sku <> '' then 5 else 0 end",
            "case when {$table}.brand_id is not null then 7 else 0 end",
            "case when {$table}.category_id is not null then 8 else 0 end",
            "case when {$table}.price > 0 then 10 else 0 end",
            "case when {$table}.stock_status is not null and {$table}.stock_status <> '' then 5 else 0 end",
            "case when {$table}.stock > 0 then 5 else 0 end",
            "case when ({$table}.main_image is not null and {$table}.main_image <> '' and {$table}.main_image not like '%placeholder%' and {$table}.main_image not like '%product-placeholder%' and {$table}.main_image not like '%images/demo/%') or exists (select 1 from product_images where product_images.product_id = {$table}.id and product_images.image not like '%placeholder%' and product_images.image not like '%product-placeholder%' and product_images.image not like '%images/demo/%') then 10 else 0 end",
            "case when exists (select 1 from product_images where product_images.product_id = {$table}.id and product_images.image not like '%placeholder%' and product_images.image not like '%product-placeholder%' and product_images.image not like '%images/demo/%') then 5 else 0 end",
            "case when {$table}.image_alt_text is not null and {$table}.image_alt_text <> '' then 5 else 0 end",
            "case when {$table}.short_description is not null and {$table}.short_description <> '' then 5 else 0 end",
            "case when {$table}.description is not null and {$table}.description <> '' then 5 else 0 end",
            "case when {$table}.seo_title is not null and {$table}.seo_title <> '' then 5 else 0 end",
            "case when {$table}.seo_description is not null and {$table}.seo_description <> '' then 5 else 0 end",
            "case when exists (select 1 from product_specifications where product_specifications.product_id = {$table}.id) then 10 else 0 end",
        ]);

        return $query->whereRaw("({$scoreSql}) < 50");
    }

    private function hasRealProductPhoto(Product $product): bool
    {
        return $this->hasRealImagePath($product->main_image) || $this->hasRealGalleryImage($product);
    }

    private function hasRealGalleryImage(Product $product): bool
    {
        return $product->images->contains(fn ($image): bool => $this->hasRealImagePath($image->image));
    }

    private function hasRealImagePath(?string $path): bool
    {
        $image = trim((string) $path);

        return $image !== ''
            && ! str_contains($image, 'placeholder')
            && ! str_contains($image, 'product-placeholder')
            && ! str_contains($image, 'images/demo/');
    }
}
