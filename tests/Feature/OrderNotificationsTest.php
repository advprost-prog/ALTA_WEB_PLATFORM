<?php

namespace Tests\Feature;

use App\Enums\NotificationStatus;
use App\Enums\OrderNotificationEvent;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Mail\OrderNotificationMail;
use App\Models\NotificationOutbox;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Commerce\OrderLifecycleService;
use App\Services\Commerce\OrderNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Concerns\CreatesCommerceData;
use Tests\TestCase;

class OrderNotificationsTest extends TestCase
{
    use CreatesCommerceData;
    use RefreshDatabase;

    public function test_seed_creates_base_notification_templates_idempotently(): void
    {
        $this->assertSame(8, NotificationTemplate::where('channel', 'email')->count());

        NotificationTemplate::ensureDefaults();

        $this->assertSame(8, NotificationTemplate::where('channel', 'email')->count());
        $this->assertDatabaseHas('notification_templates', [
            'code' => 'order_created.email',
            'event' => OrderNotificationEvent::OrderCreated->value,
            'channel' => 'email',
            'is_system' => true,
        ]);

        $template = NotificationTemplate::where('code', 'order_created.email')->firstOrFail();
        $template->forceFill([
            'subject' => 'Custom subject',
            'body' => 'Custom body',
            'is_active' => false,
        ])->save();

        NotificationTemplate::ensureDefaults();

        $this->assertSame(8, NotificationTemplate::where('channel', 'email')->count());
        $this->assertDatabaseHas('notification_templates', [
            'code' => 'order_created.email',
            'subject' => 'Custom subject',
            'body' => 'Custom body',
            'is_active' => false,
            'is_system' => true,
        ]);
    }

    public function test_system_templates_cannot_be_deleted(): void
    {
        $template = NotificationTemplate::where('code', 'order_created.email')->firstOrFail();
        $admin = $this->createUserWithRole(UserRole::Admin);

        $this->assertFalse($admin->can('delete', $template));
        $this->assertFalse($template->delete());
        $this->assertDatabaseHas('notification_templates', ['id' => $template->id]);
    }

    public function test_template_renderer_replaces_known_variables_and_keeps_unknown_variables_safe(): void
    {
        [$order] = $this->placeCheckoutOrder(email: 'buyer@example.test');
        $template = NotificationTemplate::create([
            'code' => 'test-render.email',
            'event' => OrderNotificationEvent::OrderCreated->value,
            'channel' => 'email',
            'name' => 'Render test',
            'subject' => 'Order {{ order.number }} {{ unknown.value }}',
            'body' => 'Buyer {{ order.customer_name }} / {{ order.total }} {{ order.currency }} / {{ order.payment_method }} / {{ order.delivery_method }} / {{ cancel_reason }} / {{ missing }}',
            'is_active' => true,
            'is_system' => false,
        ]);

        $rendered = app(OrderNotificationService::class)->renderTemplate($template, $order, [
            'cancel_reason' => 'Тест',
        ]);

        $this->assertStringContainsString($order->number, $rendered['subject']);
        $this->assertStringContainsString('{{ unknown.value }}', $rendered['subject']);
        $this->assertStringContainsString('Lifecycle Buyer', $rendered['body']);
        $this->assertStringContainsString(number_format((float) $order->total_amount, 2, '.', ' '), $rendered['body']);
        $this->assertStringContainsString((string) $order->currency_code, $rendered['body']);
        $this->assertStringContainsString((string) $order->payment_method_name, $rendered['body']);
        $this->assertStringContainsString((string) $order->delivery_method_name, $rendered['body']);
        $this->assertStringContainsString('Тест', $rendered['body']);
        $this->assertStringContainsString('{{ missing }}', $rendered['body']);
    }

    public function test_order_notification_mail_escapes_rendered_body(): void
    {
        $html = (new OrderNotificationMail('Test subject', 'Hello <strong>Buyer</strong>'))->render();

        $this->assertStringNotContainsString('<strong>Buyer</strong>', $html);
        $this->assertStringContainsString('&lt;strong&gt;Buyer&lt;/strong&gt;', $html);
    }

    public function test_checkout_creates_order_created_notification(): void
    {
        Mail::fake();

        [$order] = $this->placeCheckoutOrder(email: 'buyer@example.test');

        $notification = NotificationOutbox::where('order_id', $order->id)
            ->where('event', OrderNotificationEvent::OrderCreated->value)
            ->firstOrFail();

        $this->assertSame(NotificationStatus::Sent->value, $notification->status);
        $this->assertSame('buyer@example.test', $notification->recipient);
        $this->assertStringContainsString($order->number, (string) $notification->subject);
        Mail::assertSent(OrderNotificationMail::class);
    }

    public function test_lifecycle_actions_create_order_notifications(): void
    {
        Mail::fake();

        [$order] = $this->placeCheckoutOrder(email: 'buyer@example.test');
        $user = $this->createUserWithRole(UserRole::Manager);
        $service = app(OrderLifecycleService::class);

        $service->confirm($order, $user);
        $service->markProcessing($order, $user);
        $service->markPaid($order, $user);
        $service->markReadyToShip($order, $user);
        $service->markShipped($order, $user);
        $service->markCompleted($order, $user);

        $events = NotificationOutbox::where('order_id', $order->id)->pluck('event')->all();

        $this->assertContains(OrderNotificationEvent::OrderConfirmed->value, $events);
        $this->assertContains(OrderNotificationEvent::OrderProcessing->value, $events);
        $this->assertContains(OrderNotificationEvent::PaymentPaid->value, $events);
        $this->assertContains(OrderNotificationEvent::OrderReadyToShip->value, $events);
        $this->assertContains(OrderNotificationEvent::OrderShipped->value, $events);
        $this->assertContains(OrderNotificationEvent::OrderCompleted->value, $events);
    }

    public function test_cancel_creates_notification_with_reason_and_repeated_cancel_does_not_duplicate_notification_or_stock_return(): void
    {
        Mail::fake();

        [$order] = $this->placeCheckoutOrder(quantity: 2, stock: 5, email: 'buyer@example.test');
        $user = $this->createUserWithRole(UserRole::Manager);
        $service = app(OrderLifecycleService::class);

        $service->cancel($order, $user, 'Клієнт відмовився');

        $notification = NotificationOutbox::where('order_id', $order->id)
            ->where('event', OrderNotificationEvent::OrderCancelled->value)
            ->firstOrFail();

        $this->assertSame(NotificationStatus::Sent->value, $notification->status);
        $this->assertStringContainsString('Клієнт відмовився', (string) $notification->body);
        $this->assertSame(1, StockMovement::where('type', StockMovement::TYPE_RETURN)->count());

        $this->assertThrows(
            fn () => $service->cancel($order->refresh(), $user, 'Повторне скасування'),
            RuntimeException::class,
        );

        $this->assertSame(1, NotificationOutbox::where('order_id', $order->id)->where('event', OrderNotificationEvent::OrderCancelled->value)->count());
        $this->assertSame(1, StockMovement::where('type', StockMovement::TYPE_RETURN)->count());
    }

    public function test_invalid_transition_does_not_create_notification(): void
    {
        [$order] = $this->placeCheckoutOrder(email: 'buyer@example.test');
        $service = app(OrderLifecycleService::class);

        $this->assertThrows(
            fn () => $service->markCompleted($order, $this->createUserWithRole(UserRole::Admin)),
            RuntimeException::class,
        );

        $this->assertDatabaseMissing('notification_outbox', [
            'order_id' => $order->id,
            'event' => OrderNotificationEvent::OrderCompleted->value,
        ]);
    }

    public function test_notification_failure_does_not_rollback_lifecycle_transition(): void
    {
        [$order] = $this->placeCheckoutOrder(email: null);
        $order->forceFill(['email' => 'buyer@example.test'])->save();

        Mail::shouldReceive('mailer')
            ->once()
            ->with((string) config('mail.default'))
            ->andReturn(new class
            {
                public function to(string $email): object
                {
                    return new class
                    {
                        public function send(OrderNotificationMail $mail): void
                        {
                            throw new RuntimeException('SMTP transport down');
                        }
                    };
                }
            });

        app(OrderLifecycleService::class)->confirm($order, $this->createUserWithRole(UserRole::Manager));

        $order->refresh();
        $notification = NotificationOutbox::where('order_id', $order->id)
            ->where('event', OrderNotificationEvent::OrderConfirmed->value)
            ->firstOrFail();

        $this->assertSame(OrderStatus::Confirmed->value, $order->status);
        $this->assertSame(NotificationStatus::Failed->value, $notification->status);
        $this->assertStringContainsString('SMTP transport down', (string) $notification->error_message);
    }

    public function test_mailer_failure_does_not_rollback_checkout(): void
    {
        Mail::shouldReceive('mailer')
            ->once()
            ->with((string) config('mail.default'))
            ->andReturn(new class
            {
                public function to(string $email): object
                {
                    return new class
                    {
                        public function send(OrderNotificationMail $mail): void
                        {
                            throw new RuntimeException('SMTP transport down');
                        }
                    };
                }
            });

        [$order] = $this->placeCheckoutOrder(email: 'buyer@example.test');

        $notification = NotificationOutbox::where('order_id', $order->id)
            ->where('event', OrderNotificationEvent::OrderCreated->value)
            ->firstOrFail();

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
        $this->assertDatabaseHas('stock_movements', [
            'type' => StockMovement::TYPE_SALE,
            'related_type' => Order::class,
            'related_id' => $order->id,
        ]);
        $this->assertSame(NotificationStatus::Failed->value, $notification->status);
        $this->assertStringContainsString('SMTP transport down', (string) $notification->error_message);
    }

    public function test_missing_customer_email_creates_skipped_notification_without_fatal_error(): void
    {
        [$order] = $this->placeCheckoutOrder(email: null);

        $notification = NotificationOutbox::where('order_id', $order->id)
            ->where('event', OrderNotificationEvent::OrderCreated->value)
            ->firstOrFail();

        $this->assertNull($order->email);
        $this->assertSame(NotificationStatus::Skipped->value, $notification->status);
        $this->assertSame('У замовленні немає email клієнта.', $notification->error_message);
    }

    public function test_notification_admin_resources_are_available_and_outbox_is_read_only(): void
    {
        [$order] = $this->placeCheckoutOrder(email: null);
        $notification = NotificationOutbox::where('order_id', $order->id)->firstOrFail();
        $admin = $this->createUserWithRole(UserRole::Admin);

        $this->actingAs($admin)
            ->get('/admin/notification-templates')
            ->assertOk();

        $this->actingAs($admin)
            ->get('/admin/notification-outbox')
            ->assertOk();

        $this->actingAs($admin)
            ->get('/admin/notification-outbox/'.$notification->id.'/edit')
            ->assertNotFound();
    }

    public function test_resend_creates_new_attempt_for_failed_or_skipped_notification(): void
    {
        Mail::fake();

        [$order] = $this->placeCheckoutOrder(email: null);
        $skipped = NotificationOutbox::where('order_id', $order->id)->firstOrFail();
        $statusBefore = $order->status;
        $stockMovementsBefore = StockMovement::where('related_type', Order::class)
            ->where('related_id', $order->id)
            ->count();

        $order->forceFill(['email' => 'buyer@example.test'])->save();

        $resent = app(OrderNotificationService::class)->resend($skipped, $this->createUserWithRole(UserRole::Manager));

        $this->assertNotSame($skipped->id, $resent->id);
        $this->assertSame(NotificationStatus::Sent->value, $resent->status);
        $this->assertSame('buyer@example.test', $resent->recipient);
        $this->assertSame($statusBefore, $order->fresh()->status);
        $this->assertSame($stockMovementsBefore, StockMovement::where('related_type', Order::class)
            ->where('related_id', $order->id)
            ->count());
    }

    public function test_health_check_reports_notification_problems_and_warnings(): void
    {
        NotificationTemplate::where('code', 'order_created.email')->delete();

        DB::table('notification_outbox')->insert([
            'order_id' => null,
            'event' => null,
            'channel' => 'email',
            'recipient' => null,
            'subject' => null,
            'body' => 'Broken',
            'payload' => null,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('notification_outbox')->insert([
            'order_id' => null,
            'event' => OrderNotificationEvent::OrderCreated->value,
            'channel' => 'email',
            'recipient' => null,
            'subject' => null,
            'body' => 'Old pending',
            'payload' => null,
            'status' => NotificationStatus::Pending->value,
            'error_message' => null,
            'sent_at' => null,
            'created_by' => null,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        DB::table('notification_outbox')->insert([
            'order_id' => null,
            'event' => 'mystery_event',
            'channel' => 'sms',
            'recipient' => null,
            'subject' => null,
            'body' => 'Unknown values',
            'payload' => null,
            'status' => 'lost',
            'error_message' => null,
            'sent_at' => null,
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('commerce:health-check')
            ->expectsOutputToContain('notification_templates_missing')
            ->expectsOutputToContain('notification_outbox_broken_records')
            ->expectsOutputToContain('notification_outbox_unknown_event')
            ->expectsOutputToContain('notification_outbox_unknown_channel')
            ->expectsOutputToContain('notification_outbox_unknown_status')
            ->expectsOutputToContain('notification_outbox_old_pending')
            ->assertExitCode(1);
    }

    /**
     * @return array{Order, Product}
     */
    private function placeCheckoutOrder(int $quantity = 1, int $stock = 4, ?string $email = 'buyer@example.test'): array
    {
        $suffix = Str::random(8);
        $product = $this->createProduct([
            'name' => 'Notification product '.$suffix,
            'slug' => 'notification-product-'.$suffix,
            'sku' => 'NOTIFY-'.$suffix,
            'stock' => $stock,
        ]);
        $checkoutToken = 'notification-token-'.$suffix;

        $payload = [
            'checkout_token' => $checkoutToken,
            'name' => 'Lifecycle Buyer',
            'phone' => '+380501112233',
            'city' => 'Київ',
            'address' => 'Відділення 1',
            'delivery_method' => 'nova_poshta',
            'payment_method' => 'cash_on_delivery',
            'customer_comment' => 'Notification test',
        ];

        if ($email !== null) {
            $payload['email'] = $email;
        }

        $this->withSession([
            'cart' => [$product->id => $quantity],
            'storefront_checkout_token' => $checkoutToken,
        ])
            ->post(route('checkout.place'), $payload)
            ->assertRedirect();

        $order = Order::latest('id')->firstOrFail();

        return [$order->refresh(), $product->refresh()];
    }
}
