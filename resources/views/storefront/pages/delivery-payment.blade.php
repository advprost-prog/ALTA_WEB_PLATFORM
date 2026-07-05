@extends('layouts.storefront', ['title' => 'Доставка і оплата'])

@section('content')
    <section class="section-shell">
        <div class="eyebrow">Сервіс</div>
        <h1 class="mt-3 max-w-3xl text-4xl font-black text-white sm:text-6xl">Доставка і оплата без зайвих зупинок</h1>
        <div class="mt-10 grid gap-5 md:grid-cols-3">
            <div class="at-card p-6">
                <div class="text-xl font-black text-amber-300">Нова пошта</div>
                <p class="mt-3 leading-7 text-zinc-400">Відправка по Україні після підтвердження менеджером. Для габаритних деталей умови уточнюються окремо.</p>
            </div>
            <div class="at-card p-6">
                <div class="text-xl font-black text-cyan-300">Самовивіз</div>
                <p class="mt-3 leading-7 text-zinc-400">Резерв товару після дзвінка. Менеджер повідомить, коли позиція готова до видачі.</p>
            </div>
            <div class="at-card p-6">
                <div class="text-xl font-black text-lime-300">Оплата</div>
                <p class="mt-3 leading-7 text-zinc-400">Післяплата, оплата карткою або безготівковий рахунок для компаній та СТО.</p>
            </div>
        </div>
    </section>
@endsection
