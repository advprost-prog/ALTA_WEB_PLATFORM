<?php

namespace Tests\Feature;

use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\CommerceSetting;
use App\Models\DeliveryMethod;
use App\Models\NotificationMailSetting;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\ProductPrice;
use App\Models\StockBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Feature\Concerns\CreatesCommerceData;
use Tests\TestCase;

class CommerceHealthCheckTest extends TestCase
{
    use CreatesCommerceData;
    use RefreshDatabase;

    public function test_commerce_health_check_returns_success_on_valid_data(): void
    {
        CommerceSetting::current();

        $this->artisan('commerce:health-check')
            ->expectsOutputToContain('status: ok')
            ->expectsOutputToContain('Критичних проблем не знайдено.')
            ->assertExitCode(0);
    }

    public function test_commerce_health_check_allows_local_array_mailer(): void
    {
        Config::set('app.env', 'local');
        Config::set('mail.default', 'array');
        Config::set('mail.from.address', 'hello@example.com');
        CommerceSetting::current();

        $this->artisan('commerce:health-check')
            ->expectsOutputToContain('status: ok')
            ->assertExitCode(0);
    }

    public function test_commerce_health_check_reports_missing_production_smtp_configuration(): void
    {
        Config::set('app.env', 'production');
        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.host', '');
        Config::set('mail.mailers.smtp.port', '');
        Config::set('mail.from.address', '');
        CommerceSetting::current();

        $this->artisan('commerce:health-check')
            ->expectsOutputToContain('mail_smtp_host_missing')
            ->expectsOutputToContain('mail_smtp_port_missing')
            ->expectsOutputToContain('mail_from_address_missing')
            ->assertExitCode(1);
    }

    public function test_commerce_health_check_reports_production_smtp_placeholders(): void
    {
        Config::set('app.env', 'production');
        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.host', '127.0.0.1');
        Config::set('mail.mailers.smtp.port', 2525);
        Config::set('mail.from.address', 'hello@example.com');
        CommerceSetting::current();

        $this->artisan('commerce:health-check')
            ->expectsOutputToContain('mail_smtp_host_missing')
            ->expectsOutputToContain('mail_smtp_port_missing')
            ->expectsOutputToContain('mail_from_address_missing')
            ->assertExitCode(1);
    }

    public function test_commerce_health_check_reports_incomplete_db_mail_settings(): void
    {
        CommerceSetting::current();
        NotificationMailSetting::current()->forceFill([
            'is_enabled' => true,
            'mailer' => 'smtp',
            'host' => '',
            'port' => null,
            'from_address' => '',
        ])->save();

        $this->artisan('commerce:health-check')
            ->expectsOutputToContain('notification_mail_settings_smtp_host_missing')
            ->expectsOutputToContain('notification_mail_settings_smtp_port_missing')
            ->expectsOutputToContain('notification_mail_settings_from_address_invalid')
            ->assertExitCode(1);
    }

    public function test_commerce_health_check_reports_db_mail_password_decrypt_failure_without_secret(): void
    {
        CommerceSetting::current();
        NotificationMailSetting::current()->forceFill([
            'is_enabled' => true,
            'mailer' => 'smtp',
            'host' => 'smtp.example.test',
            'port' => 587,
            'from_address' => 'shop@example.test',
            'username' => 'smtp-user@example.test',
            'password_encrypted' => 'not-a-valid-encrypted-payload',
        ])->save();

        $this->artisan('commerce:health-check --json')
            ->expectsOutputToContain('notification_mail_settings_password_decrypt_failed')
            ->doesntExpectOutputToContain('smtp-user@example.test')
            ->doesntExpectOutputToContain('not-a-valid-encrypted-payload')
            ->assertExitCode(1);
    }

    public function test_commerce_health_check_returns_failure_on_missing_default_product_price(): void
    {
        $product = $this->createProduct();
        $settings = CommerceSetting::current();

        ProductPrice::where('product_id', $product->id)
            ->where('currency_id', $settings->default_currency_id)
            ->delete();

        $this->artisan('commerce:health-check')
            ->expectsOutputToContain('products_missing_default_price')
            ->assertExitCode(1);
    }

    public function test_commerce_health_check_returns_failure_on_stock_quantity_lower_than_reserved_quantity(): void
    {
        $product = $this->createProduct();
        $settings = CommerceSetting::current();

        StockBalance::where('product_id', $product->id)
            ->where('warehouse_id', $settings->default_warehouse_id)
            ->update([
                'quantity' => 1,
                'reserved_quantity' => 2,
            ]);

        $this->artisan('commerce:health-check')
            ->expectsOutputToContain('stock_balances_below_reserved')
            ->expectsOutputToContain('stock_balances_negative_available')
            ->assertExitCode(1);
    }

    public function test_commerce_health_check_reports_order_lifecycle_risks(): void
    {
        $paymentMethod = PaymentMethod::where('code', PaymentMethod::CASH_ON_DELIVERY)->firstOrFail();
        $deliveryMethod = DeliveryMethod::where('code', DeliveryMethod::NOVA_POSHTA)->firstOrFail();

        $order = Order::create([
            'customer_name' => 'Lifecycle Risk',
            'phone' => '+380501112233',
            'total_amount' => 1000,
            'status' => OrderStatus::New->value,
            'payment_status' => PaymentStatus::Unpaid->value,
            'delivery_status' => DeliveryStatus::Pending->value,
            'payment_method_id' => $paymentMethod->id,
            'delivery_method_id' => $deliveryMethod->id,
        ]);

        $order->forceFill([
            'status' => 'mystery',
            'payment_status' => 'strange',
            'delivery_status' => 'elsewhere',
            'payment_method_name' => null,
            'delivery_method_name' => null,
        ])->save();

        PaymentMethod::query()->update(['is_active' => false]);
        DeliveryMethod::query()->update(['is_active' => false]);

        $this->artisan('commerce:health-check')
            ->expectsOutputToContain('orders_unknown_status')
            ->expectsOutputToContain('orders_unknown_payment_status')
            ->expectsOutputToContain('orders_unknown_delivery_status')
            ->expectsOutputToContain('orders_missing_payment_method_snapshot')
            ->expectsOutputToContain('orders_missing_delivery_method_snapshot')
            ->expectsOutputToContain('active_payment_methods_missing')
            ->expectsOutputToContain('active_delivery_methods_missing')
            ->assertExitCode(1);
    }

    public function test_commerce_health_check_reports_lifecycle_timestamp_gaps(): void
    {
        Order::create([
            'customer_name' => 'Cancelled Risk',
            'phone' => '+380501112230',
            'total_amount' => 1000,
            'status' => OrderStatus::Cancelled->value,
            'payment_status' => PaymentStatus::Cancelled->value,
            'delivery_status' => DeliveryStatus::Cancelled->value,
        ]);

        Order::create([
            'customer_name' => 'Paid Risk',
            'phone' => '+380501112231',
            'total_amount' => 1000,
            'status' => OrderStatus::Processing->value,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Preparing->value,
        ]);

        Order::create([
            'customer_name' => 'Shipped Risk',
            'phone' => '+380501112232',
            'total_amount' => 1000,
            'status' => OrderStatus::Shipped->value,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Shipped->value,
        ]);

        Order::create([
            'customer_name' => 'Completed Risk',
            'phone' => '+380501112233',
            'total_amount' => 1000,
            'status' => OrderStatus::Completed->value,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Delivered->value,
        ]);

        $this->artisan('commerce:health-check')
            ->expectsOutputToContain('cancelled_orders_missing_cancelled_at')
            ->expectsOutputToContain('paid_orders_missing_paid_at')
            ->expectsOutputToContain('shipped_orders_missing_shipped_at')
            ->expectsOutputToContain('completed_orders_missing_completed_at')
            ->assertExitCode(1);
    }
}
