@extends('layouts.storefront', ['title' => 'Автомагазин'])

@php
    $productPlaceholder = asset('images/placeholders/product-placeholder.svg');
    $categoryPlaceholder = asset('images/placeholders/category-placeholder.svg');
    $bannerPlaceholder = asset('images/placeholders/banner-placeholder.svg');
    $pricingService = app(\App\Services\Commerce\ProductPricingService::class);
    $categoryGridVariant = ($themeLayoutConfig ?? [])['categoryGridVariant'] ?? 'cards';
@endphp

@section('content')
    @include('storefront.components.banner', [
        'banner' => $heroBanner,
        'context' => 'hero',
        'placeholder' => $bannerPlaceholder,
        'fallbackEyebrow' => 'Автотовари, запчастини, сервісні комплекти',
        'fallbackTitle' => 'Alta-Trade Commerce Engine',
        'fallbackSubtitle' => 'Графітовий автомагазин з швидким каталогом, кошиком і менеджерською адмінкою для продажів.',
        'fallbackButtonText' => 'До каталогу',
        'fallbackButtonUrl' => route('catalog'),
        'fallbackSecondaryButtonText' => 'Швидка консультація',
        'fallbackSecondaryButtonUrl' => route('contacts'),
    ])

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
                    @include('storefront.components.banner', [
                        'banner' => $banner,
                        'context' => 'promo',
                        'contained' => false,
                        'placeholder' => $bannerPlaceholder,
                        'fallbackEyebrow' => 'Промо',
                        'class' => 'h-full',
                    ])
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
