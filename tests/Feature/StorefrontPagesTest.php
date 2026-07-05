<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesCommerceData;
use Tests\TestCase;

class StorefrontPagesTest extends TestCase
{
    use CreatesCommerceData;
    use RefreshDatabase;

    public function test_home_page_opens(): void
    {
        $this->createProduct();

        $this->get(route('home'))->assertOk()->assertSee('Alta-Trade');
    }

    public function test_catalog_page_opens(): void
    {
        $this->createProduct();

        $this->get(route('catalog'))->assertOk()->assertSee('Каталог');
    }

    public function test_category_page_opens_by_slug(): void
    {
        $category = $this->createCategory();
        $this->createProduct(['category' => $category]);

        $this->get(route('category.show', $category))->assertOk()->assertSee($category->name);
    }

    public function test_product_page_opens_by_slug(): void
    {
        $product = $this->createProduct();

        $this->get(route('product.show', $product))->assertOk()->assertSee($product->name);
    }

    public function test_static_commerce_pages_open(): void
    {
        $this->get(route('delivery-payment'))->assertOk()->assertSee('Доставка');
        $this->get(route('contacts'))->assertOk()->assertSee('Контакти');
        $this->get(route('about'))->assertOk()->assertSee('Про Alta-Trade');
    }

    public function test_home_page_contains_category_placeholder_image_url(): void
    {
        $category = $this->createCategory(['image' => null]);
        $this->createProduct(['category' => $category]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('images/placeholders/category-placeholder.svg', false);
    }

    public function test_catalog_contains_product_placeholder_image_url(): void
    {
        $this->createProduct(['main_image' => null]);

        $this->get(route('catalog'))
            ->assertOk()
            ->assertSee('images/placeholders/product-placeholder.svg', false);
    }

    public function test_product_without_image_shows_product_placeholder(): void
    {
        $product = $this->createProduct(['main_image' => null]);

        $this->get(route('product.show', $product))
            ->assertOk()
            ->assertSee($product->name)
            ->assertSee('images/placeholders/product-placeholder.svg', false);
    }

    public function test_category_without_image_shows_category_placeholder(): void
    {
        $category = $this->createCategory(['image' => null]);
        $this->createProduct(['category' => $category]);

        $this->get(route('category.show', $category))
            ->assertOk()
            ->assertSee('images/placeholders/category-placeholder.svg', false);
    }

    public function test_inactive_category_returns_not_found(): void
    {
        $category = $this->createCategory(['is_active' => false]);

        $this->get(route('category.show', $category))->assertNotFound();
    }

    public function test_inactive_product_returns_not_found(): void
    {
        $product = $this->createProduct(['is_active' => false]);

        $this->get(route('product.show', $product))->assertNotFound();
    }
}
