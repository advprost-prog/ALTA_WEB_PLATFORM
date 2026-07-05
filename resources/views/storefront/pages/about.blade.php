@extends('layouts.storefront', ['title' => 'Про нас'])

@php
    $bannerPlaceholder = asset('images/placeholders/banner-placeholder.svg');
@endphp

@section('content')
    <section class="relative overflow-hidden border-b border-white/10 bg-neutral-950">
        <img src="{{ $bannerPlaceholder }}" alt="Alta-Trade workshop" class="absolute inset-0 h-full w-full object-cover opacity-35" onerror="this.onerror=null;this.src='{{ $bannerPlaceholder }}';">
        <div class="section-shell relative py-20">
            <div class="eyebrow">Про Alta-Trade</div>
            <h1 class="mt-3 max-w-4xl text-4xl font-black text-white sm:text-6xl">Платформа для швидкої комерції в автотоварах</h1>
            <p class="mt-6 max-w-3xl text-lg leading-8 text-zinc-300">Alta-Trade Commerce Engine поєднує менеджерську адмінку, керований каталог, промо-блоки й checkout, щоб магазин міг стартувати швидко та розвиватися без зміни базової архітектури.</p>
        </div>
    </section>

    <section class="section-shell">
        <div class="grid gap-5 md:grid-cols-3">
            <div class="at-card p-6">
                <div class="text-xl font-black text-amber-300">Каталог</div>
                <p class="mt-3 leading-7 text-zinc-400">Категорії, бренди, SEO-поля, фото, характеристики й бейджі для акційних товарів.</p>
            </div>
            <div class="at-card p-6">
                <div class="text-xl font-black text-cyan-300">Продажі</div>
                <p class="mt-3 leading-7 text-zinc-400">Кошик, оформлення замовлення, клієнти й статуси для менеджерської обробки.</p>
            </div>
            <div class="at-card p-6">
                <div class="text-xl font-black text-lime-300">Адмінка</div>
                <p class="mt-3 leading-7 text-zinc-400">Filament 4 resources для щоденної роботи контент-менеджера, менеджера й адміністратора.</p>
            </div>
        </div>
    </section>
@endsection
