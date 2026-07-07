@php
    use App\Models\Banner;

    $context = $context ?? 'section';
    $contained = $contained ?? true;
    $banner = $banner ?? null;
    $placeholder = $placeholder ?? asset('images/placeholders/banner-placeholder.svg');

    $designBanner = $banner instanceof Banner
        ? $banner
        : new Banner(array_merge(Banner::DESIGN_DEFAULTS, [
            'accent_color' => '#ffb703',
            'height_variant' => $context === 'hero' ? 'hero' : 'lg',
            'overlay_enabled' => true,
            'overlay_opacity' => 34,
            'visual_style' => $context === 'catalog' ? 'dark' : 'clean',
        ]));

    $classes = $designBanner->designClasses($context);
    $isSplit = $designBanner->isSplitLayout();
    $layout = $designBanner->layout_variant ?: Banner::DESIGN_DEFAULTS['layout_variant'];
    $title = filled($banner?->title) ? $banner->title : ($fallbackTitle ?? null);
    $subtitle = filled($banner?->subtitle) ? $banner->subtitle : ($fallbackSubtitle ?? null);
    $eyebrow = filled($banner?->eyebrow) ? $banner->eyebrow : ($fallbackEyebrow ?? null);
    $imageUrl = $banner instanceof Banner ? $banner->image_url : $placeholder;
    $mobileImageUrl = $banner instanceof Banner ? $banner->mobile_image_url : $imageUrl;
    $imageAlt = $title ?: 'Alta-Trade';
    $primaryText = $banner instanceof Banner ? $banner->button_text : ($fallbackButtonText ?? null);
    $primaryUrl = $banner instanceof Banner ? $banner->primaryButtonUrl() : Banner::safeUrl($fallbackButtonUrl ?? null);
    $secondaryText = $banner instanceof Banner ? $banner->secondary_button_text : ($fallbackSecondaryButtonText ?? null);
    $secondaryUrl = $banner instanceof Banner ? $banner->secondaryButtonUrl() : Banner::safeUrl($fallbackSecondaryButtonUrl ?? null);
    $hasPrimaryButton = filled($primaryText) && filled($primaryUrl);
    $hasSecondaryButton = filled($secondaryText) && filled($secondaryUrl);
    $overlayEnabled = (bool) ($designBanner->overlay_enabled ?? true);
    $rootStyle = $designBanner->designStyleAttributes();
    $overlayStyle = $designBanner->overlayStyleAttributes();
    $shellClass = $contained ? 'section-shell' : '';
    $loading = $context === 'hero' ? 'eager' : 'lazy';
    $fetchPriority = $context === 'hero' ? 'high' : 'auto';
@endphp

<{{ $context === 'promo' ? 'article' : 'section' }} class="{{ $classes['root'] }} {{ $class ?? '' }}" @if (filled($rootStyle)) style="{{ $rootStyle }}" @endif data-banner-context="{{ $context }}">
    @if ($isSplit)
        <div class="{{ $shellClass }} storefront-design-banner__inner">
            @if ($layout === 'image_left')
                <div class="storefront-design-banner__media storefront-design-banner__media--inline">
                    <picture>
                        <source media="(max-width: 767px)" srcset="{{ $mobileImageUrl }}">
                        <img src="{{ $imageUrl }}" alt="{{ $imageAlt }}" class="{{ $classes['image'] }}" loading="{{ $loading }}" fetchpriority="{{ $fetchPriority }}" onerror="this.onerror=null;this.src='{{ $placeholder }}';">
                    </picture>
                    @if ($overlayEnabled)
                        <div class="{{ $classes['overlay'] }}" style="{{ $overlayStyle }}" data-banner-overlay></div>
                    @endif
                </div>
            @endif

            <div class="storefront-design-banner__content">
                @if (filled($eyebrow))
                    <div class="storefront-design-banner__eyebrow">{{ $eyebrow }}</div>
                @endif

                @if (filled($title) && $context === 'promo')
                    <h3 class="storefront-design-banner__title">{{ $title }}</h3>
                @elseif (filled($title))
                    <h1 class="storefront-design-banner__title">{{ $title }}</h1>
                @endif

                @if (filled($subtitle))
                    <p class="storefront-design-banner__subtitle">{{ $subtitle }}</p>
                @endif

                @if ($hasPrimaryButton || $hasSecondaryButton)
                    <div class="storefront-design-banner__actions">
                        @if ($hasPrimaryButton)
                            <a href="{{ $primaryUrl }}" class="{{ $classes['primary_button'] }}">{{ $primaryText }}</a>
                        @endif

                        @if ($hasSecondaryButton)
                            <a href="{{ $secondaryUrl }}" class="{{ $classes['secondary_button'] }}">{{ $secondaryText }}</a>
                        @endif
                    </div>
                @endif
            </div>

            @if ($layout !== 'image_left')
                <div class="storefront-design-banner__media storefront-design-banner__media--inline">
                    <picture>
                        <source media="(max-width: 767px)" srcset="{{ $mobileImageUrl }}">
                        <img src="{{ $imageUrl }}" alt="{{ $imageAlt }}" class="{{ $classes['image'] }}" loading="{{ $loading }}" fetchpriority="{{ $fetchPriority }}" onerror="this.onerror=null;this.src='{{ $placeholder }}';">
                    </picture>
                    @if ($overlayEnabled)
                        <div class="{{ $classes['overlay'] }}" style="{{ $overlayStyle }}" data-banner-overlay></div>
                    @endif
                </div>
            @endif
        </div>
    @else
        <div class="storefront-design-banner__media storefront-design-banner__media--background">
            <picture>
                <source media="(max-width: 767px)" srcset="{{ $mobileImageUrl }}">
                <img src="{{ $imageUrl }}" alt="{{ $imageAlt }}" class="{{ $classes['image'] }}" loading="{{ $loading }}" fetchpriority="{{ $fetchPriority }}" onerror="this.onerror=null;this.src='{{ $placeholder }}';">
            </picture>
        </div>

        @if ($overlayEnabled)
            <div class="{{ $classes['overlay'] }}" style="{{ $overlayStyle }}" data-banner-overlay></div>
        @endif

        <div class="{{ $shellClass }} storefront-design-banner__inner">
            <div class="storefront-design-banner__content">
                @if (filled($eyebrow))
                    <div class="storefront-design-banner__eyebrow">{{ $eyebrow }}</div>
                @endif

                @if (filled($title) && $context === 'promo')
                    <h3 class="storefront-design-banner__title">{{ $title }}</h3>
                @elseif (filled($title))
                    <h1 class="storefront-design-banner__title">{{ $title }}</h1>
                @endif

                @if (filled($subtitle))
                    <p class="storefront-design-banner__subtitle">{{ $subtitle }}</p>
                @endif

                @if ($hasPrimaryButton || $hasSecondaryButton)
                    <div class="storefront-design-banner__actions">
                        @if ($hasPrimaryButton)
                            <a href="{{ $primaryUrl }}" class="{{ $classes['primary_button'] }}">{{ $primaryText }}</a>
                        @endif

                        @if ($hasSecondaryButton)
                            <a href="{{ $secondaryUrl }}" class="{{ $classes['secondary_button'] }}">{{ $secondaryText }}</a>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    @endif
</{{ $context === 'promo' ? 'article' : 'section' }}>
