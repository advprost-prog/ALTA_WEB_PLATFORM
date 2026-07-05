@extends('layouts.storefront', ['title' => $product->seo_title ?: $product->name, 'description' => $product->seo_description ?: $product->short_description])

@section('content')
    @php
        $pricingService = app(\App\Services\Commerce\ProductPricingService::class);
        $availabilityService = app(\App\Services\Commerce\ProductAvailabilityService::class);
        $priceView = $pricingService->priceView($product);
        $availabilityView = $availabilityService->availabilityView($product);
        $isAvailable = $availabilityView['is_available'];
        $isPurchasable = $isAvailable && $priceView['is_available_for_selected_currency'];
        $productPlaceholder = asset('images/placeholders/product-placeholder.svg');
    @endphp

    <section class="section-shell">
        <nav class="text-sm font-bold text-zinc-400">
            <a href="{{ route('home') }}" class="hover:text-amber-300">Головна</a>
            <span class="mx-2 text-zinc-600">/</span>
            <a href="{{ route('catalog') }}" class="hover:text-amber-300">Каталог</a>
            @if ($product->category)
                <span class="mx-2 text-zinc-600">/</span>
                <a href="{{ route('category.show', $product->category) }}" class="hover:text-amber-300">{{ $product->category->name }}</a>
            @endif
            <span class="mx-2 text-zinc-600">/</span>
            <span class="text-white">{{ $product->name }}</span>
        </nav>

        <div class="mt-8 grid gap-10 lg:grid-cols-[1fr_520px]">
            <div>
                <div class="relative overflow-hidden rounded-md border border-white/10 bg-zinc-950">
                    <img src="{{ $product->image_url }}" alt="{{ $product->image_alt_text ?: $product->name }}" class="aspect-[4/3] w-full object-cover" onerror="this.onerror=null;this.src='{{ $productPlaceholder }}';">
                    <div class="absolute left-4 top-4 flex flex-wrap gap-2">
                        @unless ($isAvailable)
                            <span class="badge bg-zinc-800 text-zinc-200">Немає в наявності</span>
                        @endunless
                        @if ($product->is_sale)
                            <span class="badge bg-rose-500 text-white">Акція</span>
                        @endif
                        @if ($product->is_hit)
                            <span class="badge bg-amber-300 text-neutral-950">Хіт</span>
                        @endif
                        @if ($product->is_new)
                            <span class="badge bg-cyan-300 text-neutral-950">Новинка</span>
                        @endif
                    </div>
                    @if ($priceView['discount_percent'])
                        <span class="absolute bottom-4 right-4 rounded bg-neutral-950 px-4 py-2 text-lg font-black text-lime-300">-{{ $priceView['discount_percent'] }}%</span>
                    @endif
                </div>
                @if ($product->images->isNotEmpty())
                    <div class="mt-4 grid grid-cols-4 gap-3 sm:grid-cols-6">
                        @foreach ($product->images as $image)
                            <img src="{{ $image->image_url }}" alt="{{ $image->alt ?: $product->name }}" class="aspect-square rounded border border-white/10 object-cover" loading="lazy" onerror="this.onerror=null;this.src='{{ $productPlaceholder }}';">
                        @endforeach
                    </div>
                @endif

                <div class="mt-6 grid gap-3 sm:grid-cols-3">
                    <div class="at-panel p-4">
                        <div class="text-sm font-black uppercase text-amber-300">Доставка</div>
                        <p class="mt-2 text-sm leading-6 text-zinc-400">Нова пошта, самовивіз або курʼєр після підтвердження.</p>
                    </div>
                    <div class="at-panel p-4">
                        <div class="text-sm font-black uppercase text-cyan-300">Оплата</div>
                        <p class="mt-2 text-sm leading-6 text-zinc-400">Післяплата, картка або безготівковий рахунок.</p>
                    </div>
                    <div class="at-panel p-4">
                        <div class="text-sm font-black uppercase text-lime-300">Підбір</div>
                        <p class="mt-2 text-sm leading-6 text-zinc-400">Менеджер уточнить сумісність перед відправкою.</p>
                    </div>
                </div>
            </div>

            <div class="lg:pt-2">
                <div class="flex flex-wrap gap-2">
                    <span class="status-pill">
                        <span class="status-dot {{ $isAvailable ? 'bg-lime-300' : 'bg-rose-400' }}"></span>
                        {{ $availabilityView['label'] }}
                    </span>
                    @if ($availabilityView['quantity_label'])
                        <span class="status-pill">{{ $availabilityView['quantity_label'] }}</span>
                    @endif
                    <span class="status-pill">Артикул: {{ $product->sku }}</span>
                </div>

                <h1 class="mt-5 text-4xl font-black leading-tight text-white sm:text-5xl">{{ $product->name }}</h1>
                <div class="mt-4 flex flex-wrap gap-3 text-sm font-bold text-zinc-400">
                    <span>Бренд: {{ $product->brand?->name ?? 'Alta' }}</span>
                    @if ($product->category)
                        <span>Категорія: {{ $product->category->name }}</span>
                    @endif
                </div>
                <p class="mt-6 text-lg leading-8 text-zinc-300">{{ $product->short_description }}</p>

                <div class="mt-8 flex flex-wrap items-end gap-4">
                    <div>
                        <div class="text-5xl font-black text-white">{{ $priceView['formatted_price'] }}</div>
                        @if ($priceView['fallback_message'])
                            <div class="mt-2 text-xs font-bold uppercase text-zinc-500">{{ $priceView['fallback_message'] }}</div>
                        @endif
                    </div>
                    @if ($priceView['formatted_compare_at_price'])
                        <div class="pb-2 text-xl font-bold text-zinc-500 line-through">{{ $priceView['formatted_compare_at_price'] }}</div>
                    @endif
                </div>

                <form method="POST" action="{{ route('cart.add', $product) }}" class="mt-8 grid max-w-xl gap-3 sm:grid-cols-[120px_1fr]">
                    @csrf
                    <input type="number" min="1" max="{{ max(1, $availabilityView['max_quantity']) }}" name="quantity" value="1" class="field" @disabled(! $isPurchasable)>
                    <button class="btn-primary w-full" @disabled(! $isPurchasable)>{{ $isPurchasable ? 'Додати до кошика' : 'Товар недоступний' }}</button>
                </form>

                <div class="at-card mt-5 p-5">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end">
                        <div class="flex-1">
                            <label class="label" for="quick-phone">Швидке замовлення</label>
                            <input id="quick-phone" class="field mt-2" placeholder="+38 (0__) ___ __ __">
                        </div>
                        <button type="button" class="btn-secondary whitespace-nowrap" disabled>Передзвонити</button>
                    </div>
                    <p class="mt-3 text-xs font-bold uppercase text-zinc-500">UI-заготовка для наступної фази без окремої бізнес-логіки.</p>
                </div>

                @if ($product->specifications->isNotEmpty())
                    <div class="mt-8 rounded-md border border-white/10 bg-zinc-950 p-5">
                        <h2 class="text-xl font-black text-white">Характеристики</h2>
                        <dl class="mt-5 grid gap-3">
                            @foreach ($product->specifications as $specification)
                                <div class="flex items-center justify-between gap-4 border-b border-white/10 pb-3 text-sm last:border-b-0 last:pb-0">
                                    <dt class="font-semibold text-zinc-400">{{ $specification->name }}</dt>
                                    <dd class="text-right font-black text-white">{{ $specification->value }} {{ $specification->unit }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                @endif
            </div>
        </div>

        @if ($product->description)
            <div class="mt-12 border-t border-white/10 pt-10">
                <div class="eyebrow">Опис</div>
                <div class="mt-4 max-w-4xl text-lg leading-8 text-zinc-300">{{ $product->description }}</div>
            </div>
        @endif
    </section>

    @if ($relatedProducts->isNotEmpty())
        <section class="border-t border-white/10 bg-zinc-950">
            <div class="section-shell">
                <div class="flex items-end justify-between gap-6">
                    <div>
                        <div class="eyebrow">Схожі товари</div>
                        <h2 class="mt-3 text-3xl font-black text-white">З цієї категорії</h2>
                    </div>
                    @if ($product->category)
                        <a href="{{ route('category.show', $product->category) }}" class="hidden text-sm font-black uppercase text-amber-300 hover:text-cyan-300 sm:block">Вся категорія</a>
                    @endif
                </div>
                <div class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($relatedProducts as $product)
                        @include('storefront.components.product-card', ['product' => $product])
                    @endforeach
                </div>
            </div>
        </section>
    @endif
@endsection
