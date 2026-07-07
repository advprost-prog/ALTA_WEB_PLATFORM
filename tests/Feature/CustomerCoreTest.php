<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Services\Commerce\CustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerCoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_address_and_order_relations_work_with_legacy_orders(): void
    {
        $customer = Customer::create([
            'full_name' => 'Core Buyer',
            'phone' => '(050) 111-22-33',
            'email' => 'core@example.test',
        ]);

        $address = $customer->addresses()->create([
            'type' => CustomerAddress::TYPE_DELIVERY,
            'city' => 'Київ',
            'address' => 'Відділення 12',
            'is_default' => true,
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'customer_name' => 'Core Buyer',
            'phone' => '+380501112233',
            'email' => 'core@example.test',
            'city' => 'Київ',
            'address' => 'Відділення 12',
            'total_amount' => 1000,
            'status' => OrderStatus::New->value,
        ]);

        $legacyOrder = Order::create([
            'customer_name' => 'Legacy Buyer',
            'phone' => '+380671112233',
            'total_amount' => 500,
            'status' => OrderStatus::New->value,
        ]);

        $this->assertSame('380501112233', $customer->normalized_phone);
        $this->assertTrue($address->is_default);
        $this->assertTrue($order->customer->is($customer));
        $this->assertNull($legacyOrder->customer_id);
    }

    public function test_customer_service_creates_and_matches_by_normalized_phone(): void
    {
        $service = app(CustomerService::class);

        $customer = $service->resolveFromCheckout([
            'name' => 'Checkout Buyer',
            'phone' => '050 111 22 33',
            'email' => 'BUYER@EXAMPLE.TEST',
            'city' => 'Київ',
            'address' => 'Відділення 1',
        ]);

        $resolved = $service->resolveFromCheckout([
            'name' => 'Buyer',
            'phone' => '+38 (050) 111-22-33',
            'email' => null,
        ]);

        $this->assertTrue($resolved->is($customer));
        $this->assertSame('380501112233', $customer->normalized_phone);
        $this->assertSame('buyer@example.test', $customer->normalized_email);
        $this->assertDatabaseHas('customer_addresses', [
            'customer_id' => $customer->id,
            'type' => CustomerAddress::TYPE_DELIVERY,
            'city' => 'Київ',
            'address' => 'Відділення 1',
            'is_default' => true,
        ]);
    }

    public function test_customer_service_matches_by_normalized_email(): void
    {
        $existing = Customer::create([
            'full_name' => 'Email Buyer',
            'email' => 'buyer@example.test',
        ]);

        $resolved = app(CustomerService::class)->resolveFromCheckout([
            'name' => 'Email Buyer',
            'email' => 'BUYER@example.test',
        ]);

        $this->assertTrue($resolved->is($existing));
    }

    public function test_empty_or_short_checkout_values_do_not_overwrite_better_customer_data(): void
    {
        $customer = Customer::create([
            'full_name' => 'Oleksandr Long Customer Name',
            'phone' => '+380501112233',
            'email' => 'stable@example.test',
        ]);

        app(CustomerService::class)->updateFromCheckout($customer, [
            'name' => 'Ole',
            'phone' => '',
            'email' => '',
            'city' => 'Львів',
        ]);

        $customer->refresh();

        $this->assertSame('Oleksandr Long Customer Name', $customer->full_name);
        $this->assertSame('+380501112233', $customer->phone);
        $this->assertSame('stable@example.test', $customer->email);
        $this->assertSame('Львів', $customer->city);
    }

    public function test_clearing_contact_fields_clears_normalized_matching_keys(): void
    {
        $customer = Customer::create([
            'full_name' => 'Clear Contact',
            'phone' => '+380501112233',
            'email' => 'clear@example.test',
        ]);

        $this->assertSame('380501112233', $customer->normalized_phone);
        $this->assertSame('clear@example.test', $customer->normalized_email);

        $customer->forceFill([
            'phone' => null,
            'email' => null,
        ])->save();

        $customer->refresh();

        $this->assertNull($customer->normalized_phone);
        $this->assertNull($customer->normalized_email);
    }

    public function test_conflicting_phone_and_email_do_not_aggressive_merge_customers(): void
    {
        $phoneCustomer = Customer::create([
            'full_name' => 'Phone Owner',
            'phone' => '+380501112233',
        ]);
        $emailCustomer = Customer::create([
            'full_name' => 'Email Owner',
            'email' => 'owner@example.test',
        ]);

        $resolved = app(CustomerService::class)->resolveFromCheckout([
            'name' => 'Conflict Buyer',
            'phone' => '0501112233',
            'email' => 'owner@example.test',
        ]);

        $this->assertTrue($resolved->is($phoneCustomer));
        $this->assertSame(2, Customer::count());
        $this->assertNull($phoneCustomer->refresh()->email);
        $this->assertNull($emailCustomer->refresh()->phone);
    }
}
