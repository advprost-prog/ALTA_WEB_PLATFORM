@extends('layouts.storefront', ['title' => 'Каталог'])

@php
    $bannerPlaceholder = asset('images/placeholders/banner-placeholder.svg');
@endphp

@section('content')
    @include('storefront.components.banner', [
        'banner' => $catalogBanner,
        'context' => 'catalog',
        'placeholder' => $bannerPlaceholder,
        'fallbackEyebrow' => 'Каталог Alta-Trade',
        'fallbackTitle' => 'Каталог запчастин і автотоварів',
        'fallbackSubtitle' => 'Фільтруйте за категорією, брендом, наявністю, акціями та популярністю.',
    ])

    <section class="section-shell">
        <div class="grid gap-8 lg:grid-cols-[300px_1fr]">
            <aside class="filter-panel">
                <form method="GET" action="{{ route('catalog') }}" class="grid gap-5">
                    <div>
                        <label class="label" for="q">Пошук</label>
                        <input id="q" name="q" value="{{ request('q') }}" class="field mt-2" placeholder="Назва або артикул">
                    </div>
                    <div>
                        <label class="label" for="category">Категорія</label>
                        <select id="category" name="category" class="field mt-2">
                            <option value="">Усі категорії</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->slug }}" @selected(request('category') === $category->slug)>{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label" for="brand">Бренд</label>
                        <select id="brand" name="brand" class="field mt-2">
                            <option value="">Усі бренди</option>
                            @foreach ($brands as $brand)
                                <option value="{{ $brand->slug }}" @selected(request('brand') === $brand->slug)>{{ $brand->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label" for="stock">Наявність</label>
                        <select id="stock" name="stock" class="field mt-2">
                            <option value="">Будь-який статус</option>
                            <option value="available" @selected(request('stock') === 'available')>Є в наявності</option>
                            <option value="preorder" @selected(request('stock') === 'preorder')>Під замовлення</option>
                            <option value="out" @selected(request('stock') === 'out')>Немає в наявності</option>
                        </select>
                    </div>
                    <div>
                        <div class="label">Позначки</div>
                        <div class="mt-3 grid gap-2">
                            @foreach ([['sale', 'Акція'], ['new', 'Новинка'], ['hit', 'Хіт']] as [$name, $label])
                                <label class="flex items-center gap-3 rounded border border-white/10 bg-white/5 px-3 py-2 text-sm font-bold text-zinc-300">
                                    <input type="checkbox" name="{{ $name }}" value="1" @checked(request()->boolean($name)) class="h-4 w-4 accent-amber-300">
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <label class="label" for="sort">Сортування</label>
                        <select id="sort" name="sort" class="field mt-2">
                            <option value="new" @selected(request('sort', 'new') === 'new')>Спочатку нові</option>
                            <option value="popular" @selected(request('sort') === 'popular')>Популярні</option>
                            <option value="cheap" @selected(request('sort') === 'cheap')>Дешевші</option>
                            <option value="expensive" @selected(request('sort') === 'expensive')>Дорожчі</option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <button class="btn-primary w-full">Фільтрувати</button>
                        <a href="{{ route('catalog') }}" class="btn-secondary w-full py-2">Скинути</a>
                    </div>
                </form>
            </aside>

            <div>
                <div class="mb-6 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                    <div>
                        <div class="eyebrow">Товари</div>
                        <h2 class="mt-2 text-3xl font-black text-white">Запчастини, автохімія та аксесуари</h2>
                    </div>
                    <div class="status-pill self-start md:self-auto">
                        <span class="status-dot bg-cyan-300"></span>
                        {{ $products->total() }} позицій
                    </div>
                </div>

                <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                    @forelse ($products as $product)
                        @include('storefront.components.product-card', ['product' => $product])
                    @empty
                        <div class="at-card p-8 text-zinc-300 sm:col-span-2 xl:col-span-3">
                            <h3 class="text-2xl font-black text-white">Нічого не знайдено</h3>
                            <p class="mt-3 text-sm leading-6 text-zinc-400">Спробуйте прибрати частину фільтрів або пошукати за артикулом.</p>
                            <a href="{{ route('catalog') }}" class="btn-primary mt-5">Скинути фільтри</a>
                        </div>
                    @endforelse
                </div>
                <div class="mt-8">{{ $products->links() }}</div>
            </div>
        </div>
    </section>
@endsection
