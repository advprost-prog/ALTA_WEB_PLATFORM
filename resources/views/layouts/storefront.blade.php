@php
    $cartCount = collect(session('cart', []))->sum();
    $pageTitle = trim(($title ?? '') . (($title ?? null) ? ' | ' : '') . 'Alta-Trade');
    $layoutConfig = $themeLayoutConfig ?? [];
    $componentConfig = $themeComponentConfig ?? [];
    $styleProfile = $themeStyleProfile ?? [];
    $headerVariant = $layoutConfig['headerVariant'] ?? 'automotive';
    $footerVariant = $layoutConfig['footerVariant'] ?? 'dark';
    $density = $layoutConfig['density'] ?? 'normal';
    $visualMode = $styleProfile['visual_mode'] ?? 'dark';
    $homepageStructure = $styleProfile['homepage_structure'] ?? 'hero_focused';
    $cardStyle = $styleProfile['card_style'] ?? 'detailed_grid';
    $selectedPreset = ($storefrontTheme ?? null)?->selected_preset ?? 'system';
    $showTopBar = ($componentConfig['showTopBar'] ?? true) && (($layoutConfig['topBarVariant'] ?? 'contact') !== 'none');
    $showSearch = $componentConfig['showSearch'] ?? true;
    $showCategoryMenu = $componentConfig['showCategoryMenu'] ?? true;
    $stickyHeader = $componentConfig['stickyHeader'] ?? true;
@endphp

<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $description ?? 'Alta-Trade Commerce Engine - автомагазин деталей, аксесуарів і сервісних рішень.' }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        :root {
        {!! $themeCssVariables ?? '' !!}
        }
        {!! ($storefrontTheme ?? null)?->custom_css !!}
    </style>
</head>
<body class="storefront-body antialiased" data-theme="{{ ($storefrontTheme ?? null)?->slug ?? 'runtime-fallback' }}" data-theme-density="{{ $density }}" data-theme-visual-mode="{{ $visualMode }}" data-theme-homepage="{{ $homepageStructure }}" data-theme-card-style="{{ $cardStyle }}" data-theme-preset="{{ $selectedPreset }}">
    <div class="min-h-screen">
        <header class="storefront-header storefront-header--{{ $headerVariant }} {{ $stickyHeader ? 'sticky top-0' : 'relative' }} z-40" x-data="{ mobileOpen: false, catalogOpen: false }">
            @if ($showTopBar)
            <div class="storefront-topbar hidden text-xs font-bold md:block">
                <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-2 sm:px-6 lg:px-8">
                    <div class="flex items-center gap-5">
                        <span class="text-amber-300">Пн-Пт 09:00-18:00</span>
                        <a class="hover:text-white" href="tel:+380671112233">+38 067 111 22 33</a>
                        <a class="hover:text-white" href="mailto:sales@alta-trade.test">sales@alta-trade.test</a>
                    </div>
                    <div class="flex items-center gap-5 uppercase">
                        <span class="text-lime-300">Швидке оформлення</span>
                        <span>Підбір для СТО</span>
                        <span>Доставка по Україні</span>
                    </div>
                </div>
            </div>
            @endif

            <div class="mx-auto grid max-w-7xl grid-cols-[auto_1fr_auto] items-center gap-3 px-4 py-3 sm:px-6 lg:grid-cols-[auto_auto_1fr_auto] lg:px-8">
                <a href="{{ route('home') }}" class="flex min-w-0 items-center gap-3">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded bg-amber-300 font-black text-neutral-950 shadow-[0_0_32px_rgb(255_183_3_/_0.28)]">AT</span>
                    <span class="min-w-0">
                        <span class="block truncate text-base font-black uppercase text-white">Alta-Trade</span>
                        <span class="block truncate text-xs font-black uppercase text-cyan-300">Commerce Engine</span>
                    </span>
                </a>

                @if ($showCategoryMenu)
                <div class="relative hidden lg:block">
                    <button type="button" class="btn-primary gap-2" @click="catalogOpen = ! catalogOpen" @click.outside="catalogOpen = false">
                        <span class="text-lg leading-none">≡</span>
                        Каталог
                    </button>
                    <div x-cloak x-show="catalogOpen" x-transition class="absolute left-0 top-full mt-3 w-80 overflow-hidden rounded-md border border-white/10 bg-zinc-950 shadow-2xl shadow-black/40">
                        <div class="border-b border-white/10 px-4 py-3 text-xs font-black uppercase text-zinc-500">Популярні категорії</div>
                        <div class="grid p-2">
                            @forelse (($navigationCategories ?? collect()) as $category)
                                <a href="{{ route('category.show', $category) }}" class="rounded px-3 py-2 text-sm font-bold text-zinc-200 hover:bg-white/10 hover:text-amber-200">{{ $category->name }}</a>
                            @empty
                                <a href="{{ route('catalog') }}" class="rounded px-3 py-2 text-sm font-bold text-zinc-200 hover:bg-white/10 hover:text-amber-200">Усі товари</a>
                            @endforelse
                        </div>
                    </div>
                </div>
                @endif

                @if ($showSearch)
                <form method="GET" action="{{ route('catalog') }}" class="hidden min-w-0 lg:flex">
                    <label for="site-search" class="sr-only">Пошук товару</label>
                    <div class="flex w-full overflow-hidden rounded border border-white/10 bg-neutral-900 focus-within:border-amber-300">
                        <input id="site-search" name="q" value="{{ request('q') }}" class="min-w-0 flex-1 bg-transparent px-4 py-3 text-sm font-semibold text-white outline-none placeholder:text-zinc-600" placeholder="Пошук за назвою або артикулом">
                        <button class="bg-white/10 px-5 text-sm font-black uppercase text-amber-200 transition hover:bg-amber-300 hover:text-neutral-950">Знайти</button>
                    </div>
                </form>
                @endif

                <div class="flex items-center justify-end gap-2">
                    <nav class="hidden items-center gap-5 xl:flex">
                        <a class="nav-link" href="{{ route('delivery-payment') }}">Доставка</a>
                        <a class="nav-link" href="{{ route('about') }}">Про нас</a>
                        <a class="nav-link" href="{{ route('contacts') }}">Контакти</a>
                    </nav>
                    <a href="{{ route('cart') }}" class="inline-flex items-center gap-2 rounded bg-white px-3 py-2 text-sm font-black text-neutral-950 transition hover:bg-amber-300 sm:px-4">
                        <span class="hidden sm:inline">Кошик</span>
                        <span class="grid h-6 min-w-6 place-items-center rounded bg-neutral-950 px-2 text-xs text-white">{{ $cartCount }}</span>
                    </a>
                    <a href="/admin" class="hidden rounded border border-white/15 px-4 py-2 text-sm font-black text-white transition hover:border-cyan-300 hover:text-cyan-200 md:inline-flex">Admin</a>
                    <button type="button" class="rounded border border-white/15 px-3 py-2 text-lg font-black text-white lg:hidden" @click="mobileOpen = ! mobileOpen" aria-label="Меню">≡</button>
                </div>
            </div>

            <div x-cloak x-show="mobileOpen" x-transition class="border-t border-white/10 bg-zinc-950 lg:hidden">
                <div class="mx-auto grid max-w-7xl gap-4 px-4 py-4 sm:px-6">
                    @if ($showSearch)
                    <form method="GET" action="{{ route('catalog') }}" class="flex overflow-hidden rounded border border-white/10 bg-neutral-900">
                        <input name="q" value="{{ request('q') }}" class="min-w-0 flex-1 bg-transparent px-4 py-3 text-sm font-semibold text-white outline-none placeholder:text-zinc-600" placeholder="Пошук товару">
                        <button class="bg-amber-300 px-4 text-sm font-black uppercase text-neutral-950">OK</button>
                    </form>
                    @endif
                    @if ($showCategoryMenu)
                    <div class="grid gap-2">
                        <a class="btn-primary" href="{{ route('catalog') }}">Каталог</a>
                        @foreach (($navigationCategories ?? collect())->take(5) as $category)
                            <a href="{{ route('category.show', $category) }}" class="rounded border border-white/10 px-4 py-3 text-sm font-bold text-zinc-200">{{ $category->name }}</a>
                        @endforeach
                    </div>
                    @endif
                    <nav class="grid gap-2 text-sm font-black uppercase text-zinc-300">
                        <a class="rounded px-2 py-2 hover:text-amber-300" href="{{ route('delivery-payment') }}">Доставка і оплата</a>
                        <a class="rounded px-2 py-2 hover:text-amber-300" href="{{ route('about') }}">Про нас</a>
                        <a class="rounded px-2 py-2 hover:text-amber-300" href="{{ route('contacts') }}">Контакти</a>
                    </nav>
                </div>
            </div>
        </header>

        @if ($isThemePreview ?? false)
            <div class="storefront-preview-banner">
                <div class="mx-auto max-w-7xl px-4 py-2 text-sm font-black sm:px-6 lg:px-8">
                    Preview theme: {{ $storefrontTheme->name }}
                </div>
            </div>
        @endif

        @if (session('status'))
            <div class="border-b border-emerald-400/20 bg-emerald-400/10">
                <div class="mx-auto max-w-7xl px-4 py-3 text-sm font-semibold text-emerald-100 sm:px-6 lg:px-8">
                    {{ session('status') }}
                </div>
            </div>
        @endif

        <main>
            @yield('content')
        </main>

        <footer class="storefront-footer storefront-footer--{{ $footerVariant }}">
            <div class="mx-auto grid max-w-7xl gap-8 px-4 py-12 sm:px-6 md:grid-cols-4 lg:px-8">
                <div class="md:col-span-2">
                    <div class="flex items-center gap-3">
                        <span class="grid h-10 w-10 place-items-center rounded bg-amber-300 font-black text-neutral-950">AT</span>
                        <div>
                            <div class="text-lg font-black uppercase text-white">Alta-Trade</div>
                            <div class="text-xs font-black uppercase text-cyan-300">Commerce Engine</div>
                        </div>
                    </div>
                    <p class="mt-3 max-w-xl text-sm leading-6 text-zinc-400">
                        Демонстраційна e-commerce платформа для автомагазину: каталог, кошик, замовлення й Filament-адмінка для менеджерів. Стартова основа для подальшого імпорту товарів, CRM-логіки та маркетингових сценаріїв.
                    </p>
                    <div class="mt-5 flex flex-wrap gap-2 text-xs font-black uppercase">
                        <span class="rounded bg-amber-300 px-3 py-1 text-neutral-950">Автозапчастини</span>
                        <span class="rounded bg-cyan-300 px-3 py-1 text-neutral-950">СТО</span>
                        <span class="rounded bg-lime-300 px-3 py-1 text-neutral-950">B2C/B2B</span>
                    </div>
                </div>
                <div>
                    <div class="text-sm font-black uppercase text-amber-300">Покупцям</div>
                    <div class="mt-3 grid gap-2 text-sm text-zinc-400">
                        <a class="hover:text-white" href="{{ route('catalog') }}">Каталог</a>
                        <a class="hover:text-white" href="{{ route('delivery-payment') }}">Доставка і оплата</a>
                        <a class="hover:text-white" href="{{ route('contacts') }}">Контакти</a>
                    </div>
                </div>
                <div>
                    <div class="text-sm font-black uppercase text-cyan-300">Звʼязок</div>
                    <div class="mt-3 grid gap-2 text-sm text-zinc-400">
                        <a class="hover:text-white" href="tel:+380671112233">+38 067 111 22 33</a>
                        <a class="hover:text-white" href="mailto:sales@alta-trade.test">sales@alta-trade.test</a>
                        <span>Пн-Пт 09:00-18:00</span>
                    </div>
                </div>
            </div>
            <div class="border-t border-white/10">
                <div class="mx-auto flex max-w-7xl flex-col gap-3 px-4 py-4 text-xs font-bold text-zinc-500 sm:px-6 md:flex-row md:items-center md:justify-between lg:px-8">
                    <span>© {{ now()->year }} Alta-Trade Commerce Engine</span>
                    <span>Demo MVP для комерційного автомагазину</span>
                </div>
            </div>
        </footer>
    </div>
</body>
</html>
