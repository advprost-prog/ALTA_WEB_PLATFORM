<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Banners\Pages\EditBanner;
use App\Models\Banner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Concerns\CreatesCommerceData;
use Tests\TestCase;

class BannerDesignSystemTest extends TestCase
{
    use CreatesCommerceData;
    use RefreshDatabase;

    public function test_banner_with_default_design_values_renders_without_errors(): void
    {
        Banner::create([
            'title' => 'Default design banner',
            'subtitle' => 'Legacy-friendly content',
            'button_text' => 'Open catalog',
            'button_url' => '/catalog',
            'image' => '/images/demo/banners/hero.svg',
            'placement' => 'home_hero',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Default design banner')
            ->assertSee('storefront-design-banner--layout-background', false)
            ->assertSee('data-banner-context="hero"', false);
    }

    public function test_inactive_banner_is_not_rendered_on_storefront(): void
    {
        Banner::create([
            'title' => 'Hidden banner title',
            'placement' => 'home_hero',
            'is_active' => false,
            'sort_order' => 1,
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('Hidden banner title');
    }

    public function test_active_banner_is_rendered_on_storefront(): void
    {
        Banner::create([
            'title' => 'Visible banner title',
            'placement' => 'home_hero',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Visible banner title');
    }

    public function test_mobile_image_falls_back_to_desktop_image(): void
    {
        Banner::create([
            'title' => 'Fallback image banner',
            'image' => '/images/demo/banners/hero.svg',
            'mobile_image' => null,
            'placement' => 'home_hero',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $response = $this->get(route('home'))
            ->assertOk()
            ->assertSee('media="(max-width: 767px)"', false);

        $this->assertGreaterThanOrEqual(2, substr_count($response->getContent(), '/images/demo/banners/hero.svg'));
    }

    public function test_overlay_classes_and_opacity_are_applied_when_enabled(): void
    {
        Banner::create([
            'title' => 'Overlay banner',
            'placement' => 'home_hero',
            'overlay_enabled' => true,
            'overlay_style' => 'brand',
            'overlay_opacity' => 45,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-banner-overlay', false)
            ->assertSee('storefront-design-banner__overlay--brand', false)
            ->assertSee('--banner-overlay-opacity: 0.45;', false);
    }

    public function test_animation_is_disabled_by_default(): void
    {
        $banner = Banner::create([
            'title' => 'Static banner',
            'placement' => 'home_hero',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->assertFalse($banner->fresh()->animation_enabled);

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('storefront-design-banner--animated', false);
    }

    public function test_invalid_enum_values_are_normalized_before_persisting(): void
    {
        $banner = Banner::create([
            'title' => 'Invalid enum banner',
            'placement' => 'not_a_placement',
            'layout_variant' => 'raw-css-class',
            'visual_style' => 'script',
            'animation_type' => 'spin_forever',
            'is_active' => true,
        ])->fresh();

        $this->assertSame('home_hero', $banner->placement);
        $this->assertSame('background_image', $banner->layout_variant);
        $this->assertSame('clean', $banner->visual_style);
        $this->assertSame('none', $banner->animation_type);
    }

    public function test_frontend_does_not_render_empty_cta_buttons(): void
    {
        Banner::create([
            'title' => 'No CTA banner',
            'button_text' => null,
            'button_url' => null,
            'secondary_button_text' => 'Missing URL',
            'secondary_button_url' => null,
            'placement' => 'home_hero',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('No CTA banner')
            ->assertDontSee('storefront-design-banner__button', false);
    }

    public function test_filament_form_saves_banner_design_settings(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Admin));

        $banner = Banner::create([
            'title' => 'Admin editable banner',
            'button_text' => 'Catalog',
            'button_url' => '/catalog',
            'placement' => 'home_hero',
            'is_active' => true,
        ]);

        Livewire::test(EditBanner::class, ['record' => $banner->getKey()])
            ->fillForm([
                'title' => 'Admin edited banner',
                'eyebrow' => 'Admin preset',
                'button_text' => 'Open catalog',
                'button_url' => '/catalog',
                'secondary_button_text' => 'Contacts',
                'secondary_button_url' => '/contacts',
                'style_preset' => 'glass_card',
                'layout_variant' => 'background_image',
                'visual_style' => 'glass',
                'color_scheme' => 'cool',
                'text_align' => 'center',
                'content_position' => 'center',
                'vertical_align' => 'center',
                'overlay_enabled' => true,
                'overlay_style' => 'dark',
                'overlay_opacity' => 32,
                'background_color' => '#101114',
                'text_color' => '#f8fafc',
                'accent_color' => '#18d7ff',
                'button_style' => 'primary',
                'border_radius' => 'lg',
                'shadow' => 'lg',
                'height_variant' => 'lg',
                'image_fit' => 'cover',
                'image_position' => 'center',
                'animation_enabled' => true,
                'animation_type' => 'slide_up',
                'animation_delay_ms' => 100,
                'animation_duration_ms' => 600,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $banner->refresh();

        $this->assertSame('Admin edited banner', $banner->title);
        $this->assertSame('glass_card', $banner->style_preset);
        $this->assertSame('glass', $banner->visual_style);
        $this->assertSame('center', $banner->text_align);
        $this->assertSame(32, $banner->overlay_opacity);
        $this->assertTrue($banner->animation_enabled);
        $this->assertSame('slide_up', $banner->animation_type);
    }
}
