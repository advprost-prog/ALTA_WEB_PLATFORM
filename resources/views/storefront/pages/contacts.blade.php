@extends('layouts.storefront', ['title' => 'Контакти'])

@section('content')
    <section class="section-shell">
        <div class="eyebrow">Контакти</div>
        <h1 class="mt-3 text-4xl font-black text-white sm:text-6xl">Підбір деталей і консультація</h1>
        <div class="mt-10 grid gap-6 lg:grid-cols-[1fr_420px]">
            <div class="at-card p-6">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <div class="text-sm font-black uppercase text-zinc-500">Телефон</div>
                        <a href="tel:+380671112233" class="mt-2 block text-2xl font-black text-white">+38 067 111 22 33</a>
                    </div>
                    <div>
                        <div class="text-sm font-black uppercase text-zinc-500">Email</div>
                        <a href="mailto:sales@alta-trade.test" class="mt-2 block text-2xl font-black text-white">sales@alta-trade.test</a>
                    </div>
                    <div>
                        <div class="text-sm font-black uppercase text-zinc-500">Графік</div>
                        <div class="mt-2 text-xl font-black text-white">Пн-Пт 09:00-18:00</div>
                    </div>
                    <div>
                        <div class="text-sm font-black uppercase text-zinc-500">Адреса</div>
                        <div class="mt-2 text-xl font-black text-white">Київ, склад-магазин Alta-Trade</div>
                    </div>
                </div>
            </div>
            <div class="at-panel p-6">
                <div class="text-xl font-black text-amber-300">Швидкий запит</div>
                <p class="mt-3 leading-7 text-zinc-400">Надішліть артикул, VIN або опис задачі на пошту. Менеджер підготує варіанти й повернеться з ціною.</p>
                <a href="{{ route('catalog') }}" class="btn-primary mt-6">Переглянути каталог</a>
            </div>
        </div>
    </section>
@endsection
