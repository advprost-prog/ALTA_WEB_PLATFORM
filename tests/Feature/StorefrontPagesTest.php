<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('caseInsensitiveSearchTerms')]
    public function test_catalog_search_is_case_insensitive(string $name, string $sku, string $term): void
    {
        $product = $this->createProduct([
            'name' => $name,
            'slug' => 'search-'.md5($term),
            'sku' => $sku,
        ]);

        $this->get(route('catalog', ['q' => $term]))
            ->assertOk()
            ->assertSee($product->name);
    }

    public static function caseInsensitiveSearchTerms(): array
    {
        return [
            'ASCII uppercase name' => ['Brake Cleaner', 'AT-CLEAN-01', 'BRAKE'],
            'ASCII mixed-case SKU' => ['Cleaner', 'At-Mixed-42', 'aT-mIxEd'],
            'Ukrainian uppercase' => ['Гальмівні колодки', 'AT-BRAKE-UA', 'ГАЛЬМІВНІ'],
            'Ukrainian mixed case' => ['Моторна олива', 'AT-OIL-UA', 'мОтОрНа'],
        ];
    }

    public function test_catalog_search_treats_wildcards_as_literals(): void
    {
        $category = $this->createCategory();
        $brand = $this->createBrand();
        $percent = $this->createProduct([
            'category' => $category,
            'brand' => $brand,
            'name' => 'Олива 100% synthetic',
            'slug' => 'literal-percent',
            'sku' => 'PERCENT-100',
        ]);
        $underscore = $this->createProduct([
            'category' => $category,
            'brand' => $brand,
            'name' => 'Filter_part',
            'slug' => 'literal-underscore',
            'sku' => 'UNDERSCORE-1',
        ]);
        $ordinary = $this->createProduct([
            'category' => $category,
            'brand' => $brand,
            'name' => 'Ordinary product',
            'slug' => 'ordinary-product',
            'sku' => 'ORDINARY-1',
        ]);

        $this->get(route('catalog', ['q' => '%']))
            ->assertSee($percent->name)
            ->assertDontSee($ordinary->name);

        $this->get(route('catalog', ['q' => '_']))
            ->assertSee($underscore->name)
            ->assertDontSee($ordinary->name);
    }

    public function test_catalog_uses_id_as_a_stable_tie_breaker(): void
    {
        CarbonImmutable::setTestNow('2026-07-18 12:00:00');
        $category = $this->createCategory();
        $brand = $this->createBrand();
        $olderId = $this->createProduct([
            'category' => $category,
            'brand' => $brand,
            'name' => 'Tie first',
            'slug' => 'tie-first',
            'sku' => 'TIE-1',
        ]);
        $newerId = $this->createProduct([
            'category' => $category,
            'brand' => $brand,
            'name' => 'Tie second',
            'slug' => 'tie-second',
            'sku' => 'TIE-2',
        ]);

        $this->get(route('catalog'))
            ->assertSeeInOrder([$newerId->name, $olderId->name]);

        CarbonImmutable::setTestNow();
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
