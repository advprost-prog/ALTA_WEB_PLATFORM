<?php

namespace Tests\Feature;

use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\CommerceSetting;
use App\Models\Customer;
use App\Models\DeliveryMethod;
use App\Models\NotificationMailSetting;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\StockBalance;
use App\Models\TaxProfile;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    public function test_commerce_health_check_reports_customer_warnings(): void
    {
        CommerceSetting::current();

        Customer::create([
            'full_name' => 'Duplicate A',
            'phone' => '+380501112233',
            'email' => 'not-valid-email',
        ]);
        Customer::create([
            'full_name' => 'Duplicate B',
            'phone' => '0501112233',
        ]);
        Customer::create([
            'full_name' => 'No Contact',
        ]);
        Order::create([
            'customer_name' => 'Legacy Buyer',
            'phone' => '+380671112233',
            'total_amount' => 100,
            'status' => OrderStatus::New->value,
            'payment_status' => PaymentStatus::Unpaid->value,
            'delivery_status' => DeliveryStatus::Pending->value,
        ]);

        $this->artisan('commerce:health-check')
            ->expectsOutputToContain('orders_without_customer_id')
            ->expectsOutputToContain('customers_without_phone_or_email')
            ->expectsOutputToContain('customers_duplicate_normalized_phone')
            ->expectsOutputToContain('customers_invalid_email')
            ->assertExitCode(0);
    }

    public function test_commerce_health_check_reports_missing_customer_addresses_table_as_critical(): void
    {
        CommerceSetting::current();

        Schema::drop('customer_addresses');

        $this->artisan('commerce:health-check')
            ->expectsOutputToContain('customer_addresses_table_missing')
            ->assertExitCode(1);
    }

    public function test_commerce_health_check_reports_linked_order_contact_conflicts_as_potential_duplicates(): void
    {
        CommerceSetting::current();

        $phoneCustomer = Customer::create([
            'full_name' => 'Phone Owner',
            'phone' => '+380501112233',
        ]);

        Customer::create([
            'full_name' => 'Email Owner',
            'email' => 'owner@example.test',
        ]);

        Order::create([
            'customer_id' => $phoneCustomer->id,
            'customer_name' => 'Conflict Buyer',
            'phone' => '+380501112233',
            'email' => 'owner@example.test',
            'total_amount' => 100,
            'status' => OrderStatus::New->value,
            'payment_status' => PaymentStatus::Unpaid->value,
            'delivery_status' => DeliveryStatus::Pending->value,
        ]);

        $this->artisan('commerce:health-check')
            ->expectsOutputToContain('orders_customer_contact_potential_duplicate')
            ->assertExitCode(0);
    }

    public function test_product_variant_excise_fields_are_normalized_on_save(): void
    {
        $product = $this->createProduct();
        $unit = Unit::ensurePiece();
        $taxProfile = TaxProfile::ensureDefault();

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'AT-OIL-530-4L-EX',
            'name' => 'Excise variant',
            'base_unit_id' => $unit->id,
            'sales_unit_id' => $unit->id,
            'purchase_unit_id' => $unit->id,
            'tax_profile_id' => $taxProfile->id,
            'is_excise_applicable' => true,
            'excise_rate' => null,
            'requires_excise_stamp_entry' => true,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->assertSame('5.00', $variant->fresh()->excise_rate);

        $variant->forceFill([
            'is_excise_applicable' => true,
            'excise_rate' => 7.25,
            'requires_excise_stamp_entry' => true,
        ])->save();

        $this->assertSame('7.25', $variant->fresh()->excise_rate);

        $variant->forceFill([
            'is_excise_applicable' => false,
            'excise_rate' => 10,
            'requires_excise_stamp_entry' => true,
        ])->save();

        $variant = $variant->fresh();

        $this->assertNull($variant->excise_rate);
        $this->assertFalse($variant->requires_excise_stamp_entry);
    }

    public function test_commerce_health_check_reports_catalog_variant_excise_inconsistency(): void
    {
        $product = $this->createProduct();
        $unit = Unit::ensurePiece();
        $taxProfile = TaxProfile::ensureDefault();
        $now = now();

        DB::table('product_variants')->insert([
            'product_id' => $product->id,
            'sku' => 'AT-OIL-530-4L-BROKEN-EXCISE',
            'name' => 'Broken excise variant',
            'base_unit_id' => $unit->id,
            'sales_unit_id' => $unit->id,
            'purchase_unit_id' => $unit->id,
            'tax_profile_id' => $taxProfile->id,
            'is_excise_applicable' => 1,
            'excise_rate' => null,
            'requires_excise_stamp_entry' => 1,
            'is_default' => 0,
            'is_active' => 1,
            'sort_order' => 10,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->artisan('commerce:health-check')
            ->expectsOutputToContain('variants_excise_inconsistent')
            ->assertExitCode(1);
    }

    public function test_commerce_health_check_reports_missing_variant_snapshot_on_order_item(): void
    {
        $product = $this->createProduct();
        $unit = Unit::ensurePiece();
        $taxProfile = TaxProfile::ensureDefault();

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'AT-OIL-530-4L-SNAPSHOT',
            'name' => 'Snapshot variant',
            'base_unit_id' => $unit->id,
            'sales_unit_id' => $unit->id,
            'purchase_unit_id' => $unit->id,
            'tax_profile_id' => $taxProfile->id,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $order = Order::create([
            'customer_name' => 'Snapshot Risk',
            'phone' => '+380501112244',
            'total_amount' => 1000,
            'status' => OrderStatus::New->value,
            'payment_status' => PaymentStatus::Unpaid->value,
            'delivery_status' => DeliveryStatus::Pending->value,
        ]);

        DB::table('order_items')->insert([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => null,
            'product_name' => $product->name,
            'sku' => $variant->sku,
            'unit_name' => 'шт',
            'unit_short_name' => 'шт',
            'base_unit_id' => null,
            'sales_unit_id' => null,
            'quantity' => 1,
            'quantity_in_base_unit' => 1,
            'tax_profile_id' => null,
            'tax_profile_name' => null,
            'tax_profile_code' => null,
            'vat_rate' => null,
            'vat_amount' => null,
            'is_excise_applicable' => 0,
            'excise_rate' => null,
            'excise_amount' => null,
            'requires_excise_stamp_entry' => 0,
            'unit_price' => 1000,
            'price_excluding_tax' => 1000,
            'price_including_tax' => 1000,
            'price' => 1000,
            'total' => 1000,
            'line_total_excluding_tax' => 1000,
            'line_total_tax_amount' => 0,
            'line_total_including_tax' => 1000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('commerce:health-check')
            ->expectsOutputToContain('order_items_variant_snapshot_missing')
            ->assertExitCode(1);
    }

    public function test_commerce_health_check_reports_active_product_without_active_default_variant(): void
    {
        $product = $this->createProduct();

        ProductVariant::query()
            ->where('product_id', $product->id)
            ->update([
                'is_default' => false,
                'is_active' => false,
            ]);

        $this->artisan('commerce:health-check')
            ->expectsOutputToContain('products_without_active_default_variant')
            ->assertExitCode(1);
    }

    public function test_commerce_health_check_reports_multiple_default_variants_per_product(): void
    {
        $product = $this->createProduct();
        $unit = Unit::ensurePiece();
        $taxProfile = TaxProfile::ensureDefault();

        DB::table('product_variants')->insert([
            'product_id' => $product->id,
            'sku' => 'AT-OIL-530-4L-DEFAULT-2',
            'name' => 'Second default',
            'barcode' => null,
            'base_unit_id' => $unit->id,
            'sales_unit_id' => $unit->id,
            'purchase_unit_id' => $unit->id,
            'tax_profile_id' => $taxProfile->id,
            'is_excise_applicable' => false,
            'excise_rate' => null,
            'requires_excise_stamp_entry' => false,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('commerce:health-check')
            ->expectsOutputToContain('variants_multiple_defaults_per_product')
            ->assertExitCode(1);
    }
}
