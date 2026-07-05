<?php

namespace App\Services\Commerce;

use App\Models\CommerceSetting;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ProductPricingService
{
    public const SESSION_CURRENCY_ID = 'storefront_currency_id';

    public function settings(): CommerceSetting
    {
        return CommerceSetting::current();
    }

    public function defaultCurrency(): Currency
    {
        $settings = $this->settings();

        return Currency::query()
            ->whereKey($settings->default_currency_id)
            ->first()
            ?? Currency::ensureDefault();
    }

    /**
     * @return Collection<int, Currency>
     */
    public function activeCurrencies(): Collection
    {
        return Currency::query()
            ->where('is_active', true)
            ->orderByDesc('is_base')
            ->orderBy('code')
            ->get();
    }

    public function currencySwitcherVisible(): bool
    {
        return $this->settings()->multi_currency_enabled
            && $this->activeCurrencies()->count() > 1;
    }

    public function currentCurrency(?Request $request = null): Currency
    {
        $request ??= request();
        $settings = $this->settings();
        $defaultCurrency = $this->defaultCurrency();

        if (! $settings->multi_currency_enabled) {
            $request->session()->forget(self::SESSION_CURRENCY_ID);

            return $defaultCurrency;
        }

        $selectedCurrencyId = (int) $request->session()->get(self::SESSION_CURRENCY_ID, $defaultCurrency->id);
        $selectedCurrency = Currency::query()
            ->whereKey($selectedCurrencyId)
            ->where('is_active', true)
            ->first();

        if (! $selectedCurrency) {
            $request->session()->put(self::SESSION_CURRENCY_ID, $defaultCurrency->id);

            return $defaultCurrency;
        }

        return $selectedCurrency;
    }

    public function selectCurrency(int $currencyId, Request $request): Currency
    {
        $settings = $this->settings();
        $defaultCurrency = $this->defaultCurrency();

        if (! $settings->multi_currency_enabled) {
            $request->session()->forget(self::SESSION_CURRENCY_ID);

            return $defaultCurrency;
        }

        $currency = Currency::query()
            ->whereKey($currencyId)
            ->where('is_active', true)
            ->first();

        if (! $currency) {
            $request->session()->put(self::SESSION_CURRENCY_ID, $defaultCurrency->id);

            return $defaultCurrency;
        }

        $request->session()->put(self::SESSION_CURRENCY_ID, $currency->id);

        return $currency;
    }

    public function priceForCurrency(Product $product, Currency|int $currency): ?ProductPrice
    {
        $currencyId = $currency instanceof Currency ? $currency->id : $currency;

        if ($product->relationLoaded('prices')) {
            return $product->prices
                ->first(fn (ProductPrice $price): bool => (int) $price->currency_id === (int) $currencyId
                    && $price->is_active
                    && (float) $price->price > 0
                    && (bool) ($price->currency?->is_active ?? true));
        }

        return ProductPrice::query()
            ->with('currency')
            ->where('product_id', $product->id)
            ->where('currency_id', $currencyId)
            ->where('is_active', true)
            ->where('price', '>', 0)
            ->whereHas('currency', fn ($query) => $query->where('is_active', true))
            ->first();
    }

    public function defaultPrice(Product $product): ?ProductPrice
    {
        return $this->priceForCurrency($product, $this->defaultCurrency());
    }

    /**
     * @return array{price: ?float, compare_at_price: ?float, currency_code: ?string, currency_symbol: ?string, is_fallback_price: bool, is_available_for_selected_currency: bool, has_price: bool, formatted_price: string, formatted_compare_at_price: ?string, fallback_message: ?string, discount_percent: ?int}
     */
    public function priceView(Product $product, ?Currency $currency = null, bool $allowFallback = true): array
    {
        $currency ??= $this->currentCurrency();
        $selectedPrice = $this->priceForCurrency($product, $currency);

        if ($selectedPrice) {
            return $this->formatPriceView($selectedPrice, $currency, false, true);
        }

        $defaultCurrency = $this->defaultCurrency();

        if ($allowFallback && (int) $currency->id !== (int) $defaultCurrency->id) {
            $defaultPrice = $this->defaultPrice($product);

            if ($defaultPrice) {
                return $this->formatPriceView(
                    $defaultPrice,
                    $defaultCurrency,
                    true,
                    false,
                    'Ціна показана у валюті магазину.'
                );
            }
        }

        return [
            'price' => null,
            'compare_at_price' => null,
            'currency_code' => $currency->code,
            'currency_symbol' => $currency->symbol,
            'is_fallback_price' => false,
            'is_available_for_selected_currency' => false,
            'has_price' => false,
            'formatted_price' => 'Ціна уточнюється',
            'formatted_compare_at_price' => null,
            'fallback_message' => (int) $currency->id === (int) $defaultCurrency->id
                ? null
                : 'Ціна недоступна в обраній валюті.',
            'discount_percent' => null,
        ];
    }

    public function checkoutPrice(Product $product, Currency $currency): ?ProductPrice
    {
        return $this->priceForCurrency($product, $currency);
    }

    public function formatAmount(null|float|int|string $amount, null|Currency|string $currency = null): string
    {
        if ($amount === null) {
            return 'Ціна уточнюється';
        }

        $currencyModel = $currency instanceof Currency ? $currency : null;
        $symbol = $currencyModel?->symbol ?: ($currencyModel?->code ?: (is_string($currency) ? $currency : $this->defaultCurrency()->symbol));
        $value = (float) $amount;
        $precision = abs($value - round($value)) < 0.001 ? 0 : ($currencyModel?->precision ?? 2);

        return trim(number_format($value, $precision, ',', ' ') . ' ' . $symbol);
    }

    /**
     * @return array{price: float, compare_at_price: ?float, currency_code: string, currency_symbol: ?string, is_fallback_price: bool, is_available_for_selected_currency: bool, has_price: bool, formatted_price: string, formatted_compare_at_price: ?string, fallback_message: ?string, discount_percent: ?int}
     */
    private function formatPriceView(
        ProductPrice $price,
        Currency $currency,
        bool $isFallback,
        bool $isAvailableForSelectedCurrency,
        ?string $fallbackMessage = null,
    ): array {
        $value = (float) $price->price;
        $compareAtPrice = $price->compare_at_price !== null && (float) $price->compare_at_price > $value
            ? (float) $price->compare_at_price
            : null;

        return [
            'price' => $value,
            'compare_at_price' => $compareAtPrice,
            'currency_code' => $currency->code,
            'currency_symbol' => $currency->symbol,
            'is_fallback_price' => $isFallback,
            'is_available_for_selected_currency' => $isAvailableForSelectedCurrency,
            'has_price' => true,
            'formatted_price' => $this->formatAmount($value, $currency),
            'formatted_compare_at_price' => $compareAtPrice === null ? null : $this->formatAmount($compareAtPrice, $currency),
            'fallback_message' => $fallbackMessage,
            'discount_percent' => $compareAtPrice === null ? null : (int) round((1 - ($value / $compareAtPrice)) * 100),
        ];
    }
}
