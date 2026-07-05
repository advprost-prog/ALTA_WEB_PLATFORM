<?php

namespace Tests\Feature;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontCategoryHierarchyTest extends TestCase
{
    use RefreshDatabase;

    public function test_nested_categories_only_show_on_parent_category_page(): void
    {
        $parent = Category::create([
            'name' => 'Автозапчастини',
            'slug' => 'avtozapchastyny',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $child = Category::create([
            'parent_id' => $parent->id,
            'name' => 'Двигун',
            'slug' => 'dvygun',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $grandchild = Category::create([
            'parent_id' => $child->id,
            'name' => 'Поршні',
            'slug' => 'porshni',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $catalogResponse = $this->get(route('catalog'));

        $catalogResponse->assertOk();
        $catalogResponse->assertSee('option value="' . $parent->slug . '"', false);
        $catalogResponse->assertDontSee('option value="' . $child->slug . '"', false);
        $catalogResponse->assertDontSee('option value="' . $grandchild->slug . '"', false);

        $categoryResponse = $this->get(route('category.show', $parent));

        $categoryResponse->assertOk();
        $categoryResponse->assertSee($parent->name);
        $categoryResponse->assertSee($child->name);
        $categoryResponse->assertSee($grandchild->name);
    }
}
