<?php

namespace Tests\Feature;

use App\Models\CommerceSetting;
use App\Models\Currency;
use App\Models\Order;
use App\Models\ProductPrice;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use RuntimeException;
use Tests\Feature\Concerns\CreatesCommerceData;
use Tests\TestCase;

class CommerceArchitectureTest extends TestCase
{
    use CreatesCommerceData;
    use RefreshDatabase;

    public function test_database_seeder_creates_default_commerce_infrastructure(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('currencies', [
            'code' => 'UAH',
            'is_base' => true,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('warehouses', [
            'name' => 'Основний склад',
            'is_default' => true,
            'is_active' => true,
        ]);

        $settings = CommerceSetting::current();

        $this->assertFalse($settings->multi_currency_enabled);
        $this->assertFalse($settings->multi_warehouse_enabled);
        $this->assertSame('UAH', $settings->defaultCurrency->code);
        $this->assertSame('Основний склад', $settings->defaultWarehouse->name);
    }

    public function test_product_in_simple_mode_has_one_default_price_and_stock_balance(): void
    {
        $product = $this->createProduct([
            'price' => 1500,
            'old_price' => 1800,
            'stock' => 7,
        ]);

        $settings = CommerceSetting::current();

        $this->assertDatabaseHas('product_prices', [
            'product_id' => $product->id,
            'currency_id' => $settings->default_currency_id,
            'price' => 1500,
            'compare_at_price' => 1800,
        ]);

        $this->assertDatabaseHas('stock_balances', [
            'product_id' => $product->id,
            'warehouse_id' => $settings->default_warehouse_id,
            'quantity' => 7,
            'reserved_quantity' => 0,
        ]);

        $this->assertSame(1, $product->prices()->count());
        $this->assertSame(1, $product->stockBalances()->count());
    }

    public function test_multi_currency_mode_allows_multiple_product_prices(): void
    {
        $product = $this->createProduct();
        $settings = CommerceSetting::current();
        $settings->update(['multi_currency_enabled' => true]);

        $usd = Currency::create([
            'code' => 'USD',
            'name' => 'US Dollar',
            'symbol' => '$',
            'precision' => 2,
            'rate_to_base' => '40.000000',
            'is_active' => true,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'currency_id' => $usd->id,
            'price' => 25,
            'is_active' => true,
        ]);

        $this->assertSame(2, $product->prices()->count());
        $this->assertDatabaseHas('product_prices', [
            'product_id' => $product->id,
            'currency_id' => $usd->id,
            'price' => 25,
        ]);
    }

    public function test_only_one_base_currency_can_exist(): void
    {
        $uah = Currency::ensureDefault();

        $usd = Currency::create([
            'code' => 'USD',
            'name' => 'US Dollar',
            'symbol' => '$',
            'precision' => 2,
            'rate_to_base' => '40.000000',
            'is_base' => true,
            'is_active' => true,
        ]);

        $this->assertTrue($usd->fresh()->is_base);
        $this->assertFalse($uah->fresh()->is_base);
        $this->assertSame(1, Currency::where('is_base', true)->count());
    }

    public function test_multi_warehouse_mode_allows_multiple_stock_balances(): void
    {
        $product = $this->createProduct(['stock' => 5]);
        $settings = CommerceSetting::current();
        $settings->update(['multi_warehouse_enabled' => true]);

        $secondaryWarehouse = Warehouse::create([
            'name' => 'Резервний склад',
            'code' => 'reserve',
            'is_active' => true,
        ]);

        StockBalance::create([
            'product_id' => $product->id,
            'warehouse_id' => $secondaryWarehouse->id,
            'quantity' => 3,
            'reserved_quantity' => 1,
        ]);

        $this->assertSame(2, $product->stockBalances()->count());
        $this->assertSame(5, $product->fresh()->stock);
        $this->assertSame(2.0, StockBalance::where('warehouse_id', $secondaryWarehouse->id)->firstOrFail()->available_quantity);
    }

    public function test_only_one_default_warehouse_can_exist(): void
    {
        $main = Warehouse::ensureDefault();

        $secondaryWarehouse = Warehouse::create([
            'name' => 'Резервний склад',
            'code' => 'reserve',
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->assertTrue($secondaryWarehouse->fresh()->is_default);
        $this->assertFalse($main->fresh()->is_default);
        $this->assertSame(1, Warehouse::where('is_default', true)->count());
    }

    public function test_stock_balance_changes_are_audited_with_movements(): void
    {
        $product = $this->createProduct(['stock' => 5]);

        $product->update(['stock' => 9]);

        $movement = StockMovement::where('product_id', $product->id)
            ->where('type', StockMovement::TYPE_ADJUSTMENT)
            ->firstOrFail();

        $this->assertSame('4.000', $movement->quantity);
        $this->assertSame('9.000', $movement->balance_after);
    }

    public function test_stock_changes_cannot_drive_default_balance_negative(): void
    {
        $product = $this->createProduct(['stock' => 1]);

        $this->expectException(RuntimeException::class);

        $product->applyStockChange(-2, StockMovement::TYPE_SALE);
    }

    public function test_order_in_simple_mode_receives_default_currency_and_warehouse(): void
    {
        $product = $this->createProduct();
        $settings = CommerceSetting::current();

        $order = Order::create([
            'customer_name' => 'Тестовий Покупець',
            'phone' => '+380501112233',
            'total_amount' => 1000,
            'status' => 'new',
        ]);

        $item = $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 1,
            'price' => 1000,
            'total' => 1000,
        ]);

        $order->refresh();
        $item->refresh();

        $this->assertSame($settings->default_currency_id, $order->currency_id);
        $this->assertSame('UAH', $order->currency_code);
        $this->assertSame($settings->default_warehouse_id, $order->warehouse_id);
        $this->assertSame($settings->default_warehouse_id, $item->warehouse_id);
        $this->assertSame('1000.00', $item->unit_price);
    }

    public function test_default_currency_and_warehouse_cannot_be_deleted_while_used_by_settings(): void
    {
        $settings = CommerceSetting::current();

        try {
            $settings->defaultCurrency->delete();
            $this->fail('Default currency was deleted.');
        } catch (LogicException) {
            $this->assertDatabaseHas('currencies', ['id' => $settings->default_currency_id]);
        }

        try {
            $settings->defaultWarehouse->delete();
            $this->fail('Default warehouse was deleted.');
        } catch (LogicException) {
            $this->assertDatabaseHas('warehouses', ['id' => $settings->default_warehouse_id]);
        }
    }

    public function test_currency_cannot_be_deleted_while_used_by_prices_or_orders(): void
    {
        $product = $this->createProduct();
        $usd = Currency::create([
            'code' => 'USD',
            'name' => 'US Dollar',
            'symbol' => '$',
            'precision' => 2,
            'rate_to_base' => '40.000000',
            'is_active' => true,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'currency_id' => $usd->id,
            'price' => 25,
            'is_active' => true,
        ]);

        try {
            $usd->delete();
            $this->fail('Currency used by product prices was deleted.');
        } catch (LogicException) {
            $this->assertDatabaseHas('currencies', ['id' => $usd->id]);
        }

        $usd->productPrices()->delete();

        Order::create([
            'currency_id' => $usd->id,
            'customer_name' => 'Тестовий Покупець',
            'phone' => '+380501112233',
            'total_amount' => 25,
            'status' => 'new',
        ]);

        try {
            $usd->delete();
            $this->fail('Currency used by orders was deleted.');
        } catch (LogicException) {
            $this->assertDatabaseHas('currencies', ['id' => $usd->id]);
        }
    }

    public function test_warehouse_cannot_be_deleted_while_used_by_stock_or_orders(): void
    {
        $product = $this->createProduct();
        $warehouse = Warehouse::create([
            'name' => 'Резервний склад',
            'code' => 'reserve',
            'is_active' => true,
        ]);

        StockBalance::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 3,
            'reserved_quantity' => 0,
        ]);

        try {
            $warehouse->delete();
            $this->fail('Warehouse used by stock balances was deleted.');
        } catch (LogicException) {
            $this->assertDatabaseHas('warehouses', ['id' => $warehouse->id]);
        }

        $warehouse->stockBalances()->delete();
        StockMovement::where('warehouse_id', $warehouse->id)->delete();

        Order::create([
            'warehouse_id' => $warehouse->id,
            'customer_name' => 'Тестовий Покупець',
            'phone' => '+380501112233',
            'total_amount' => 1000,
            'status' => 'new',
        ]);

        try {
            $warehouse->delete();
            $this->fail('Warehouse used by orders was deleted.');
        } catch (LogicException) {
            $this->assertDatabaseHas('warehouses', ['id' => $warehouse->id]);
        }
    }

    public function test_commerce_settings_current_is_singleton_and_sets_defaults(): void
    {
        $settings = CommerceSetting::current();

        $this->assertNotNull($settings->default_currency_id);
        $this->assertNotNull($settings->default_warehouse_id);

        $this->expectException(LogicException::class);

        CommerceSetting::create([
            'multi_currency_enabled' => false,
            'multi_warehouse_enabled' => false,
            'default_currency_id' => $settings->default_currency_id,
            'default_warehouse_id' => $settings->default_warehouse_id,
        ]);
    }
}
