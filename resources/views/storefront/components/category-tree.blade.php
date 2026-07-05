@php
    $currentCategory = $currentCategory ?? null;
    $level = $level ?? 0;
    $compact = $compact ?? false;
@endphp

<ul class="grid gap-2 {{ $level > 0 ? 'ml-3 border-l border-white/10 pl-3' : '' }}">
    @foreach ($categories as $item)
        <li>
            <a href="{{ route('category.show', $item) }}"
               class="flex items-center justify-between gap-3 rounded px-3 py-2 text-sm font-bold transition {{ $item->is($currentCategory) || ($currentCategory && $item->children->contains(fn ($child) => $child->is($currentCategory))) ? 'bg-amber-300 text-neutral-950' : 'text-zinc-300 hover:bg-white/10 hover:text-white' }}"
               data-category-depth="{{ $level }}">
                <span class="flex items-center gap-2">
                    @if ($level > 0)
                        <span class="text-xs text-zinc-500">↳</span>
                    @endif
                    <span>{{ $item->name }}</span>
                </span>
                @if ($item->children->isNotEmpty())
                    <span class="text-[10px] font-black uppercase tracking-[0.2em] text-zinc-500">{{ $item->children->count() }}</span>
                @endif
            </a>

            @if ($item->children->isNotEmpty())
                @include('storefront.components.category-tree', ['categories' => $item->children, 'currentCategory' => $currentCategory, 'level' => $level + 1, 'compact' => $compact])
            @endif
        </li>
    @endforeach
</ul>
