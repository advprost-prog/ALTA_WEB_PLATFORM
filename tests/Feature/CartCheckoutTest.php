<?php

namespace Tests\Feature;

use App\Models\CommerceSetting;
use App\Models\Currency;
use App\Models\Order;
use App\Models\ProductPrice;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Commerce\ProductPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesCommerceData;
use Tests\TestCase;

class CartCheckoutTest extends TestCase
{
    use CreatesCommerceData;
    use RefreshDatabase;

    public function test_product_can_be_added_to_cart(): void
    {
        $product = $this->createProduct();

        $this->post(route('cart.add', $product), ['quantity' => 2])
            ->assertRedirect()
            ->assertSessionHas('cart.'.$product->id, 2);
    }

    public function test_out_of_stock_product_is_not_added_to_cart(): void
    {
        $product = $this->createProduct(['stock' => 0, 'stock_status' => 'out_of_stock']);

        $this->post(route('cart.add', $product), ['quantity' => 1])
            ->assertRedirect()
            ->assertSessionMissing('cart.'.$product->id);
    }

    public function test_cart_page_opens_and_shows_product(): void
    {
        $product = $this->createProduct();

        $this->withSession(['cart' => [$product->id => 1]])
            ->get(route('cart'))
            ->assertOk()
            ->assertSee($product->name);
    }

    public function test_negative_cart_quantity_removes_product(): void
    {
        $product = $this->createProduct();

        $this->withSession(['cart' => [$product->id => 2]])
            ->patch(route('cart.update'), ['quantities' => [$product->id => -5]])
            ->assertRedirect()
            ->assertSessionHas('cart', []);
    }

    public function test_inactive_product_is_cleaned_from_cart(): void
    {
        $product = $this->createProduct(['is_active' => false]);

        $this->withSession(['cart' => [$product->id => 1]])
            ->get(route('cart'))
            ->assertOk()
            ->assertDontSee($product->name)
            ->assertSessionHas('cart', []);
    }

    public function test_checkout_opens_when_cart_has_product(): void
    {
        $product = $this->createProduct();

        $this->withSession(['cart' => [$product->id => 1]])
            ->get(route('checkout'))
            ->assertOk()
            ->assertSee('Оформлення замовлення');
    }

    public function test_empty_checkout_redirects_to_cart(): void
    {
        $this->get(route('checkout'))->assertRedirect(route('cart'));
    }

    public function test_currency_switcher_is_hidden_when_multi_currency_is_disabled(): void
    {
        CommerceSetting::current()->update(['multi_currency_enabled' => false]);

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('currency-switcher', false);
    }

    public function test_currency_switcher_is_visible_and_selection_is_stored_when_multi_currency_is_enabled(): void
    {
        $usd = $this->createCurrency('USD', '$');
        CommerceSetting::current()->update(['multi_currency_enabled' => true]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('currency-switcher', false)
            ->assertSee('USD');

        $this->post(route('currency.switch'), [
            'currency_id' => $usd->id,
            'redirect_to' => route('catalog'),
        ])
            ->assertRedirect(route('catalog'))
            ->assertSessionHas(ProductPricingService::SESSION_CURRENCY_ID, $usd->id);
    }

    public function test_product_shows_selected_currency_price_when_it_exists(): void
    {
        $product = $this->createProduct();
        $usd = $this->createCurrency('USD', '$');
        CommerceSetting::current()->update(['multi_currency_enabled' => true]);

        ProductPrice::create([
            'product_id' => $product->id,
            'currency_id' => $usd->id,
            'price' => 25,
            'compare_at_price' => 30,
            'is_active' => true,
        ]);

        $this->withSession([ProductPricingService::SESSION_CURRENCY_ID => $usd->id])
            ->get(route('product.show', $product))
            ->assertOk()
            ->assertSee('25 $')
            ->assertSee('30 $')
            ->assertDontSee('1 000 ₴');
    }

    public function test_product_does_not_auto_convert_when_selected_currency_price_is_missing(): void
    {
        $product = $this->createProduct();
        $usd = $this->createCurrency('USD', '$');
        CommerceSetting::current()->update(['multi_currency_enabled' => true]);

        $this->withSession([ProductPricingService::SESSION_CURRENCY_ID => $usd->id])
            ->get(route('product.show', $product))
            ->assertOk()
            ->assertSee('Ціна показана у валюті магазину')
            ->assertSee('1 000 ₴')
            ->assertDontSee('25 $');

        $this->withSession([ProductPricingService::SESSION_CURRENCY_ID => $usd->id])
            ->post(route('cart.add', $product), ['quantity' => 1])
            ->assertRedirect()
            ->assertSessionMissing('cart.'.$product->id);
    }

    public function test_product_detail_uses_default_product_price_in_simple_mode(): void
    {
        $product = $this->createProduct();
        $settings = CommerceSetting::current();

        ProductPrice::where('product_id', $product->id)
            ->where('currency_id', $settings->default_currency_id)
            ->firstOrFail()
            ->update([
                'price' => 1500,
                'compare_at_price' => 1700,
            ]);

        $product->forceFill([
            'price' => 999,
            'old_price' => 1099,
        ])->saveQuietly();

        $this->get(route('product.show', $product))
            ->assertOk()
            ->assertSee('1 500 ₴')
            ->assertSee('1 700 ₴')
            ->assertDontSee('999 ₴');
    }

    public function test_product_availability_uses_default_stock_balance_in_simple_mode(): void
    {
        $product = $this->createProduct(['stock' => 5]);
        $settings = CommerceSetting::current();

        StockBalance::where('product_id', $product->id)
            ->where('warehouse_id', $settings->default_warehouse_id)
            ->firstOrFail()
            ->update(['quantity' => 2]);

        $product->forceFill(['stock' => 5])->saveQuietly();

        $this->get(route('product.show', $product))
            ->assertOk()
            ->assertSee('Залишок: 2 шт');
    }

    public function test_cart_cannot_exceed_available_stock_balance(): void
    {
        $product = $this->createProduct(['stock' => 5]);
        $settings = CommerceSetting::current();

        StockBalance::where('product_id', $product->id)
            ->where('warehouse_id', $settings->default_warehouse_id)
            ->firstOrFail()
            ->update(['quantity' => 2]);

        $this->post(route('cart.add', $product), ['quantity' => 5])
            ->assertRedirect()
            ->assertSessionHas('cart.'.$product->id, 2);
    }

    public function test_order_is_created_and_redirects_to_thank_you(): void
    {
        $product = $this->createProduct(['stock' => 2]);
        $settings = CommerceSetting::current();

        $response = $this->withSession(['cart' => [$product->id => 2]])
            ->post(route('checkout.place'), $this->checkoutData());

        $order = Order::firstOrFail();
        $item = $order->items()->firstOrFail();

        $response->assertRedirect(route('checkout.thank-you', $order));
        $this->assertDatabaseHas('orders', [
            'phone' => '+380501112233',
            'total_amount' => 2000,
            'currency_id' => $settings->default_currency_id,
            'currency_code' => 'UAH',
            'warehouse_id' => $settings->default_warehouse_id,
        ]);
        $this->assertSame($settings->default_warehouse_id, $item->warehouse_id);
        $this->assertSame('1000.00', $item->unit_price);
        $this->assertSame('1000.00', $item->price);
        $this->assertSame('2000.00', $item->total);
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame('out_of_stock', $product->fresh()->stock_status);

        $balance = StockBalance::where('product_id', $product->id)
            ->where('warehouse_id', $settings->default_warehouse_id)
            ->firstOrFail();

        $this->assertSame('0.000', $balance->quantity);

        $movement = StockMovement::where('product_id', $product->id)
            ->where('warehouse_id', $settings->default_warehouse_id)
            ->where('type', StockMovement::TYPE_SALE)
            ->firstOrFail();

        $this->assertSame('-2.000', $movement->quantity);
        $this->assertSame('0.000', $movement->balance_after);
    }

    public function test_order_item_price_snapshot_does_not_change_after_product_price_changes(): void
    {
        $product = $this->createProduct(['price' => 1000, 'stock' => 2]);

        $this->withSession(['cart' => [$product->id => 1]])
            ->post(route('checkout.place'), $this->checkoutData())
            ->assertRedirect();

        $item = Order::firstOrFail()->items()->firstOrFail();

        $product->update(['price' => 2500, 'old_price' => 3000]);

        $item->refresh();

        $this->assertSame('1000.00', $item->unit_price);
        $this->assertSame('1000.00', $item->price);
        $this->assertSame('1000.00', $item->total);
    }

    public function test_checkout_cannot_be_submitted_twice_with_the_same_token(): void
    {
        $product = $this->createProduct(['stock' => 2]);

        $this->withSession(['cart' => [$product->id => 1]])
            ->get(route('checkout'))
            ->assertOk();

        $checkoutToken = session('storefront_checkout_token');

        $this->post(route('checkout.place'), $this->checkoutData($checkoutToken, primeSession: false))
            ->assertRedirect();

        $this->assertSame(1, Order::count());

        session(['cart' => [$product->id => 1]]);

        $this->post(route('checkout.place'), $this->checkoutData($checkoutToken, primeSession: false))
            ->assertRedirect(route('cart'));

        $this->assertSame(1, Order::count());
    }

    public function test_checkout_token_is_preserved_after_validation_failure_and_can_be_reused(): void
    {
        $product = $this->createProduct(['stock' => 1]);

        $this->withSession(['cart' => [$product->id => 1]])
            ->get(route('checkout'))
            ->assertOk();

        $checkoutToken = session('storefront_checkout_token');

        $this->post(route('checkout.place'), [
            'checkout_token' => $checkoutToken,
            'name' => 'Test Buyer',
            'email' => 'buyer@example.test',
            'city' => 'Київ',
            'address' => 'Відділення 1',
            'delivery_method' => 'Нова пошта',
            'payment_method' => 'Післяплата',
        ])->assertSessionHasErrors(['phone']);

        $this->withSession(['cart' => [$product->id => 1]])
            ->post(route('checkout.place'), $this->checkoutData($checkoutToken, primeSession: false))
            ->assertRedirect();

        $this->assertSame(1, Order::count());
    }

    public function test_checkout_blocks_missing_price_in_selected_currency_without_creating_order(): void
    {
        $product = $this->createProduct(['stock' => 2]);
        $usd = $this->createCurrency('USD', '$');
        CommerceSetting::current()->update(['multi_currency_enabled' => true]);

        $this->withSession([
            ProductPricingService::SESSION_CURRENCY_ID => $usd->id,
            'cart' => [$product->id => 1],
        ])
            ->post(route('checkout.place'), $this->checkoutData())
            ->assertRedirect(route('cart'));

        $this->assertSame(0, Order::count());
        $this->assertSame(0, StockMovement::where('product_id', $product->id)->where('type', StockMovement::TYPE_SALE)->count());
    }

    public function test_checkout_uses_selected_currency_price_snapshot(): void
    {
        $product = $this->createProduct(['stock' => 3]);
        $usd = $this->createCurrency('USD', '$');
        CommerceSetting::current()->update(['multi_currency_enabled' => true]);

        ProductPrice::create([
            'product_id' => $product->id,
            'currency_id' => $usd->id,
            'price' => 25,
            'compare_at_price' => null,
            'is_active' => true,
        ]);

        $this->withSession([
            ProductPricingService::SESSION_CURRENCY_ID => $usd->id,
            'cart' => [$product->id => 2],
        ])
            ->post(route('checkout.place'), $this->checkoutData())
            ->assertRedirect();

        $order = Order::firstOrFail();
        $item = $order->items()->firstOrFail();

        $this->assertSame($usd->id, $order->currency_id);
        $this->assertSame('USD', $order->currency_code);
        $this->assertSame('25.00', $item->unit_price);
        $this->assertSame('50.00', $item->total);
    }

    public function test_checkout_falls_back_to_default_currency_when_selected_currency_becomes_inactive(): void
    {
        $product = $this->createProduct(['stock' => 2]);
        $usd = $this->createCurrency('USD', '$');
        $settings = CommerceSetting::current();
        $settings->update(['multi_currency_enabled' => true]);

        session([
            ProductPricingService::SESSION_CURRENCY_ID => $usd->id,
            'cart' => [$product->id => 1],
        ]);

        $usd->update(['is_active' => false]);

        $this->post(route('checkout.place'), $this->checkoutData())
            ->assertRedirect();

        $order = Order::firstOrFail();

        $this->assertSame($settings->default_currency_id, $order->currency_id);
        $this->assertSame('UAH', $order->currency_code);
    }

    public function test_checkout_falls_back_to_another_active_warehouse_without_showing_split_fulfillment(): void
    {
        $product = $this->createProduct(['stock' => 1]);
        $settings = CommerceSetting::current();
        $settings->update(['multi_warehouse_enabled' => true]);
        $secondaryWarehouse = $this->createWarehouse('secondary', 'Резервний склад');

        StockBalance::where('product_id', $product->id)
            ->where('warehouse_id', $settings->default_warehouse_id)
            ->firstOrFail()
            ->update(['quantity' => 1]);

        StockBalance::create([
            'product_id' => $product->id,
            'warehouse_id' => $secondaryWarehouse->id,
            'quantity' => 3,
            'reserved_quantity' => 0,
        ]);

        $this->withSession(['cart' => [$product->id => 2]])
            ->post(route('checkout.place'), $this->checkoutData())
            ->assertRedirect();

        $order = Order::firstOrFail();
        $item = $order->items()->firstOrFail();

        $this->assertSame($secondaryWarehouse->id, $item->warehouse_id);
        $this->assertSame($secondaryWarehouse->id, $order->warehouse_id);
        $this->assertSame('1.000', StockBalance::where('product_id', $product->id)->where('warehouse_id', $settings->default_warehouse_id)->firstOrFail()->quantity);
        $this->assertSame('1.000', StockBalance::where('product_id', $product->id)->where('warehouse_id', $secondaryWarehouse->id)->firstOrFail()->quantity);

        $movement = StockMovement::where('product_id', $product->id)
            ->where('warehouse_id', $secondaryWarehouse->id)
            ->where('type', StockMovement::TYPE_SALE)
            ->firstOrFail();

        $this->assertSame('-2.000', $movement->quantity);
    }

    public function test_checkout_blocks_when_no_single_warehouse_can_fulfill_quantity(): void
    {
        $product = $this->createProduct(['stock' => 1]);
        $settings = CommerceSetting::current();
        $settings->update(['multi_warehouse_enabled' => true]);
        $secondaryWarehouse = $this->createWarehouse('secondary', 'Резервний склад');

        StockBalance::where('product_id', $product->id)
            ->where('warehouse_id', $settings->default_warehouse_id)
            ->firstOrFail()
            ->update(['quantity' => 1]);

        StockBalance::create([
            'product_id' => $product->id,
            'warehouse_id' => $secondaryWarehouse->id,
            'quantity' => 1,
            'reserved_quantity' => 0,
        ]);

        $this->withSession(['cart' => [$product->id => 2]])
            ->post(route('checkout.place'), $this->checkoutData())
            ->assertRedirect(route('cart'));

        $this->assertSame(0, Order::count());
        $this->assertSame(0, StockMovement::where('product_id', $product->id)->where('type', StockMovement::TYPE_SALE)->count());
        $this->assertSame('1.000', StockBalance::where('product_id', $product->id)->where('warehouse_id', $settings->default_warehouse_id)->firstOrFail()->quantity);
        $this->assertSame('1.000', StockBalance::where('product_id', $product->id)->where('warehouse_id', $secondaryWarehouse->id)->firstOrFail()->quantity);
    }

    public function test_checkout_blocks_unavailable_stock_without_negative_balance_or_partial_order(): void
    {
        $product = $this->createProduct(['stock' => 0, 'stock_status' => 'out_of_stock']);
        $settings = CommerceSetting::current();

        $this->withSession(['cart' => [$product->id => 1]])
            ->post(route('checkout.place'), $this->checkoutData())
            ->assertRedirect(route('cart'));

        $this->assertSame(0, Order::count());
        $this->assertSame(0, StockMovement::where('product_id', $product->id)->count());

        $balance = StockBalance::where('product_id', $product->id)
            ->where('warehouse_id', $settings->default_warehouse_id)
            ->firstOrFail();

        $this->assertSame('0.000', $balance->quantity);
    }

    private function createCurrency(string $code, string $symbol): Currency
    {
        return Currency::create([
            'code' => $code,
            'name' => $code.' currency',
            'symbol' => $symbol,
            'precision' => 2,
            'rate_to_base' => '40.000000',
            'is_base' => false,
            'is_active' => true,
        ]);
    }

    private function createWarehouse(string $code, string $name): Warehouse
    {
        return Warehouse::create([
            'name' => $name,
            'code' => $code,
            'address' => null,
            'is_default' => false,
            'is_active' => true,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function checkoutData(?string $checkoutToken = null, bool $primeSession = true): array
    {
        $checkoutToken ??= 'checkout-test-token';

        if ($primeSession) {
            session(['storefront_checkout_token' => $checkoutToken]);
        }

        return [
            'checkout_token' => $checkoutToken,
            'name' => 'Test Buyer',
            'phone' => '+380501112233',
            'email' => 'buyer@example.test',
            'city' => 'Київ',
            'address' => 'Відділення 1',
            'delivery_method' => 'Нова пошта',
            'payment_method' => 'Післяплата',
            'customer_comment' => 'Тест',
        ];
    }
}
