@extends('layouts.storefront', ['title' => 'Кошик'])

@php
    $productPlaceholder = asset('images/placeholders/product-placeholder.svg');
@endphp

@section('content')
    <section class="section-shell">
        <nav class="text-sm font-bold text-zinc-400">
            <a href="{{ route('home') }}" class="hover:text-amber-300">Головна</a>
            <span class="mx-2 text-zinc-600">/</span>
            <span class="text-white">Кошик</span>
        </nav>

        <div class="mt-8 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div>
                <div class="eyebrow">Кошик</div>
                <h1 class="mt-3 text-4xl font-black text-white sm:text-5xl">Ваше замовлення</h1>
            </div>
            <a href="{{ route('catalog') }}" class="btn-secondary self-start md:self-auto">Продовжити покупки</a>
        </div>

        @if ($items->isEmpty())
            <div class="at-card mt-8 grid gap-6 p-8 md:grid-cols-[1fr_auto] md:items-center">
                <div>
                    <h2 class="text-2xl font-black text-white">Кошик порожній</h2>
                    <p class="mt-3 text-sm leading-6 text-zinc-400">Додайте товар із каталогу, щоб перейти до оформлення замовлення.</p>
                </div>
                <a href="{{ route('catalog') }}" class="btn-primary">До каталогу</a>
            </div>
        @else
            <form method="POST" action="{{ route('cart.update') }}" class="mt-8 grid gap-6 lg:grid-cols-[1fr_360px]">
                @csrf
                @method('PATCH')
                <div class="overflow-hidden rounded-md border border-white/10 bg-zinc-950">
                    @foreach ($items as $item)
                        <div class="grid gap-4 border-b border-white/10 p-4 last:border-b-0 md:grid-cols-[96px_1fr_140px_132px_140px] md:items-center">
                            <img src="{{ $item['product']->image_url }}" alt="{{ $item['product']->image_alt_text ?: $item['product']->name }}" class="h-24 w-24 rounded object-cover" loading="lazy" onerror="this.onerror=null;this.src='{{ $productPlaceholder }}';">
                            <div>
                                <a href="{{ route('product.show', $item['product']) }}" class="text-lg font-black text-white hover:text-amber-300">{{ $item['product']->name }}</a>
                                <div class="mt-2 flex flex-wrap gap-2 text-xs font-bold uppercase text-zinc-500">
                                    <span>{{ $item['product']->sku }}</span>
                                    <span>{{ $item['product']->brand?->name }}</span>
                                </div>
                            </div>
                            <div>
                                <div class="text-xs font-black uppercase text-zinc-500">Ціна</div>
                                <div class="mt-1 font-black text-white">{{ number_format((float) $item['product']->price, 0, '.', ' ') }} ₴</div>
                            </div>
                            <div>
                                <label class="sr-only" for="quantity-{{ $item['product']->id }}">Кількість</label>
                                <input id="quantity-{{ $item['product']->id }}" class="field" type="number" min="0" max="{{ $item['product']->stock }}" name="quantities[{{ $item['product']->id }}]" value="{{ $item['quantity'] }}">
                                <div class="mt-1 text-xs font-bold text-zinc-500">0 = прибрати</div>
                            </div>
                            <div>
                                <div class="text-xs font-black uppercase text-zinc-500">Разом</div>
                                <div class="mt-1 text-xl font-black text-amber-300">{{ number_format($item['line_total'], 0, '.', ' ') }} ₴</div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <aside class="at-card self-start p-5">
                    <h2 class="text-xl font-black text-white">Підсумок</h2>
                    <div class="mt-5 grid gap-3 border-b border-white/10 pb-5 text-sm font-bold">
                        <div class="flex items-center justify-between text-zinc-400">
                            <span>Товарів</span>
                            <span class="text-white">{{ $items->sum('quantity') }}</span>
                        </div>
                        <div class="flex items-center justify-between text-zinc-400">
                            <span>Сума</span>
                            <span class="text-white">{{ number_format($subtotal, 0, '.', ' ') }} ₴</span>
                        </div>
                        <div class="flex items-center justify-between text-zinc-400">
                            <span>Доставка</span>
                            <span class="text-cyan-300">за тарифом</span>
                        </div>
                    </div>
                    <div class="mt-5 flex items-end justify-between gap-4">
                        <span class="font-bold text-zinc-400">До сплати</span>
                        <span class="text-4xl font-black text-white">{{ number_format($total, 0, '.', ' ') }} ₴</span>
                    </div>
                    <div class="mt-6 grid gap-3">
                        <button class="btn-secondary w-full">Оновити кошик</button>
                        <a href="{{ route('checkout') }}" class="btn-primary w-full">Оформити</a>
                    </div>
                </aside>
            </form>
        @endif
    </section>
@endsection
