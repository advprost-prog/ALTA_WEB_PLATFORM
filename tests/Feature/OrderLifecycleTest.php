<?php

namespace Tests\Feature;

use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\CommerceSetting;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Services\Commerce\OrderLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Feature\Concerns\CreatesCommerceData;
use Tests\TestCase;

class OrderLifecycleTest extends TestCase
{
    use CreatesCommerceData;
    use RefreshDatabase;

    public function test_admin_can_progress_order_lifecycle(): void
    {
        [$order] = $this->placeCheckoutOrder(quantity: 1, stock: 4);
        $user = $this->createUserWithRole(UserRole::Manager);
        $service = app(OrderLifecycleService::class);

        $service->confirm($order, $user, 'Підтверджено менеджером');
        $order->refresh();

        $this->assertSame(OrderStatus::Confirmed->value, $order->status);
        $this->assertNotNull($order->confirmed_at);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'type' => OrderStatusHistory::TYPE_STATUS,
            'from_value' => OrderStatus::New->value,
            'to_value' => OrderStatus::Confirmed->value,
            'created_by' => $user->id,
            'comment' => 'Підтверджено менеджером',
        ]);

        $service->markProcessing($order, $user);
        $order->refresh();
        $this->assertSame(OrderStatus::Processing->value, $order->status);
        $this->assertSame(DeliveryStatus::Preparing->value, $order->delivery_status);

        $service->markPaid($order, $user);
        $order->refresh();
        $this->assertSame(PaymentStatus::Paid->value, $order->payment_status);
        $this->assertNotNull($order->paid_at);

        $service->markReadyToShip($order, $user);
        $order->refresh();
        $this->assertSame(OrderStatus::ReadyToShip->value, $order->status);
        $this->assertSame(DeliveryStatus::ReadyToShip->value, $order->delivery_status);

        $service->markShipped($order, $user);
        $order->refresh();
        $this->assertSame(OrderStatus::Shipped->value, $order->status);
        $this->assertSame(DeliveryStatus::Shipped->value, $order->delivery_status);
        $this->assertNotNull($order->shipped_at);

        $service->markCompleted($order, $user);
        $order->refresh();
        $this->assertSame(OrderStatus::Completed->value, $order->status);
        $this->assertNotNull($order->completed_at);
    }

    public function test_invalid_transition_is_blocked_and_completed_order_cannot_be_changed(): void
    {
        [$order] = $this->placeCheckoutOrder(quantity: 1, stock: 4);
        $user = $this->createUserWithRole(UserRole::Admin);
        $service = app(OrderLifecycleService::class);

        $this->assertThrows(
            fn () => $service->markCompleted($order, $user),
            RuntimeException::class,
        );

        $service->confirm($order, $user);
        $service->markProcessing($order, $user);
        $service->markReadyToShip($order, $user);
        $service->markShipped($order, $user);
        $service->markCompleted($order, $user);

        $order->refresh();
        $this->assertSame(OrderStatus::Completed->value, $order->status);

        $this->assertThrows(
            fn () => $service->markPaid($order, $user),
            RuntimeException::class,
        );

        $this->assertThrows(
            fn () => $service->cancel($order, $user, 'Пізнє скасування'),
            RuntimeException::class,
        );
    }

    public function test_cancelling_non_shipped_order_restores_stock_and_creates_return_movement(): void
    {
        [$order, $product] = $this->placeCheckoutOrder(quantity: 2, stock: 5);
        $user = $this->createUserWithRole(UserRole::Manager);
        $settings = CommerceSetting::current();

        $this->assertSame(1, StockMovement::where('type', StockMovement::TYPE_SALE)->count());
        $this->assertSame('3.000', StockBalance::where('product_id', $product->id)->where('warehouse_id', $settings->default_warehouse_id)->firstOrFail()->quantity);

        app(OrderLifecycleService::class)->cancel($order, $user, 'Клієнт відмовився');

        $order->refresh();
        $balance = StockBalance::where('product_id', $product->id)
            ->where('warehouse_id', $settings->default_warehouse_id)
            ->firstOrFail();

        $this->assertSame(OrderStatus::Cancelled->value, $order->status);
        $this->assertSame(PaymentStatus::Cancelled->value, $order->payment_status);
        $this->assertSame(DeliveryStatus::Cancelled->value, $order->delivery_status);
        $this->assertSame('Клієнт відмовився', $order->cancel_reason);
        $this->assertSame('5.000', $balance->quantity);
        $this->assertSame(5, $product->fresh()->stock);
        $this->assertSame(1, StockMovement::where('type', StockMovement::TYPE_SALE)->count());

        $returnMovement = StockMovement::where('type', StockMovement::TYPE_RETURN)->firstOrFail();

        $this->assertSame('2.000', $returnMovement->quantity);
        $this->assertSame('5.000', $returnMovement->balance_after);
        $this->assertSame('Order cancelled: '.$order->number, $returnMovement->note);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'type' => OrderStatusHistory::TYPE_STATUS,
            'to_value' => OrderStatus::Cancelled->value,
            'comment' => 'Клієнт відмовився',
            'created_by' => $user->id,
        ]);
    }

    public function test_repeated_cancel_does_not_restore_stock_twice(): void
    {
        [$order, $product] = $this->placeCheckoutOrder(quantity: 2, stock: 5);
        $user = $this->createUserWithRole(UserRole::Manager);
        $settings = CommerceSetting::current();
        $service = app(OrderLifecycleService::class);

        $service->cancel($order, $user, 'Перше скасування');

        $balanceAfterFirstCancel = StockBalance::where('product_id', $product->id)
            ->where('warehouse_id', $settings->default_warehouse_id)
            ->firstOrFail();

        $this->assertSame('5.000', $balanceAfterFirstCancel->quantity);
        $this->assertSame(1, StockMovement::where('type', StockMovement::TYPE_RETURN)->count());

        $this->assertThrows(
            fn () => $service->cancel($order, $user, 'Повторне скасування'),
            RuntimeException::class,
        );

        $balanceAfterSecondAttempt = StockBalance::where('product_id', $product->id)
            ->where('warehouse_id', $settings->default_warehouse_id)
            ->firstOrFail();

        $this->assertSame('5.000', $balanceAfterSecondAttempt->quantity);
        $this->assertSame(1, StockMovement::where('type', StockMovement::TYPE_RETURN)->count());
    }

    public function test_shipped_or_completed_order_cannot_be_cancelled_automatically(): void
    {
        [$shippedOrder] = $this->placeCheckoutOrder(quantity: 1, stock: 4, suffix: 'shipped');
        [$completedOrder] = $this->placeCheckoutOrder(quantity: 1, stock: 4, suffix: 'completed');
        $user = $this->createUserWithRole(UserRole::Admin);
        $service = app(OrderLifecycleService::class);

        $service->confirm($shippedOrder, $user);
        $service->markProcessing($shippedOrder, $user);
        $service->markReadyToShip($shippedOrder, $user);
        $service->markShipped($shippedOrder, $user);

        $this->assertThrows(
            fn () => $service->cancel($shippedOrder, $user, 'Не можна автоматично'),
            RuntimeException::class,
        );

        $service->confirm($completedOrder, $user);
        $service->markProcessing($completedOrder, $user);
        $service->markReadyToShip($completedOrder, $user);
        $service->markShipped($completedOrder, $user);
        $service->markCompleted($completedOrder, $user);

        $this->assertThrows(
            fn () => $service->cancel($completedOrder, $user, 'Не можна автоматично'),
            RuntimeException::class,
        );

        $this->assertSame(0, StockMovement::where('type', StockMovement::TYPE_RETURN)->count());
    }

    /**
     * @return array{Order, Product}
     */
    private function placeCheckoutOrder(int $quantity, int $stock, string $suffix = 'order'): array
    {
        $category = $this->createCategory([
            'name' => 'Lifecycle category '.$suffix,
            'slug' => 'lifecycle-category-'.$suffix,
        ]);
        $brand = $this->createBrand([
            'name' => 'Lifecycle brand '.$suffix,
            'slug' => 'lifecycle-brand-'.$suffix,
        ]);
        $product = $this->createProduct([
            'category' => $category,
            'brand' => $brand,
            'name' => 'Lifecycle product '.$suffix,
            'slug' => 'lifecycle-product-'.$suffix,
            'sku' => 'LIFE-'.$suffix,
            'stock' => $stock,
        ]);
        $checkoutToken = 'lifecycle-token-'.$suffix;

        $this->withSession([
            'cart' => [$product->id => $quantity],
            'storefront_checkout_token' => $checkoutToken,
        ])
            ->post(route('checkout.place'), $this->checkoutData($checkoutToken))
            ->assertRedirect();

        $order = Order::latest('id')->firstOrFail();

        return [$order->refresh(), $product->refresh()];
    }

    /**
     * @return array<string, string>
     */
    private function checkoutData(string $checkoutToken): array
    {
        return [
            'checkout_token' => $checkoutToken,
            'name' => 'Lifecycle Buyer',
            'phone' => '+380501112233',
            'email' => 'buyer@example.test',
            'city' => 'Київ',
            'address' => 'Відділення 1',
            'delivery_method' => 'nova_poshta',
            'payment_method' => 'cash_on_delivery',
            'customer_comment' => 'Lifecycle test',
        ];
    }
}
