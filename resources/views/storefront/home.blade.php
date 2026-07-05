@extends('layouts.storefront', ['title' => 'Автомагазин'])

@php
    $productPlaceholder = asset('images/placeholders/product-placeholder.svg');
    $categoryPlaceholder = asset('images/placeholders/category-placeholder.svg');
    $bannerPlaceholder = asset('images/placeholders/banner-placeholder.svg');
    $pricingService = app(\App\Services\Commerce\ProductPricingService::class);
    $heroVariant = ($themeLayoutConfig ?? [])['heroVariant'] ?? 'dark_promo';
    $categoryGridVariant = ($themeLayoutConfig ?? [])['categoryGridVariant'] ?? 'cards';
    $heroOverlay = ($themeComponentConfig ?? [])['heroOverlay'] ?? true;
    $heroIsCompact = in_array($heroVariant, ['none', 'low_dominance_section_header'], true);
@endphp

@section('content')
    <section class="storefront-hero storefront-hero--{{ $heroVariant }} relative isolate overflow-hidden border-b border-white/10 {{ $heroIsCompact ? 'bg-white/5' : 'bg-neutral-950' }}">
        <div class="absolute inset-0 -z-10">
            <img src="{{ $heroBanner?->image_url ?? $bannerPlaceholder }}" alt="Alta-Trade" class="h-full w-full object-cover opacity-45" onerror="this.onerror=null;this.src='{{ $bannerPlaceholder }}';">
            @if ($heroOverlay && ! $heroIsCompact)
            <div class="storefront-hero__overlay absolute inset-0"></div>
            @endif
        </div>
        <div class="section-shell grid min-h-[520px] items-center gap-10 py-12 lg:grid-cols-[1fr_380px]">
            <div class="max-w-4xl">
                <div class="eyebrow">Автотовари, запчастини, сервісні комплекти</div>
                <h1 class="mt-5 max-w-4xl text-5xl font-black leading-none text-white sm:text-7xl">
                    {{ $heroBanner?->title ?? 'Alta-Trade Commerce Engine' }}
                </h1>
                <p class="mt-6 max-w-2xl text-lg leading-8 text-zinc-300">
                    {{ $heroBanner?->subtitle ?? 'Графітовий автомагазин з швидким каталогом, кошиком і менеджерською адмінкою для продажів.' }}
                </p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <a href="{{ $heroBanner?->button_url ?: route('catalog') }}" class="btn-primary">{{ $heroBanner?->button_text ?: 'До каталогу' }}</a>
                    <a href="{{ route('contacts') }}" class="btn-secondary">Швидка консультація</a>
                </div>
                <div class="mt-8 grid max-w-2xl grid-cols-3 gap-3">
                    <div class="at-panel p-4">
                        <div class="text-2xl font-black text-amber-300">{{ $categories->count() }}+</div>
                        <div class="mt-1 text-xs font-black uppercase text-zinc-500">категорій</div>
                    </div>
                    <div class="at-panel p-4">
                        <div class="text-2xl font-black text-cyan-300">{{ $featuredProducts->count() }}+</div>
                        <div class="mt-1 text-xs font-black uppercase text-zinc-500">вітрина</div>
                    </div>
                    <div class="at-panel p-4">
                        <div class="text-2xl font-black text-lime-300">{{ $promotions->count() }}</div>
                        <div class="mt-1 text-xs font-black uppercase text-zinc-500">акції</div>
                    </div>
                </div>
            </div>

            <div class="hidden lg:grid gap-4">
                @foreach ($promotions as $promotion)
                    <a href="{{ route('catalog', ['sale' => 1]) }}" class="storefront-hero-promo-card at-card p-5 transition hover:border-amber-300/60">
                        <span class="badge bg-amber-300 text-neutral-950">{{ $promotion->badge_label ?: 'Акція' }}</span>
                        <h2 class="mt-4 text-2xl font-black text-white">{{ $promotion->title }}</h2>
                        <p class="mt-2 text-sm leading-6 text-zinc-400">{{ $promotion->description }}</p>
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    <section class="section-shell">
        <div class="flex items-end justify-between gap-6">
            <div>
                <div class="eyebrow">Категорії</div>
                <h2 class="mt-3 text-3xl font-black text-white sm:text-4xl">Швидкий вхід у каталог</h2>
            </div>
            <a href="{{ route('catalog') }}" class="btn-secondary hidden py-2 sm:inline-flex">Всі товари</a>
        </div>
        <div class="category-grid category-grid--{{ $categoryGridVariant }} mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($categories as $category)
                <a href="{{ route('category.show', $category) }}" class="group overflow-hidden rounded-md border border-white/10 bg-zinc-950 transition hover:-translate-y-1 hover:border-cyan-300/60">
                    <div class="aspect-[16/10] overflow-hidden bg-neutral-900">
                        <img src="{{ $category->image_url }}" alt="{{ $category->name }}" class="h-full w-full object-cover transition duration-500 group-hover:scale-105" loading="lazy" onerror="this.onerror=null;this.src='{{ $categoryPlaceholder }}';">
                    </div>
                    <div class="flex items-center justify-between gap-4 p-4">
                        <div>
                            <div class="text-lg font-black text-white">{{ $category->name }}</div>
                            <div class="mt-1 text-sm font-semibold text-zinc-500">{{ $category->products_count }} товарів</div>
                        </div>
                        <span class="text-2xl font-black text-amber-300">›</span>
                    </div>
                </a>
            @endforeach
        </div>
    </section>

    @if ($promoBanners->isNotEmpty())
        <section class="border-y border-white/10 bg-zinc-950">
            <div class="section-shell grid gap-4 md:grid-cols-2">
                @foreach ($promoBanners as $banner)
                    <a href="{{ $banner->button_url ?: route('catalog') }}" class="storefront-banner-card group relative min-h-72 overflow-hidden rounded-md border border-white/10 p-6 transition hover:border-amber-300/60">
                        <img src="{{ $banner->image_url }}" alt="{{ $banner->title }}" class="absolute inset-0 h-full w-full object-cover opacity-45 transition duration-500 group-hover:scale-105" loading="lazy" onerror="this.onerror=null;this.src='{{ $bannerPlaceholder }}';">
                        <div class="absolute inset-0 bg-[linear-gradient(90deg,#111315_0%,rgba(17,19,21,.72)_60%,rgba(17,19,21,.25)_100%)]"></div>
                        <div class="relative max-w-md">
                            <span class="badge bg-white text-neutral-950">Промо</span>
                            <h3 class="mt-5 text-3xl font-black text-white">{{ $banner->title }}</h3>
                            <p class="mt-3 text-sm leading-6 text-zinc-300">{{ $banner->subtitle }}</p>
                            <span class="btn-primary mt-6 py-2">{{ $banner->button_text ?: 'Детальніше' }}</span>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if ($saleProducts->isNotEmpty())
        <section class="section-shell">
            <div class="flex items-end justify-between gap-6">
                <div>
                    <div class="eyebrow">Гарячі пропозиції</div>
                    <h2 class="mt-3 text-3xl font-black text-white sm:text-4xl">Акційні позиції</h2>
                </div>
                <a href="{{ route('catalog', ['sale' => 1]) }}" class="hidden text-sm font-black uppercase text-amber-300 hover:text-cyan-300 sm:block">Більше акцій</a>
            </div>
            <div class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($saleProducts as $product)
                    @include('storefront.components.product-card', ['product' => $product])
                @endforeach
            </div>
        </section>
    @endif

    <section class="border-y border-white/10 bg-neutral-950">
        <div class="section-shell grid gap-10 lg:grid-cols-[1fr_1fr]">
            <div>
                <div class="eyebrow">Новинки</div>
                <h2 class="mt-3 text-3xl font-black text-white">Свіже надходження</h2>
                <div class="mt-6 grid gap-4">
                    @foreach ($newProducts->take(3) as $product)
                        <a href="{{ route('product.show', $product) }}" class="at-card grid grid-cols-[88px_1fr] gap-4 p-3 transition hover:border-cyan-300/60">
                            <img src="{{ $product->image_url }}" alt="{{ $product->image_alt_text ?: $product->name }}" class="aspect-square rounded object-cover" loading="lazy" onerror="this.onerror=null;this.src='{{ $productPlaceholder }}';">
                            <span>
                                <span class="block text-sm font-black text-white">{{ $product->name }}</span>
                                <span class="mt-2 block text-xl font-black text-amber-300">{{ $pricingService->priceView($product)['formatted_price'] }}</span>
                                <span class="mt-1 block text-xs font-bold uppercase text-zinc-500">{{ $product->brand?->name }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>
            <div>
                <div class="eyebrow">Хіти продажів</div>
                <h2 class="mt-3 text-3xl font-black text-white">Часто беруть для сервісу</h2>
                <div class="mt-6 grid gap-4">
                    @foreach ($hitProducts->take(3) as $product)
                        <a href="{{ route('product.show', $product) }}" class="at-card grid grid-cols-[88px_1fr] gap-4 p-3 transition hover:border-amber-300/60">
                            <img src="{{ $product->image_url }}" alt="{{ $product->image_alt_text ?: $product->name }}" class="aspect-square rounded object-cover" loading="lazy" onerror="this.onerror=null;this.src='{{ $productPlaceholder }}';">
                            <span>
                                <span class="block text-sm font-black text-white">{{ $product->name }}</span>
                                <span class="mt-2 block text-xl font-black text-lime-300">{{ $pricingService->priceView($product)['formatted_price'] }}</span>
                                <span class="mt-1 block text-xs font-bold uppercase text-zinc-500">{{ $product->sku }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <section class="section-shell">
        <div class="flex items-end justify-between gap-6">
            <div>
                <div class="eyebrow">Бренди</div>
                <h2 class="mt-3 text-3xl font-black text-white sm:text-4xl">Вітрина перевірених виробників</h2>
            </div>
            <a href="{{ route('catalog') }}" class="hidden text-sm font-black uppercase text-amber-300 hover:text-cyan-300 sm:block">Каталог брендів</a>
        </div>
        <div class="mt-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($brands as $brand)
                <a href="{{ route('catalog', ['brand' => $brand->slug]) }}" class="at-card flex items-center justify-between gap-4 p-5 transition hover:border-cyan-300/60">
                    <span>
                        <span class="block text-xl font-black text-white">{{ $brand->name }}</span>
                        <span class="mt-1 block text-sm font-bold text-zinc-500">{{ $brand->products_count }} товарів</span>
                    </span>
                    <span class="text-2xl font-black text-amber-300">›</span>
                </a>
            @endforeach
        </div>
    </section>

    <section class="border-y border-white/10 bg-zinc-950">
        <div class="section-shell grid gap-8 lg:grid-cols-[0.9fr_1.1fr]">
            <div>
                <div class="eyebrow">Як замовити</div>
                <h2 class="mt-3 text-3xl font-black text-white sm:text-4xl">Короткий шлях від підбору до відправки</h2>
                <p class="mt-5 text-base leading-7 text-zinc-400">Оберіть товар, додайте до кошика, залиште контакти. Менеджер підтвердить сумісність, наявність і спосіб доставки.</p>
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
                @foreach ([['1', 'Підбір', 'Категорія, бренд, артикул або пошук.'], ['2', 'Кошик', 'Кількість, сума і перевірка наявності.'], ['3', 'Контакт', 'Доставка, оплата і коментар менеджеру.']] as [$number, $title, $text])
                    <div class="at-card p-5">
                        <div class="grid h-10 w-10 place-items-center rounded bg-amber-300 font-black text-neutral-950">{{ $number }}</div>
                        <h3 class="mt-5 text-xl font-black text-white">{{ $title }}</h3>
                        <p class="mt-2 text-sm leading-6 text-zinc-400">{{ $text }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="section-shell">
        <div class="grid gap-4 md:grid-cols-3">
            <div class="at-card p-5">
                <div class="text-sm font-black uppercase text-cyan-300">Менеджерський процес</div>
                <p class="mt-3 text-sm leading-6 text-zinc-400">Замовлення одразу потрапляють у Filament, де менеджер бачить клієнта, товари, суму й статус.</p>
            </div>
            <div class="at-card p-5">
                <div class="text-sm font-black uppercase text-amber-300">Живий каталог</div>
                <p class="mt-3 text-sm leading-6 text-zinc-400">Категорії, бренди, SEO-поля, фото, галерея й характеристики керуються з адмінки.</p>
            </div>
            <div class="at-card p-5">
                <div class="text-sm font-black uppercase text-lime-300">Готовність до росту</div>
                <p class="mt-3 text-sm leading-6 text-zinc-400">MVP підготовлений до наступних фаз: visual polish, Excel/CSV імпорт і бізнес-автоматизація.</p>
            </div>
        </div>
        <div class="mt-10 max-w-4xl border-t border-white/10 pt-8">
            <div class="eyebrow">Alta-Trade</div>
            <h2 class="mt-3 text-3xl font-black text-white">Автомагазин для швидких продажів</h2>
            <p class="mt-4 text-base leading-7 text-zinc-400">
                Alta-Trade Commerce Engine демонструє базу e-commerce платформи для автотоварів: темний графітовий інтерфейс, акційні бейджі, адаптивний каталог, картки товарів, кошик, checkout і робочу Filament-адмінку для щоденної роботи менеджерів.
            </p>
        </div>
    </section>
@endsection
