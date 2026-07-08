<x-filament-panels::page>
    <div class="space-y-6">

        {{-- Diagnostics / warnings --}}
        @if ($diagnostics)
            <div class="rounded-xl border border-danger-300 bg-danger-50 p-4 text-sm text-danger-700 dark:border-danger-500/40 dark:bg-danger-500/10 dark:text-danger-300">
                <div class="font-semibold">Помилки каталогу:</div>
                <ul class="mt-1 list-disc pl-5">
                    @foreach ($diagnostics as $diagnostic)
                        <li>{{ $diagnostic }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($warnings)
            <div class="rounded-xl border border-warning-300 bg-warning-50 p-4 text-sm text-warning-700 dark:border-warning-500/40 dark:bg-warning-500/10 dark:text-warning-300">
                <div class="font-semibold">Зауваження:</div>
                <ul class="mt-1 list-disc pl-5">
                    @foreach ($warnings as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Filters --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-3 lg:grid-cols-6">
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Тип</label>
                    <select wire:model.live="filterType" class="w-full rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm text-gray-900 dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
                        <option value="">Усі</option>
                        @foreach ($typeOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label === 'module' ? 'Модуль' : ($label === 'extension' ? 'Розширення' : $label) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Статус</label>
                    <select wire:model.live="filterStatus" class="w-full rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm text-gray-900 dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
                        <option value="">Усі</option>
                        @foreach ($statusOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Категорія</label>
                    <select wire:model.live="filterCategory" class="w-full rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm text-gray-900 dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
                        <option value="">Усі</option>
                        @foreach ($categoryOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Vendor</label>
                    <select wire:model.live="filterVendor" class="w-full rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm text-gray-900 dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
                        <option value="">Усі</option>
                        @foreach ($vendorOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Рекомендовані</label>
                    <select wire:model.live="filterFeatured" class="w-full rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm text-gray-900 dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
                        <option value="">Усі</option>
                        @foreach ($featuredOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end">
                    <button wire:click="resetFilters" type="button" class="w-full rounded-lg border border-gray-300 bg-gray-100 px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-200 dark:border-white/10 dark:bg-white/5 dark:text-gray-200 dark:hover:bg-white/10">
                        Скинути
                    </button>
                </div>
            </div>
        </div>

        {{-- Actions bar --}}
        <div class="flex items-center justify-between">
            <div class="text-sm text-gray-500 dark:text-gray-400">
                Всього позицій: <span class="font-semibold text-gray-700 dark:text-gray-200">{{ count($rows) }}</span>
            </div>
            <button wire:click="discover" type="button" class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-500">
                Discover / rescan
            </button>
        </div>

        {{-- Empty state --}}
        @if (count($rows) === 0)
            <div class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center text-gray-500 dark:border-white/10 dark:bg-gray-900 dark:text-gray-400">
                Каталог порожній або не містить позицій за обраними фільтрами.
            </div>
        @endif

        {{-- Cards --}}
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2 xl:grid-cols-3">
            @foreach ($rows as $row)
                @php
                    $item = $row['item'];
                    $status = $row['status'];
                    $statusLabel = $statusLabels[$status] ?? $status;
                    $statusClass = match ($status) {
                        'enabled' => 'bg-success-100 text-success-700 dark:bg-success-500/15 dark:text-success-300',
                        'disabled', 'installed' => 'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-gray-300',
                        'discovered', 'available' => 'bg-info-100 text-info-700 dark:bg-info-500/15 dark:text-info-300',
                        'missing_files', 'invalid' => 'bg-danger-100 text-danger-700 dark:bg-danger-500/15 dark:text-danger-300',
                        'failed' => 'bg-danger-100 text-danger-700 dark:bg-danger-500/15 dark:text-danger-300',
                        'removed' => 'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400',
                        default => 'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-gray-300',
                    };
                    $typeLabel = $item->type === 'module' ? 'Модуль' : 'Розширення';
                    $canEnable = in_array('enable', $row['actions'], true);
                    $enableBlocked = $canEnable && $row['dependency_issues'] !== [];
                @endphp

                <div class="flex flex-col rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-300">
                                @if ($item->icon)
                                    <x-filament::icon :icon="$item->icon" class="h-5 w-5" />
                                @else
                                    <span class="text-lg font-bold">{{ mb_substr($item->vendor, 0, 1) }}</span>
                                @endif
                            </div>
                            <div>
                                <div class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $item->name }}</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $item->vendor }} · {{ $item->code }}</div>
                            </div>
                        </div>
                        <span class="rounded-full px-2.5 py-0.5 text-xs font-medium {{ $statusClass }}">{{ $statusLabel }}</span>
                    </div>

                    @if ($item->isFeatured)
                        <div class="mt-2">
                            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">Рекомендовано</span>
                        </div>
                    @endif

                    <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ $item->description ?: '—' }}</p>

                    <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                        <div><dt class="inline font-medium">Тип:</dt> <dd class="inline">{{ $typeLabel }}</dd></div>
                        <div><dt class="inline font-medium">Версія:</dt> <dd class="inline">{{ $item->version }}</dd></div>
                        <div><dt class="inline font-medium">Категорія:</dt> <dd class="inline">{{ $item->category ?: '—' }}</dd></div>
                        <div><dt class="inline font-medium">Платформа:</dt> <dd class="inline">{{ $item->platformVersion ?: '—' }}</dd></div>
                    </dl>

                    @if ($item->tags)
                        <div class="mt-3 flex flex-wrap gap-1.5">
                            @foreach ($item->tags as $tag)
                                <span class="rounded-md bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-300">{{ $tag }}</span>
                            @endforeach
                        </div>
                    @endif

                    @if ($item->dependencies)
                        <div class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                            <span class="font-medium">Залежності:</span>
                            {{ implode(', ', $item->dependencies) }}
                        </div>
                    @endif

                    @if ($row['warnings'])
                        <div class="mt-3 rounded-lg border border-warning-300 bg-warning-50 p-2 text-xs text-warning-700 dark:border-warning-500/40 dark:bg-warning-500/10 dark:text-warning-300">
                            <ul class="list-disc pl-4">
                                @foreach ($row['warnings'] as $warning)
                                    <li>{{ $warning }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if ($expandedCode === $item->code)
                        <div class="mt-3 rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">
                            <div class="font-semibold text-gray-700 dark:text-gray-200">Деталі та діагностика</div>
                            <dl class="mt-1 space-y-1">
                                <div><dt class="inline font-medium">Code:</dt> <dd class="inline">{{ $item->code }}</dd></div>
                                <div><dt class="inline font-medium">Manifest path:</dt> <dd class="inline">{{ $item->path ?: '—' }}</dd></div>
                                <div><dt class="inline font-medium">Computed status:</dt> <dd class="inline">{{ $statusLabel }}</dd></div>
                                @if ($row['addon'])
                                    <div><dt class="inline font-medium">system_addons:</dt> <dd class="inline">{{ $row['addon']->status }} (installed: {{ $row['addon']->is_installed ? 'yes' : 'no' }}, enabled: {{ $row['addon']->is_enabled ? 'yes' : 'no' }})</dd></div>
                                    @if ($row['addon']->last_error)
                                        <div><dt class="inline font-medium">last_error:</dt> <dd class="inline text-danger-600 dark:text-danger-300">{{ $row['addon']->last_error }}</dd></div>
                                    @endif
                                @else
                                    <div><dt class="inline font-medium">system_addons:</dt> <dd class="inline">немає запису</dd></div>
                                @endif
                                @if (! $item->isValid())
                                    <div><dt class="inline font-medium text-danger-600 dark:text-danger-300">Помилки:</dt> <dd class="inline text-danger-600 dark:text-danger-300">{{ implode(' ', $item->errors) }}</dd></div>
                                @endif
                            </dl>
                        </div>
                    @endif

                    <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3 dark:border-white/10">
                        @foreach ($row['actions'] as $action)
                            @php
                                $labels = [
                                    'discover' => ['label' => 'Discover', 'class' => 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-200 dark:hover:bg-white/10'],
                                    'install' => ['label' => 'Install', 'class' => 'bg-primary-600 text-white hover:bg-primary-500'],
                                    'enable' => ['label' => 'Enable', 'class' => 'bg-success-600 text-white hover:bg-success-500'],
                                    'disable' => ['label' => 'Disable', 'class' => 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-200 dark:hover:bg-white/10'],
                                    'uninstall' => ['label' => 'Uninstall', 'class' => 'bg-danger-600 text-white hover:bg-danger-500'],
                                ];
                                $cfg = $labels[$action] ?? ['label' => $action, 'class' => 'bg-gray-100 text-gray-700'];
                                $disabled = $action === 'enable' && $enableBlocked;
                            @endphp
                            @if ($disabled)
                                <button type="button" disabled title="Спочатку увімкніть залежності" class="cursor-not-allowed rounded-lg bg-success-600/40 px-3 py-1.5 text-xs font-semibold text-white opacity-60">
                                    {{ $cfg['label'] }}
                                </button>
                            @else
                                <button wire:click="{{ $action }}(@js($item->code))" type="button" class="rounded-lg px-3 py-1.5 text-xs font-semibold transition {{ $cfg['class'] }}">
                                    {{ $cfg['label'] }}
                                </button>
                            @endif
                        @endforeach

                        <button wire:click="toggleDetails(@js($item->code))" type="button" class="ml-auto rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 transition hover:bg-gray-100 dark:border-white/10 dark:text-gray-300 dark:hover:bg-white/5">
                            {{ $expandedCode === $item->code ? 'Приховати' : 'Деталі' }}
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
