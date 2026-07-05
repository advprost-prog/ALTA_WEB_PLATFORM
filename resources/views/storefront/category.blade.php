@extends('layouts.storefront', ['title' => $category->seo_title ?: $category->name, 'description' => $category->seo_description])

@php
    $categoryPlaceholder = asset('images/placeholders/category-placeholder.svg');
@endphp

@section('content')
    <section class="relative border-b border-white/10 bg-neutral-950">
        <img src="{{ $category->image_url }}" alt="{{ $category->name }}" class="absolute inset-0 h-full w-full object-cover opacity-35" onerror="this.onerror=null;this.src='{{ $categoryPlaceholder }}';">
        <div class="absolute inset-0 bg-[linear-gradient(90deg,#101114_0%,rgba(16,17,20,.86)_58%,rgba(16,17,20,.30)_100%)]"></div>
        <div class="section-shell relative py-14">
            <nav class="text-sm font-bold text-zinc-400">
                <a href="{{ route('home') }}" class="hover:text-amber-300">Головна</a>
                <span class="mx-2 text-zinc-600">/</span>
                <a href="{{ route('catalog') }}" class="hover:text-amber-300">Каталог</a>
                @foreach ($category->breadcrumb_path as $parent)
                    <span class="mx-2 text-zinc-600">/</span>
                    <a href="{{ route('category.show', $parent) }}" class="hover:text-amber-300">{{ $parent->name }}</a>
                @endforeach
                <span class="mx-2 text-zinc-600">/</span>
                <span class="text-white">{{ $category->name }}</span>
            </nav>
            <div class="mt-8 max-w-3xl">
                <div class="eyebrow">Категорія</div>
                <h1 class="mt-3 text-4xl font-black text-white sm:text-6xl">{{ $category->name }}</h1>
                <p class="mt-5 max-w-2xl text-lg leading-8 text-zinc-300">{{ $category->description }}</p>
            </div>
        </div>
    </section>

    <section class="section-shell">
        <div class="grid gap-8 lg:grid-cols-[300px_1fr]">
            <aside class="grid gap-5 self-start">
                <div class="filter-panel static lg:sticky">
                    <div class="text-sm font-black uppercase text-zinc-400">Категорії</div>
                    <div class="mt-4 grid gap-2">
                        @foreach ($categories as $item)
                            <a href="{{ route('category.show', $item) }}" class="rounded px-3 py-2 text-sm font-bold transition {{ $item->is($category) ? 'bg-amber-300 text-neutral-950' : 'text-zinc-300 hover:bg-white/10 hover:text-white' }}">{{ $item->name }}</a>
                        @endforeach
                    </div>
                </div>

                @if ($category->children->isNotEmpty())
                    <div class="filter-panel static lg:sticky lg:top-[25rem]">
                        <div class="text-sm font-black uppercase text-zinc-400">Підкатегорії</div>
                        <div class="mt-4 grid gap-2">
                            @foreach ($category->children as $child)
                                <a href="{{ route('category.show', $child) }}" class="rounded px-3 py-2 text-sm font-bold transition text-zinc-300 hover:bg-white/10 hover:text-white">{{ $child->name }}</a>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="filter-panel static lg:sticky lg:top-[25rem]">
                    <form method="GET" action="{{ route('category.show', $category) }}" class="grid gap-5">
                        <div>
                            <label class="label" for="q">Пошук у категорії</label>
                            <input id="q" name="q" value="{{ request('q') }}" class="field mt-2" placeholder="Назва або артикул">
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
                            <a href="{{ route('category.show', $category) }}" class="btn-secondary w-full py-2">Скинути</a>
                        </div>
                    </form>
                </div>
            </aside>

            <div>
                <div class="mb-6 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                    <div>
                        <div class="eyebrow">Товари категорії</div>
                        <h2 class="mt-2 text-3xl font-black text-white">{{ $category->name }}</h2>
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
                            <h3 class="text-2xl font-black text-white">У цій категорії поки порожньо</h3>
                            <p class="mt-3 text-sm leading-6 text-zinc-400">Змініть фільтри або поверніться до повного каталогу.</p>
                            <a href="{{ route('catalog') }}" class="btn-primary mt-5">До каталогу</a>
                        </div>
                    @endforelse
                </div>
                <div class="mt-8">{{ $products->links() }}</div>
            </div>
        </div>

        <div class="mt-12 max-w-4xl border-t border-white/10 pt-8">
            <div class="eyebrow">Підбір Alta-Trade</div>
            <h2 class="mt-3 text-3xl font-black text-white">{{ $category->name }} для регулярних закупівель</h2>
            <p class="mt-4 text-base leading-7 text-zinc-400">
                Категорія містить demo-позиції з цінами, залишками, SEO-полями, фото й характеристиками. Менеджер може швидко оновити асортимент у Filament та прийняти замовлення з публічної частини.
            </p>
        </div>
    </section>
@endsection
