<?php

namespace App\Services\Commerce;

use App\Models\Currency;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CheckoutService
{
    public const MAX_CART_QUANTITY = 99;

    public function __construct(
        private readonly ProductPricingService $pricingService,
        private readonly ProductAvailabilityService $availabilityService,
        private readonly FulfillmentService $fulfillmentService,
        private readonly StockService $stockService,
    ) {}

    /**
     * @return array{cart: array<int, int>, status: string}
     */
    public function addToCart(Product $product, int $quantity, array $cart): array
    {
        $product->loadMissing(['prices.currency', 'stockBalances.warehouse']);

        if ($quantity <= 0) {
            return [
                'cart' => $cart,
                'status' => 'Оберіть коректну кількість товару.',
            ];
        }

        $currency = $this->pricingService->currentCurrency();

        if (! $this->pricingService->checkoutPrice($product, $currency)) {
            return [
                'cart' => $cart,
                'status' => 'Для цього товару немає ціни в обраній валюті.',
            ];
        }

        $maxQuantity = min($this->availabilityService->maxPurchasableQuantity($product), self::MAX_CART_QUANTITY);

        if ($maxQuantity < 1) {
            return [
                'cart' => $cart,
                'status' => 'Цей товар зараз недоступний для замовлення.',
            ];
        }

        $currentQuantity = (int) ($cart[$product->id] ?? 0);
        $requestedQuantity = min($quantity, self::MAX_CART_QUANTITY);
        $newQuantity = min($currentQuantity + $requestedQuantity, $maxQuantity);
        $cart[$product->id] = $newQuantity;

        return [
            'cart' => $cart,
            'status' => $newQuantity < $currentQuantity + $requestedQuantity
                ? 'Кількість у кошику обмежено доступним залишком.'
                : 'Товар додано до кошика.',
        ];
    }

    /**
     * @return array{items: Collection<int, array<string, mixed>>, subtotal: float, total: float, currency: Currency, can_checkout: bool, messages: array<int, string>, cart: array<int, int>}
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

        $products = Product::query()
            ->active()
            ->whereIn('id', array_keys($cart))
            ->with($this->productRelations())
            ->get()
            ->keyBy('id');

        $messages = [];

        $items = collect($cart)
            ->map(function (int $quantity, int $productId) use ($products, $currency, &$messages): ?array {
                /** @var Product|null $product */
                $product = $products->get($productId);

                if (! $product) {
                    return null;
                }

                $availability = $this->availabilityService->availabilityView($product, $quantity);
                $displayPrice = $this->pricingService->priceView($product, $currency, allowFallback: true);
                $checkoutPrice = $this->pricingService->checkoutPrice($product, $currency);
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
     * @return array<int, int>
     */
    public function sanitizeCart(array $cart, bool $capQuantities = true): array
    {
        $requested = $this->normalizeCart($cart);

        if ($requested === []) {
            return [];
        }

        $products = Product::query()
            ->active()
            ->whereIn('id', array_keys($requested))
            ->with(['stockBalances.warehouse'])
            ->get()
            ->keyBy('id');

        return collect($requested)
            ->mapWithKeys(function (int $quantity, int $productId) use ($products, $capQuantities): array {
                /** @var Product|null $product */
                $product = $products->get($productId);

                if (! $product) {
                    return [];
                }

                $maxQuantity = min($this->availabilityService->maxPurchasableQuantity($product), self::MAX_CART_QUANTITY);

                if ($maxQuantity < 1) {
                    return [];
                }

                return [$productId => $capQuantities ? min($quantity, $maxQuantity) : $quantity];
            })
            ->filter()
            ->all();
    }

    /**
     * @param  array<string, string|null>  $validated
     */
    public function placeOrder(array $cart, array $validated): Order
    {
        return DB::transaction(function () use ($cart, $validated): Order {
            $requested = $this->normalizeCart($cart);

            if ($requested === []) {
                throw new RuntimeException('Кошик порожній.');
            }

            $currency = $this->lockedCurrentCurrency();
            $products = Product::query()
                ->whereIn('id', array_keys($requested))
                ->with(['prices.currency'])
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lines = collect($requested)
                ->map(function (int $quantity, int $productId) use ($products, $currency): array {
                    /** @var Product|null $product */
                    $product = $products->get($productId);

                    if (! $product || ! $this->availabilityService->productCanBeOrdered($product)) {
                        throw new RuntimeException('Один із товарів більше недоступний.');
                    }

                    $price = $this->pricingService->checkoutPrice($product, $currency);

                    if (! $price) {
                        throw new RuntimeException('У кошику є товар без ціни в обраній валюті.');
                    }

                    $warehouse = $this->fulfillmentService->resolveWarehouse($product, $quantity, lockForUpdate: true);

                    return [
                        'product' => $product,
                        'quantity' => $quantity,
                        'price' => $price,
                        'warehouse' => $warehouse,
                        'total' => round((float) $price->price * $quantity, 2),
                    ];
                })
                ->values();

            if ($lines->isEmpty()) {
                throw new RuntimeException('Кошик порожній.');
            }

            $customer = Customer::updateOrCreate(
                ['phone' => $validated['phone']],
                [
                    'name' => $validated['name'],
                    'email' => $validated['email'] ?? null,
                    'city' => $validated['city'] ?? null,
                    'address' => $validated['address'] ?? null,
                ],
            );

            /** @var Warehouse $primaryWarehouse */
            $primaryWarehouse = $lines->first()['warehouse'];

            $order = Order::create([
                'customer_id' => $customer->id,
                'currency_id' => $currency->id,
                'currency_code' => $currency->code,
                'exchange_rate_to_base' => $currency->rate_to_base,
                'warehouse_id' => $primaryWarehouse->id,
                'customer_name' => $validated['name'],
                'phone' => $validated['phone'],
                'email' => $validated['email'] ?? null,
                'total_amount' => $lines->sum('total'),
                'status' => 'new',
                'delivery_method' => $validated['delivery_method'],
                'payment_method' => $validated['payment_method'],
                'customer_comment' => $validated['customer_comment'] ?? null,
            ]);

            foreach ($lines as $line) {
                /** @var Product $product */
                $product = $line['product'];
                /** @var ProductPrice $price */
                $price = $line['price'];
                /** @var Warehouse $warehouse */
                $warehouse = $line['warehouse'];

                $order->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'quantity' => $line['quantity'],
                    'warehouse_id' => $warehouse->id,
                    'unit_price' => $price->price,
                    'price' => $price->price,
                    'total' => $line['total'],
                ]);

                $this->stockService->applyDelta(
                    product: $product,
                    warehouseId: $warehouse->id,
                    delta: -1 * (float) $line['quantity'],
                    type: StockMovement::TYPE_SALE,
                    note: 'Storefront checkout',
                    related: $order,
                );

                $freshProduct = $product->fresh(['stockBalances.warehouse']);

                if ($freshProduct && $this->availabilityService->maxPurchasableQuantity($freshProduct) < 1) {
                    $freshProduct->forceFill(['stock_status' => 'out_of_stock'])->save();
                }
            }

            return $order;
        });
    }

    /**
     * @return array<int, string>
     */
    public function productRelations(): array
    {
        return ['brand', 'category', 'specifications', 'prices.currency', 'stockBalances.warehouse'];
    }

    /**
     * @param  array<int|string, int|string>  $cart
     * @return array<int, int>
     */
    private function normalizeCart(array $cart): array
    {
        return collect($cart)
            ->mapWithKeys(fn ($quantity, $productId): array => [(int) $productId => max(0, min((int) $quantity, self::MAX_CART_QUANTITY))])
            ->filter()
            ->all();
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
}
