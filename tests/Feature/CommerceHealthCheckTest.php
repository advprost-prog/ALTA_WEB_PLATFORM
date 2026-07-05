<?php

namespace Tests\Feature;

use App\Models\CommerceSetting;
use App\Models\ProductPrice;
use App\Models\StockBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
