@extends('layouts.storefront', ['title' => 'Оформлення замовлення'])

@php
    $productPlaceholder = asset('images/placeholders/product-placeholder.svg');
@endphp

@section('content')
    <section class="section-shell">
        <nav class="text-sm font-bold text-zinc-400">
            <a href="{{ route('home') }}" class="hover:text-amber-300">Головна</a>
            <span class="mx-2 text-zinc-600">/</span>
            <a href="{{ route('cart') }}" class="hover:text-amber-300">Кошик</a>
            <span class="mx-2 text-zinc-600">/</span>
            <span class="text-white">Оформлення</span>
        </nav>

        <div class="mt-8">
            <div class="eyebrow">Checkout</div>
            <h1 class="mt-3 text-4xl font-black text-white sm:text-5xl">Оформлення замовлення</h1>
            <p class="mt-4 max-w-2xl text-base leading-7 text-zinc-400">Залиште контакти, спосіб доставки й оплату. Менеджер підтвердить деталі перед відправкою.</p>
        </div>

        <form method="POST" action="{{ route('checkout.place') }}" class="mt-8 grid gap-8 lg:grid-cols-[1fr_420px]">
            @csrf
            <input type="hidden" name="checkout_token" value="{{ $checkout_token }}">
            <div class="grid gap-5">
                <div class="at-card p-5">
                    <h2 class="text-xl font-black text-white">Контакти</h2>
                    <div class="mt-5 grid gap-5 md:grid-cols-2">
                        <div>
                            <label class="label" for="name">Імʼя</label>
                            <input id="name" name="name" value="{{ old('name') }}" class="field mt-2" required>
                            @error('name') <div class="mt-2 text-sm text-rose-300">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="label" for="phone">Телефон</label>
                            <input id="phone" name="phone" value="{{ old('phone') }}" class="field mt-2" required>
                            @error('phone') <div class="mt-2 text-sm text-rose-300">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="label" for="email">Email</label>
                            <input id="email" type="email" name="email" value="{{ old('email') }}" class="field mt-2">
                            @error('email') <div class="mt-2 text-sm text-rose-300">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="label" for="city">Місто</label>
                            <input id="city" name="city" value="{{ old('city') }}" class="field mt-2">
                            @error('city') <div class="mt-2 text-sm text-rose-300">{{ $message }}</div> @enderror
                        </div>
                    </div>
                </div>

                <div class="at-card p-5">
                    <h2 class="text-xl font-black text-white">Доставка і оплата</h2>
                    <div class="mt-5 grid gap-5 md:grid-cols-2">
                        <div>
                            <label class="label" for="delivery_method">Доставка</label>
                            <select id="delivery_method" name="delivery_method" class="field mt-2" required>
                                <option value="Нова пошта" @selected(old('delivery_method') === 'Нова пошта')>Нова пошта</option>
                                <option value="Самовивіз" @selected(old('delivery_method') === 'Самовивіз')>Самовивіз</option>
                                <option value="Курʼєр" @selected(old('delivery_method') === 'Курʼєр')>Курʼєр</option>
                            </select>
                            @error('delivery_method') <div class="mt-2 text-sm text-rose-300">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="label" for="payment_method">Оплата</label>
                            <select id="payment_method" name="payment_method" class="field mt-2" required>
                                <option value="Післяплата" @selected(old('payment_method') === 'Післяплата')>Післяплата</option>
                                <option value="Оплата карткою" @selected(old('payment_method') === 'Оплата карткою')>Оплата карткою</option>
                                <option value="Безготівковий рахунок" @selected(old('payment_method') === 'Безготівковий рахунок')>Безготівковий рахунок</option>
                            </select>
                            @error('payment_method') <div class="mt-2 text-sm text-rose-300">{{ $message }}</div> @enderror
                        </div>
                    </div>
                    <div class="mt-5">
                        <label class="label" for="address">Адреса або відділення</label>
                        <textarea id="address" name="address" class="field mt-2" rows="3">{{ old('address') }}</textarea>
                        @error('address') <div class="mt-2 text-sm text-rose-300">{{ $message }}</div> @enderror
                    </div>
                    <div class="mt-5">
                        <label class="label" for="customer_comment">Коментар</label>
                        <textarea id="customer_comment" name="customer_comment" class="field mt-2" rows="4">{{ old('customer_comment') }}</textarea>
                        @error('customer_comment') <div class="mt-2 text-sm text-rose-300">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>

            <aside class="at-card self-start p-5 lg:sticky lg:top-32">
                <h2 class="text-xl font-black text-white">Підсумок</h2>
                <div class="mt-5 grid gap-4">
                    @foreach ($items as $item)
                        <div class="grid grid-cols-[64px_1fr] gap-3 border-b border-white/10 pb-4 last:border-b-0">
                            <img src="{{ $item['product']->image_url }}" alt="{{ $item['product']->image_alt_text ?: $item['product']->name }}" class="h-16 w-16 rounded object-cover" loading="lazy" onerror="this.onerror=null;this.src='{{ $productPlaceholder }}';">
                            <div>
                                <div class="font-bold leading-5 text-white">{{ $item['product']->name }}</div>
                                <div class="mt-1 text-sm text-zinc-500">{{ $item['quantity'] }} x {{ $item['formatted_unit_price'] }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-5 grid gap-3 border-t border-white/10 pt-5 text-sm font-bold">
                    <div class="flex items-center justify-between text-zinc-400">
                        <span>Товарів</span>
                        <span class="text-white">{{ $items->sum('quantity') }}</span>
                    </div>
                    <div class="flex items-center justify-between text-zinc-400">
                        <span>До сплати</span>
                        <span class="text-3xl font-black text-white">{{ app(\App\Services\Commerce\ProductPricingService::class)->formatAmount($total, $currency) }}</span>
                    </div>
                </div>
                <button class="btn-primary mt-6 w-full">Підтвердити замовлення</button>
                <p class="mt-4 text-xs font-bold uppercase leading-5 text-zinc-500">Після підтвердження кошик очиститься, а замовлення зʼявиться в адмінці.</p>
            </aside>
        </form>
    </section>
@endsection
