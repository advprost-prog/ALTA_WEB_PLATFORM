@php
    $pricingService = app(\App\Services\Commerce\ProductPricingService::class);
    $availabilityService = app(\App\Services\Commerce\ProductAvailabilityService::class);
    $priceView = $pricingService->priceView($product);
    $availabilityView = $availabilityService->availabilityView($product);
    $isAvailable = $availabilityView['is_available'];
    $isPurchasable = $isAvailable && $priceView['is_available_for_selected_currency'];
    $specifications = $product->relationLoaded('specifications') ? $product->specifications->take(2) : collect();
    $productPlaceholder = asset('images/placeholders/product-placeholder.svg');
    $layoutConfig = $themeLayoutConfig ?? [];
    $componentConfig = $themeComponentConfig ?? [];
    $variant = $layoutConfig['productCardVariant'] ?? 'dark';
    $showBadges = $componentConfig['showBadges'] ?? true;
    $showBrand = $componentConfig['showBrandInCard'] ?? true;
    $showSku = $componentConfig['showSkuInCard'] ?? true;
    $showQuickBuy = $componentConfig['showQuickBuy'] ?? true;
    $showShortSpecs = $componentConfig['showProductShortSpecs'] ?? true;
    $buttonLabel = $variant === 'light_woocommerce_boutique' ? 'До кошика' : ($variant === 'compact' ? 'Додати в кошик' : 'Купити');
    $imageRatioClass = match ($componentConfig['cardImageRatio'] ?? '4/3') {
        'square' => 'aspect-square',
        '16/9' => 'aspect-video',
        'contain' => 'aspect-[4/3]',
        default => 'aspect-[4/3]',
    };
    $imageObjectClass = ($componentConfig['cardImageRatio'] ?? null) === 'contain' ? 'object-contain p-4' : 'object-cover';
@endphp

<article class="product-card product-card--{{ $variant }} group flex h-full flex-col">
    <a href="{{ route('product.show', $product) }}" class="block">
        <div class="storefront-card-media relative {{ $imageRatioClass }} overflow-hidden bg-neutral-900">
            <img src="{{ $product->image_url }}" alt="{{ $product->image_alt_text ?: $product->name }}" class="h-full w-full {{ $imageObjectClass }} transition duration-500 group-hover:scale-105" loading="lazy" onerror="this.onerror=null;this.src='{{ $productPlaceholder }}';">
            @if ($showBadges)
            <div class="absolute left-3 top-3 flex flex-wrap gap-2">
                @unless ($isAvailable)
                    <span class="badge bg-zinc-800 text-zinc-200">Немає</span>
                @endunless
                @if ($product->is_sale)
                    <span class="badge bg-rose-500 text-white">Акція</span>
                @endif
                @if ($product->is_hit)
                    <span class="badge bg-amber-300 text-neutral-950">Хіт</span>
                @endif
                @if ($product->is_new)
                    <span class="badge bg-cyan-300 text-neutral-950">Новинка</span>
                @endif
            </div>
            @endif
            @if ($priceView['discount_percent'])
                <span class="absolute bottom-3 right-3 rounded bg-neutral-950 px-3 py-1 text-sm font-black text-lime-300">-{{ $priceView['discount_percent'] }}%</span>
            @endif
        </div>
    </a>
    <div class="flex flex-1 flex-col gap-4 p-4">
        <div>
            @if ($showBrand || $showSku)
                <div class="flex items-center justify-between gap-3 text-xs font-bold uppercase text-zinc-500">
                    @if ($showBrand)
                        <span>{{ $product->brand?->name ?? 'Alta' }}</span>
                    @endif
                    @if ($showSku)
                        <span>{{ $product->sku }}</span>
                    @endif
                </div>
            @endif
            <a href="{{ route('product.show', $product) }}" class="mt-2 block text-lg font-black leading-tight text-white hover:text-amber-300">{{ $product->name }}</a>
            <p class="mt-2 line-clamp-2 text-sm leading-6 text-zinc-400">{{ $product->short_description }}</p>
        </div>

        @if ($showShortSpecs && $specifications->isNotEmpty())
            <div class="grid grid-cols-2 gap-2">
                @foreach ($specifications as $specification)
                    <div class="spec-chip">
                        <span class="block truncate text-zinc-500">{{ $specification->name }}</span>
                        <span class="mt-1 block truncate text-white">{{ $specification->value }} {{ $specification->unit }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="mt-auto flex items-center justify-between gap-3">
            <span class="status-pill">
                <span class="status-dot {{ $isAvailable ? 'bg-lime-300' : 'bg-rose-400' }}"></span>
                {{ $availabilityView['label'] }}
            </span>
            @if ($availabilityView['show_quantity'])
                <span class="text-xs font-bold uppercase text-zinc-500">{{ $availabilityView['available_quantity'] }} шт</span>
            @endif
        </div>

        <div class="flex items-end justify-between gap-3">
            <div>
                <div class="text-2xl font-black text-white">{{ $priceView['formatted_price'] }}</div>
                @if ($priceView['formatted_compare_at_price'])
                    <div class="text-sm font-bold text-zinc-500 line-through">{{ $priceView['formatted_compare_at_price'] }}</div>
                @endif
                @if ($priceView['fallback_message'])
                    <div class="mt-1 text-xs font-bold uppercase text-zinc-500">{{ $priceView['currency_code'] }}</div>
                @endif
            </div>
            @if ($showQuickBuy)
            <form method="POST" action="{{ route('cart.add', $product) }}">
                @csrf
                <button class="btn-primary px-4 py-2" @disabled(! $isPurchasable)>{{ $isPurchasable ? $buttonLabel : ($isAvailable ? 'Недоступно' : 'Немає') }}</button>
            </form>
            @endif
        </div>

        <a href="{{ route('product.show', $product) }}" class="btn-secondary w-full py-2">{{ $variant === 'compact' ? 'Швидкий перегляд' : 'Деталі' }}</a>
    </div>
</article>
