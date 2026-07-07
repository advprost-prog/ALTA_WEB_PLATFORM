<?php

namespace App\Models;

use App\Models\Concerns\ResolvesImageUrls;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    use HasFactory;
    use ResolvesImageUrls;

    public const PLACEMENTS = [
        'home_hero' => 'Головний hero',
        'home_promo' => 'Промо на головній',
        'catalog_top' => 'Верх каталогу',
    ];

    public const STYLE_PRESETS = [
        'clean_light' => 'Clean light',
        'dark_overlay' => 'Dark overlay',
        'brand_gradient' => 'Brand gradient',
        'glass_card' => 'Glass card',
        'compact_promo' => 'Compact promo',
        'split_product' => 'Split product',
    ];

    public const LAYOUT_VARIANTS = [
        'image_right' => 'Зображення праворуч',
        'image_left' => 'Зображення ліворуч',
        'background_image' => 'Фонове зображення',
        'split' => 'Split',
        'compact' => 'Компактний',
        'centered' => 'Центрований',
    ];

    public const VISUAL_STYLES = [
        'clean' => 'Clean',
        'light' => 'Light',
        'dark' => 'Dark',
        'accent' => 'Accent',
        'glass' => 'Glass',
        'gradient' => 'Gradient',
        'outline' => 'Outline',
    ];

    public const COLOR_SCHEMES = [
        'auto' => 'Auto',
        'brand' => 'Brand',
        'neutral' => 'Neutral',
        'warm' => 'Warm',
        'cool' => 'Cool',
        'dark' => 'Dark',
    ];

    public const TEXT_ALIGNMENTS = [
        'left' => 'Ліворуч',
        'center' => 'По центру',
        'right' => 'Праворуч',
    ];

    public const CONTENT_POSITIONS = [
        'left' => 'Ліворуч',
        'center' => 'По центру',
        'right' => 'Праворуч',
    ];

    public const VERTICAL_ALIGNMENTS = [
        'top' => 'Вгорі',
        'center' => 'По центру',
        'bottom' => 'Внизу',
    ];

    public const OVERLAY_STYLES = [
        'dark' => 'Темний',
        'light' => 'Світлий',
        'brand' => 'Брендовий',
        'gradient' => 'Градієнт',
    ];

    public const BUTTON_STYLES = [
        'primary' => 'Primary',
        'secondary' => 'Secondary',
        'dark' => 'Dark',
        'light' => 'Light',
        'outline' => 'Outline',
    ];

    public const BORDER_RADII = [
        'none' => 'Без округлення',
        'sm' => 'Small',
        'md' => 'Medium',
        'lg' => 'Large',
        'xl' => 'Extra large',
        'full' => 'Pill',
    ];

    public const SHADOWS = [
        'none' => 'Без тіні',
        'sm' => 'Small',
        'md' => 'Medium',
        'lg' => 'Large',
    ];

    public const HEIGHT_VARIANTS = [
        'sm' => 'Small',
        'md' => 'Medium',
        'lg' => 'Large',
        'xl' => 'Extra large',
        'hero' => 'Hero',
    ];

    public const IMAGE_FITS = [
        'cover' => 'Cover',
        'contain' => 'Contain',
    ];

    public const IMAGE_POSITIONS = [
        'center' => 'Center',
        'left' => 'Left',
        'right' => 'Right',
        'top' => 'Top',
        'bottom' => 'Bottom',
    ];

    public const ANIMATION_TYPES = [
        'none' => 'Без анімації',
        'fade' => 'Fade',
        'slide_up' => 'Slide up',
        'slide_left' => 'Slide left',
        'zoom_in' => 'Zoom in',
        'soft_parallax' => 'Soft parallax',
    ];

    public const DESIGN_DEFAULTS = [
        'style_preset' => 'dark_overlay',
        'layout_variant' => 'background_image',
        'visual_style' => 'clean',
        'color_scheme' => 'auto',
        'text_align' => 'left',
        'content_position' => 'left',
        'vertical_align' => 'center',
        'overlay_style' => 'dark',
        'button_style' => 'primary',
        'border_radius' => 'md',
        'shadow' => 'md',
        'height_variant' => 'md',
        'image_fit' => 'cover',
        'image_position' => 'center',
        'animation_type' => 'none',
    ];

    public const PRESET_DEFAULTS = [
        'clean_light' => [
            'layout_variant' => 'background_image',
            'visual_style' => 'light',
            'color_scheme' => 'neutral',
            'overlay_enabled' => true,
            'overlay_style' => 'light',
            'overlay_opacity' => 55,
            'button_style' => 'dark',
            'height_variant' => 'lg',
            'shadow' => 'sm',
        ],
        'dark_overlay' => [
            'layout_variant' => 'background_image',
            'visual_style' => 'dark',
            'color_scheme' => 'dark',
            'overlay_enabled' => true,
            'overlay_style' => 'dark',
            'overlay_opacity' => 34,
            'button_style' => 'primary',
            'height_variant' => 'hero',
            'shadow' => 'lg',
        ],
        'brand_gradient' => [
            'layout_variant' => 'background_image',
            'visual_style' => 'gradient',
            'color_scheme' => 'brand',
            'overlay_enabled' => true,
            'overlay_style' => 'brand',
            'overlay_opacity' => 42,
            'button_style' => 'light',
            'height_variant' => 'lg',
            'shadow' => 'lg',
        ],
        'glass_card' => [
            'layout_variant' => 'background_image',
            'visual_style' => 'glass',
            'color_scheme' => 'cool',
            'overlay_enabled' => true,
            'overlay_style' => 'dark',
            'overlay_opacity' => 26,
            'button_style' => 'primary',
            'height_variant' => 'lg',
            'shadow' => 'lg',
        ],
        'compact_promo' => [
            'layout_variant' => 'compact',
            'visual_style' => 'accent',
            'color_scheme' => 'warm',
            'overlay_enabled' => true,
            'overlay_style' => 'gradient',
            'overlay_opacity' => 24,
            'button_style' => 'dark',
            'height_variant' => 'sm',
            'shadow' => 'md',
        ],
        'split_product' => [
            'layout_variant' => 'image_right',
            'visual_style' => 'clean',
            'color_scheme' => 'neutral',
            'overlay_enabled' => false,
            'overlay_style' => 'dark',
            'overlay_opacity' => 0,
            'button_style' => 'primary',
            'height_variant' => 'lg',
            'shadow' => 'md',
        ],
    ];

    protected $fillable = [
        'eyebrow',
        'title',
        'subtitle',
        'button_text',
        'button_url',
        'secondary_button_text',
        'secondary_button_url',
        'image',
        'mobile_image',
        'placement',
        'style_preset',
        'layout_variant',
        'visual_style',
        'color_scheme',
        'text_align',
        'content_position',
        'vertical_align',
        'overlay_enabled',
        'overlay_opacity',
        'overlay_style',
        'background_color',
        'text_color',
        'accent_color',
        'button_style',
        'border_radius',
        'shadow',
        'height_variant',
        'image_fit',
        'image_position',
        'animation_enabled',
        'animation_type',
        'animation_delay_ms',
        'animation_duration_ms',
        'starts_at',
        'ends_at',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'overlay_enabled' => 'boolean',
            'overlay_opacity' => 'integer',
            'animation_enabled' => 'boolean',
            'animation_delay_ms' => 'integer',
            'animation_duration_ms' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $banner): void {
            $banner->applyDesignDefaults();
        });
    }

    public function getImageUrlAttribute(): string
    {
        return $this->resolveImageUrl($this->image, 'images/placeholders/banner-placeholder.svg');
    }

    public function getMobileImageUrlAttribute(): string
    {
        return $this->resolveImageUrl($this->mobile_image ?: $this->image, 'images/placeholders/banner-placeholder.svg');
    }

    public function isSplitLayout(): bool
    {
        return in_array($this->designValue('layout_variant'), ['image_right', 'image_left', 'split'], true);
    }

    public function primaryButtonUrl(): ?string
    {
        return self::safeUrl($this->button_url);
    }

    public function secondaryButtonUrl(): ?string
    {
        return self::safeUrl($this->secondary_button_url);
    }

    public function designClasses(string $context = 'section'): array
    {
        $animationType = $this->animation_enabled ? $this->designValue('animation_type') : 'none';

        return [
            'root' => self::classes([
                'storefront-design-banner',
                self::classFromMap('context', $context, 'storefront-design-banner--context-section'),
                self::classFromMap('layout_variant', $this->designValue('layout_variant')),
                self::classFromMap('visual_style', $this->designValue('visual_style')),
                self::classFromMap('color_scheme', $this->designValue('color_scheme')),
                self::classFromMap('text_align', $this->designValue('text_align')),
                self::classFromMap('content_position', $this->designValue('content_position')),
                self::classFromMap('vertical_align', $this->designValue('vertical_align')),
                self::classFromMap('border_radius', $this->designValue('border_radius')),
                self::classFromMap('shadow', $this->designValue('shadow')),
                self::classFromMap('height_variant', $this->designValue('height_variant')),
                $this->animation_enabled ? 'storefront-design-banner--animated' : null,
                self::classFromMap('animation_type', $animationType),
            ]),
            'image' => self::classes([
                'storefront-design-banner__image',
                self::classFromMap('image_fit', $this->designValue('image_fit')),
                self::classFromMap('image_position', $this->designValue('image_position')),
            ]),
            'overlay' => self::classes([
                'storefront-design-banner__overlay',
                self::classFromMap('overlay_style', $this->designValue('overlay_style')),
            ]),
            'primary_button' => self::classFromMap('button_style', $this->designValue('button_style'), 'storefront-design-banner__button storefront-design-banner__button--primary'),
            'secondary_button' => 'storefront-design-banner__button storefront-design-banner__button--secondary',
        ];
    }

    public function designStyleAttributes(): string
    {
        $styles = [
            '--banner-accent' => self::safeCssColor($this->accent_color) ?: '#ffb703',
            '--banner-bg' => self::safeCssColor($this->background_color),
            '--banner-text' => self::safeCssColor($this->text_color),
            '--banner-animation-delay' => max(0, min(5000, (int) $this->animation_delay_ms)).'ms',
            '--banner-animation-duration' => max(100, min(3000, (int) $this->animation_duration_ms)).'ms',
        ];

        return self::styleString($styles);
    }

    public function overlayStyleAttributes(): string
    {
        return self::styleString([
            '--banner-overlay-opacity' => number_format(max(0, min(90, (int) $this->overlay_opacity)) / 100, 2, '.', ''),
        ]);
    }

    public static function safeUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '' || preg_match('/[\s\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }

        if (preg_match('/^(https?:\/\/|\/(?!\/)|#)/i', $url) === 1) {
            return $url;
        }

        return null;
    }

    public static function presetDefaults(string $preset): array
    {
        return self::PRESET_DEFAULTS[$preset] ?? self::PRESET_DEFAULTS[self::DESIGN_DEFAULTS['style_preset']];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(fn (Builder $query): Builder => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $query): Builder => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    private function applyDesignDefaults(): void
    {
        foreach (self::DESIGN_DEFAULTS as $field => $default) {
            $this->{$field} = $this->allowedValue($field, $this->{$field} ?? null, $default);
        }

        $this->placement = $this->allowedValue('placement', $this->placement, 'home_hero');
        $this->overlay_enabled ??= $this->isSplitLayout() ? false : true;
        $this->overlay_opacity = max(0, min(90, (int) ($this->overlay_opacity ?? 30)));
        $this->animation_enabled ??= false;
        $this->animation_delay_ms = max(0, min(5000, (int) ($this->animation_delay_ms ?? 0)));
        $this->animation_duration_ms = max(100, min(3000, (int) ($this->animation_duration_ms ?? 500)));
    }

    private function designValue(string $field): string
    {
        return $this->allowedValue($field, $this->{$field} ?? null, self::DESIGN_DEFAULTS[$field] ?? '');
    }

    private function allowedValue(string $field, mixed $value, string $default): string
    {
        $value = trim((string) $value);

        return array_key_exists($value, self::optionsFor($field)) ? $value : $default;
    }

    private static function optionsFor(string $field): array
    {
        return match ($field) {
            'placement' => self::PLACEMENTS,
            'style_preset' => self::STYLE_PRESETS,
            'layout_variant' => self::LAYOUT_VARIANTS,
            'visual_style' => self::VISUAL_STYLES,
            'color_scheme' => self::COLOR_SCHEMES,
            'text_align' => self::TEXT_ALIGNMENTS,
            'content_position' => self::CONTENT_POSITIONS,
            'vertical_align' => self::VERTICAL_ALIGNMENTS,
            'overlay_style' => self::OVERLAY_STYLES,
            'button_style' => self::BUTTON_STYLES,
            'border_radius' => self::BORDER_RADII,
            'shadow' => self::SHADOWS,
            'height_variant' => self::HEIGHT_VARIANTS,
            'image_fit' => self::IMAGE_FITS,
            'image_position' => self::IMAGE_POSITIONS,
            'animation_type' => self::ANIMATION_TYPES,
            default => [],
        };
    }

    private static function classFromMap(string $field, string $value, ?string $default = null): ?string
    {
        $classes = [
            'context' => [
                'hero' => 'storefront-design-banner--context-hero',
                'promo' => 'storefront-design-banner--context-promo',
                'catalog' => 'storefront-design-banner--context-catalog',
                'section' => 'storefront-design-banner--context-section',
            ],
            'layout_variant' => [
                'image_right' => 'storefront-design-banner--layout-image-right',
                'image_left' => 'storefront-design-banner--layout-image-left',
                'background_image' => 'storefront-design-banner--layout-background',
                'split' => 'storefront-design-banner--layout-split',
                'compact' => 'storefront-design-banner--layout-compact',
                'centered' => 'storefront-design-banner--layout-centered',
            ],
            'visual_style' => [
                'clean' => 'storefront-design-banner--style-clean',
                'light' => 'storefront-design-banner--style-light',
                'dark' => 'storefront-design-banner--style-dark',
                'accent' => 'storefront-design-banner--style-accent',
                'glass' => 'storefront-design-banner--style-glass',
                'gradient' => 'storefront-design-banner--style-gradient',
                'outline' => 'storefront-design-banner--style-outline',
            ],
            'color_scheme' => [
                'auto' => 'storefront-design-banner--scheme-auto',
                'brand' => 'storefront-design-banner--scheme-brand',
                'neutral' => 'storefront-design-banner--scheme-neutral',
                'warm' => 'storefront-design-banner--scheme-warm',
                'cool' => 'storefront-design-banner--scheme-cool',
                'dark' => 'storefront-design-banner--scheme-dark',
            ],
            'text_align' => [
                'left' => 'storefront-design-banner--text-left',
                'center' => 'storefront-design-banner--text-center',
                'right' => 'storefront-design-banner--text-right',
            ],
            'content_position' => [
                'left' => 'storefront-design-banner--content-left',
                'center' => 'storefront-design-banner--content-center',
                'right' => 'storefront-design-banner--content-right',
            ],
            'vertical_align' => [
                'top' => 'storefront-design-banner--vertical-top',
                'center' => 'storefront-design-banner--vertical-center',
                'bottom' => 'storefront-design-banner--vertical-bottom',
            ],
            'overlay_style' => [
                'dark' => 'storefront-design-banner__overlay--dark',
                'light' => 'storefront-design-banner__overlay--light',
                'brand' => 'storefront-design-banner__overlay--brand',
                'gradient' => 'storefront-design-banner__overlay--gradient',
            ],
            'button_style' => [
                'primary' => 'storefront-design-banner__button storefront-design-banner__button--primary',
                'secondary' => 'storefront-design-banner__button storefront-design-banner__button--secondary',
                'dark' => 'storefront-design-banner__button storefront-design-banner__button--dark',
                'light' => 'storefront-design-banner__button storefront-design-banner__button--light',
                'outline' => 'storefront-design-banner__button storefront-design-banner__button--outline',
            ],
            'border_radius' => [
                'none' => 'storefront-design-banner--radius-none',
                'sm' => 'storefront-design-banner--radius-sm',
                'md' => 'storefront-design-banner--radius-md',
                'lg' => 'storefront-design-banner--radius-lg',
                'xl' => 'storefront-design-banner--radius-xl',
                'full' => 'storefront-design-banner--radius-full',
            ],
            'shadow' => [
                'none' => 'storefront-design-banner--shadow-none',
                'sm' => 'storefront-design-banner--shadow-sm',
                'md' => 'storefront-design-banner--shadow-md',
                'lg' => 'storefront-design-banner--shadow-lg',
            ],
            'height_variant' => [
                'sm' => 'storefront-design-banner--height-sm',
                'md' => 'storefront-design-banner--height-md',
                'lg' => 'storefront-design-banner--height-lg',
                'xl' => 'storefront-design-banner--height-xl',
                'hero' => 'storefront-design-banner--height-hero',
            ],
            'image_fit' => [
                'cover' => 'storefront-design-banner__image--fit-cover',
                'contain' => 'storefront-design-banner__image--fit-contain',
            ],
            'image_position' => [
                'center' => 'storefront-design-banner__image--position-center',
                'left' => 'storefront-design-banner__image--position-left',
                'right' => 'storefront-design-banner__image--position-right',
                'top' => 'storefront-design-banner__image--position-top',
                'bottom' => 'storefront-design-banner__image--position-bottom',
            ],
            'animation_type' => [
                'none' => null,
                'fade' => 'storefront-design-banner--animation-fade',
                'slide_up' => 'storefront-design-banner--animation-slide-up',
                'slide_left' => 'storefront-design-banner--animation-slide-left',
                'zoom_in' => 'storefront-design-banner--animation-zoom-in',
                'soft_parallax' => 'storefront-design-banner--animation-soft-parallax',
            ],
        ];

        return $classes[$field][$value] ?? $default;
    }

    private static function classes(array $classes): string
    {
        return implode(' ', array_values(array_filter($classes)));
    }

    private static function safeCssColor(?string $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $value) === 1 ? strtolower($value) : null;
    }

    private static function styleString(array $styles): string
    {
        return implode(' ', array_map(
            fn (string $property, string $value): string => $property.': '.$value.';',
            array_keys(array_filter($styles)),
            array_values(array_filter($styles)),
        ));
    }
}
