<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Admin\AdminUserProvisioner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoSeedRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.env' => 'testing',
            'app.allow_demo_seeding' => false,
        ]);
    }

    public function test_demo_users_have_password_role_and_panel_contract(): void
    {
        $this->seed();

        $panel = filament()->getPanel('admin');

        foreach ([
            AdminUserProvisioner::PRIMARY_ADMIN_EMAIL => UserRole::Admin,
            'admin@alta-trade.test' => UserRole::Admin,
            'office@alta-trade.com.ua' => UserRole::Admin,
            'manager@alta-trade.test' => UserRole::Manager,
            'content@alta-trade.test' => UserRole::ContentManager,
        ] as $email => $role) {
            $user = User::where('email', $email)->firstOrFail();

            $this->assertTrue(Hash::check('password', $user->password), $email.' demo password is invalid.');
            $this->assertSame($role, $user->role);
            $this->assertTrue($user->canAccessPanel($panel), $email.' cannot access admin panel.');
        }
    }

    public function test_admin_demo_user_can_reach_admin_panel(): void
    {
        $this->assertSeededUserCanReachAdminPanel('admin@alta-trade.test');
    }

    public function test_office_demo_admin_can_reach_admin_panel(): void
    {
        $this->assertSeededUserCanReachAdminPanel('office@alta-trade.com.ua');
    }

    public function test_manager_demo_user_can_reach_admin_panel(): void
    {
        $this->assertSeededUserCanReachAdminPanel('manager@alta-trade.test');
    }

    public function test_content_demo_user_can_reach_admin_panel(): void
    {
        $this->assertSeededUserCanReachAdminPanel('content@alta-trade.test');
    }

    public function test_demo_seed_uses_local_demo_images_without_remote_urls(): void
    {
        $this->seed();

        $this->assertSame(0, Product::where('main_image', 'like', 'http%')->count());
        $this->assertSame(0, Category::where('image', 'like', 'http%')->count());
        $this->assertSame(0, Banner::where('image', 'like', 'http%')->count());
        $this->assertSame(0, ProductImage::where('image', 'like', 'http%')->count());

        $this->assertGreaterThan(1, Product::pluck('main_image')->unique()->count());
        $this->assertGreaterThan(1, Category::pluck('image')->unique()->count());
        $this->assertGreaterThan(1, Banner::pluck('image')->unique()->count());

        $this->assertTrue(Product::pluck('main_image')->every(fn (?string $image): bool => str_starts_with((string) $image, '/images/demo/products/')));
        $this->assertTrue(Category::pluck('image')->every(fn (?string $image): bool => str_starts_with((string) $image, '/images/demo/categories/')));
        $this->assertTrue(Banner::pluck('image')->every(fn (?string $image): bool => str_starts_with((string) $image, '/images/demo/banners/')));
    }

    public function test_seeded_storefront_renders_demo_images_not_remote_images(): void
    {
        $this->seed();

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('images.unsplash.com')
            ->assertSee('/images/demo/categories/', false)
            ->assertSee('/images/demo/banners/', false);

        $this->get(route('catalog'))
            ->assertOk()
            ->assertDontSee('images.unsplash.com')
            ->assertSee('/images/demo/products/', false);

        $product = Product::firstOrFail();

        $this->get(route('product.show', $product))
            ->assertOk()
            ->assertSee('/images/demo/products/', false);
    }

    public function test_demo_seed_is_skipped_in_production_like_mode_without_flag(): void
    {
        config([
            'app.env' => 'production',
            'app.allow_demo_seeding' => false,
        ]);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, Banner::count());
        $this->assertSame(0, Category::count());
        $this->assertSame(0, Product::count());
        $this->assertSame(0, ProductImage::count());

        $this->assertDatabaseHas('users', [
            'email' => AdminUserProvisioner::PRIMARY_ADMIN_EMAIL,
        ]);
        $this->assertDatabaseMissing('users', [
            'email' => 'admin@alta-trade.test',
        ]);
    }

    public function test_production_like_seeding_does_not_overwrite_existing_banner_when_demo_seed_is_skipped(): void
    {
        Banner::create([
            'title' => 'Existing production banner',
            'subtitle' => 'Keep this content',
            'button_text' => 'Open',
            'button_url' => '/catalog',
            'image' => '/images/placeholders/banner-placeholder.svg',
            'placement' => 'home_hero',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        config([
            'app.env' => 'production',
            'app.allow_demo_seeding' => false,
        ]);

        $this->seed(DatabaseSeeder::class);

        $banner = Banner::query()->where('placement', 'home_hero')->where('sort_order', 1)->firstOrFail();

        $this->assertSame('Existing production banner', $banner->title);
    }

    public function test_explicit_flag_allows_demo_seeding_in_production_like_mode(): void
    {
        config([
            'app.env' => 'production',
            'app.allow_demo_seeding' => true,
        ]);

        $this->seed(DatabaseSeeder::class);

        $this->assertGreaterThan(0, Banner::count());
        $this->assertGreaterThan(0, Product::count());
        $this->assertDatabaseHas('users', [
            'email' => 'admin@alta-trade.test',
        ]);
    }

    private function assertSeededUserCanReachAdminPanel(string $email): void
    {
        $this->seed();

        $user = User::where('email', $email)->firstOrFail();
        $response = $this->actingAs($user)->get('/admin');

        $this->assertNotSame(403, $response->getStatusCode(), $email.' is forbidden from admin panel.');
        $this->assertFalse(
            str_contains((string) $response->headers->get('Location'), '/admin/login'),
            $email.' was redirected to admin login.',
        );

        if (! $response->isRedirection()) {
            $response->assertOk();
        }
    }
}
