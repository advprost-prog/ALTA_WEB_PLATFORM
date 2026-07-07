<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CommerceSetting;
use App\Models\Currency;
use App\Models\Order;
use App\Models\ProductPrice;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Commerce\StockService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Feature\Concerns\CreatesCommerceData;
use Tests\TestCase;

class CommerceOperationsTest extends TestCase
{
    use CreatesCommerceData;
    use RefreshDatabase;

    public function test_manual_stock_adjustment_updates_balance_and_creates_movement(): void
    {
        $user = $this->createUserWithRole(UserRole::Manager);
        $product = $this->createProduct(['stock' => 5]);
        $settings = CommerceSetting::current();

        $movement = app(StockService::class)->setQuantity(
            subject: $product,
            warehouseId: $settings->default_warehouse_id,
            newQuantity: 9,
            note: 'Перерахунок полиці',
            createdBy: $user->id,
        );

        $balance = StockBalance::where('product_id', $product->id)
            ->where('warehouse_id', $settings->default_warehouse_id)
            ->firstOrFail();

        $this->assertSame('9.000', $balance->quantity);
        $this->assertSame(9, $product->fresh()->stock);
        $this->assertNotNull($movement);
        $this->assertSame(StockMovement::TYPE_ADJUSTMENT, $movement->type);
        $this->assertSame('4.000', $movement->quantity);
        $this->assertSame('9.000', $movement->balance_after);
        $this->assertSame('Перерахунок полиці', $movement->note);
        $this->assertSame($user->id, $movement->created_by);
    }

    public function test_manual_stock_adjustment_does_not_allow_quantity_lower_than_reserved_quantity(): void
    {
        $product = $this->createProduct(['stock' => 5]);
        $settings = CommerceSetting::current();

        StockBalance::where('product_id', $product->id)
            ->where('warehouse_id', $settings->default_warehouse_id)
            ->firstOrFail()
            ->update(['reserved_quantity' => 2]);

        $this->expectException(RuntimeException::class);

        app(StockService::class)->setQuantity(
            subject: $product,
            warehouseId: $settings->default_warehouse_id,
            newQuantity: 1,
            note: 'Неможливе коригування',
        );
    }

    public function test_product_simple_stock_edit_creates_adjustment_movement_only_when_value_changed(): void
    {
        $product = $this->createProduct(['stock' => 5]);

        $product->update(['stock' => 5]);

        $this->assertSame(0, StockMovement::where('product_id', $product->id)->count());

        $product->update(['stock' => 8]);

        $movement = StockMovement::where('product_id', $product->id)->firstOrFail();

        $this->assertSame(StockMovement::TYPE_ADJUSTMENT, $movement->type);
        $this->assertSame('3.000', $movement->quantity);
        $this->assertSame('8.000', $movement->balance_after);
        $this->assertSame('Ручне коригування з картки товару', $movement->note);
    }

    public function test_product_simple_price_edit_updates_default_product_price(): void
    {
        $product = $this->createProduct(['price' => 1000, 'old_price' => 1200]);
        $settings = CommerceSetting::current();

        $product->update(['price' => 1500, 'old_price' => 1800]);

        $price = ProductPrice::where('product_id', $product->id)
            ->where('currency_id', $settings->default_currency_id)
            ->firstOrFail();

        $this->assertSame('1500.00', $price->price);
        $this->assertSame('1800.00', $price->compare_at_price);
    }

    public function test_multi_currency_mode_prevents_duplicate_product_prices_per_currency(): void
    {
        $product = $this->createProduct();
        $currency = Currency::create([
            'code' => 'USD',
            'name' => 'US Dollar',
            'symbol' => '$',
            'precision' => 2,
            'rate_to_base' => '40.000000',
            'is_active' => true,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'currency_id' => $currency->id,
            'price' => 25,
            'is_active' => true,
        ]);

        $this->expectException(QueryException::class);

        ProductPrice::create([
            'product_id' => $product->id,
            'currency_id' => $currency->id,
            'price' => 26,
            'is_active' => true,
        ]);
    }

    public function test_multi_warehouse_mode_handles_separate_stock_balances(): void
    {
        $product = $this->createProduct(['stock' => 5]);
        CommerceSetting::current()->update(['multi_warehouse_enabled' => true]);
        $secondary = Warehouse::create([
            'name' => 'Другий склад',
            'code' => 'secondary',
            'is_active' => true,
        ]);

        app(StockService::class)->setQuantity($product, $secondary->id, 4, 'Окремий склад');

        $this->assertSame('5.000', StockBalance::where('product_id', $product->id)->where('warehouse_id', CommerceSetting::current()->default_warehouse_id)->firstOrFail()->quantity);
        $this->assertSame('4.000', StockBalance::where('product_id', $product->id)->where('warehouse_id', $secondary->id)->firstOrFail()->quantity);
        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_warehouse_transfer_decreases_source_increases_target_and_creates_movements(): void
    {
        $product = $this->createProduct(['stock' => 10]);
        $settings = CommerceSetting::current();
        $settings->update(['multi_warehouse_enabled' => true]);
        $target = Warehouse::create([
            'name' => 'Другий склад',
            'code' => 'secondary',
            'is_active' => true,
        ]);

        app(StockService::class)->setQuantity($product, $target->id, 1, 'Початковий залишок');
        StockMovement::query()->delete();

        app(StockService::class)->transfer(
            subject: $product,
            sourceWarehouseId: $settings->default_warehouse_id,
            targetWarehouseId: $target->id,
            quantity: 3,
            note: 'Переміщення тест',
        );

        $this->assertSame('7.000', StockBalance::where('product_id', $product->id)->where('warehouse_id', $settings->default_warehouse_id)->firstOrFail()->quantity);
        $this->assertSame('4.000', StockBalance::where('product_id', $product->id)->where('warehouse_id', $target->id)->firstOrFail()->quantity);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'warehouse_id' => $settings->default_warehouse_id,
            'type' => StockMovement::TYPE_TRANSFER_OUT,
            'quantity' => -3,
            'balance_after' => 7,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'warehouse_id' => $target->id,
            'type' => StockMovement::TYPE_TRANSFER_IN,
            'quantity' => 3,
            'balance_after' => 4,
        ]);
    }

    public function test_warehouse_transfer_rolls_back_if_source_stock_is_insufficient(): void
    {
        $product = $this->createProduct(['stock' => 2]);
        $settings = CommerceSetting::current();
        $settings->update(['multi_warehouse_enabled' => true]);
        $target = Warehouse::create([
            'name' => 'Другий склад',
            'code' => 'secondary',
            'is_active' => true,
        ]);

        app(StockService::class)->setQuantity($product, $target->id, 1, 'Початковий залишок');
        StockMovement::query()->delete();

        try {
            app(StockService::class)->transfer(
                subject: $product,
                sourceWarehouseId: $settings->default_warehouse_id,
                targetWarehouseId: $target->id,
                quantity: 5,
                note: 'Неможливе переміщення',
            );

            $this->fail('Transfer succeeded with insufficient stock.');
        } catch (RuntimeException) {
            $this->assertSame('2.000', StockBalance::where('product_id', $product->id)->where('warehouse_id', $settings->default_warehouse_id)->firstOrFail()->quantity);
            $this->assertSame('1.000', StockBalance::where('product_id', $product->id)->where('warehouse_id', $target->id)->firstOrFail()->quantity);
            $this->assertSame(0, StockMovement::where('product_id', $product->id)->count());
        }
    }

    public function test_warehouse_transfer_page_is_unavailable_when_multi_warehouse_is_disabled(): void
    {
        CommerceSetting::current()->update(['multi_warehouse_enabled' => false]);

        $this->actingAs($this->createUserWithRole(UserRole::Admin))
            ->get('/admin/warehouse-transfer')
            ->assertForbidden();
    }

    public function test_stock_movements_are_not_directly_editable(): void
    {
        $product = $this->createProduct(['stock' => 5]);
        $settings = CommerceSetting::current();
        $movement = app(StockService::class)->setQuantity($product, $settings->default_warehouse_id, 7, 'Аудит');

        $this->actingAs($this->createUserWithRole(UserRole::Admin))
            ->get('/admin/stock-movements/'.$movement->id.'/edit')
            ->assertNotFound();
    }

    public function test_order_edit_route_shows_read_only_snapshot_view(): void
    {
        $user = $this->createUserWithRole(UserRole::Admin);
        $order = Order::create([
            'customer_name' => 'Snapshot Customer',
            'phone' => '+380501112233',
            'total_amount' => 1000,
            'status' => 'new',
        ]);

        $this->actingAs($user)
            ->get('/admin/orders/'.$order->id.'/edit')
            ->assertOk()
            ->assertSee($order->number)
            ->assertDontSee('Зберегти');
    }
}
