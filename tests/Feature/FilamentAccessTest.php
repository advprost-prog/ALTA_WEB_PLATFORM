<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\CommerceSetting;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesCommerceData;
use Tests\TestCase;

class FilamentAccessTest extends TestCase
{
    use CreatesCommerceData;
    use RefreshDatabase;

    public function test_guest_cannot_access_admin_panel(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_admin_can_access_admin_panel(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Admin))
            ->get('/admin')
            ->assertOk();
    }

    public function test_manager_can_access_orders_resource(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Manager))
            ->get('/admin/orders')
            ->assertOk();
    }

    public function test_manager_cannot_access_site_settings_resource(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Manager))
            ->get('/admin/site-settings')
            ->assertForbidden();
    }

    public function test_content_manager_can_access_products_but_not_orders(): void
    {
        $contentManager = $this->createUserWithRole(UserRole::ContentManager);

        $this->actingAs($contentManager)
            ->get('/admin/products')
            ->assertOk();

        $this->actingAs($contentManager)
            ->get('/admin/orders')
            ->assertForbidden();
    }

    public function test_products_table_renders_compact_product_rows(): void
    {
        $brand = $this->createBrand(['name' => 'Castrol', 'slug' => 'castrol']);
        $category = $this->createCategory(['name' => 'Моторні оливи', 'slug' => 'motorni-olyvy']);

        $this->createProduct([
            'brand' => $brand,
            'category' => $category,
            'name' => 'Castrol EDGE 5W-30 LL 4L',
            'sku' => 'AT-OIL-530-4L',
            'stock_status' => 'in_stock',
        ]);

        $this->actingAs($this->createUserWithRole(UserRole::ContentManager))
            ->get('/admin/products')
            ->assertOk()
            ->assertSee('Castrol EDGE 5W-30 LL 4L')
            ->assertSee('Castrol')
            ->assertSee('Моторні оливи')
            ->assertSee('SKU AT-OIL-530-4L')
            ->assertSee('В наявності');
    }

    public function test_product_resource_simple_mode_hides_multi_currency_and_multi_warehouse_fields(): void
    {
        CommerceSetting::current()->update([
            'multi_currency_enabled' => false,
            'multi_warehouse_enabled' => false,
        ]);

        $this->actingAs($this->createUserWithRole(UserRole::Admin))
            ->get('/admin/products/create')
            ->assertOk()
            ->assertSee('Ціна')
            ->assertSee('Залишок')
            ->assertDontSee('Ціни за валютами')
            ->assertDontSee('Залишки за складами');
    }

    public function test_product_resource_extended_mode_shows_multi_currency_and_multi_warehouse_fields(): void
    {
        CommerceSetting::current()->update([
            'multi_currency_enabled' => true,
            'multi_warehouse_enabled' => true,
        ]);

        $this->actingAs($this->createUserWithRole(UserRole::Admin))
            ->get('/admin/products/create')
            ->assertOk()
            ->assertSee('Ціни за валютами')
            ->assertSee('Залишки за складами');
    }

    public function test_admin_can_access_customer_resource_and_customer_view(): void
    {
        $customer = Customer::create([
            'full_name' => 'Filament Buyer',
            'phone' => '+380501112233',
            'email' => 'filament@example.test',
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'customer_name' => 'Filament Buyer',
            'phone' => '+380501112233',
            'email' => 'filament@example.test',
            'total_amount' => 100,
            'status' => OrderStatus::New->value,
        ]);

        $admin = $this->createUserWithRole(UserRole::Admin);

        $this->actingAs($admin)
            ->get('/admin/customers')
            ->assertOk()
            ->assertSee('Filament Buyer');

        $this->actingAs($admin)
            ->get('/admin/customers/'.$customer->id)
            ->assertOk()
            ->assertSee('Filament Buyer')
            ->assertSee('Замовлення');
    }
}
