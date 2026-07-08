<x-filament-panels::page>
    <div style="width:100%">

        {{-- Header --}}
        <x-filament::section
            :aside="true"
            heading="Marketplace модулів"
            description="Керування локальними модулями та розширеннями платформи."
            icon="heroicon-o-squares-2x2"
        >
            <x-filament::button wire:click="rescan" icon="heroicon-o-arrow-path" size="sm">
                Discover / rescan
            </x-filament::button>
        </x-filament::section>

        {{-- Summary --}}
        <div
            class="fi-grid lg:fi-grid-cols"
            style="--cols-default:repeat(1,minmax(0,1fr));--cols-md:repeat(2,minmax(0,1fr));--cols-lg:repeat(4,minmax(0,1fr));margin-top:1rem;"
        >
            @foreach ($summary as $stat)
                <x-filament::section>
                    <div style="width:100%">
                        <div style="font-size:1.75rem;font-weight:700;line-height:1">{{ $stat['value'] }}</div>
                        <div class="fi-sc-section-label" style="margin-top:0.25rem">{{ $stat['label'] }}</div>
                        <div class="fi-in-text" style="font-size:0.75rem;color:var(--gray-500);margin-top:0.25rem">{{ $stat['description'] }}</div>
                    </div>
                </x-filament::section>
            @endforeach
        </div>

        {{-- Diagnostics (errors) --}}
        @if ($diagnostics)
            <x-filament::callout color="danger" icon="heroicon-o-exclamation-triangle" heading="Помилки каталогу" style="margin-top:1rem">
                <ul>
                    @foreach ($diagnostics as $diagnostic)
                        <li class="fi-in-text">{{ $diagnostic }}</li>
                    @endforeach
                </ul>
            </x-filament::callout>
        @endif

        {{-- Warnings --}}
        @if ($warnings)
            <x-filament::callout color="warning" icon="heroicon-o-exclamation-circle" heading="Зауваження" style="margin-top:1rem">
                <ul>
                    @foreach ($warnings as $warning)
                        <li class="fi-in-text">{{ $warning }}</li>
                    @endforeach
                </ul>
            </x-filament::callout>
        @endif

        {{-- Filters --}}
        <x-filament::section heading="Фільтри" icon="heroicon-o-funnel" style="margin-top:1rem">
            <div
                class="fi-grid md:fi-grid-cols lg:fi-grid-cols"
                style="--cols-default:repeat(1,minmax(0,1fr));--cols-md:repeat(2,minmax(0,1fr));--cols-lg:repeat(3,minmax(0,1fr));"
            >
                <div class="fi-input-wrp">
                    <select class="fi-select-input" wire:model.live="filterType">
                        <option value="">Усі типи</option>
                        <option value="module">Модуль</option>
                        <option value="extension">Розширення</option>
                    </select>
                </div>
                <div class="fi-input-wrp">
                    <select class="fi-select-input" wire:model.live="filterStatus">
                        <option value="">Усі статуси</option>
                        @foreach ($statusOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="fi-input-wrp">
                    <select class="fi-select-input" wire:model.live="filterCategory">
                        <option value="">Усі категорії</option>
                        @foreach ($categoryOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="fi-input-wrp">
                    <select class="fi-select-input" wire:model.live="filterVendor">
                        <option value="">Усі vendors</option>
                        @foreach ($vendorOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="fi-input-wrp">
                    <select class="fi-select-input" wire:model.live="filterFeatured">
                        <option value="">Рекомендовані: усі</option>
                        <option value="1">Так</option>
                        <option value="0">Ні</option>
                    </select>
                </div>
            </div>
            <div style="margin-top:0.75rem">
                <x-filament::button wire:click="resetFilters" color="gray" size="sm" icon="heroicon-o-x-mark">
                    Скинути фільтри
                </x-filament::button>
            </div>
        </x-filament::section>

        {{-- Cards --}}
        @if (count($rows) === 0)
            <div class="fi-empty-state" style="margin-top:1rem">
                <div class="fi-empty-state-text-ctn">
                    <h4 class="fi-empty-state-heading">Немає модулів за вибраними фільтрами.</h4>
                    <p class="fi-empty-state-description">Спробуйте змінити або скинути фільтри.</p>
                </div>
                <div class="fi-empty-state-footer">
                    <x-filament::button wire:click="resetFilters" color="gray" size="sm">
                        Скинути фільтри
                    </x-filament::button>
                </div>
            </div>
        @else
            <div
                class="fi-grid md:fi-grid-cols lg:fi-grid-cols"
                style="--cols-default:repeat(1,minmax(0,1fr));--cols-md:repeat(2,minmax(0,1fr));--cols-lg:repeat(3,minmax(0,1fr));margin-top:1rem;"
            >
                @foreach ($rows as $row)
                    @php
                        $item = $row['item'];
                        $status = $row['status'];
                        $statusLabel = $statusLabels[$status] ?? $status;
                        $statusColor = $statusColors[$status] ?? 'gray';
                        $typeLabel = $item->type === 'module' ? 'Модуль' : 'Розширення';
                        $canEnable = in_array('enable', $row['actions'], true);
                        $dependencyBlocked = $canEnable && $row['dependency_issues'] !== [];
                        $unmet = [];
                        if (preg_match_all('/\[([a-z0-9._-]+)\]/', implode(' ', $row['dependency_issues']), $matches) === 1) {
                            $unmet = $matches[1];
                        }
                        $updateStatus = $row['update_status'] ?? 'unknown';
                        $updateStatusLabel = $updateStatusLabels[$updateStatus] ?? $updateStatus;
                        $updateStatusColor = $updateStatusColors[$updateStatus] ?? 'gray';
                        $compat = $row['compatibility_status'] ?? 'unknown';
                        $compatLabel = $compatibilityLabels[$compat] ?? $compat;
                        $compatColor = $compatibilityColors[$compat] ?? 'gray';
                        $installedVersion = $row['installed_version'] ?? null;
                        $availableVersion = $row['available_version'] ?? null;
                        $platformConstraint = $row['platform_constraint'] ?? null;
                        $isIncompatible = $compat === 'incompatible';
                        $actionConfig = [
                            'discover' => ['label' => 'Discover', 'color' => 'gray', 'icon' => 'heroicon-o-magnifying-glass'],
                            'install' => ['label' => 'Install', 'color' => 'primary', 'icon' => 'heroicon-o-plus-circle'],
                            'enable' => ['label' => 'Enable', 'color' => 'success', 'icon' => 'heroicon-o-play-circle'],
                            'disable' => ['label' => 'Disable', 'color' => 'gray', 'icon' => 'heroicon-o-pause-circle'],
                            'update' => ['label' => 'Update', 'color' => 'warning', 'icon' => 'heroicon-o-arrow-up-circle'],
                            'uninstall' => ['label' => 'Uninstall', 'color' => 'danger', 'icon' => 'heroicon-o-trash', 'outlined' => true],
                        ];
                        $dependencyDisplay = [];
                        foreach ($item->getDependencies() as $dependency) {
                            $constraint = $dependency['constraint'];
                            $dependencyDisplay[] = $dependency['code'].($constraint !== null && $constraint !== '' ? ' ('.$constraint.')' : '');
                        }
                        $compatDetail = $compatLabel.($platformConstraint !== null && $platformConstraint !== '' ? ' ('.$platformConstraint.')' : '');
                    @endphp

                    <x-filament::section
                        :heading="$item->name"
                        :description="$item->vendor . ' · ' . $item->code"
                        :icon="$item->icon ?? 'heroicon-o-cube'"
                    >
                        <div style="width:100%">

                            {{-- Badges --}}
                            <div>
                                <x-filament::badge :color="$item->type === 'module' ? 'primary' : 'info'">{{ $typeLabel }}</x-filament::badge>
                                <x-filament::badge :color="$statusColor">{{ $statusLabel }}</x-filament::badge>
                                <x-filament::badge :color="$updateStatusColor">{{ $updateStatusLabel }}</x-filament::badge>
                                <x-filament::badge :color="$compatColor">{{ $compatLabel }}</x-filament::badge>
                                @if ($item->isFeatured)
                                    <x-filament::badge color="warning">Рекомендовано</x-filament::badge>
                                @endif
                            </div>

                            {{-- Description --}}
                            <p class="fi-in-text" style="margin-top:0.5rem">{{ $item->description ?: '—' }}</p>

                            {{-- Metadata --}}
                            <div class="fi-in-text" style="margin-top:0.5rem;font-size:0.8rem">
                                @if ($installedVersion)
                                    Встановлено: <strong>{{ $installedVersion }}</strong>
                                @else
                                    Версія: {{ $item->version }}
                                @endif
                                @if ($availableVersion && $availableVersion !== $installedVersion)
                                    · Доступно: <strong>{{ $availableVersion }}</strong>
                                @endif
                                · Категорія: {{ $item->category ?: '—' }}
                                · Платформа: {{ $item->platformVersion ?: '—' }}
                                @if ($platformConstraint)
                                    · Обмеження: {{ $platformConstraint }}
                                @endif
                            </div>

                            {{-- Tags --}}
                            @if ($item->tags)
                                <div style="margin-top:0.5rem">
                                    @foreach ($item->tags as $tag)
                                        <x-filament::badge color="gray">{{ $tag }}</x-filament::badge>
                                    @endforeach
                                </div>
                            @endif

                            {{-- Dependencies --}}
                            @if ($dependencyDisplay)
                                <div class="fi-in-text" style="margin-top:0.5rem">
                                    <strong>Залежності:</strong>
                                    @foreach ($dependencyDisplay as $dependency)
                                        <x-filament::badge :color="in_array(explode(' ', $dependency)[0], $unmet, true) ? 'danger' : 'gray'">{{ $dependency }}</x-filament::badge>
                                    @endforeach
                                </div>
                            @endif

                            {{-- Incompatible warning --}}
                            @if ($isIncompatible)
                                <div class="fi-callout" style="margin-top:0.5rem;padding:0.5rem;--ctn-color:var(--danger-600)">
                                    <div class="fi-in-text" style="font-size:0.8rem;color:#b91c1c">
                                        Несумісно з поточною версією платформи ({{ $platformConstraint }}). Встановлення/оновлення заблоковано.
                                    </div>
                                </div>
                            @endif

                            {{-- Warnings --}}
                            @if ($row['warnings'])
                                <ul class="fi-in-text" style="margin-top:0.5rem;color:#b45309">
                                    @foreach ($row['warnings'] as $warning)
                                        <li>{{ $warning }}</li>
                                    @endforeach
                                </ul>
                            @endif

                            {{-- Invalid errors --}}
                            @if (! $item->isValid())
                                <div style="margin-top:0.5rem">
                                    @foreach ($item->errors as $error)
                                        <p class="fi-in-text">{{ $error }}</p>
                                    @endforeach
                                </div>
                            @endif

                            {{-- Missing files path --}}
                            @if ($status === 'missing_files' && $item->path)
                                <p class="fi-in-text" style="margin-top:0.5rem;font-family:var(--mono-font-family),monospace;font-size:0.75rem">
                                    {{ $item->path }}
                                </p>
                            @endif

                            {{-- Details (expanded) --}}
                            @if ($expandedCode === $item->code)
                                <div class="fi-callout" style="margin-top:0.5rem;padding:0.5rem">
                                    <div class="fi-in-text" style="font-size:0.8rem">
                                        <div><strong>Code:</strong> {{ $item->code }}</div>
                                        <div><strong>Manifest:</strong> {{ $item->path ?: '—' }}</div>
                                        <div><strong>Статус:</strong> {{ $statusLabel }}</div>
                                        <div><strong>Installed version:</strong> {{ $installedVersion ?: '—' }}</div>
                                        <div><strong>Available version:</strong> {{ $availableVersion ?: '—' }}</div>
                                        <div><strong>Update status:</strong> {{ $updateStatusLabel }}</div>
                                        <div><strong>Compatibility:</strong> {{ $compatDetail }}</div>
                                        @if ($row['addon'])
                                            <div><strong>system_addons:</strong> {{ $row['addon']->status }} (installed: {{ $row['addon']->is_installed ? 'так' : 'ні' }}, enabled: {{ $row['addon']->is_enabled ? 'так' : 'ні' }})</div>
                                            @if ($row['addon']->last_error)
                                                <div><strong>last_error:</strong> {{ $row['addon']->last_error }}</div>
                                            @endif
                                        @else
                                            <div><strong>system_addons:</strong> немає запису</div>
                                        @endif
                                    </div>
                                </div>
                            @endif

                            {{-- Actions --}}
                            <div style="margin-top:0.75rem">
                    @foreach ($row['actions'] as $action)
                        @php
                            $cfg = $actionConfig[$action] ?? ['label' => $action, 'color' => 'gray', 'icon' => null];
                            $isBlocked = $action === 'enable' && $dependencyBlocked;
                            $method = match ($action) {
                                'install' => 'installAddon',
                                'enable' => 'enableAddon',
                                'disable' => 'disableAddon',
                                'update' => 'updateAddon',
                                'uninstall' => 'uninstallAddon',
                                default => $action,
                            };
                            $code = e($item->code);
                        @endphp
                        @if ($isBlocked)
                            <x-filament::button
                                :disabled="true"
                                :tooltip="'Спочатку увімкніть залежності: ' . implode(', ', $row['dependency_issues'])"
                                :color="$cfg['color']"
                                icon="{{ $cfg['icon'] }}"
                                size="sm"
                            >{{ $cfg['label'] }}</x-filament::button>
                        @else
                            <x-filament::button
                                wire:click="{{ $method }}('{{ $code }}')"
                                wire:loading.attr="disabled"
                                wire:target="{{ $method }}('{{ $code }}')"
                                :color="$cfg['color']"
                                :outlined="$cfg['outlined'] ?? false"
                                :icon="$cfg['icon']"
                                size="sm"
                            >{{ $cfg['label'] }}</x-filament::button>
                        @endif
                    @endforeach

                    @php($detailsCode = e($item->code))
                    <x-filament::button
                        wire:click="toggleDetails('{{ $detailsCode }}')"
                        wire:loading.attr="disabled"
                        wire:target="toggleDetails('{{ $detailsCode }}')"
                        color="gray"
                        size="sm"
                    >{{ $expandedCode === $item->code ? 'Приховати' : 'Деталі' }}</x-filament::button>
                            </div>

                        </div>
                    </x-filament::section>
                @endforeach
            </div>
        @endif

    </div>
</x-filament-panels::page>
