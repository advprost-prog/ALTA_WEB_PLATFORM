@php
    $isPurchasable = $product->isPurchasable();
    $stockLabel = \App\Models\Product::STOCK_STATUSES[$product->stock_status] ?? $product->stock_status;
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
                @unless ($isPurchasable)
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
            @if ($product->discount_percent)
                <span class="absolute bottom-3 right-3 rounded bg-neutral-950 px-3 py-1 text-sm font-black text-lime-300">-{{ $product->discount_percent }}%</span>
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
                <span class="status-dot {{ $isPurchasable ? 'bg-lime-300' : 'bg-rose-400' }}"></span>
                {{ $stockLabel }}
            </span>
            <span class="text-xs font-bold uppercase text-zinc-500">{{ $product->stock }} шт</span>
        </div>

        <div class="flex items-end justify-between gap-3">
            <div>
                <div class="text-2xl font-black text-white">{{ number_format((float) $product->price, 0, '.', ' ') }} ₴</div>
                @if ($product->old_price)
                    <div class="text-sm font-bold text-zinc-500 line-through">{{ number_format((float) $product->old_price, 0, '.', ' ') }} ₴</div>
                @endif
            </div>
            @if ($showQuickBuy)
            <form method="POST" action="{{ route('cart.add', $product) }}">
                @csrf
                <button class="btn-primary px-4 py-2" @disabled(! $isPurchasable)>{{ $isPurchasable ? $buttonLabel : 'Немає' }}</button>
            </form>
            @endif
        </div>

        <a href="{{ route('product.show', $product) }}" class="btn-secondary w-full py-2">{{ $variant === 'compact' ? 'Швидкий перегляд' : 'Деталі' }}</a>
    </div>
</article>
