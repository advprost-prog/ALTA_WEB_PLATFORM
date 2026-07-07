<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\ProductAttributes\ProductAttributeResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\RelationManagers\ProductVariantsRelationManager;
use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Filament\Resources\TaxProfiles\TaxProfileResource;
use App\Filament\Resources\Units\UnitResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\TaxProfile;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\Feature\Concerns\CreatesCommerceData;
use Tests\TestCase;

class FilamentCatalogResourcesTest extends TestCase
{
    use CreatesCommerceData;
    use RefreshDatabase;

    public function test_catalog_navigation_resources_are_accessible_and_variant_resource_is_hidden_from_navigation(): void
    {
        $admin = $this->createUserWithRole(UserRole::Admin);

        $this->actingAs($admin)->get('/admin/products')->assertOk();
        $this->actingAs($admin)->get('/admin/units')->assertOk();
        $this->actingAs($admin)->get('/admin/tax-profiles')->assertOk();
        $this->actingAs($admin)->get('/admin/product-attributes')->assertOk();

        $reflection = new ReflectionClass(ProductVariantResource::class);
        $property = $reflection->getProperty('shouldRegisterNavigation');
        $property->setAccessible(true);

        $this->assertFalse((bool) $property->getValue());
        $this->assertSame('Каталог', ProductResource::getNavigationGroup());
        $this->assertSame('Каталог', UnitResource::getNavigationGroup());
        $this->assertSame('Каталог', TaxProfileResource::getNavigationGroup());
        $this->assertSame('Каталог', ProductAttributeResource::getNavigationGroup());
    }

    public function test_product_edit_page_contains_product_centered_sku_tabs(): void
    {
        $admin = $this->createUserWithRole(UserRole::Admin);
        $product = $this->createProduct();

        $this->actingAs($admin)
            ->get('/admin/products/'.$product->slug.'/edit')
            ->assertOk()
            ->assertSee('Продаж')
            ->assertSee('Товар має варіанти')
            ->assertSee('Продажні налаштування')
            ->assertSee('Податки / Акциз')
            ->assertSee('Оподаткування товару')
            ->assertSee('Пакування')
            ->assertSee('Пакування товару')
            ->assertSee('Штрихкоди')
            ->assertSee('Штрихкоди товару')
            ->assertDontSee('Продаж / SKU')
            ->assertDontSee('Основний SKU')
            ->assertDontSee('Варіанти товару')
            ->assertDontSee('name="data.product_id"', false);
    }

    public function test_variant_tab_and_relation_manager_are_visible_only_for_multi_variant_products(): void
    {
        $admin = $this->createUserWithRole(UserRole::Admin);
        $simpleProduct = $this->createProduct();

        $this->assertFalse(ProductVariantsRelationManager::canViewForRecord($simpleProduct->fresh(), 'edit'));

        $simpleResponse = $this->actingAs($admin)->get('/admin/products/'.$simpleProduct->slug.'/edit');
        $simpleResponse->assertOk()->assertDontSee('Варіанти товару');

        $multiProduct = $this->createProduct([
            'category' => $simpleProduct->category,
            'brand' => $simpleProduct->brand,
            'name' => 'Multi Variant Product',
            'slug' => 'multi-variant-product',
            'sku' => 'AT-MULTI-1',
            'has_variants' => true,
        ]);

        $this->assertTrue(ProductVariantsRelationManager::canViewForRecord($multiProduct->fresh(), 'edit'));

        $this->actingAs($admin)
            ->get('/admin/products/'.$multiProduct->slug.'/edit')
            ->assertOk()
            ->assertSee('Варіанти товару')
            ->assertSee('Default SKU залишається першим варіантом');
    }

    public function test_product_form_sku_fields_sync_into_default_variant_payload_handler(): void
    {
        $product = $this->createProduct();
        $unit = Unit::ensurePiece();
        $taxProfile = TaxProfile::ensureDefault();

        ProductResource::syncDefaultVariantFromPayload($product, [
            'default_variant_name' => 'Основний варіант 1л',
            'default_variant_barcode' => '4820001112223',
            'default_variant_is_active' => true,
            'default_variant_base_unit_id' => $unit->id,
            'default_variant_sales_unit_id' => $unit->id,
            'default_variant_purchase_unit_id' => $unit->id,
            'default_variant_tax_profile_id' => $taxProfile->id,
            'default_variant_is_excise_applicable' => true,
            'default_variant_excise_rate' => 7.5,
            'default_variant_requires_excise_stamp_entry' => true,
        ]);

        $variant = Product::query()->findOrFail($product->id)->resolveDefaultVariant();

        $this->assertNotNull($variant);
        $this->assertSame($product->sku, $variant->sku);
        $this->assertSame('Основний варіант 1л', $variant->name);
        $this->assertSame('4820001112223', $variant->barcode);
        $this->assertSame('7.50', $variant->excise_rate);
        $this->assertTrue($variant->requires_excise_stamp_entry);
    }

    public function test_product_form_excise_defaults_rate_and_clears_stamp_when_disabled(): void
    {
        $product = $this->createProduct();
        $unit = Unit::ensurePiece();
        $taxProfile = TaxProfile::ensureDefault();

        ProductResource::syncDefaultVariantFromPayload($product, [
            'default_variant_name' => null,
            'default_variant_barcode' => null,
            'default_variant_is_active' => true,
            'default_variant_base_unit_id' => $unit->id,
            'default_variant_sales_unit_id' => $unit->id,
            'default_variant_purchase_unit_id' => $unit->id,
            'default_variant_tax_profile_id' => $taxProfile->id,
            'default_variant_is_excise_applicable' => true,
            'default_variant_excise_rate' => null,
            'default_variant_requires_excise_stamp_entry' => true,
        ]);

        $variant = $product->fresh()->resolveDefaultVariant();

        $this->assertSame('5.00', $variant->excise_rate);
        $this->assertTrue($variant->requires_excise_stamp_entry);

        ProductResource::syncDefaultVariantFromPayload($product, [
            'default_variant_name' => null,
            'default_variant_barcode' => null,
            'default_variant_is_active' => true,
            'default_variant_base_unit_id' => $unit->id,
            'default_variant_sales_unit_id' => $unit->id,
            'default_variant_purchase_unit_id' => $unit->id,
            'default_variant_tax_profile_id' => $taxProfile->id,
            'default_variant_is_excise_applicable' => false,
            'default_variant_excise_rate' => '7.50',
            'default_variant_requires_excise_stamp_entry' => true,
        ]);

        $variant = $product->fresh()->resolveDefaultVariant();

        $this->assertNull($variant->excise_rate);
        $this->assertFalse($variant->requires_excise_stamp_entry);
    }

    public function test_simple_product_packages_and_barcodes_sync_to_default_variant_without_manual_variant_selection(): void
    {
        $product = $this->createProduct();
        $unit = Unit::ensurePiece();
        $taxProfile = TaxProfile::ensureDefault();

        ProductResource::syncDefaultVariantFromPayload($product, [
            'default_variant_name' => null,
            'default_variant_barcode' => null,
            'default_variant_is_active' => true,
            'default_variant_base_unit_id' => $unit->id,
            'default_variant_sales_unit_id' => $unit->id,
            'default_variant_purchase_unit_id' => $unit->id,
            'default_variant_tax_profile_id' => $taxProfile->id,
            'default_variant_is_excise_applicable' => false,
            'default_variant_excise_rate' => null,
            'default_variant_requires_excise_stamp_entry' => false,
            'default_variant_packages' => [[
                'name' => 'Коробка',
                'unit_id' => $unit->id,
                'quantity_in_base_unit' => 12,
                'barcode' => '4820000000012',
                'is_default_sales_package' => true,
                'is_active' => true,
                'sort_order' => 1,
            ]],
            'default_variant_barcodes' => [[
                'barcode' => '4820000000005',
                'type' => 'ean13',
                'is_primary' => true,
                'is_active' => true,
            ]],
        ]);

        $variant = $product->fresh()->resolveDefaultVariant();

        $this->assertSame(1, $variant->packages()->count());
        $this->assertSame(1, $variant->barcodes()->count());
        $this->assertSame('Коробка', $variant->packages()->firstOrFail()->name);
        $this->assertSame('4820000000005', $variant->barcodes()->firstOrFail()->barcode);
    }

    public function test_product_creation_creates_default_variant_with_required_links(): void
    {
        $product = $this->createProduct([
            'sku' => 'AT-CAT-UX-1',
            'slug' => 'at-cat-ux-1',
        ]);

        $variant = $product->fresh()->resolveDefaultVariant();

        $this->assertNotNull($variant);
        $this->assertFalse($product->fresh()->has_variants);
        $this->assertFalse($product->fresh()->hasVariants());
        $this->assertTrue($variant->is_default);
        $this->assertNotNull($variant->base_unit_id);
        $this->assertNotNull($variant->tax_profile_id);
        $this->assertSame('AT-CAT-UX-1', $variant->sku);
    }

    public function test_product_cannot_disable_variants_with_multiple_active_skus(): void
    {
        $product = $this->createProduct([
            'has_variants' => true,
            'sku' => 'AT-MULTI-GUARD',
            'slug' => 'at-multi-guard',
        ]);

        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'AT-MULTI-GUARD-B',
            'name' => 'Другий варіант',
            'base_unit_id' => Unit::ensurePiece()->id,
            'sales_unit_id' => Unit::ensurePiece()->id,
            'purchase_unit_id' => Unit::ensurePiece()->id,
            'tax_profile_id' => TaxProfile::ensureDefault()->id,
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $this->assertSame(2, $product->fresh()->activeVariantsCount());
        $this->assertFalse($product->fresh()->canDisableVariants());
    }

    public function test_package_and_barcode_have_no_standalone_admin_routes(): void
    {
        $admin = $this->createUserWithRole(UserRole::Admin);

        $this->actingAs($admin)->get('/admin/variant-packages')->assertNotFound();
        $this->actingAs($admin)->get('/admin/product-barcodes')->assertNotFound();
    }
}
