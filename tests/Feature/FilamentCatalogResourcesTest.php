<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\ProductAttributes\ProductAttributeResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Filament\Resources\TaxProfiles\TaxProfileResource;
use App\Filament\Resources\Units\UnitResource;
use App\Models\Product;
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
            ->assertSee('Продаж / SKU')
            ->assertSee('Податки / Акциз')
            ->assertSee('Пакування')
            ->assertSee('Штрихкоди')
            ->assertSee('Варіанти')
            ->assertDontSee('name="data.product_id"', false);
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

    public function test_product_creation_creates_default_variant_with_required_links(): void
    {
        $product = $this->createProduct([
            'sku' => 'AT-CAT-UX-1',
            'slug' => 'at-cat-ux-1',
        ]);

        $variant = $product->fresh()->resolveDefaultVariant();

        $this->assertNotNull($variant);
        $this->assertTrue($variant->is_default);
        $this->assertNotNull($variant->base_unit_id);
        $this->assertNotNull($variant->tax_profile_id);
        $this->assertSame('AT-CAT-UX-1', $variant->sku);
    }

    public function test_package_and_barcode_have_no_standalone_admin_routes(): void
    {
        $admin = $this->createUserWithRole(UserRole::Admin);

        $this->actingAs($admin)->get('/admin/variant-packages')->assertNotFound();
        $this->actingAs($admin)->get('/admin/product-barcodes')->assertNotFound();
    }
}
