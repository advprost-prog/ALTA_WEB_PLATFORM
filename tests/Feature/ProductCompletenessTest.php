<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Services\Catalog\ProductCompletenessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Concerns\CreatesCommerceData;
use Tests\TestCase;

class ProductCompletenessTest extends TestCase
{
    use CreatesCommerceData;
    use RefreshDatabase;

    public function test_product_without_photo_has_lower_score_than_complete_product(): void
    {
        $category = $this->createCategory();
        $brand = $this->createBrand();
        $service = app(ProductCompletenessService::class);

        $withoutPhoto = $this->createProduct([
            'category' => $category,
            'brand' => $brand,
            'slug' => 'without-photo',
            'sku' => 'WITHOUT-PHOTO',
            'seo_title' => 'SEO title',
            'seo_description' => 'SEO description',
            'image_alt_text' => null,
        ]);

        $complete = $this->createProduct([
            'category' => $category,
            'brand' => $brand,
            'slug' => 'complete-product',
            'sku' => 'COMPLETE-PRODUCT',
            'main_image' => 'product-gallery/complete-product/main.webp',
            'image_alt_text' => 'Фільтр Bosch для авто',
            'seo_title' => 'Фільтр Bosch купити',
            'seo_description' => 'Фільтр Bosch для сервісного обслуговування авто.',
        ]);
        $complete->images()->create([
            'image' => 'product-gallery/complete-product/gallery.webp',
            'alt' => 'Фільтр Bosch',
        ]);
        $complete->specifications()->create([
            'name' => 'Тип',
            'value' => 'Фільтр',
        ]);

        $this->assertLessThan($service->score($complete), $service->score($withoutPhoto));
        $this->assertSame('success', $service->status($complete));
    }

    public function test_product_resource_shows_completeness_column_and_problem_filters(): void
    {
        $category = $this->createCategory();
        $brand = $this->createBrand();
        $low = $this->createProduct([
            'category' => $category,
            'brand' => $brand,
            'slug' => 'low-completeness',
            'sku' => 'LOW-COMP',
            'short_description' => null,
            'description' => null,
            'price' => 0,
            'stock' => 0,
            'main_image' => null,
            'image_alt_text' => null,
            'seo_title' => null,
            'seo_description' => null,
        ]);
        $ready = $this->createProduct([
            'category' => $category,
            'brand' => $brand,
            'slug' => 'ready-product',
            'sku' => 'READY-PRODUCT',
            'main_image' => 'product-gallery/ready-product/main.webp',
            'image_alt_text' => 'Фільтр Bosch для авто',
            'seo_title' => 'Фільтр Bosch купити',
            'seo_description' => 'Фільтр Bosch для сервісного обслуговування авто.',
        ]);
        $ready->images()->create([
            'image' => 'product-gallery/ready-product/gallery.webp',
            'alt' => 'Фільтр Bosch',
        ]);
        $ready->specifications()->create([
            'name' => 'Тип',
            'value' => 'Фільтр',
        ]);

        $this->actingAs($this->createUserWithRole(UserRole::Manager));

        Livewire::test(ListProducts::class)
            ->assertTableColumnExists('completeness')
            ->assertCanSeeTableRecords([$low, $ready]);

        Livewire::test(ListProducts::class)
            ->filterTable('without_photo')
            ->assertCanSeeTableRecords([$low])
            ->assertCanNotSeeTableRecords([$ready]);

        Livewire::test(ListProducts::class)
            ->filterTable('without_seo')
            ->assertCanSeeTableRecords([$low])
            ->assertCanNotSeeTableRecords([$ready]);

        Livewire::test(ListProducts::class)
            ->filterTable('low_completeness')
            ->assertCanSeeTableRecords([$low])
            ->assertCanNotSeeTableRecords([$ready]);
    }
}
