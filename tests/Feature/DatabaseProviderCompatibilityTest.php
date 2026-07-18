<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StorefrontTheme;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Concerns\CreatesCommerceData;
use Tests\TestCase;

class DatabaseProviderCompatibilityTest extends TestCase
{
    use CreatesCommerceData;
    use RefreshDatabase;

    public function test_complete_migration_inventory_and_representative_schema_objects_exist(): void
    {
        $expected = collect(glob(database_path('migrations/*.php')))
            ->map(fn (string $path): string => pathinfo($path, PATHINFO_FILENAME))
            ->sort()
            ->values();
        $actual = DB::table('migrations')->orderBy('migration')->pluck('migration');

        $this->assertCount(26, $expected);
        $this->assertSame($expected->all(), $actual->all());
        $this->assertTrue(Schema::hasColumns('products', ['id', 'is_active', 'price', 'created_at']));
        $this->assertTrue(Schema::hasColumns('system_addons', ['code', 'metadata']));
        $this->assertTrue(Schema::hasTable('system_addon_events'));
    }

    public function test_boolean_decimal_json_and_unicode_round_trip(): void
    {
        $product = $this->createProduct([
            'name' => 'Український товар',
            'slug' => 'unicode-product',
            'sku' => 'CASE-Sensitive-SKU',
            'price' => '9999999999.99',
            'old_price' => '9999999999.98',
            'is_active' => true,
            'is_sale' => false,
        ])->fresh();

        $theme = StorefrontTheme::create([
            'name' => 'JSON provider matrix',
            'slug' => 'json-provider-matrix',
            'tokens' => ['nested' => ['ключ' => 'значення']],
            'layout_config' => [],
            'style_profile' => ['shape' => 'object'],
            'is_active' => false,
        ])->fresh();

        $this->assertTrue($product->is_active);
        $this->assertFalse($product->is_sale);
        $this->assertSame('9999999999.99', $product->price);
        $this->assertSame('9999999999.98', $product->old_price);
        $this->assertSame('значення', $theme->tokens['nested']['ключ']);
        $this->assertSame(['shape' => 'object'], $theme->style_profile);
        $this->assertDatabaseHas('products', ['sku' => 'CASE-Sensitive-SKU']);
        $this->assertDatabaseMissing('products', ['sku' => 'case-sensitive-sku']);
    }

    public function test_foreign_keys_accept_valid_rows_and_reject_invalid_parents(): void
    {
        $product = $this->createProduct();
        $this->assertNotNull($product->category);

        $this->expectException(QueryException::class);
        DB::table('product_images')->insert([
            'product_id' => 999999999,
            'image' => 'invalid-parent.webp',
            'sort_order' => 0,
        ]);
    }

    public function test_typed_boolean_backfill_query_is_portable(): void
    {
        $product = $this->createProduct(['status' => '', 'is_active' => true]);

        DB::table('products')
            ->where('id', $product->id)
            ->where('is_active', true)
            ->update(['status' => 'active']);

        $this->assertSame('active', Product::findOrFail($product->id)->status);
    }
}
