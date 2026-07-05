<?php

namespace Tests\Feature;

use App\Models\Order;
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
            ->assertSessionHas('cart.' . $product->id, 2);
    }

    public function test_out_of_stock_product_is_not_added_to_cart(): void
    {
        $product = $this->createProduct(['stock' => 0, 'stock_status' => 'out_of_stock']);

        $this->post(route('cart.add', $product), ['quantity' => 1])
            ->assertRedirect()
            ->assertSessionMissing('cart.' . $product->id);
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

    public function test_order_is_created_and_redirects_to_thank_you(): void
    {
        $product = $this->createProduct(['stock' => 2]);

        $response = $this->withSession(['cart' => [$product->id => 2]])
            ->post(route('checkout.place'), [
                'name' => 'Test Buyer',
                'phone' => '+380501112233',
                'email' => 'buyer@example.test',
                'city' => 'Київ',
                'address' => 'Відділення 1',
                'delivery_method' => 'Нова пошта',
                'payment_method' => 'Післяплата',
                'customer_comment' => 'Тест',
            ]);

        $order = Order::first();

        $response->assertRedirect(route('checkout.thank-you', $order));
        $this->assertDatabaseHas('orders', ['phone' => '+380501112233', 'total_amount' => 2000]);
        $this->assertDatabaseHas('order_items', ['product_id' => $product->id, 'quantity' => 2]);
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame('out_of_stock', $product->fresh()->stock_status);
    }
}
