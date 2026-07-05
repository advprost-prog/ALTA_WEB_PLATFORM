<?php

namespace App\Services\Images;

use App\Models\Product;
use Illuminate\Support\Str;

class ProductImageQueryBuilder
{
    /**
     * @return array<int, string>
     */
    public function buildQueries(Product $product): array
    {
        $product->loadMissing(['brand', 'category']);

        $brand = $this->clean((string) $product->brand?->name);
        $name = $this->clean((string) $product->name);
        $nameWithoutBrand = $this->nameWithoutLeadingBrand($name, $brand);
        $sku = $this->clean((string) $product->sku);
        $category = $this->clean((string) $product->category?->name);
        $volume = $this->extractPackSize($product->name);
        $nameWithVolume = $volume !== '' && ! str_contains(Str::lower($nameWithoutBrand), Str::lower($volume))
            ? trim($nameWithoutBrand.' '.$volume)
            : $nameWithoutBrand;
        $categoryHint = $this->categoryHint($category.' '.$name);

        return collect([
            trim($brand.' '.$sku),
            trim($brand.' '.$nameWithoutBrand),
            trim($brand.' '.$nameWithoutBrand.' product image'),
            trim($brand.' '.$sku.' photo'),
            $nameWithVolume,
            trim($nameWithoutBrand.' '.$categoryHint),
            trim($nameWithoutBrand.' упаковка'),
        ])
            ->map(fn (string $query): string => $this->clean($query))
            ->filter(fn (string $query): bool => $query !== '' && mb_strlen($query) >= 3)
            ->unique(fn (string $query): string => Str::lower($query))
            ->take(5)
            ->values()
            ->all();
    }

    private function clean(string $value): string
    {
        $value = preg_replace('/\b(купити|акція|акция|ціна|цена|доставка|alta[\s-]?trade|альта[\s-]?трейд)\b/iu', ' ', $value) ?? $value;
        $value = preg_replace('/[^\pL\pN\.\-\+\/ ]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function extractPackSize(string $name): string
    {
        if (preg_match_all('/\b\d+(?:[\.,]\d+)?\s?(?:l|л|ml|мл|kg|кг|g|гр|ah|аh|v|шт|pcs)\b/iu', $name, $matches) < 1) {
            return '';
        }

        return $this->clean((string) end($matches[0]));
    }

    private function nameWithoutLeadingBrand(string $name, string $brand): string
    {
        if ($brand === '') {
            return $name;
        }

        $pattern = '/^'.preg_quote($brand, '/').'\s+/iu';

        return $this->clean(preg_replace($pattern, '', $name) ?? $name);
    }

    private function categoryHint(string $haystack): string
    {
        $haystack = Str::lower($haystack);

        return match (true) {
            str_contains($haystack, 'олив') || str_contains($haystack, 'oil') => 'bottle',
            str_contains($haystack, 'акум') || str_contains($haystack, 'battery') => 'battery',
            str_contains($haystack, 'фільтр') || str_contains($haystack, 'filter') => 'filter',
            str_contains($haystack, 'ламп') || str_contains($haystack, 'led') || str_contains($haystack, 'h7') => 'headlight',
            str_contains($haystack, 'хім') || str_contains($haystack, 'chem') => 'canister',
            str_contains($haystack, 'інструмент') || str_contains($haystack, 'tool') => 'box',
            default => 'pack',
        };
    }
}
