<?php

namespace App\Console\Commands;

use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\OrderNotificationEvent;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\CommerceSetting;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\DeliveryMethod;
use App\Models\NotificationMailSetting;
use App\Models\NotificationOutbox;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\TaxProfile;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Support\Addons\AddonHealthCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

        $criticalIssues = array_merge($criticalIssues, $this->catalogCoreIssues());

        $criticalIssues = array_merge($criticalIssues, $this->lifecycleIssues());
        [$notificationCriticalIssues, $notificationWarningIssues] = $this->notificationIssues();
        $criticalIssues = array_merge($criticalIssues, $notificationCriticalIssues);
        [$mailCriticalIssues, $mailWarningIssues] = $this->mailIssues();
        $criticalIssues = array_merge($criticalIssues, $mailCriticalIssues);
        [$customerCriticalIssues, $customerWarningIssues] = $this->customerIssues();
        $criticalIssues = array_merge($criticalIssues, $customerCriticalIssues);
        $addonDiagnostics = app(AddonHealthCheck::class)->diagnostics();
        $criticalIssues = array_merge($criticalIssues, $addonDiagnostics['issues']);

        $criticalIssues = array_values(array_filter($criticalIssues));
        $warningIssues = array_values(array_filter(array_merge(
            $notificationWarningIssues,
            $mailWarningIssues,
            $customerWarningIssues,
            $addonDiagnostics['warnings'],
        )));

        return [
            'status' => $criticalIssues === [] ? 'ok' : 'failed',
            'critical_count' => count($criticalIssues),
            'warning_count' => count($warningIssues),
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
            'warnings' => $warningIssues,
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

        if ($report['issues'] === [] && $report['warnings'] === []) {
            $this->newLine();
            $this->info('Критичних проблем не знайдено.');

            return;
        }

        if ($report['issues'] !== []) {
            $this->newLine();
            $this->warn('Критичні проблеми:');
            $this->renderIssues($report['issues']);
        } else {
            $this->newLine();
            $this->info('Критичних проблем не знайдено.');
        }

        if ($report['warnings'] !== []) {
            $this->newLine();
            $this->warn('Попередження:');
            $this->renderIssues($report['warnings']);
        }
    }

    /**
     * @param  array<int, array{code: string, message: string, count: int, examples: array<int, string>}>  $issues
     */
    private function renderIssues(array $issues): void
    {
        foreach ($issues as $issue) {
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
            ->keyBy(fn (StockBalance $balance): string => implode('|', [
                $balance->product_id,
                $balance->warehouse_id,
                $balance->product_variant_id ?? 'null',
            ]));

        return StockMovement::query()
            ->whereNotNull('product_id')
            ->whereNotNull('warehouse_id')
            ->with(['product:id,name,sku', 'warehouse:id,name'])
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (StockMovement $movement): string => implode('|', [
                $movement->product_id,
                $movement->warehouse_id,
                $movement->product_variant_id ?? 'null',
            ]))
            ->map(function (Collection $movements) use ($balances): ?string {
                /** @var StockMovement $latest */
                $latest = $movements->first();
                $key = implode('|', [
                    $latest->product_id,
                    $latest->warehouse_id,
                    $latest->product_variant_id ?? 'null',
                ]);
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
    private function catalogCoreIssues(): array
    {
        $defaultUnits = Unit::query()->where('is_active', true)->whereIn('code', ['piece', 'kg'])->get();
        $defaultTaxProfiles = TaxProfile::query()->where('is_active', true)->whereIn('code', ['no_vat', 'vat_20'])->get();

        $inactiveProductDefaultVariant = Product::query()
            ->where('is_active', true)
            ->whereHas('defaultVariant', fn ($query) => $query->where('is_active', false))
            ->limit(20)
            ->get();

        $productsWithoutActiveDefaultVariant = Product::query()
            ->where('is_active', true)
            ->whereDoesntHave('variants', fn ($query) => $query->where('is_default', true)->where('is_active', true))
            ->limit(20)
            ->get();

        $productsWithMultipleDefaultVariants = DB::table('product_variants')
            ->select('product_id', DB::raw('COUNT(*) as defaults_count'))
            ->where('is_default', true)
            ->groupBy('product_id')
            ->havingRaw('COUNT(*) > 1')
            ->limit(20)
            ->get();

        $simpleProductsWithMultipleActiveVariants = Product::query()
            ->where('has_variants', false)
            ->whereIn('id', DB::table('product_variants')
                ->select('product_id')
                ->where('is_active', true)
                ->groupBy('product_id')
                ->havingRaw('COUNT(*) > 1'))
            ->limit(20)
            ->get();

        $multiVariantProductsWithoutActiveVariants = Product::query()
            ->where('has_variants', true)
            ->whereDoesntHave('variants', fn ($query) => $query->where('is_active', true))
            ->limit(20)
            ->get();

        $simpleProductsWithoutDefaultVariant = Product::query()
            ->where('has_variants', false)
            ->whereDoesntHave('defaultVariant')
            ->limit(20)
            ->get();

        $multiVariantProductsWithoutDefaultVariant = Product::query()
            ->where('has_variants', true)
            ->whereDoesntHave('defaultVariant')
            ->limit(20)
            ->get();

        $variantsWithoutMandatoryRefs = ProductVariant::query()
            ->where(function ($query): void {
                $query->whereNull('base_unit_id')->orWhereNull('tax_profile_id');
            })
            ->limit(20)
            ->get();

        $variantExciseInconsistent = ProductVariant::query()
            ->where(function ($query): void {
                $query
                    ->where(function ($inner): void {
                        $inner->where('is_excise_applicable', true)
                            ->where(function ($q): void {
                                $q->whereNull('excise_rate')->orWhere('excise_rate', '<=', 0);
                            });
                    })
                    ->orWhere(function ($inner): void {
                        $inner->where('is_excise_applicable', false)
                            ->where(function ($q): void {
                                $q->whereNotNull('excise_rate')->orWhere('requires_excise_stamp_entry', true);
                            });
                    });
            })
            ->limit(20)
            ->get();

        $orderItemsMissingVariantSnapshot = OrderItem::query()
            ->whereNotNull('product_variant_id')
            ->where(function ($query): void {
                $query->whereNull('tax_profile_id')
                    ->orWhereNull('vat_rate')
                    ->orWhereNull('base_unit_id')
                    ->orWhereNull('sales_unit_id');
            })
            ->limit(20)
            ->get();

        $inactiveAttributesOnActiveProducts = DB::table('product_attribute_values')
            ->join('attributes', 'attributes.id', '=', 'product_attribute_values.attribute_id')
            ->join('products', 'products.id', '=', 'product_attribute_values.product_id')
            ->where('products.is_active', true)
            ->where('attributes.is_active', false)
            ->limit(20)
            ->get([
                'product_attribute_values.id',
                'products.id as product_id',
                'products.name as product_name',
                'attributes.id as attribute_id',
                'attributes.name as attribute_name',
            ]);

        return [
            $this->issue(
                $defaultUnits->count() < 2,
                'catalog_units_defaults_missing',
                'Немає активних базових одиниць виміру (piece, kg).',
                $this->sampleList($defaultUnits->map(fn (Unit $unit): string => $this->entityLabel($unit, ['code' => $unit->code]))),
            ),
            $this->issue(
                $defaultTaxProfiles->count() < 2,
                'catalog_tax_profiles_defaults_missing',
                'Немає активних базових податкових профілів (no_vat, vat_20).',
                $this->sampleList($defaultTaxProfiles->map(fn (TaxProfile $profile): string => $this->entityLabel($profile, ['code' => $profile->code]))),
            ),
            $this->issue(
                $inactiveProductDefaultVariant->isNotEmpty(),
                'products_default_variant_inactive',
                'Активні товари мають default SKU, що неактивний.',
                $this->sampleList($this->productsList($inactiveProductDefaultVariant)),
            ),
            $this->issue(
                $productsWithoutActiveDefaultVariant->isNotEmpty(),
                'products_without_active_default_variant',
                'Є активні товари без активного основного SKU.',
                $this->sampleList($this->productsList($productsWithoutActiveDefaultVariant)),
            ),
            $this->issue(
                $productsWithMultipleDefaultVariants->isNotEmpty(),
                'variants_multiple_defaults_per_product',
                'Є товари з кількома основними SKU.',
                $this->sampleList($productsWithMultipleDefaultVariants->map(fn (object $row): string => 'product#'.$row->product_id.' defaults='.$row->defaults_count)),
            ),
            $this->issue(
                $simpleProductsWithMultipleActiveVariants->isNotEmpty(),
                'simple_products_multiple_active_variants',
                'Є прості товари з більше ніж одним активним SKU.',
                $this->sampleList($this->productsList($simpleProductsWithMultipleActiveVariants)),
            ),
            $this->issue(
                $multiVariantProductsWithoutActiveVariants->isNotEmpty(),
                'variant_products_without_active_variants',
                'Є товари з варіантами, але без активних SKU.',
                $this->sampleList($this->productsList($multiVariantProductsWithoutActiveVariants)),
            ),
            $this->issue(
                $simpleProductsWithoutDefaultVariant->isNotEmpty(),
                'simple_products_without_default_variant',
                'Є прості товари без службового default SKU.',
                $this->sampleList($this->productsList($simpleProductsWithoutDefaultVariant)),
            ),
            $this->issue(
                $multiVariantProductsWithoutDefaultVariant->isNotEmpty(),
                'variant_products_without_default_variant',
                'Є товари з варіантами без основного SKU.',
                $this->sampleList($this->productsList($multiVariantProductsWithoutDefaultVariant)),
            ),
            $this->issue(
                $variantsWithoutMandatoryRefs->isNotEmpty(),
                'variants_missing_base_unit_or_tax_profile',
                'Є SKU без base unit або tax profile.',
                $this->sampleList($variantsWithoutMandatoryRefs->map(fn (ProductVariant $variant): string => $this->entityLabel($variant, [
                    'sku' => $variant->sku,
                    'product_id' => (string) $variant->product_id,
                    'base_unit_id' => (string) $variant->base_unit_id,
                    'tax_profile_id' => (string) $variant->tax_profile_id,
                ]))),
            ),
            $this->issue(
                $variantExciseInconsistent->isNotEmpty(),
                'variants_excise_inconsistent',
                'Є SKU з неузгодженими акцизними полями.',
                $this->sampleList($variantExciseInconsistent->map(fn (ProductVariant $variant): string => $this->entityLabel($variant, [
                    'sku' => $variant->sku,
                    'is_excise_applicable' => $variant->is_excise_applicable ? '1' : '0',
                    'excise_rate' => (string) $variant->excise_rate,
                    'requires_excise_stamp_entry' => $variant->requires_excise_stamp_entry ? '1' : '0',
                ]))),
            ),
            $this->issue(
                $orderItemsMissingVariantSnapshot->isNotEmpty(),
                'order_items_variant_snapshot_missing',
                'Є позиції замовлень зі SKU без unit/tax snapshot.',
                $this->sampleList($orderItemsMissingVariantSnapshot->map(fn (OrderItem $item): string => 'order_item#'.$item->id.' variant#'.$item->product_variant_id)),
            ),
            $this->issue(
                $inactiveAttributesOnActiveProducts->isNotEmpty(),
                'product_attributes_inactive_linked_to_active_products',
                'Активні товари мають значення неактивних атрибутів.',
                $this->sampleList($inactiveAttributesOnActiveProducts->map(fn (object $row): string => 'value#'.$row->id.' product#'.$row->product_id.' attribute#'.$row->attribute_id)),
            ),
        ];
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
     * @return array{0: array<int, array{code: string, message: string, count: int, examples: array<int, string>}|null>, 1: array<int, array{code: string, message: string, count: int, examples: array<int, string>}|null>}
     */
    private function notificationIssues(): array
    {
        $eventValues = $this->enumValues(OrderNotificationEvent::cases());
        $channelValues = $this->enumValues(NotificationChannel::cases());
        $statusValues = $this->enumValues(NotificationStatus::cases());
        $requiredEmailEvents = OrderNotificationEvent::requiredEmailTemplateEvents();

        $missingTemplates = collect($requiredEmailEvents)
            ->reject(fn (string $event): bool => NotificationTemplate::query()
                ->where('event', $event)
                ->where('channel', NotificationChannel::Email->value)
                ->exists())
            ->values();

        $duplicateTemplateCodes = NotificationTemplate::query()
            ->select('code')
            ->groupBy('code')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('code');

        $activeTemplatesWithoutBody = NotificationTemplate::query()
            ->where('is_active', true)
            ->whereRaw("TRIM(COALESCE(body, '')) = ''")
            ->orderBy('id')
            ->limit(20)
            ->get();

        $templatesWithUnknownEvent = NotificationTemplate::query()
            ->whereNotIn('event', $eventValues)
            ->orderBy('id')
            ->limit(20)
            ->get();

        $templatesWithUnknownChannel = NotificationTemplate::query()
            ->whereNotIn('channel', $channelValues)
            ->orderBy('id')
            ->limit(20)
            ->get();

        $brokenOutbox = NotificationOutbox::query()
            ->where(fn ($query) => $query
                ->whereNull('event')
                ->orWhere('event', '')
                ->orWhereNull('channel')
                ->orWhere('channel', '')
                ->orWhereNull('status')
                ->orWhere('status', ''))
            ->orderBy('id')
            ->limit(20)
            ->get();

        $outboxWithUnknownEvent = NotificationOutbox::query()
            ->whereNotNull('event')
            ->where('event', '!=', '')
            ->whereNotIn('event', $eventValues)
            ->orderBy('id')
            ->limit(20)
            ->get();

        $outboxWithUnknownChannel = NotificationOutbox::query()
            ->whereNotNull('channel')
            ->where('channel', '!=', '')
            ->whereNotIn('channel', $channelValues)
            ->orderBy('id')
            ->limit(20)
            ->get();

        $outboxWithUnknownStatus = NotificationOutbox::query()
            ->whereNotNull('status')
            ->where('status', '!=', '')
            ->whereNotIn('status', $statusValues)
            ->orderBy('id')
            ->limit(20)
            ->get();

        $oldPendingNotifications = NotificationOutbox::query()
            ->where('status', NotificationStatus::Pending->value)
            ->where('created_at', '<', now()->subDay())
            ->orderBy('id')
            ->limit(20)
            ->get();

        $failedNotifications = NotificationOutbox::query()
            ->where('status', NotificationStatus::Failed->value)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $emailTemplatesWithoutSubject = NotificationTemplate::query()
            ->where('channel', NotificationChannel::Email->value)
            ->where('is_active', true)
            ->whereRaw("TRIM(COALESCE(subject, '')) = ''")
            ->orderBy('id')
            ->limit(20)
            ->get();

        return [
            [
                $this->issue(
                    $missingTemplates->isNotEmpty(),
                    'notification_templates_missing',
                    'Відсутні базові email-шаблони повідомлень по замовленнях.',
                    $this->sampleList($missingTemplates->all()),
                ),
                $this->issue(
                    $duplicateTemplateCodes->isNotEmpty(),
                    'notification_templates_duplicate_code',
                    'Є дублікати notification_templates.code.',
                    $this->sampleList($duplicateTemplateCodes->all()),
                ),
                $this->issue(
                    $activeTemplatesWithoutBody->isNotEmpty(),
                    'notification_templates_empty_body',
                    'Є активні шаблони повідомлень із порожнім body.',
                    $this->sampleList($this->notificationTemplateList($activeTemplatesWithoutBody)),
                ),
                $this->issue(
                    $templatesWithUnknownEvent->isNotEmpty(),
                    'notification_templates_unknown_event',
                    'Є шаблони повідомлень із невідомим event.',
                    $this->sampleList($this->notificationTemplateList($templatesWithUnknownEvent)),
                ),
                $this->issue(
                    $templatesWithUnknownChannel->isNotEmpty(),
                    'notification_templates_unknown_channel',
                    'Є шаблони повідомлень із невідомим channel.',
                    $this->sampleList($this->notificationTemplateList($templatesWithUnknownChannel)),
                ),
                $this->issue(
                    $brokenOutbox->isNotEmpty(),
                    'notification_outbox_broken_records',
                    'Є notification outbox записи без event, channel або status.',
                    $this->sampleList($this->notificationOutboxList($brokenOutbox)),
                ),
                $this->issue(
                    $outboxWithUnknownEvent->isNotEmpty(),
                    'notification_outbox_unknown_event',
                    'Є notification outbox записи з невідомим event.',
                    $this->sampleList($this->notificationOutboxList($outboxWithUnknownEvent)),
                ),
                $this->issue(
                    $outboxWithUnknownChannel->isNotEmpty(),
                    'notification_outbox_unknown_channel',
                    'Є notification outbox записи з невідомим channel.',
                    $this->sampleList($this->notificationOutboxList($outboxWithUnknownChannel)),
                ),
                $this->issue(
                    $outboxWithUnknownStatus->isNotEmpty(),
                    'notification_outbox_unknown_status',
                    'Є notification outbox записи з невідомим status.',
                    $this->sampleList($this->notificationOutboxList($outboxWithUnknownStatus)),
                ),
            ],
            [
                $this->issue(
                    $oldPendingNotifications->isNotEmpty(),
                    'notification_outbox_old_pending',
                    'Є pending повідомлення старші за 24 години.',
                    $this->sampleList($this->notificationOutboxList($oldPendingNotifications)),
                ),
                $this->issue(
                    $failedNotifications->isNotEmpty(),
                    'notification_outbox_failed',
                    'Є failed повідомлення в outbox.',
                    $this->sampleList($this->notificationOutboxList($failedNotifications)),
                ),
                $this->issue(
                    $emailTemplatesWithoutSubject->isNotEmpty(),
                    'notification_email_templates_missing_subject',
                    'Є активні email-шаблони без subject.',
                    $this->sampleList($this->notificationTemplateList($emailTemplatesWithoutSubject)),
                ),
            ],
        ];
    }

    /**
     * @return array{0: array<int, array{code: string, message: string, count: int, examples: array<int, string>}|null>, 1: array<int, array{code: string, message: string, count: int, examples: array<int, string>}|null>}
     */
    private function mailIssues(): array
    {
        $environment = (string) config('app.env', app()->environment());
        $isProduction = $environment === 'production';
        $critical = [];
        $warnings = [];

        if (! Schema::hasTable('notification_mail_settings')) {
            $critical[] = $this->issue(
                true,
                'notification_mail_settings_table_missing',
                'Таблиця notification_mail_settings відсутня. Запустіть міграції.',
            );

            [$envCritical, $envWarnings] = $this->envMailIssues($isProduction);

            return [
                array_merge($critical, $envCritical),
                array_merge($warnings, $envWarnings),
            ];
        }

        $settingsCount = NotificationMailSetting::query()->count();
        $settings = NotificationMailSetting::query()->orderBy('id')->first();

        $warnings[] = $this->issue(
            $settingsCount === 0,
            'notification_mail_settings_missing',
            'Немає запису notification mail settings. Буде використано env fallback.',
        );
        $warnings[] = $this->issue(
            $settingsCount > 1,
            'notification_mail_settings_multiple',
            'Знайдено кілька записів notification mail settings. Використовується перший за ID.',
            $this->sampleList($this->ids(NotificationMailSetting::query()->orderBy('id')->limit(10)->get())),
        );

        if (! $settings?->is_enabled) {
            [$envCritical, $envWarnings] = $this->envMailIssues($isProduction);

            return [
                array_merge($critical, $envCritical),
                array_merge($warnings, $envWarnings),
            ];
        }

        $mailer = $settings->normalizedMailer();
        $errors = $settings->configurationErrors();

        $critical[] = $this->issue(
            in_array('mailer', $errors, true),
            'notification_mail_settings_mailer_invalid',
            'DB notification mail settings має невідомий mailer.',
            ['mailer='.$mailer],
        );
        $critical[] = $this->issue(
            in_array('host', $errors, true),
            'notification_mail_settings_smtp_host_missing',
            'DB SMTP override увімкнений, але host порожній.',
        );
        $critical[] = $this->issue(
            in_array('port', $errors, true),
            'notification_mail_settings_smtp_port_missing',
            'DB SMTP override увімкнений, але port порожній.',
        );
        $critical[] = $this->issue(
            in_array('from_address', $errors, true),
            'notification_mail_settings_from_address_invalid',
            'DB notification MAIL_FROM_ADDRESS порожній або невалідний.',
            filled($settings->from_address) ? ['from='.$settings->from_address] : [],
        );
        $critical[] = $this->issue(
            in_array('encryption', $errors, true),
            'notification_mail_settings_encryption_invalid',
            'DB notification mail encryption має бути none, tls або ssl.',
            ['encryption='.($settings->encryption ?: 'none')],
        );
        $critical[] = $this->issue(
            in_array('password_decrypt', $errors, true),
            'notification_mail_settings_password_decrypt_failed',
            'SMTP password у DB settings неможливо розшифрувати. Перевірте APP_KEY або очистіть пароль.',
        );

        $warnings[] = $this->issue(
            $isProduction && in_array($mailer, ['log', 'array'], true),
            'notification_mail_settings_non_smtp_in_production',
            'Production notification mail override використовує non-SMTP mailer.',
            ['mailer='.$mailer],
        );
        $warnings[] = $this->issue(
            $settings->last_test_status === NotificationMailSetting::TEST_STATUS_FAILED,
            'notification_mail_settings_last_test_failed',
            'Останній тест notification mail settings завершився помилкою.',
            $settings->last_test_error ? [$settings->last_test_error] : [],
        );

        return [$critical, $warnings];
    }

    /**
     * @return array{0: array<int, array{code: string, message: string, count: int, examples: array<int, string>}|null>, 1: array<int, array{code: string, message: string, count: int, examples: array<int, string>}|null>}
     */
    private function customerIssues(): array
    {
        if (! Schema::hasTable('customers')) {
            return [
                [
                    $this->issue(
                        true,
                        'customers_table_missing',
                        'Таблиця customers відсутня. Запустіть міграції.',
                    ),
                ],
                [],
            ];
        }

        $critical = [];
        $warnings = [];

        $ordersWithMissingCustomer = DB::table('orders')
            ->leftJoin('customers', 'orders.customer_id', '=', 'customers.id')
            ->whereNotNull('orders.customer_id')
            ->whereNull('customers.id')
            ->orderBy('orders.id')
            ->limit(20)
            ->get(['orders.id', 'orders.number', 'orders.customer_id']);

        $critical[] = $this->issue(
            $ordersWithMissingCustomer->isNotEmpty(),
            'orders_invalid_customer_id',
            'Є замовлення з customer_id на неіснуючого customer.',
            $this->sampleList($ordersWithMissingCustomer->map(
                fn (object $order): string => 'order#'.$order->id.' '.$order->number.' customer#'.$order->customer_id,
            )),
        );

        if (! Schema::hasTable('customer_addresses')) {
            $critical[] = $this->issue(
                true,
                'customer_addresses_table_missing',
                'Таблиця customer_addresses відсутня. Запустіть міграції.',
            );
        } else {
            $addressesWithMissingCustomer = DB::table('customer_addresses')
                ->leftJoin('customers', 'customer_addresses.customer_id', '=', 'customers.id')
                ->whereNull('customers.id')
                ->orderBy('customer_addresses.id')
                ->limit(20)
                ->get(['customer_addresses.id', 'customer_addresses.customer_id']);

            $critical[] = $this->issue(
                $addressesWithMissingCustomer->isNotEmpty(),
                'customer_addresses_missing_customer',
                'Є адреси клієнтів без валідного customer_id.',
                $this->sampleList($addressesWithMissingCustomer->map(
                    fn (object $address): string => 'address#'.$address->id.' customer#'.$address->customer_id,
                )),
            );
        }

        $ordersWithoutCustomer = Order::query()
            ->whereNull('customer_id')
            ->orderBy('id')
            ->limit(20)
            ->get();

        $warnings[] = $this->issue(
            $ordersWithoutCustomer->isNotEmpty(),
            'orders_without_customer_id',
            'Є замовлення без customer_id. Старі замовлення валідні, але їх можна звʼязати через backfill.',
            $this->sampleList($this->ordersList($ordersWithoutCustomer)),
        );

        $customersWithoutContact = Customer::query()
            ->where(function ($query): void {
                $query->where(fn ($query) => $query->whereNull('phone')->orWhere('phone', ''))
                    ->where(fn ($query) => $query->whereNull('email')->orWhere('email', ''));
            })
            ->orderBy('id')
            ->limit(20)
            ->get();

        $warnings[] = $this->issue(
            $customersWithoutContact->isNotEmpty(),
            'customers_without_phone_or_email',
            'Є customers без телефону й email.',
            $this->sampleList($customersWithoutContact->map(fn (Customer $customer): string => $this->entityLabel($customer, [
                'name' => $customer->display_name,
            ]))),
        );

        $duplicatePhones = Customer::query()
            ->select('normalized_phone', DB::raw('COUNT(*) as aggregate'))
            ->whereNotNull('normalized_phone')
            ->where('normalized_phone', '!=', '')
            ->groupBy('normalized_phone')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('normalized_phone')
            ->limit(20)
            ->get();

        $warnings[] = $this->issue(
            $duplicatePhones->isNotEmpty(),
            'customers_duplicate_normalized_phone',
            'Є potential duplicate customers за normalized_phone.',
            $this->sampleList($duplicatePhones->map(fn (Customer $customer): string => 'phone='.$customer->normalized_phone.' count='.$customer->aggregate)),
        );

        $duplicateEmails = Customer::query()
            ->select('normalized_email', DB::raw('COUNT(*) as aggregate'))
            ->whereNotNull('normalized_email')
            ->where('normalized_email', '!=', '')
            ->groupBy('normalized_email')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('normalized_email')
            ->limit(20)
            ->get();

        $warnings[] = $this->issue(
            $duplicateEmails->isNotEmpty(),
            'customers_duplicate_normalized_email',
            'Є potential duplicate customers за normalized_email.',
            $this->sampleList($duplicateEmails->map(fn (Customer $customer): string => 'email='.$customer->normalized_email.' count='.$customer->aggregate)),
        );

        $invalidEmails = Customer::query()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->orderBy('id')
            ->get()
            ->filter(fn (Customer $customer): bool => ! filter_var($customer->email, FILTER_VALIDATE_EMAIL))
            ->values()
            ->take(20);

        $warnings[] = $this->issue(
            $invalidEmails->isNotEmpty(),
            'customers_invalid_email',
            'Є customers з невалідним email.',
            $this->sampleList($invalidEmails->map(fn (Customer $customer): string => $this->entityLabel($customer, [
                'email' => $customer->email,
            ]))),
        );

        $linkedOrdersMissingSnapshots = Order::query()
            ->whereNotNull('customer_id')
            ->where(function ($query): void {
                $query->whereNull('customer_name')
                    ->orWhere('customer_name', '')
                    ->orWhereNull('phone')
                    ->orWhere('phone', '');
            })
            ->orderBy('id')
            ->limit(20)
            ->get();

        $warnings[] = $this->issue(
            $linkedOrdersMissingSnapshots->isNotEmpty(),
            'orders_linked_customer_missing_contact_snapshot',
            'Є замовлення з customer_id, але без базових customer snapshot полів.',
            $this->sampleList($this->ordersList($linkedOrdersMissingSnapshots)),
        );

        $linkedOrdersWithContactConflicts = $this->linkedOrdersWithCustomerContactConflicts();

        $warnings[] = $this->issue(
            $linkedOrdersWithContactConflicts->isNotEmpty(),
            'orders_customer_contact_potential_duplicate',
            'Є замовлення, де snapshot контакт збігається з іншим customer. Це potential duplicate, не auto-merge.',
            $this->sampleList($linkedOrdersWithContactConflicts),
        );

        return [$critical, $warnings];
    }

    /**
     * @return Collection<int, string>
     */
    private function linkedOrdersWithCustomerContactConflicts(): Collection
    {
        $customersByEmail = Customer::query()
            ->whereNotNull('normalized_email')
            ->where('normalized_email', '!=', '')
            ->get(['id', 'normalized_email'])
            ->groupBy('normalized_email');

        $customersByPhone = Customer::query()
            ->whereNotNull('normalized_phone')
            ->where('normalized_phone', '!=', '')
            ->get(['id', 'normalized_phone'])
            ->groupBy('normalized_phone');

        return Order::query()
            ->whereNotNull('customer_id')
            ->with('customer:id,normalized_email,normalized_phone')
            ->orderBy('id')
            ->get()
            ->map(function (Order $order) use ($customersByEmail, $customersByPhone): ?string {
                $matches = [];
                $normalizedEmail = $this->normalizeCustomerEmail($order->email);
                $normalizedPhone = $this->normalizeCustomerPhone($order->phone);

                $emailMatches = $normalizedEmail
                    ? $customersByEmail->get($normalizedEmail, collect())->where('id', '!=', $order->customer_id)
                    : collect();
                $phoneMatches = $normalizedPhone
                    ? $customersByPhone->get($normalizedPhone, collect())->where('id', '!=', $order->customer_id)
                    : collect();

                if ($emailMatches->isNotEmpty()) {
                    $matches[] = 'email_matches_customer#'.$emailMatches->pluck('id')->join(',');
                }

                if ($phoneMatches->isNotEmpty()) {
                    $matches[] = 'phone_matches_customer#'.$phoneMatches->pluck('id')->join(',');
                }

                if ($matches === []) {
                    return null;
                }

                return 'order#'.$order->id.' '.$order->number.' linked_customer#'.$order->customer_id.' '.implode(' ', $matches);
            })
            ->filter()
            ->values()
            ->take(20);
    }

    private function normalizeCustomerEmail(?string $email): ?string
    {
        $email = trim((string) $email);

        return $email === '' ? null : mb_strtolower($email);
    }

    private function normalizeCustomerPhone(?string $phone): ?string
    {
        $phone = trim((string) $phone);

        if ($phone === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?: '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $digits = '38'.$digits;
        }

        return $digits === '' ? null : $digits;
    }

    /**
     * @return array{0: array<int, array{code: string, message: string, count: int, examples: array<int, string>}|null>, 1: array<int, array{code: string, message: string, count: int, examples: array<int, string>}|null>}
     */
    private function envMailIssues(bool $isProduction): array
    {
        $mailer = trim((string) config('mail.default', ''));
        $smtp = (array) config('mail.mailers.smtp', []);
        $smtpHost = trim((string) ($smtp['host'] ?? ''));
        $smtpPort = trim((string) ($smtp['port'] ?? ''));
        $fromAddress = trim((string) config('mail.from.address', ''));
        $smtpHostLooksLikePlaceholder = in_array(strtolower($smtpHost), ['127.0.0.1', 'localhost', '0.0.0.0'], true);
        $smtpPortLooksLikePlaceholder = $smtpPort === '2525';
        $fromLooksLikePlaceholder = $fromAddress === 'hello@example.com';
        $smtpHostMissing = $smtpHost === '' || ($isProduction && $smtpHostLooksLikePlaceholder);
        $smtpPortMissing = $smtpPort === '' || ($isProduction && $smtpPortLooksLikePlaceholder);
        $fromMissing = $fromAddress === '' || ($isProduction && $fromLooksLikePlaceholder);
        $fromInvalid = $fromAddress !== '' && ! filter_var($fromAddress, FILTER_VALIDATE_EMAIL);

        $critical = [
            $this->issue(
                $mailer === '',
                'mail_mailer_missing',
                'MAIL_MAILER не налаштований.',
            ),
            $this->issue(
                $isProduction && $mailer !== '' && $mailer !== 'smtp',
                'mail_smtp_required_in_production',
                'Production середовище має використовувати SMTP mailer для реальної доставки email.',
                ['current_mailer='.$mailer],
            ),
            $this->issue(
                $isProduction && $mailer === 'smtp' && $smtpHostMissing,
                'mail_smtp_host_missing',
                'SMTP mailer увімкнений, але MAIL_HOST порожній або має dev-placeholder.',
            ),
            $this->issue(
                $isProduction && $mailer === 'smtp' && $smtpPortMissing,
                'mail_smtp_port_missing',
                'SMTP mailer увімкнений, але MAIL_PORT порожній або має dev-placeholder.',
            ),
            $this->issue(
                $isProduction && $fromMissing,
                'mail_from_address_missing',
                'Production MAIL_FROM_ADDRESS має бути заданий реальним email.',
            ),
            $this->issue(
                $isProduction && $fromInvalid,
                'mail_from_address_invalid',
                'MAIL_FROM_ADDRESS не є валідним email.',
                ['from='.$fromAddress],
            ),
        ];

        $warnings = [
            $this->issue(
                ! $isProduction && $mailer === 'smtp' && $smtpHost === '',
                'mail_smtp_host_missing',
                'SMTP mailer увімкнений, але MAIL_HOST порожній.',
            ),
            $this->issue(
                ! $isProduction && $mailer === 'smtp' && $smtpPort === '',
                'mail_smtp_port_missing',
                'SMTP mailer увімкнений, але MAIL_PORT порожній.',
            ),
            $this->issue(
                ! $isProduction && $mailer === 'smtp' && $fromAddress === '',
                'mail_from_address_missing',
                'SMTP mailer увімкнений, але MAIL_FROM_ADDRESS порожній.',
            ),
            $this->issue(
                ! $isProduction && $fromInvalid,
                'mail_from_address_invalid',
                'MAIL_FROM_ADDRESS не є валідним email.',
                ['from='.$fromAddress],
            ),
        ];

        return [$critical, $warnings];
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
     * @param  Collection<int, NotificationTemplate>  $items
     * @return array<int, string>
     */
    private function notificationTemplateList(Collection $items): array
    {
        return $items
            ->map(fn (NotificationTemplate $template): string => 'template#'.$template->id.' '.$template->code)
            ->all();
    }

    /**
     * @param  Collection<int, NotificationOutbox>  $items
     * @return array<int, string>
     */
    private function notificationOutboxList(Collection $items): array
    {
        return $items
            ->map(fn (NotificationOutbox $notification): string => 'notification#'.$notification->id.' order#'.($notification->order_id ?? '-').' '.$notification->event.'/'.$notification->channel.' status='.(string) $notification->status)
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
