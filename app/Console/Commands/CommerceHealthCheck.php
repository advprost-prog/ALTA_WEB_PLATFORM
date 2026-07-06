<?php

namespace App\Console\Commands;

use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\CommerceSetting;
use App\Models\Currency;
use App\Models\DeliveryMethod;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class CommerceHealthCheck extends Command
{
    protected $signature = 'commerce:health-check {--json : Output machine-readable JSON}';

    protected $description = 'Report commerce configuration, stock, pricing, and checkout health without changing data.';

    public function handle(): int
    {
        $report = $this->buildReport();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $report['critical_count'] > 0 ? self::FAILURE : self::SUCCESS;
        }

        $this->renderReport($report);

        return $report['critical_count'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReport(): array
    {
        $settingsCount = CommerceSetting::query()->count();
        $settings = CommerceSetting::query()->orderBy('id')->first();
        $defaultCurrency = $settings?->default_currency_id
            ? Currency::query()->find($settings->default_currency_id)
            : null;
        $defaultWarehouse = $settings?->default_warehouse_id
            ? Warehouse::query()->find($settings->default_warehouse_id)
            : null;

        $criticalIssues = array_values(array_filter([
            $this->issue(
                $settingsCount === 0,
                'settings_missing',
                'Немає жодного запису налаштувань магазину.',
            ),
            $this->issue(
                $settingsCount > 1,
                'settings_multiple',
                'Знайдено кілька записів налаштувань магазину. Використовується перший за ID.',
                $this->sampleList($this->ids(CommerceSetting::query()->orderBy('id')->limit(10)->get())),
            ),
            $this->issue(
                ! $settings || ! $settings->default_currency_id,
                'default_currency_missing',
                'У налаштуваннях не задано валюту за замовчуванням.',
            ),
            $this->issue(
                ! $settings || ! $settings->default_warehouse_id,
                'default_warehouse_missing',
                'У налаштуваннях не задано склад за замовчуванням.',
            ),
            $this->issue(
                (bool) $defaultCurrency && ! $defaultCurrency->is_active,
                'default_currency_inactive',
                'Валюта за замовчуванням існує, але неактивна.',
                $defaultCurrency ? [$this->entityLabel($defaultCurrency)] : [],
            ),
            $this->issue(
                (bool) $defaultWarehouse && ! $defaultWarehouse->is_active,
                'default_warehouse_inactive',
                'Склад за замовчуванням існує, але неактивний.',
                $defaultWarehouse ? [$this->entityLabel($defaultWarehouse)] : [],
            ),
            $this->issue(
                (int) Currency::query()->where('is_base', true)->count() !== 1,
                'base_currency_count_mismatch',
                'Має бути рівно одна базова валюта.',
                $this->sampleList($this->ids(Currency::query()->where('is_base', true)->limit(10)->get())),
            ),
            $this->issue(
                (int) Warehouse::query()->where('is_default', true)->count() !== 1,
                'default_warehouse_count_mismatch',
                'Має бути рівно один склад за замовчуванням.',
                $this->sampleList($this->ids(Warehouse::query()->where('is_default', true)->limit(10)->get())),
            ),
        ]));

        if ($settings?->default_currency_id) {
            $productsWithoutDefaultPrice = Product::query()
                ->orderBy('id')
                ->whereDoesntHave('prices', fn ($query) => $query->where('currency_id', $settings->default_currency_id))
                ->limit(20)
                ->get();

            $criticalIssues[] = $this->issue(
                $productsWithoutDefaultPrice->isNotEmpty(),
                'products_missing_default_price',
                'Є товари без ціни у валюті за замовчуванням.',
                $this->sampleList($this->productsList($productsWithoutDefaultPrice)),
            );
        }

        if ($settings?->default_warehouse_id) {
            $productsWithoutDefaultStock = Product::query()
                ->orderBy('id')
                ->whereDoesntHave('stockBalances', fn ($query) => $query->where('warehouse_id', $settings->default_warehouse_id))
                ->limit(20)
                ->get();

            $criticalIssues[] = $this->issue(
                $productsWithoutDefaultStock->isNotEmpty(),
                'products_missing_default_stock_balance',
                'Є товари без залишку на складі за замовчуванням.',
                $this->sampleList($this->productsList($productsWithoutDefaultStock)),
            );
        }

        $invalidProductPrices = ProductPrice::query()
            ->with(['product:id,name,sku', 'currency:id,code,is_active'])
            ->orderBy('id')
            ->get()
            ->filter(fn (ProductPrice $price): bool => ! $price->currency || ! $price->currency->is_active)
            ->values();

        $criticalIssues[] = $this->issue(
            $invalidProductPrices->isNotEmpty(),
            'product_prices_invalid_currency',
            'Є ціни товарів, що посилаються на неіснуючу або неактивну валюту.',
            $this->sampleList($invalidProductPrices->take(10)->map(fn (ProductPrice $price): string => $this->entityLabel($price, [
                'product' => $price->product?->name,
                'currency' => $price->currency?->code ?? 'missing',
            ]))),
        );

        $invalidStockBalances = StockBalance::query()
            ->with(['product:id,name,sku', 'warehouse:id,name,is_active'])
            ->orderBy('id')
            ->get()
            ->filter(fn (StockBalance $balance): bool => ! $balance->warehouse || ! $balance->warehouse->is_active)
            ->values();

        $criticalIssues[] = $this->issue(
            $invalidStockBalances->isNotEmpty(),
            'stock_balances_invalid_warehouse',
            'Є залишки, що посилаються на неіснуючий або неактивний склад.',
            $this->sampleList($invalidStockBalances->take(10)->map(fn (StockBalance $balance): string => $this->entityLabel($balance, [
                'product' => $balance->product?->name,
                'warehouse' => $balance->warehouse?->name ?? 'missing',
            ]))),
        );

        $quantityBelowReserved = StockBalance::query()
            ->with(['product:id,name,sku', 'warehouse:id,name'])
            ->whereColumn('quantity', '<', 'reserved_quantity')
            ->orderBy('id')
            ->get();

        $criticalIssues[] = $this->issue(
            $quantityBelowReserved->isNotEmpty(),
            'stock_balances_below_reserved',
            'Є залишки, де доступна кількість нижча за зарезервовану.',
            $this->sampleList($quantityBelowReserved->take(10)->map(fn (StockBalance $balance): string => $this->entityLabel($balance, [
                'product' => $balance->product?->name,
                'warehouse' => $balance->warehouse?->name,
                'quantity' => (string) $balance->quantity,
                'reserved' => (string) $balance->reserved_quantity,
            ]))),
        );

        $negativeAvailable = StockBalance::query()
            ->with(['product:id,name,sku', 'warehouse:id,name'])
            ->whereRaw('(quantity - reserved_quantity) < 0')
            ->orderBy('id')
            ->get();

        $criticalIssues[] = $this->issue(
            $negativeAvailable->isNotEmpty(),
            'stock_balances_negative_available',
            'Є залишки з від’ємною доступною кількістю.',
            $this->sampleList($negativeAvailable->take(10)->map(fn (StockBalance $balance): string => $this->entityLabel($balance, [
                'product' => $balance->product?->name,
                'warehouse' => $balance->warehouse?->name,
                'available' => (string) $balance->available_quantity,
            ]))),
        );

        $ordersMissingSnapshots = Order::query()
            ->orderBy('id')
            ->get()
            ->filter(fn (Order $order): bool => ! $order->currency_id || ! $order->currency_code)
            ->values();

        $criticalIssues[] = $this->issue(
            $ordersMissingSnapshots->isNotEmpty(),
            'orders_missing_currency_snapshot',
            'Є замовлення без валютного snapshot.',
            $this->sampleList($ordersMissingSnapshots->take(10)->map(fn (Order $order): string => 'order#'.$order->id.' '.$order->number)),
        );

        $orderItemsMissingSnapshots = OrderItem::query()
            ->orderBy('id')
            ->get()
            ->filter(fn (OrderItem $item): bool => $item->unit_price === null || ! $item->warehouse_id)
            ->values();

        $criticalIssues[] = $this->issue(
            $orderItemsMissingSnapshots->isNotEmpty(),
            'order_items_missing_snapshot',
            'Є позиції замовлень без ціни або без складу snapshot.',
            $this->sampleList($orderItemsMissingSnapshots->take(10)->map(fn (OrderItem $item): string => 'order_item#'.$item->id.' order#'.$item->order_id)),
        );

        $stockMovementsMissingReferences = StockMovement::query()
            ->orderBy('id')
            ->get()
            ->filter(fn (StockMovement $movement): bool => ! $movement->product_id || ! $movement->warehouse_id)
            ->values();

        $criticalIssues[] = $this->issue(
            $stockMovementsMissingReferences->isNotEmpty(),
            'stock_movements_missing_reference',
            'Є рухи товарів без товару або складу.',
            $this->sampleList($stockMovementsMissingReferences->take(10)->map(fn (StockMovement $movement): string => 'movement#'.$movement->id)),
        );

        $stockMovementDrift = $this->stockMovementDrift();

        $criticalIssues[] = $this->issue(
            $stockMovementDrift->isNotEmpty(),
            'stock_movements_balance_drift',
            'Є останні рухи, де balance_after не збігається з поточним залишком.',
            $this->sampleList($stockMovementDrift->take(10)->all()),
        );

        $criticalIssues = array_merge($criticalIssues, $this->lifecycleIssues());

        $criticalIssues = array_values(array_filter($criticalIssues));

        return [
            'status' => $criticalIssues === [] ? 'ok' : 'failed',
            'critical_count' => count($criticalIssues),
            'settings_count' => $settingsCount,
            'default_currency' => $defaultCurrency ? [
                'id' => $defaultCurrency->id,
                'code' => $defaultCurrency->code,
                'active' => (bool) $defaultCurrency->is_active,
            ] : null,
            'default_warehouse' => $defaultWarehouse ? [
                'id' => $defaultWarehouse->id,
                'name' => $defaultWarehouse->name,
                'active' => (bool) $defaultWarehouse->is_active,
            ] : null,
            'issues' => $criticalIssues,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderReport(array $report): void
    {
        $this->info('Commerce health check');
        $this->line('status: '.$report['status']);
        $this->line('settings_count: '.$report['settings_count']);
        $this->line('default_currency: '.$this->summaryValue($report['default_currency']));
        $this->line('default_warehouse: '.$this->summaryValue($report['default_warehouse']));

        if ($report['issues'] === []) {
            $this->newLine();
            $this->info('Критичних проблем не знайдено.');

            return;
        }

        $this->newLine();
        $this->warn('Критичні проблеми:');

        foreach ($report['issues'] as $issue) {
            $this->line('- '.$issue['code'].': '.$issue['message']);

            if ($issue['count'] > 0) {
                $this->line('  count: '.$issue['count']);
            }

            foreach ($issue['examples'] as $example) {
                $this->line('  example: '.$example);
            }
        }
    }

    /**
     * @param  array<int, mixed>  $examples
     * @return array{code: string, message: string, count: int, examples: array<int, string>}|null
     */
    private function issue(bool $failed, string $code, string $message, array $examples = []): ?array
    {
        if (! $failed) {
            return null;
        }

        return [
            'code' => $code,
            'message' => $message,
            'count' => count($examples),
            'examples' => array_values(array_filter(array_map('strval', $examples))),
        ];
    }

    private function stockMovementDrift(): Collection
    {
        $balances = StockBalance::query()
            ->get()
            ->keyBy(fn (StockBalance $balance): string => $balance->product_id.'|'.$balance->warehouse_id);

        return StockMovement::query()
            ->whereNotNull('product_id')
            ->whereNotNull('warehouse_id')
            ->with(['product:id,name,sku', 'warehouse:id,name'])
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (StockMovement $movement): string => $movement->product_id.'|'.$movement->warehouse_id)
            ->map(function (Collection $movements) use ($balances): ?string {
                /** @var StockMovement $latest */
                $latest = $movements->first();
                $key = $latest->product_id.'|'.$latest->warehouse_id;
                $balance = $balances->get($key);

                if (! $balance) {
                    return $this->entityLabel($latest, [
                        'product' => $latest->product?->name,
                        'warehouse' => $latest->warehouse?->name,
                        'issue' => 'missing stock balance',
                    ]);
                }

                if (abs((float) $balance->quantity - (float) $latest->balance_after) < 0.001) {
                    return null;
                }

                return $this->entityLabel($latest, [
                    'product' => $latest->product?->name,
                    'warehouse' => $latest->warehouse?->name,
                    'movement_balance_after' => (string) $latest->balance_after,
                    'stock_balance_quantity' => (string) $balance->quantity,
                ]);
            })
            ->filter()
            ->values();
    }

    /**
     * @return array<int, array{code: string, message: string, count: int, examples: array<int, string>}|null>
     */
    private function lifecycleIssues(): array
    {
        $orderStatusValues = $this->enumValues(OrderStatus::cases());
        $paymentStatusValues = $this->enumValues(PaymentStatus::cases());
        $deliveryStatusValues = $this->enumValues(DeliveryStatus::cases());

        $ordersMissingStatus = Order::query()
            ->where(fn ($query) => $query->whereNull('status')->orWhere('status', ''))
            ->orderBy('id')
            ->limit(20)
            ->get();

        $ordersWithUnknownStatus = Order::query()
            ->whereNotNull('status')
            ->where('status', '!=', '')
            ->whereNotIn('status', $orderStatusValues)
            ->orderBy('id')
            ->limit(20)
            ->get();

        $ordersMissingPaymentStatus = Order::query()
            ->where(fn ($query) => $query->whereNull('payment_status')->orWhere('payment_status', ''))
            ->orderBy('id')
            ->limit(20)
            ->get();

        $ordersWithUnknownPaymentStatus = Order::query()
            ->whereNotNull('payment_status')
            ->where('payment_status', '!=', '')
            ->whereNotIn('payment_status', $paymentStatusValues)
            ->orderBy('id')
            ->limit(20)
            ->get();

        $ordersMissingDeliveryStatus = Order::query()
            ->where(fn ($query) => $query->whereNull('delivery_status')->orWhere('delivery_status', ''))
            ->orderBy('id')
            ->limit(20)
            ->get();

        $ordersWithUnknownDeliveryStatus = Order::query()
            ->whereNotNull('delivery_status')
            ->where('delivery_status', '!=', '')
            ->whereNotIn('delivery_status', $deliveryStatusValues)
            ->orderBy('id')
            ->limit(20)
            ->get();

        $ordersMissingPaymentMethodSnapshot = Order::query()
            ->whereNotNull('payment_method_id')
            ->where(fn ($query) => $query->whereNull('payment_method_name')->orWhere('payment_method_name', ''))
            ->orderBy('id')
            ->limit(20)
            ->get();

        $ordersMissingDeliveryMethodSnapshot = Order::query()
            ->whereNotNull('delivery_method_id')
            ->where(fn ($query) => $query->whereNull('delivery_method_name')->orWhere('delivery_method_name', ''))
            ->orderBy('id')
            ->limit(20)
            ->get();

        $cancelledOrdersMissingTimestamp = Order::query()
            ->where('status', OrderStatus::Cancelled->value)
            ->whereNull('cancelled_at')
            ->orderBy('id')
            ->limit(20)
            ->get();

        $paidOrdersMissingTimestamp = Order::query()
            ->where('payment_status', PaymentStatus::Paid->value)
            ->whereNull('paid_at')
            ->orderBy('id')
            ->limit(20)
            ->get();

        $shippedOrdersMissingTimestamp = Order::query()
            ->where(fn ($query) => $query
                ->where('status', OrderStatus::Shipped->value)
                ->orWhere('delivery_status', DeliveryStatus::Shipped->value))
            ->whereNull('shipped_at')
            ->orderBy('id')
            ->limit(20)
            ->get();

        $completedOrdersMissingTimestamp = Order::query()
            ->where('status', OrderStatus::Completed->value)
            ->whereNull('completed_at')
            ->orderBy('id')
            ->limit(20)
            ->get();

        return [
            $this->issue(
                $ordersMissingStatus->isNotEmpty(),
                'orders_missing_status',
                'Є замовлення без статусу.',
                $this->sampleList($this->ordersList($ordersMissingStatus)),
            ),
            $this->issue(
                $ordersWithUnknownStatus->isNotEmpty(),
                'orders_unknown_status',
                'Є замовлення з невідомим статусом.',
                $this->sampleList($this->ordersList($ordersWithUnknownStatus, 'status')),
            ),
            $this->issue(
                $ordersMissingPaymentStatus->isNotEmpty(),
                'orders_missing_payment_status',
                'Є замовлення без статусу оплати.',
                $this->sampleList($this->ordersList($ordersMissingPaymentStatus)),
            ),
            $this->issue(
                $ordersWithUnknownPaymentStatus->isNotEmpty(),
                'orders_unknown_payment_status',
                'Є замовлення з невідомим статусом оплати.',
                $this->sampleList($this->ordersList($ordersWithUnknownPaymentStatus, 'payment_status')),
            ),
            $this->issue(
                $ordersMissingDeliveryStatus->isNotEmpty(),
                'orders_missing_delivery_status',
                'Є замовлення без статусу доставки.',
                $this->sampleList($this->ordersList($ordersMissingDeliveryStatus)),
            ),
            $this->issue(
                $ordersWithUnknownDeliveryStatus->isNotEmpty(),
                'orders_unknown_delivery_status',
                'Є замовлення з невідомим статусом доставки.',
                $this->sampleList($this->ordersList($ordersWithUnknownDeliveryStatus, 'delivery_status')),
            ),
            $this->issue(
                $ordersMissingPaymentMethodSnapshot->isNotEmpty(),
                'orders_missing_payment_method_snapshot',
                'Є замовлення з payment_method_id, але без payment_method_name snapshot.',
                $this->sampleList($this->ordersList($ordersMissingPaymentMethodSnapshot)),
            ),
            $this->issue(
                $ordersMissingDeliveryMethodSnapshot->isNotEmpty(),
                'orders_missing_delivery_method_snapshot',
                'Є замовлення з delivery_method_id, але без delivery_method_name snapshot.',
                $this->sampleList($this->ordersList($ordersMissingDeliveryMethodSnapshot)),
            ),
            $this->issue(
                PaymentMethod::query()->active()->count() === 0,
                'active_payment_methods_missing',
                'Немає жодного активного способу оплати для checkout.',
            ),
            $this->issue(
                DeliveryMethod::query()->active()->count() === 0,
                'active_delivery_methods_missing',
                'Немає жодного активного способу доставки для checkout.',
            ),
            $this->issue(
                $cancelledOrdersMissingTimestamp->isNotEmpty(),
                'cancelled_orders_missing_cancelled_at',
                'Є скасовані замовлення без cancelled_at.',
                $this->sampleList($this->ordersList($cancelledOrdersMissingTimestamp)),
            ),
            $this->issue(
                $paidOrdersMissingTimestamp->isNotEmpty(),
                'paid_orders_missing_paid_at',
                'Є оплачені замовлення без paid_at.',
                $this->sampleList($this->ordersList($paidOrdersMissingTimestamp)),
            ),
            $this->issue(
                $shippedOrdersMissingTimestamp->isNotEmpty(),
                'shipped_orders_missing_shipped_at',
                'Є відправлені замовлення без shipped_at.',
                $this->sampleList($this->ordersList($shippedOrdersMissingTimestamp)),
            ),
            $this->issue(
                $completedOrdersMissingTimestamp->isNotEmpty(),
                'completed_orders_missing_completed_at',
                'Є завершені замовлення без completed_at.',
                $this->sampleList($this->ordersList($completedOrdersMissingTimestamp)),
            ),
        ];
    }

    /**
     * @template T of object
     *
     * @param  Collection<int, T>  $items
     * @return array<int, int|string>
     */
    private function ids(Collection $items): array
    {
        return $items->map(fn (object $item): int|string => $item->getKey())->all();
    }

    /**
     * @param  Collection<int, object>  $items
     * @return array<int, string>
     */
    private function productsList(Collection $items): array
    {
        return $items->map(fn (object $item): string => 'product#'.$item->getKey().' '.$item->name)->all();
    }

    /**
     * @param  Collection<int, Order>  $items
     * @return array<int, string>
     */
    private function ordersList(Collection $items, ?string $field = null): array
    {
        return $items
            ->map(function (Order $order) use ($field): string {
                $label = 'order#'.$order->id.' '.$order->number;

                if ($field) {
                    $label .= ' '.$field.'='.(string) $order->{$field};
                }

                return $label;
            })
            ->all();
    }

    /**
     * @param  array<int, \BackedEnum>  $cases
     * @return array<int, string>
     */
    private function enumValues(array $cases): array
    {
        return array_map(fn (\BackedEnum $case): string => (string) $case->value, $cases);
    }

    /**
     * @param  Collection<int, mixed>|array<int, mixed>  $items
     * @return array<int, string>
     */
    private function sampleList(Collection|array $items): array
    {
        return collect($items)->map(fn (mixed $item): string => (string) $item)->values()->all();
    }

    private function entityLabel(object $model, array $details = []): string
    {
        $parts = [class_basename($model).'#'.$model->getKey()];

        foreach ($details as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $parts[] = $key.'='.$value;
        }

        return implode(' ', $parts);
    }

    /**
     * @param  array<string, mixed>|null  $value
     */
    private function summaryValue(?array $value): string
    {
        if ($value === null) {
            return '-';
        }

        $parts = [];

        foreach ($value as $key => $item) {
            $parts[] = $key.': '.(is_bool($item) ? ($item ? 'yes' : 'no') : (string) $item);
        }

        return implode(', ', $parts);
    }
}
