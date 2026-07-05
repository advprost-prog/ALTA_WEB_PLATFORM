@extends('layouts.storefront', ['title' => 'Дякуємо'])

@section('content')
    <section class="section-shell">
        <div class="grid gap-8 lg:grid-cols-[1fr_380px]">
            <div class="rounded-md border border-emerald-400/20 bg-emerald-400/10 p-8">
                <div class="eyebrow text-emerald-300">Замовлення прийнято</div>
                <h1 class="mt-3 text-4xl font-black text-white sm:text-5xl">Дякуємо, номер {{ $order->number }}</h1>
                <p class="mt-4 max-w-2xl text-lg leading-8 text-zinc-300">Менеджер звʼяжеться з клієнтом для підтвердження наявності, доставки та оплати.</p>
                <div class="mt-8 grid gap-3 sm:grid-cols-3">
                    <div class="at-panel p-4">
                        <div class="text-2xl font-black text-emerald-300">1</div>
                        <div class="mt-2 text-sm font-bold text-zinc-300">Перевірка товарів</div>
                    </div>
                    <div class="at-panel p-4">
                        <div class="text-2xl font-black text-amber-300">2</div>
                        <div class="mt-2 text-sm font-bold text-zinc-300">Підтвердження з менеджером</div>
                    </div>
                    <div class="at-panel p-4">
                        <div class="text-2xl font-black text-cyan-300">3</div>
                        <div class="mt-2 text-sm font-bold text-zinc-300">Передача в доставку</div>
                    </div>
                </div>
                <div class="mt-8 flex flex-wrap gap-3">
                    <a href="{{ route('catalog') }}" class="btn-primary">Повернутися в каталог</a>
                    <a href="{{ route('contacts') }}" class="btn-secondary">Контакти магазину</a>
                </div>
            </div>

            <aside class="at-card p-5">
                <h2 class="text-xl font-black text-white">Підсумок</h2>
                <div class="mt-5 grid gap-4">
                    @foreach ($order->items as $item)
                        <div class="border-b border-white/10 pb-4 last:border-b-0">
                            <div class="font-bold leading-5 text-white">{{ $item->product_name }}</div>
                            <div class="mt-1 text-sm text-zinc-500">{{ $item->quantity }} x {{ number_format((float) $item->price, 0, '.', ' ') }} ₴</div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-5 flex items-center justify-between border-t border-white/10 pt-5">
                    <span class="font-bold text-zinc-400">Сума</span>
                    <span class="text-3xl font-black text-white">{{ number_format((float) $order->total_amount, 0, '.', ' ') }} ₴</span>
                </div>
                <div class="mt-5 grid gap-2 text-sm font-bold text-zinc-400">
                    <div>Статус: <span class="text-amber-300">{{ \App\Models\Order::STATUSES[$order->status] ?? $order->status }}</span></div>
                    <div>Доставка: <span class="text-white">{{ $order->delivery_method }}</span></div>
                    <div>Оплата: <span class="text-white">{{ $order->payment_method }}</span></div>
                </div>
            </aside>
        </div>
    </section>
@endsection
