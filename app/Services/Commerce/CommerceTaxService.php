<?php

namespace App\Services\Commerce;

use App\Models\ProductPrice;
use App\Models\ProductVariant;

class CommerceTaxService
{
    /**
     * @return array{
     *     unit_name: ?string,
     *     unit_short_name: ?string,
     *     base_unit_id: ?int,
     *     sales_unit_id: ?int,
     *     quantity_in_base_unit: float,
     *     tax_profile_id: ?int,
     *     tax_profile_name: ?string,
     *     tax_profile_code: ?string,
     *     vat_rate: float,
     *     vat_amount: float,
     *     is_excise_applicable: bool,
     *     excise_rate: ?float,
     *     excise_amount: float,
     *     requires_excise_stamp_entry: bool,
     *     price_excluding_tax: float,
     *     price_including_tax: float,
     *     line_total_excluding_tax: float,
     *     line_total_tax_amount: float,
     *     line_total_including_tax: float
     * }
     */
    public function snapshot(ProductVariant $variant, ProductPrice $price, float $quantity): array
    {
        $taxProfile = $variant->taxProfile;
        $baseUnit = $variant->baseUnit;
        $salesUnit = $variant->salesUnit ?? $baseUnit;
        $vatRate = (float) ($taxProfile?->vat_rate ?? 0);
        $priceIncludesTax = (bool) ($taxProfile?->price_includes_tax ?? true);
        $unitSellPrice = round((float) $price->price, 2);
        $quantityInBaseUnit = round($quantity, 3);

        if ($priceIncludesTax) {
            $unitPriceExcludingTax = $vatRate > 0
                ? round($unitSellPrice / (1 + ($vatRate / 100)), 2)
                : $unitSellPrice;
            $unitPriceIncludingTax = $unitSellPrice;
        } else {
            $unitPriceExcludingTax = $unitSellPrice;
            $unitPriceIncludingTax = round($unitSellPrice * (1 + ($vatRate / 100)), 2);
        }

        $lineTotalExcludingTax = round($unitPriceExcludingTax * $quantity, 2);
        $lineTotalIncludingTax = round($unitPriceIncludingTax * $quantity, 2);
        $lineTaxAmount = round($lineTotalIncludingTax - $lineTotalExcludingTax, 2);
        $exciseRate = $variant->is_excise_applicable
            ? (float) ($variant->excise_rate ?? 5.00)
            : null;
        $exciseAmount = $exciseRate === null
            ? 0.0
            : round($lineTotalExcludingTax * ($exciseRate / 100), 2);

        return [
            'unit_name' => $salesUnit?->name,
            'unit_short_name' => $salesUnit?->short_name,
            'base_unit_id' => $baseUnit?->getKey(),
            'sales_unit_id' => $salesUnit?->getKey(),
            'quantity_in_base_unit' => $quantityInBaseUnit,
            'tax_profile_id' => $taxProfile?->getKey(),
            'tax_profile_name' => $taxProfile?->name,
            'tax_profile_code' => $taxProfile?->code,
            'vat_rate' => $vatRate,
            'vat_amount' => $lineTaxAmount,
            'is_excise_applicable' => $variant->is_excise_applicable,
            'excise_rate' => $exciseRate,
            'excise_amount' => $exciseAmount,
            'requires_excise_stamp_entry' => $variant->is_excise_applicable && $variant->requires_excise_stamp_entry,
            'price_excluding_tax' => $unitPriceExcludingTax,
            'price_including_tax' => $unitPriceIncludingTax,
            'line_total_excluding_tax' => $lineTotalExcludingTax,
            'line_total_tax_amount' => $lineTaxAmount,
            'line_total_including_tax' => $lineTotalIncludingTax,
        ];
    }
}