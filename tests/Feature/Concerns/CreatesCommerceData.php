<?php

namespace Tests\Feature\Concerns;

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;

trait CreatesCommerceData
{
    protected function createCategory(array $attributes = []): Category
    {
        return Category::create($attributes + [
            'name' => 'Моторні оливи',
            'slug' => 'motorni-olyvy',
            'description' => 'Категорія для тестів',
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    protected function createBrand(array $attributes = []): Brand
    {
        return Brand::create($attributes + [
            'name' => 'Bosch',
            'slug' => 'bosch',
            'is_active' => true,
        ]);
    }

    protected function createProduct(array $attributes = []): Product
    {
        $category = $attributes['category'] ?? $this->createCategory();
        $brand = $attributes['brand'] ?? $this->createBrand();

        unset($attributes['category'], $attributes['brand']);

        return Product::create($attributes + [
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'name' => 'Castrol EDGE 5W-30 LL 4L',
            'slug' => 'castrol-edge-5w-30-ll-4l',
            'sku' => 'AT-OIL-530-4L',
            'short_description' => 'Тестовий товар',
            'description' => 'Тестовий опис',
            'price' => 1000,
            'old_price' => 1200,
            'purchase_price' => 700,
            'stock' => 5,
            'stock_status' => 'in_stock',
            'is_active' => true,
            'is_new' => true,
            'is_hit' => true,
            'is_sale' => false,
        ]);
    }

    protected function createUserWithRole(UserRole $role): User
    {
        return User::create([
            'name' => $role->label(),
            'email' => $role->value . '@example.test',
            'password' => 'password',
            'role' => $role,
        ]);
    }
}
