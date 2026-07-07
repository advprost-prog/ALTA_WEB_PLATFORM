<?php

namespace App\Services\Commerce;

use App\Enums\DeliveryStatus;
use App\Enums\OrderNotificationEvent;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Currency;
use App\Models\DeliveryMethod;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CheckoutService
{
    public const MAX_CART_QUANTITY = 99;

    private const PAYMENT_METHOD_ALIASES = [
        'Післяплата' => PaymentMethod::CASH_ON_DELIVERY,
        'Банківський переказ' => PaymentMethod::BANK_TRANSFER,
        'Безготівковий рахунок' => PaymentMethod::BANK_TRANSFER,
        'Готівка' => PaymentMethod::CASH,
    ];

    private const DELIVERY_METHOD_ALIASES = [
        'Нова пошта' => DeliveryMethod::NOVA_POSHTA,
        'Самовивіз' => DeliveryMethod::PICKUP,
        'Кур’єрська доставка' => DeliveryMethod::COURIER,
        'Курʼєрська доставка' => DeliveryMethod::COURIER,
        'Кур’єр' => DeliveryMethod::COURIER,
        'Курʼєр' => DeliveryMethod::COURIER,
        'Кур\'єр' => DeliveryMethod::COURIER,
    ];

    public function __construct(
        private readonly ProductPricingService $pricingService,
        private readonly ProductAvailabilityService $availabilityService,
        private readonly FulfillmentService $fulfillmentService,
        private readonly StockService $stockService,
        private readonly OrderLifecycleService $orderLifecycleService,
        private readonly OrderNotificationService $orderNotificationService,
        private readonly CustomerService $customerService,
        private readonly CommerceTaxService $taxService,
    ) {}

    /**
     * @return array{cart: array<string|int, int>, status: string}
     */
    public function addToCart(Product $product, int $quantity, array $cart, ?int $variantId = null): array
    {
        $product->loadMissing(['defaultVariant.prices.currency', 'defaultVariant.stockBalances.warehouse', 'variants']);
        $variant = $this->resolveCartVariant($product, $variantId);

        if (! $variant || ! $variant->is_active) {
            return [
                'cart' => $cart,
                'status' => 'Для цього товару не налаштовано активний SKU.',
            ];
        }

        if ($quantity <= 0) {
            return [
                'cart' => $cart,
                'status' => 'Оберіть коректну кількість товару.',
            ];
        }

        $currency = $this->pricingService->currentCurrency();

        if (! $this->pricingService->checkoutPrice($variant, $currency)) {
            return [
                'cart' => $cart,
                'status' => 'Для цього товару немає ціни в обраній валюті.',
            ];
        }

        $maxQuantity = min($this->availabilityService->maxPurchasableQuantity($variant), self::MAX_CART_QUANTITY);

        if ($maxQuantity < 1) {
            return [
                'cart' => $cart,
                'status' => 'Цей товар зараз недоступний для замовлення.',
            ];
        }

        $cart = $this->sanitizeCart($cart, capQuantities: false);
        $cartKey = $this->cartKey($product->id, $variant->id);
        $currentQuantity = (int) ($cart[$cartKey] ?? 0);
        $requestedQuantity = min($quantity, self::MAX_CART_QUANTITY);
        $newQuantity = min($currentQuantity + $requestedQuantity, $maxQuantity);
        $cart[$cartKey] = $newQuantity;

        return [
            'cart' => $cart,
            'status' => $newQuantity < $currentQuantity + $requestedQuantity
                ? 'Кількість у кошику обмежено доступним залишком.'
                : 'Товар додано до кошика.',
        ];
    }

    /**
      * @return array{items: Collection<int, array<string, mixed>>, subtotal: float, total: float, currency: Currency, can_checkout: bool, messages: array<int, string>, cart: array<string|int, int>}
     */
    public function cartPayload(array $cart): array
    {
          $cart = $this->sanitizeCart($cart, capQuantities: false);
        $currency = $this->pricingService->currentCurrency();

        if ($cart === []) {
            return [
                'items' => collect(),
                'subtotal' => 0.0,
                'total' => 0.0,
                'currency' => $currency,
                'can_checkout' => false,
                'messages' => [],
                'cart' => [],
            ];
        }

        $normalized = $this->normalizeCart($cart);
        $products = Product::query()
            ->active()
            ->whereIn('id', array_values(array_unique(array_column($normalized, 'product_id'))))
            ->with($this->productRelations())
            ->get()
            ->keyBy('id');
        $variants = ProductVariant::query()
            ->whereIn('id', array_values(array_filter(array_unique(array_column($normalized, 'variant_id')))))
            ->with(['product.brand', 'product.category', 'prices.currency', 'stockBalances.warehouse', 'baseUnit', 'salesUnit', 'taxProfile'])
            ->get()
            ->keyBy('id');

        $messages = [];

        $items = collect($normalized)
            ->map(function (array $entry) use ($products, $variants, $currency, &$messages): ?array {
                /** @var Product|null $product */
                $product = $products->get($entry['product_id']);

                if (! $product) {
                    return null;
                }

                /** @var ProductVariant|null $variant */
                $variant = $entry['variant_id'] ? $variants->get($entry['variant_id']) : $this->resolveCartVariant($product, null);

                if (! $variant) {
                    $messages[] = 'У кошику є товар без активного SKU.';

                    return null;
                }

                $quantity = $entry['quantity'];
                $availability = $this->availabilityService->availabilityView($variant, $quantity);
                $displayPrice = $this->pricingService->priceView($variant, $currency, allowFallback: true);
                $checkoutPrice = $this->pricingService->checkoutPrice($variant, $currency);
                $maxQuantity = min($availability['max_quantity'], self::MAX_CART_QUANTITY);
                $safeQuantity = $quantity;
                $unitPrice = $checkoutPrice ? (float) $checkoutPrice->price : null;
                $lineTotal = $unitPrice === null ? 0.0 : round($unitPrice * $safeQuantity, 2);
                $canCheckout = $safeQuantity > 0
                    && $safeQuantity <= $maxQuantity
                    && $availability['is_available']
                    && $checkoutPrice !== null;

                if (! $checkoutPrice) {
                    $messages[] = 'У кошику є товар без ціни в обраній валюті.';
                }

                if ($safeQuantity > $maxQuantity || ! $availability['is_available']) {
                    $messages[] = 'У кошику є товар з недостатнім залишком.';
                }

                return [
                    'product' => $product,
                    'variant' => $variant,
                    'cart_key' => $entry['cart_key'],
                    'quantity' => $safeQuantity,
                    'max_quantity' => $maxQuantity,
                    'unit_price' => $unitPrice,
                    'price_view' => $displayPrice,
                    'availability' => $availability,
                    'line_total' => $lineTotal,
                    'formatted_unit_price' => $unitPrice === null
                        ? $displayPrice['formatted_price']
                        : $this->pricingService->formatAmount($unitPrice, $currency),
                    'formatted_line_total' => $unitPrice === null
                        ? '-'
                        : $this->pricingService->formatAmount($lineTotal, $currency),
                    'can_checkout' => $canCheckout,
                ];
            })
            ->filter()
            ->values();

        $subtotal = (float) $items->sum('line_total');
        $canCheckout = $items->isNotEmpty()
            && $items->every(fn (array $item): bool => (bool) $item['can_checkout']);

        return [
            'items' => $items,
            'subtotal' => $subtotal,
            'total' => $subtotal,
            'currency' => $currency,
            'can_checkout' => $canCheckout,
            'messages' => array_values(array_unique($messages)),
            'cart' => $cart,
        ];
    }

    /**
     * @param  array<int|string, int|string>  $cart
     * @return array<string|int, int>
     */
    public function sanitizeCart(array $cart, bool $capQuantities = true): array
    {
        $requested = $this->normalizeCart($cart);

        if ($requested === []) {
            return [];
        }

        $products = Product::query()
            ->active()
            ->whereIn('id', array_values(array_unique(array_column($requested, 'product_id'))))
            ->with(['defaultVariant.stockBalances.warehouse', 'variants'])
            ->get()
            ->keyBy('id');

        $variants = ProductVariant::query()
            ->whereIn('id', array_values(array_filter(array_unique(array_column($requested, 'variant_id')))))
            ->with(['stockBalances.warehouse'])
            ->get()
            ->keyBy('id');

        return collect($requested)
            ->mapWithKeys(function (array $entry) use ($products, $variants, $capQuantities): array {
                /** @var Product|null $product */
                $product = $products->get($entry['product_id']);

                if (! $product) {
                    return [];
                }

                /** @var ProductVariant|null $variant */
                $variant = $entry['variant_id'] ? $variants->get($entry['variant_id']) : $this->resolveCartVariant($product, null);

                if (! $variant || ! $variant->is_active) {
                    return [];
                }

                $maxQuantity = min($this->availabilityService->maxPurchasableQuantity($variant), self::MAX_CART_QUANTITY);

                if ($maxQuantity < 1) {
                    return [];
                }

                return [
                    $this->cartKey($product->id, $variant->id) => $capQuantities
                        ? min($entry['quantity'], $maxQuantity)
                        : $entry['quantity'],
                ];
            })
            ->filter()
            ->all();
    }

    /**
     * @param  array<string, string|null>  $validated
     */
    public function placeOrder(array $cart, array $validated): Order
    {
        $order = DB::transaction(function () use ($cart, $validated): Order {
            $requested = $this->normalizeCart($cart);

            if ($requested === []) {
                throw new RuntimeException('Кошик порожній.');
            }

            $currency = $this->lockedCurrentCurrency();
            $products = Product::query()
                ->whereIn('id', array_values(array_unique(array_column($requested, 'product_id'))))
                ->with(['prices.currency', 'defaultVariant.taxProfile', 'defaultVariant.baseUnit', 'defaultVariant.salesUnit', 'variants'])
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $variants = ProductVariant::query()
                ->whereIn('id', array_values(array_filter(array_unique(array_column($requested, 'variant_id')))))
                ->with(['taxProfile', 'baseUnit', 'salesUnit', 'product'])
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lines = collect($requested)
                ->map(function (array $entry) use ($products, $variants, $currency): array {
                    /** @var Product|null $product */
                    $product = $products->get($entry['product_id']);

                    /** @var ProductVariant|null $variant */
                    $variant = $entry['variant_id'] ? $variants->get($entry['variant_id']) : ($product?->resolveDefaultVariant());

                    if (! $product || ! $variant || ! $this->availabilityService->productCanBeOrdered($variant)) {
                        throw new RuntimeException('Один із товарів більше недоступний.');
                    }

                    $price = $this->pricingService->checkoutPrice($variant, $currency);

                    if (! $price) {
                        throw new RuntimeException('У кошику є товар без ціни в обраній валюті.');
                    }

                    $quantity = $entry['quantity'];
                    $warehouse = $this->fulfillmentService->resolveWarehouse($variant, $quantity, lockForUpdate: true);
                    $taxSnapshot = $this->taxService->snapshot($variant, $price, $quantity);

                    return [
                        'product' => $product,
                        'variant' => $variant,
                        'quantity' => $quantity,
                        'price' => $price,
                        'tax_snapshot' => $taxSnapshot,
                        'warehouse' => $warehouse,
                        'total' => (float) $taxSnapshot['line_total_including_tax'],
                    ];
                })
                ->values();

            if ($lines->isEmpty()) {
                throw new RuntimeException('Кошик порожній.');
            }

            /** @var Warehouse $primaryWarehouse */
            $primaryWarehouse = $lines->first()['warehouse'];
            $paymentMethod = $this->resolvePaymentMethod((string) $validated['payment_method']);
            $deliveryMethod = $this->resolveDeliveryMethod((string) $validated['delivery_method']);
            $customer = $this->customerService->resolveFromCheckout($validated + [
                'delivery_method_id' => $deliveryMethod->id,
            ]);

            $order = Order::create([
                'customer_id' => $customer->id,
                'currency_id' => $currency->id,
                'currency_code' => $currency->code,
                'exchange_rate_to_base' => $currency->rate_to_base,
                'warehouse_id' => $primaryWarehouse->id,
                'customer_name' => $validated['name'],
                'phone' => $validated['phone'],
                'email' => $validated['email'] ?? null,
                'city' => $validated['city'] ?? null,
                'address' => $validated['address'] ?? null,
                'total_amount' => $lines->sum('total'),
                'status' => OrderStatus::New->value,
                'payment_status' => $this->initialPaymentStatus($paymentMethod)->value,
                'delivery_status' => DeliveryStatus::Pending->value,
                'delivery_method' => $deliveryMethod->code,
                'delivery_method_id' => $deliveryMethod->id,
                'delivery_method_name' => $deliveryMethod->name,
                'payment_method' => $paymentMethod->code,
                'payment_method_id' => $paymentMethod->id,
                'payment_method_name' => $paymentMethod->name,
                'customer_comment' => $validated['customer_comment'] ?? null,
            ]);

            foreach ($lines as $line) {
                /** @var Product $product */
                $product = $line['product'];
                /** @var ProductVariant $variant */
                $variant = $line['variant'];
                /** @var ProductPrice $price */
                $price = $line['price'];
                /** @var Warehouse $warehouse */
                $warehouse = $line['warehouse'];
                $taxSnapshot = $line['tax_snapshot'];

                $order->items()->create([
                    'product_id' => $product->id,
                    'product_variant_id' => $variant->id,
                    'product_name' => $product->name,
                    'sku' => $variant->sku ?: $product->sku,
                    'unit_name' => $taxSnapshot['unit_name'],
                    'unit_short_name' => $taxSnapshot['unit_short_name'],
                    'base_unit_id' => $taxSnapshot['base_unit_id'],
                    'sales_unit_id' => $taxSnapshot['sales_unit_id'],
                    'quantity' => $line['quantity'],
                    'quantity_in_base_unit' => $taxSnapshot['quantity_in_base_unit'],
                    'warehouse_id' => $warehouse->id,
                    'tax_profile_id' => $taxSnapshot['tax_profile_id'],
                    'tax_profile_name' => $taxSnapshot['tax_profile_name'],
                    'tax_profile_code' => $taxSnapshot['tax_profile_code'],
                    'vat_rate' => $taxSnapshot['vat_rate'],
                    'vat_amount' => $taxSnapshot['vat_amount'],
                    'is_excise_applicable' => $taxSnapshot['is_excise_applicable'],
                    'excise_rate' => $taxSnapshot['excise_rate'],
                    'excise_amount' => $taxSnapshot['excise_amount'],
                    'requires_excise_stamp_entry' => $taxSnapshot['requires_excise_stamp_entry'],
                    'unit_price' => $price->price,
                    'price_excluding_tax' => $taxSnapshot['price_excluding_tax'],
                    'price_including_tax' => $taxSnapshot['price_including_tax'],
                    'price' => $price->price,
                    'total' => $line['total'],
                    'line_total_excluding_tax' => $taxSnapshot['line_total_excluding_tax'],
                    'line_total_tax_amount' => $taxSnapshot['line_total_tax_amount'],
                    'line_total_including_tax' => $taxSnapshot['line_total_including_tax'],
                ]);

                $this->stockService->applyDelta(
                    subject: $variant,
                    warehouseId: $warehouse->id,
                    delta: -1 * (float) $line['quantity'],
                    type: StockMovement::TYPE_SALE,
                    note: 'Storefront checkout',
                    related: $order,
                );

                $freshVariant = $variant->fresh(['stockBalances.warehouse', 'product']);

                if ($freshVariant && $this->availabilityService->maxPurchasableQuantity($freshVariant) < 1) {
                    $freshVariant->product?->forceFill(['stock_status' => 'out_of_stock'])->save();
                }
            }

            $this->orderLifecycleService->recordSystemEvent($order, 'Замовлення створено');

            return $order;
        });

        $this->orderNotificationService->queueOrderNotification($order, OrderNotificationEvent::OrderCreated);

        return $order;
    }

    /**
     * @return array<int, string>
     */
    public function productRelations(): array
    {
        return [
            'brand',
            'category',
            'specifications',
            'prices.currency',
            'stockBalances.warehouse',
            'defaultVariant.prices.currency',
            'defaultVariant.stockBalances.warehouse',
            'defaultVariant.baseUnit',
            'defaultVariant.salesUnit',
            'defaultVariant.taxProfile',
            'variants',
        ];
    }

    /**
     * @return Collection<int, PaymentMethod>
     */
    public function activePaymentMethods(): Collection
    {
        return PaymentMethod::query()->active()->ordered()->get();
    }

    /**
     * @return Collection<int, DeliveryMethod>
     */
    public function activeDeliveryMethods(): Collection
    {
        return DeliveryMethod::query()->active()->ordered()->get();
    }

    /**
     * @param  array<int|string, int|string>  $cart
     * @return array<int, array{cart_key: string|int, product_id: int, variant_id: ?int, quantity: int}>
     */
    private function normalizeCart(array $cart): array
    {
        return collect($cart)
            ->map(function ($quantity, $rawKey): ?array {
                $quantity = max(0, min((int) $quantity, self::MAX_CART_QUANTITY));

                if ($quantity < 1) {
                    return null;
                }

                $key = is_int($rawKey) ? (string) $rawKey : trim((string) $rawKey);

                if (preg_match('/^variant:(\d+)$/', $key, $matches) === 1) {
                    return [
                        'cart_key' => $key,
                        'product_id' => 0,
                        'variant_id' => (int) $matches[1],
                        'quantity' => $quantity,
                    ];
                }

                return [
                    'cart_key' => $key,
                    'product_id' => (int) $key,
                    'variant_id' => null,
                    'quantity' => $quantity,
                ];
            })
            ->filter()
            ->map(function (array $entry): array {
                if ($entry['variant_id']) {
                    $productId = (int) ProductVariant::query()->whereKey($entry['variant_id'])->value('product_id');
                    $entry['product_id'] = $productId;

                    return $entry;
                }

                return $entry;
            })
            ->all();
    }

    private function resolveCartVariant(Product $product, ?int $variantId): ?ProductVariant
    {
        if ($variantId) {
            return $product->variants()->whereKey($variantId)->where('is_active', true)->first();
        }

        return $product->resolveDefaultVariant();
    }

    private function cartKey(int $productId, int $variantId): string
    {
        $variant = ProductVariant::query()->find($variantId);

        if ($variant?->is_default) {
            return (string) $productId;
        }

        return 'variant:'.$variantId;
    }

    /**
     * @param  array<string|int, int>  $cart
     * @return array<string|int, int>
     */
    public function removeProductEntries(array $cart, Product $product): array
    {
        foreach ($this->normalizeCart($cart) as $entry) {
            if ((int) $entry['product_id'] !== (int) $product->id) {
                continue;
            }

            unset($cart[$entry['cart_key']]);
        }

        unset($cart[$product->id]);

        return $cart;
    }

    private function lockedCurrentCurrency(): Currency
    {
        $currentCurrency = $this->pricingService->currentCurrency();
        $currency = Currency::query()
            ->whereKey($currentCurrency->id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if (! $currency) {
            throw new RuntimeException('Обрана валюта більше недоступна.');
        }

        return $currency;
    }

    private function resolvePaymentMethod(string $value): PaymentMethod
    {
        $value = trim($value);
        $aliasCode = self::PAYMENT_METHOD_ALIASES[$value] ?? null;

        $method = PaymentMethod::query()
            ->active()
            ->where(function ($query) use ($value, $aliasCode): void {
                $query->where('code', $value)
                    ->orWhere('name', $value);

                if ($aliasCode) {
                    $query->orWhere('code', $aliasCode);
                }

                if (ctype_digit($value)) {
                    $query->orWhereKey((int) $value);
                }
            })
            ->first();

        if (! $method) {
            throw new RuntimeException('Обраний спосіб оплати недоступний.');
        }

        return $method;
    }

    private function resolveDeliveryMethod(string $value): DeliveryMethod
    {
        $value = trim($value);
        $aliasCode = self::DELIVERY_METHOD_ALIASES[$value] ?? null;

        $method = DeliveryMethod::query()
            ->active()
            ->where(function ($query) use ($value, $aliasCode): void {
                $query->where('code', $value)
                    ->orWhere('name', $value);

                if ($aliasCode) {
                    $query->orWhere('code', $aliasCode);
                }

                if (ctype_digit($value)) {
                    $query->orWhereKey((int) $value);
                }
            })
            ->first();

        if (! $method) {
            throw new RuntimeException('Обраний спосіб доставки недоступний.');
        }

        return $method;
    }

    private function initialPaymentStatus(PaymentMethod $paymentMethod): PaymentStatus
    {
        return match ($paymentMethod->code) {
            PaymentMethod::BANK_TRANSFER => PaymentStatus::Pending,
            default => PaymentStatus::Unpaid,
        };
    }
}
