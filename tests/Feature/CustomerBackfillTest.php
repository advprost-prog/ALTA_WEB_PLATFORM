<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_dry_run_does_not_mutate_database(): void
    {
        Order::create([
            'customer_name' => 'Dry Run Buyer',
            'phone' => '+380501112233',
            'email' => 'dry@example.test',
            'city' => 'Київ',
            'address' => 'Відділення 1',
            'total_amount' => 100,
            'status' => OrderStatus::New->value,
        ]);

        $this->artisan('customers:backfill-from-orders --dry-run')
            ->expectsOutputToContain('dry_run: yes')
            ->expectsOutputToContain('scanned: 1')
            ->expectsOutputToContain('created_customers: 1')
            ->expectsOutputToContain('linked_orders: 1')
            ->assertExitCode(0);

        $this->assertSame(0, Customer::count());
        $this->assertNull(Order::firstOrFail()->customer_id);
    }

    public function test_backfill_creates_customers_links_orders_and_is_idempotent(): void
    {
        $order = Order::create([
            'customer_name' => 'Backfill Buyer',
            'phone' => '+380501112233',
            'email' => 'backfill@example.test',
            'city' => 'Київ',
            'address' => 'Відділення 2',
            'total_amount' => 200,
            'status' => OrderStatus::New->value,
        ]);

        $this->artisan('customers:backfill-from-orders')
            ->expectsOutputToContain('dry_run: no')
            ->expectsOutputToContain('scanned: 1')
            ->expectsOutputToContain('created_customers: 1')
            ->expectsOutputToContain('linked_orders: 1')
            ->assertExitCode(0);

        $customer = Customer::firstOrFail();
        $this->assertSame($customer->id, $order->refresh()->customer_id);
        $this->assertDatabaseHas('customer_addresses', [
            'customer_id' => $customer->id,
            'city' => 'Київ',
            'address' => 'Відділення 2',
        ]);

        $this->artisan('customers:backfill-from-orders')
            ->expectsOutputToContain('scanned: 0')
            ->assertExitCode(0);

        $this->assertSame(1, Customer::count());
    }

    public function test_backfill_reports_conflicting_phone_and_email_as_potential_duplicate(): void
    {
        Customer::create([
            'full_name' => 'Phone Owner',
            'phone' => '+380501112233',
        ]);
        Customer::create([
            'full_name' => 'Email Owner',
            'email' => 'owner@example.test',
        ]);
        Order::create([
            'customer_name' => 'Conflict Buyer',
            'phone' => '0501112233',
            'email' => 'owner@example.test',
            'total_amount' => 300,
            'status' => OrderStatus::New->value,
        ]);

        $this->artisan('customers:backfill-from-orders --dry-run')
            ->expectsOutputToContain('potential_duplicates: 1')
            ->assertExitCode(0);
    }
}
