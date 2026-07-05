<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesCommerceData;
use Tests\TestCase;

class ProductImageResolutionTest extends TestCase
{
    use CreatesCommerceData;
    use RefreshDatabase;

    public function test_product_storage_main_image_resolves_to_public_storage_url(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/test-product.jpg', 'fake-image');

        $product = $this->createProduct([
            'main_image' => 'products/test-product.jpg',
        ]);

        $this->assertStringContainsString('/storage/products/test-product.jpg', $product->image_url);
        $this->assertStringNotContainsString('product-placeholder.svg', $product->image_url);
    }

    public function test_product_without_main_image_uses_product_placeholder(): void
    {
        $product = $this->createProduct([
            'main_image' => null,
        ]);

        $this->assertStringContainsString('images/placeholders/product-placeholder.svg', $product->image_url);
    }

    public function test_product_uses_first_gallery_storage_image_when_main_image_is_missing(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/gallery/test-gallery.webp', 'fake-image');

        $product = $this->createProduct([
            'main_image' => null,
        ]);

        $productImage = $product->images()->create([
            'image' => 'products/gallery/test-gallery.webp',
            'alt' => 'Gallery test image',
            'sort_order' => 1,
        ]);

        $this->assertStringContainsString('/storage/products/gallery/test-gallery.webp', $product->fresh()->image_url);
        $this->assertStringContainsString('/storage/products/gallery/test-gallery.webp', $productImage->image_url);
        $this->assertStringNotContainsString('product-placeholder.svg', $product->fresh()->image_url);
    }

    public function test_product_ignores_remote_main_image_when_local_gallery_main_exists(): void
    {
        Storage::fake('public');

        $product = $this->createProduct([
            'main_image' => 'https://remote.example.test/product.jpg',
        ]);
        $path = 'product-gallery/'.$product->id.'/main.webp';
        Storage::disk('public')->put($path, 'fake-image');

        $galleryImage = $product->images()->create([
            'image' => $path,
            'alt' => 'Main',
            'sort_order' => 1,
            'is_main' => true,
        ]);

        $this->assertStringContainsString('/storage/'.$galleryImage->image, $product->fresh()->image_url);
        $this->assertStringNotContainsString('remote.example.test', $product->fresh()->image_url);
    }

    public function test_product_local_demo_image_resolves_without_placeholder(): void
    {
        $product = $this->createProduct([
            'main_image' => '/images/demo/products/lighting.svg',
        ]);

        $this->assertStringContainsString('/images/demo/products/lighting.svg', $product->image_url);
        $this->assertStringNotContainsString('product-placeholder.svg', $product->image_url);
    }

    public function test_local_storage_debug_route_exposes_public_disk_details(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/debug-test.jpg', 'fake-image');

        $this->get('/_debug/storage-check?path=products/debug-test.jpg')
            ->assertOk()
            ->assertSee('debug-test.jpg')
            ->assertSee('public.disk.url');
    }

    public function test_database_seed_does_not_overwrite_existing_storage_main_image(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/custom-osram.jpg', 'fake-image');

        $category = $this->createCategory([
            'name' => 'Освітлення',
            'slug' => 'osvitlennia',
        ]);

        $brand = $this->createBrand([
            'name' => 'Osram',
            'slug' => 'osram',
        ]);

        $product = $this->createProduct([
            'category' => $category,
            'brand' => $brand,
            'name' => 'Osram Night Breaker LED H7',
            'slug' => 'osram-night-breaker-led-h7',
            'sku' => 'AT-LGT-H7LED',
            'main_image' => 'products/custom-osram.jpg',
        ]);

        $this->seed();

        $this->assertSame('products/custom-osram.jpg', $product->fresh()->main_image);
        $this->assertStringContainsString('/storage/products/custom-osram.jpg', $product->fresh()->image_url);
    }
}
