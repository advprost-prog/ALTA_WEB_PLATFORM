<x-filament-panels::page>
    <style>
        .marketplace-page-header{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem}.marketplace-page-header__actions{display:flex;gap:.5rem;flex-wrap:wrap;justify-content:flex-end}.marketplace-tabs{display:flex;gap:.5rem;overflow-x:auto;white-space:nowrap;padding:.25rem 0 .75rem;border-bottom:1px solid var(--gray-200);scrollbar-width:thin}.marketplace-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.5rem}.marketplace-table-wrap{max-width:100%;overflow-x:auto;border:1px solid var(--gray-200);border-radius:.5rem}.marketplace-state{margin-top:1rem}.marketplace-state__meta{font-size:.75rem;color:var(--gray-500);margin-top:.5rem}.marketplace-remnant{border:1px solid var(--gray-200);border-radius:.5rem;padding:.75rem;margin-top:.5rem}
        @media(max-width:900px){.marketplace-page-header{flex-direction:column}.marketplace-page-header__actions{justify-content:flex-start}.marketplace-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.marketplace-table-wrap table{min-width:760px}}
    </style>
    <div class="addon-marketplace">

        <header class="marketplace-page-header">
            <div><h1 style="font-size:1.35rem;font-weight:700">Marketplace додатків</h1><p class="fi-in-text" style="color:var(--gray-500)">Опубліковані модулі, локально встановлені додатки та операційний стан.</p></div>
            @php
                $registryConfig = config('addons-registry', []);
                $registryEnabled = (bool) ($registryConfig['enabled'] ?? false);
            @endphp
            <div class="marketplace-page-header__actions">
                @if ($registryEnabled)<x-filament::button wire:click="refreshRegistry" icon="heroicon-o-cloud-arrow-down" size="sm">Оновити каталог</x-filament::button>@endif
                @if ($activeTab === 'installed' || $activeTab === 'operations')<x-filament::button wire:click="rescan" icon="heroicon-o-arrow-path" size="sm" color="gray">Перевірити локальні файли</x-filament::button>@endif
            </div>
        </header>

        <nav class="marketplace-tabs" aria-label="Розділи Marketplace" style="margin-top:1rem">
            <x-filament::button wire:click="setMarketplaceTab('marketplace')" :color="$activeTab === 'marketplace' ? 'primary' : 'gray'" size="sm">Marketplace</x-filament::button>
            <x-filament::button wire:click="setMarketplaceTab('installed')" :color="$activeTab === 'installed' ? 'primary' : 'gray'" size="sm">Встановлені</x-filament::button>
            <x-filament::button wire:click="setMarketplaceTab('operations')" :color="$activeTab === 'operations' ? 'primary' : 'gray'" size="sm">Операції та відновлення</x-filament::button>
            @if ($showDevelopmentTab)
                <x-filament::button wire:click="setMarketplaceTab('development')" :color="$activeTab === 'development' ? 'warning' : 'gray'" size="sm">Для розробки</x-filament::button>
            @endif
        </nav>

        @if (in_array($activeTab, ['marketplace', 'installed'], true))
        <div class="marketplace-kpis" style="margin-top:.75rem">
            @foreach ([['Опубліковано', $remoteCount], ['Встановлено', $installedCount], ['Доступні оновлення', $updateCount], ['Потребують уваги', $attentionCount]] as [$label, $value])
                <div style="border:1px solid var(--gray-200);border-radius:0.5rem;padding:0.75rem;background:var(--gray-50)">
                    <div style="font-size:1.25rem;font-weight:600">{{ $value }}</div><div class="fi-in-text" style="font-size:0.75rem;color:var(--gray-500)">{{ $label }}</div>
                </div>
            @endforeach
        </div>
        @endif

        @if ($activeTab === 'marketplace' && ! in_array($registryPresentationState, ['connected_empty', 'connected_with_items'], true))
            <x-filament::section :heading="$registryPresentation['title']" icon="heroicon-o-cloud" class="marketplace-state">
                <p class="fi-in-text">{{ $registryPresentation['description'] }}</p>
                <div class="marketplace-state__meta">Остання перевірка: {{ $registryMeta['checked_at'] ?? '—' }} · схема: {{ $registryHeader['schema_version'] ?? '—' }}</div>
                <div style="margin-top:.75rem;display:flex;gap:.5rem">
                    @if ($registryEnabled)<x-filament::button wire:click="refreshRegistry" size="sm">Спробувати знову</x-filament::button>@endif
                    @if ($registryFailureCode)<x-filament::button wire:click="toggleRegistryDetails" color="gray" size="sm">Деталі</x-filament::button>@endif
                </div>
                @if ($registryDetailsOpen && $registryFailureCode)<div class="marketplace-state__meta">Код діагностики: {{ $registryFailureCode }}</div>@endif
            </x-filament::section>
        @endif

        @if ($activeTab === 'operations')
        <x-filament::section :heading="__('marketplace.operations.heading')" icon="heroicon-o-wrench-screwdriver" style="margin-top:1rem">
            <div class="fi-in-text" style="font-size:0.875rem">
                <strong>{{ __('marketplace.operations.status') }}:</strong> {{ mb_strtolower($operationsStatusLabel) }} ·
                {{ __('marketplace.operations.unresolved') }}: {{ $operationsHealth['unresolved_count'] }} ·
                {{ __('marketplace.operations.manual') }}: {{ $operationsHealth['manual_intervention_count'] }} ·
                {{ __('marketplace.operations.corrupt') }}: {{ $operationsHealth['corrupt_backup_count'] }}
            </div>
            <div style="margin-top:0.75rem">
                <x-filament::button wire:click="refreshRecoveryHealth" color="gray" size="sm" icon="heroicon-o-arrow-path">
                    {{ __('marketplace.operations.refresh') }}
                </x-filament::button>
            </div>

            @if ($operationsHealth['items'])
                <div style="margin-top:1rem">
                    @foreach ($operationsHealth['items'] as $operation)
                        <div class="marketplace-remnant">
                            <div style="display:flex;justify-content:space-between;gap:.75rem;align-items:center;flex-wrap:wrap">
                                <div><strong>Незавершена операція додатка</strong><div class="fi-in-text" style="font-size:.8rem;color:var(--gray-500)">Зміни призупинено до безпечної повторної перевірки.</div></div>
                                <div style="display:flex;gap:.35rem;flex-wrap:wrap">
                                    <x-filament::button wire:click="toggleRecoveryOperation('{{ $operation['operation_id'] }}')" size="xs" color="gray">Деталі</x-filament::button>
                                    <x-filament::button wire:click="recoveryDryRun('{{ $operation['operation_id'] }}')" size="xs" color="gray">{{ __('marketplace.operations.dry_run') }}</x-filament::button>
                                    @if ($operation['automatic'] && $canManagePromotion)
                                        <x-filament::button wire:click="runSafeRecovery('{{ $operation['operation_id'] }}')" wire:confirm="Run the revalidated safe recovery plan?" size="xs" color="warning">{{ __('marketplace.operations.run_safe') }}</x-filament::button>
                                    @endif
                                    @if ($canManagePromotion)
                                        <x-filament::button wire:click="rollbackDryRun('{{ $operation['addon_code'] }}', '{{ $operation['operation_id'] }}')" size="xs" color="gray">{{ __('marketplace.operations.rollback_preflight') }}</x-filament::button>
                                        <x-filament::button wire:click="markManualIntervention('{{ $operation['operation_id'] }}')" wire:confirm="Preserve this operation as manual-intervention-required? A reason is required." size="xs" color="danger">{{ __('marketplace.operations.mark_manual') }}</x-filament::button>
                                    @endif
                                </div>
                            </div>
                            @if ($expandedRecoveryOperation === $operation['operation_id'])
                                <div class="fi-in-text" style="font-size:.8rem;margin-top:.75rem">ID: {{ substr($operation['operation_id'], 0, 8) }}… · додаток: {{ $operation['addon_code'] }} · стан: {{ $operation['state'] }} · перевірка: основні файли {{ $operation['integrity']['live'] }}, резервна копія {{ $operation['integrity']['backup'] }}, кандидат {{ $operation['integrity']['candidate'] }}, каталог підготовки {{ $operation['integrity']['staging'] }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
            @if ($canManagePromotion && $operationsHealth['items'])
                <div style="margin-top:0.75rem;max-width:36rem">
                    <label class="fi-fo-field-wrp-label"><span class="fi-fo-field-wrp-label-text">{{ __('marketplace.operations.manual_reason') }}</span></label>
                    <input class="fi-input" type="text" maxlength="500" wire:model="manualInterventionReason">
                </div>
            @endif

            @if ($operationsHealth['rollback_candidates'])
                <h3 style="font-weight:600;margin-top:1rem">{{ __('marketplace.operations.completed_rollback') }}</h3>
                @foreach ($operationsHealth['rollback_candidates'] as $rollback)
                    <div class="fi-in-text" style="margin-top:0.5rem">
                        {{ $rollback['addon_code'] }} · {{ $rollback['current_version'] ?? 'невідомо' }} → {{ $rollback['target_version'] ?? 'невідомо' }}
                        <x-filament::button wire:click="rollbackDryRun('{{ $rollback['addon_code'] }}', '{{ $rollback['operation_id'] }}')" size="xs" color="gray">{{ __('marketplace.operations.rollback_dry') }}</x-filament::button>
                        @if ($rollback['eligible'] && $canManagePromotion)
                            <x-filament::button wire:click="executeOperationalRollback('{{ $rollback['addon_code'] }}', '{{ $rollback['operation_id'] }}')" wire:confirm="Execute the revalidated operational rollback?" size="xs" color="danger">{{ __('marketplace.operations.execute_rollback') }}</x-filament::button>
                        @endif
                    </div>
                @endforeach
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('marketplace.backups.heading')" icon="heroicon-o-archive-box" style="margin-top:1rem">
            @if ($backupRetention)
                @foreach ($backupRetention as $backup)
                    <div class="fi-in-text" style="margin-bottom:0.5rem">
                        <strong>{{ $backup['backupId'] }}</strong> · {{ $backup['addonCode'] ?? 'unknown' }} · {{ $backup['version'] ?? 'unknown' }} ·
                        {{ $backup['integrityStatus'] }} · {{ $backup['reason'] }}
                        @if ($backup['lastKnownGood']) · {{ __('marketplace.backups.last_good') }} @endif
                        @if ($backup['referencedByIncompleteOperation']) · {{ __('marketplace.backups.reference') }} @endif
                        @if ($backup['eligible'] && $canManagePromotion)
                            <x-filament::button wire:click="cleanupBackup('{{ $backup['backupId'] }}')" wire:confirm="Delete this exact eligible managed backup?" size="xs" color="danger">{{ __('marketplace.backups.cleanup') }}</x-filament::button>
                        @endif
                    </div>
                @endforeach
            @else
                <div class="fi-in-text">{{ __('marketplace.backups.none') }}</div>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('marketplace.stale.heading')" icon="heroicon-o-trash" style="margin-top:1rem">
            @if ($staleRemnants)
                @foreach ($staleRemnants as $item)
                    <div class="marketplace-remnant">
                        <strong>{{ $item['reason'] === 'stale_item_unmanaged' ? 'Виявлено непідтверджені службові дані' : 'Виявлено застарілі службові дані' }}</strong>
                        <p class="fi-in-text" style="font-size:.8rem;color:var(--gray-500)">{{ $item['reason'] === 'stale_item_unmanaged' ? 'Каталог підготовки не пов’язаний із відомою операцією та залишений без змін із міркувань безпеки.' : 'Службові дані збережено до підтвердження безпечного очищення.' }}</p>
                        <div style="display:flex;gap:.35rem;margin-top:.5rem">
                            <x-filament::button wire:click="toggleStaleItem('{{ $item['identifier'] }}')" color="gray" size="xs">Деталі</x-filament::button>
                            @if ($item['eligible'] && $canManagePromotion)
                                <x-filament::button wire:click="cleanupStaleItem('{{ $item['identifier'] }}')" wire:confirm="Видалити ці повторно перевірені службові дані?" size="xs" color="danger">{{ __('marketplace.stale.cleanup') }}</x-filament::button>
                            @endif
                        </div>
                        @if ($expandedStaleItem === $item['identifier'])
                            <div class="fi-in-text" style="font-size:.8rem;margin-top:.75rem">Ідентифікатор: {{ substr($item['identifier'], 0, 12) }}… · тип: {{ $item['kind'] }}</div>
                        @endif
                    </div>
                @endforeach
            @else
                <div class="fi-in-text">{{ __('marketplace.stale.none') }}</div>
            @endif
        </x-filament::section>
        @endif

        @if (in_array($activeTab, ['marketplace', 'installed'], true))
            @if ($activeTab === 'marketplace' && $remoteCount === 0 && $registryPresentationState === 'connected_empty')
                <x-filament::section heading="У Marketplace поки немає опублікованих модулів" icon="heroicon-o-shopping-bag" style="margin-top:1rem;text-align:center">
                    <p class="fi-in-text">Підключення до сервера Marketplace працює, але каталог ще не містить доступних релізів.</p>
                    <div class="fi-in-text" style="font-size:0.8rem;color:var(--gray-500);margin-top:0.75rem">
                        Останнє оновлення: {{ $registryMeta['checked_at'] ?? '—' }} · схема: {{ $registryHeader['schema_version'] ?? '—' }} · кеш: актуальний
                    </div>
                    <div style="margin-top:1rem;display:flex;justify-content:center;gap:0.5rem">
                        @if ($registryEnabled)
                            <x-filament::button wire:click="refreshRegistry" size="sm">Оновити каталог</x-filament::button>
                        @endif
                        <x-filament::button wire:click="setMarketplaceTab('installed')" color="gray" size="sm">Перейти до встановлених</x-filament::button>
                    </div>
                </x-filament::section>
            @elseif ($activeTab === 'marketplace' && $remoteCount === 0)
            @elseif ($activeTab === 'installed' && $installedCount === 0)
                <x-filament::section heading="Встановлених модулів немає" icon="heroicon-o-cube" style="margin-top:1rem">
                    <p class="fi-in-text">Локальний registry не містить підтверджених встановлених addons.</p>
                </x-filament::section>
            @else
                <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;margin-top:1rem">
                    <input class="fi-input" style="max-width:20rem" type="search" wire:model.live.debounce.300ms="search" placeholder="Пошук за назвою, code або видавцем">
                    <select class="fi-select-input" style="max-width:12rem" wire:model.live="filterType"><option value="">Усі типи</option><option value="module">Модулі</option><option value="extension">Розширення</option></select>
                    <select class="fi-select-input" style="max-width:12rem" wire:model.live="filterStatus"><option value="">Усі стани</option>@foreach ($statusOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                    <x-filament::button wire:click="resetFilters" color="gray" size="sm">Скинути</x-filament::button>
                </div>
                <div class="marketplace-table-wrap" style="margin-top:1rem">
                    <table class="fi-ta-table w-full table-auto divide-y divide-gray-200 text-start dark:divide-white/5">
                        <thead><tr><th style="padding:0.75rem">Модуль</th><th>Видавець</th><th>Категорія</th><th>Встановлена версія</th><th>Доступна версія</th><th>Стан</th><th>Дії</th></tr></thead>
                        <tbody>
                        @foreach ($rows as $row)
                            @php
                                $item = $row['item'];
                            @endphp
                            <tr>
                                <td style="padding:0.75rem"><strong>{{ $item->name }}</strong><div style="font-size:0.75rem;color:var(--gray-500)">{{ $item->code }}</div></td>
                                <td>{{ $item->vendor }}</td><td>{{ $item->category ?: '—' }}</td>
                                <td>{{ $row['installed_version'] ?? '—' }}</td><td>{{ $row['remote_version'] ?? '—' }}</td>
                                <td>
                                    <x-filament::badge :color="($row['trust_status'] ?? null) === 'trusted' ? 'success' : (in_array($row['status'], ['enabled','installed'], true) ? 'success' : (($row['status'] ?? null) === 'failed' ? 'danger' : 'gray'))">
                                        {{ ($row['trust_status'] ?? null) === 'trusted' ? 'Довірений' : ($statusLabels[$row['status']] ?? $row['status']) }}
                                    </x-filament::badge>
                                    @if (($row['update_status'] ?? null) === 'update_available') <span style="color:var(--warning-600);font-size:0.75rem">Доступне оновлення</span>@endif
                                    @if (($row['trust_status'] ?? null) === 'trusted') <div style="font-size:0.75rem;color:var(--gray-500)">Встановлення з quarantine буде доступне у наступній фазі.</div>@endif
                                </td>
                                <td style="white-space:nowrap">
                                    @if ($activeTab === 'installed')
                                        @if ((($row['addon'] ?? null)?->is_enabled ?? false))<x-filament::button wire:click="disableAddon('{{ $item->code }}')" color="gray" size="xs">Вимкнути</x-filament::button>@else<x-filament::button wire:click="enableAddon('{{ $item->code }}')" size="xs">Увімкнути</x-filament::button>@endif
                                        <x-filament::button wire:click="toggleDetails('{{ $item->code }}')" color="gray" size="xs">Деталі</x-filament::button>
                                    @elseif (($row['artifact_status'] ?? null) === 'not_downloaded')
                                        <x-filament::button wire:click="downloadArtifact('{{ $item->code }}')" size="xs">Завантажити</x-filament::button>
                                    @elseif (($row['artifact_status'] ?? null) !== 'not_available')
                                        <x-filament::button wire:click="inspectArtifact('{{ $item->code }}')" color="gray" size="xs">Перевірити</x-filament::button>
                                        @if (($row['trust_status'] ?? null) === 'trusted' && ($row['review_status'] ?? null) !== 'approved')
                                            <x-filament::button wire:click="openApproveArtifactModal('{{ $item->code }}')" size="xs">Схвалити</x-filament::button>
                                        @endif
                                        <x-filament::button wire:click="toggleDetails('{{ $item->code }}')" color="gray" size="xs">Деталі</x-filament::button>
                                    @else
                                        <x-filament::button wire:click="toggleDetails('{{ $item->code }}')" color="gray" size="xs">Деталі</x-filament::button>
                                    @endif
                                </td>
                            </tr>
                            @if ($expandedCode === $item->code)
                                <tr><td colspan="7" style="padding:0.75rem;background:var(--gray-50)"><strong>Технічні деталі:</strong> сумісність {{ $row['compatibility_status'] ?? 'unknown' }}; залежності {{ ($row['dependency_issues'] ?? []) === [] ? 'виконані' : implode('; ', $row['dependency_issues']) }}; версія локального пакета {{ $row['local_catalog_version'] ?? '—' }}; дані Marketplace {{ $row['remote_version'] ?? 'відсутні' }}; manifest {{ $item->path ? basename($item->path) : 'відсутній' }}.</td></tr>
                            @endif
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif

        <?php if ($activeTab === 'development') { ?>
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
            class="addon-marketplace__grid"
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
                        $remoteVersion = $row['remote_version'] ?? null;
                        $source = $row['source'] ?? 'local';
                        $assessment = $row['assessment'] ?? [];
                        $actionPolicy = $assessment['actions'] ?? [];
                        $artifact = $row['artifact'] ?? null;
                        $artifactStatus = $row['artifact_status'] ?? 'not_available';
                        $artifactMetadata = $row['artifact_metadata'] ?? null;
                        $downloadsEnabled = (bool) (config('addons-registry.downloads.enabled') ?? false);
                        $platformConstraint = $row['platform_constraint'] ?? null;
                        $isIncompatible = $compat === 'incompatible';
                        $promotionStatus = $row['promotion_status'] ?? 'not_promoted';
                        $promotionLabel = $row['promotion_label'] ?? ($promotionLabels[$promotionStatus] ?? $promotionStatus);
                        $promotionColor = $row['promotion_color'] ?? 'gray';
                        $promotionBlockedReasons = $row['promotion_blocked_reasons'] ?? [];
                        $hasPromotionSnapshot = in_array($promotionStatus, ['promoted', 'stale', 'rollback_available'], true);
                        $hasLiveMismatch = $hasPromotionSnapshot && (
                            ($row['promotion_is_stale'] ?? false)
                            || ! ($row['live_inventory_matches'] ?? true)
                            || collect($row['promotion_diagnostics'] ?? [])->contains(function ($diagnostic): bool {
                                return is_array($diagnostic) && (($diagnostic['code'] ?? null) === 'artifact_promotion_live_fingerprint_mismatch');
                            })
                        );
                        $idempotentReady = ($row['idempotent_ready'] ?? false) && ($row['live_inventory_matches'] ?? false) && $promotionStatus === 'promoted';
                        $canRenderPromoteAction = ($row['promotion_enabled'] ?? false)
                            && $canManagePromotion
                            && ($row['can_promote'] ?? false)
                            && ($row['staging_status'] ?? 'not_staged') === 'staged'
                            && (($row['trust_status'] ?? null) === 'trusted')
                            && (($row['review_status'] ?? null) === 'approved')
                            && ! ($row['approval_is_stale'] ?? false)
                            && ! ($row['staging_is_stale'] ?? false)
                            && ! $idempotentReady
                            && ! $hasLiveMismatch;
                        $canRenderRollbackAction = $canManagePromotion
                            && ($row['can_rollback'] ?? false)
                            && ($row['rollback_available'] ?? false)
                            && in_array($promotionStatus, ['promoted', 'stale', 'rollback_available'], true);
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
                        class="addon-marketplace-card"
                        :heading="$item->name"
                        :description="$item->vendor . ' · ' . $item->code"
                        :icon="$item->icon ?? 'heroicon-o-cube'"
                    >
                        <div class="addon-marketplace-card__body">

                            {{-- Badges --}}
                            <div class="addon-marketplace-card__badges">
                                <x-filament::badge :color="$item->type === 'module' ? 'primary' : 'info'">{{ $typeLabel }}</x-filament::badge>
                                <x-filament::badge :color="$statusColor">{{ $statusLabel }}</x-filament::badge>
                                <x-filament::badge :color="$updateStatusColor">{{ $updateStatusLabel }}</x-filament::badge>
                                <x-filament::badge :color="$compatColor">{{ $compatLabel }}</x-filament::badge>
                                @if ($source === 'local_remote')
                                    <x-filament::badge color="info">Local + Registry</x-filament::badge>
                                @elseif ($source === 'remote')
                                    <x-filament::badge color="warning">Registry</x-filament::badge>
                                @else
                                    <x-filament::badge color="gray">Local</x-filament::badge>
                                @endif
                                <x-filament::badge color="gray">{{ $assessment['versionState'] ?? 'unknown' }}</x-filament::badge>
                                <x-filament::badge :color="($assessment['dependencies']['blocking'] ?? []) === [] ? 'success' : 'danger'">deps: {{ $assessment['dependencies']['state'] ?? 'unknown' }}</x-filament::badge>
                                @if ($item->isFeatured)
                                    <x-filament::badge color="warning">Рекомендовано</x-filament::badge>
                                @endif
                            </div>

                            {{-- Description --}}
                            <p class="fi-in-text addon-marketplace-card__description">{{ $item->description ?: '—' }}</p>

                            {{-- Metadata --}}
                            <dl class="addon-marketplace-card__meta">
                                @if ($installedVersion)
                                    <dt>Встановлено</dt><dd>{{ $installedVersion }}</dd>
                                @endif
                                <dt>Local catalog</dt><dd>{{ $assessment['localCatalogVersion'] ?? '—' }}</dd>
                                <dt>Marketplace</dt><dd>{{ $assessment['remoteVersion'] ?? '—' }}</dd>
                                @if (($assessment['remoteVersion'] ?? null) !== null)
                                    <dt>Registry state</dt><dd>{{ $assessment['registryState'] }}</dd>
                                    <dt>Publisher</dt><dd>{{ $assessment['publisher']['name'] ?? '—' }}</dd>
                                    <dt>Publisher ID</dt><dd>{{ $assessment['publisher']['public_id'] ?? '—' }}</dd>
                                    <dt>Signing key ID</dt><dd>{{ $assessment['signingKeyId'] ?? '—' }} (identifier only)</dd>
                                @endif
                                <dt>Категорія</dt><dd>{{ $item->category ?: '—' }}</dd>
                                <dt>Платформа</dt><dd>{{ $platformConstraint ?: ($item->platformVersion ?: '—') }}</dd>
                            </dl>

                            {{-- Tags --}}
                            @if ($item->tags)
                                <div class="addon-marketplace-card__badges addon-marketplace-card__tags">
                                    @foreach ($item->tags as $tag)
                                        <x-filament::badge color="gray">{{ $tag }}</x-filament::badge>
                                    @endforeach
                                </div>
                            @endif
                            @if (($assessment['dependencies']['nodes'] ?? []) !== [])
                                <div class="fi-in-text" style="margin-top:0.5rem">
                                    <strong>Dependency preflight:</strong>
                                    @foreach ($assessment['dependencies']['nodes'] as $node)
                                        <x-filament::badge :color="$node['blocking'] ? 'danger' : 'success'">{{ $node['code'] }}: {{ $node['state'] }}</x-filament::badge>
                                    @endforeach
                                    @if (($assessment['dependencies']['plan'] ?? []) !== [])
                                        <div>Plan: {{ implode(' → ', $assessment['dependencies']['plan']) }}</div>
                                    @endif
                                    @foreach ($assessment['dependencies']['cycles'] ?? [] as $cycle)<div>Cycle: {{ $cycle }}</div>@endforeach
                                </div>
                            @endif

                            {{-- Dependencies --}}
                            @if ($item->getDependencies())
                                <div class="fi-in-text" style="margin-top:0.5rem">
                                    <strong>Залежності:</strong>
                                    @foreach ($row['dependency_report'] as $code => $report)
                                        @php
                                            $dependencyIssues = $report['issues'] ?? [];
                                            $dependencyColor = $dependencyIssues === [] ? 'gray' : 'danger';
                                            $dependencyLabel = $code.($report['constraint'] !== null && $report['constraint'] !== '' ? ' ('.$report['constraint'].')' : '');
                                        @endphp
                                        <x-filament::badge :color="$dependencyColor">{{ $dependencyLabel }}</x-filament::badge>
                                        @if ($dependencyIssues !== [])
                                            <x-filament::badge color="danger">{{ implode('; ', $dependencyIssues) }}</x-filament::badge>
                                        @endif
                                    @endforeach
                                    @if ($row['can_install_dependencies'] && $row['dependency_report'] !== [])
                                        <x-filament::button
                                            wire:click="installDependencies('{{ e($item->code) }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="installDependencies('{{ e($item->code) }}')"
                                            color="primary"
                                            size="sm"
                                            icon="heroicon-o-arrow-down-tray"
                                        >Встановити залежності</x-filament::button>
                                    @endif
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

                            {{-- Remote-only / artifact notice --}}
                            @if ($status === 'remote_only' || $artifact !== null)
                                <div class="addon-marketplace-artifact">
                                    @if ($status === 'remote_only')
                                        <div class="addon-marketplace-artifact__notice">
                                            Цей addon доступний тільки у registry. Встановлення та оновлення недоступні.
                                        </div>
                                    @endif
                                    @if ($artifact !== null)
                                        <div class="addon-marketplace-artifact__header">
                                            <strong>Artifact</strong>
                                            <x-filament::badge :color="match ($artifactStatus) {
                                                'quarantined' => 'success',
                                                'not_downloaded' => 'gray',
                                                'downloads_disabled' => 'warning',
                                                'rejected' => 'danger',
                                                'failed' => 'danger',
                                                default => 'gray',
                                            }">{{ $statusLabels[$artifactStatus] ?? $artifactStatus }}</x-filament::badge>
                                        </div>
                                        <dl class="addon-marketplace-artifact__meta">
                                            @if ($downloadsEnabled)
                                                <dt>Розмір</dt><dd>{{ number_format($artifact['size'] ?? 0) }} байт</dd>
                                                @if (! empty($artifact['sha256']))
                                                    <dt>SHA-256</dt><dd>{{ Str::substr($artifact['sha256'], 0, 12) }}…</dd>
                                                @endif
                                            @else
                                                <dt>Download</dt><dd>Вимкнено</dd>
                                            @endif
                                        </dl>
                                        @if ($artifactStatus === 'quarantined' && $artifactMetadata !== null)
                                            @php
                                                $signatureStatus = $row['signature_status'] ?? null;
                                                $manifestStatus = $row['manifest_status'] ?? null;
                                                $trustStatus = $row['trust_status'] ?? null;
                                                $reviewStatus = $row['review_status'] ?? null;
                                                $signatureKeyId = $artifactMetadata['signature_key_id'] ?? null;
                                            @endphp
                                            <div class="addon-marketplace-artifact__statuses">
                                                <x-filament::badge class="addon-marketplace-artifact__badge" :color="$this->inspectionColor($signatureStatus, $inspectionColors)">
                                                    {{ match ($signatureStatus) { 'valid' => 'Підпис дійсний', default => $this->inspectionLabel($signatureStatus, $inspectionLabels) } }}
                                                </x-filament::badge>
                                                <x-filament::badge class="addon-marketplace-artifact__badge" :color="$this->inspectionColor($manifestStatus, $inspectionColors)">
                                                    Manifest: {{ match ($manifestStatus) { 'valid' => 'валідний', 'manifest_missing' => 'відсутній', 'manifest_invalid' => 'некоректний', 'identity_mismatch' => 'code/version не збігаються', default => $this->inspectionLabel($manifestStatus, $inspectionLabels) } }}
                                                </x-filament::badge>
                                                <x-filament::badge class="addon-marketplace-artifact__badge" :color="$this->inspectionColor($trustStatus, $inspectionColors)">
                                                    {{ match ($trustStatus) { 'trusted' => 'Довірений', default => $this->inspectionLabel($trustStatus, $inspectionLabels) } }}
                                                </x-filament::badge>
                                                @if ($reviewStatus)
                                                    <x-filament::badge class="addon-marketplace-artifact__badge" :color="$this->inspectionColor($reviewStatus, $inspectionColors)">
                                                        Review: {{ $row['review_label'] ?? $this->inspectionLabel($reviewStatus, $inspectionLabels) }}
                                                    </x-filament::badge>
                                                @endif
                                                @if ($signatureKeyId)
                                                    <x-filament::badge class="addon-marketplace-artifact__badge" color="gray">Key: {{ $signatureKeyId }}</x-filament::badge>
                                                @endif
                                                @if (! empty($artifactMetadata['signing_key_fingerprint']))
                                                    <x-filament::badge class="addon-marketplace-artifact__badge" color="gray">Fingerprint: {{ Str::substr($artifactMetadata['signing_key_fingerprint'], 0, 12) }}…</x-filament::badge>
                                                @endif
                                                @if (! empty($artifactMetadata['local_trust_status']))
                                                    <x-filament::badge class="addon-marketplace-artifact__badge" color="gray">Key status: {{ $artifactMetadata['local_trust_status'] }}</x-filament::badge>
                                                @endif
                                            </div>
                                            <div class="fi-in-text" style="font-size:0.75rem">
                                                Verified: {{ $artifactMetadata['verified_at'] ?? '—' }} ·
                                                Size: {{ number_format($artifactMetadata['actual_size'] ?? $artifactMetadata['size'] ?? 0) }} bytes ·
                                                SHA-256: {{ Str::substr($artifactMetadata['actual_sha256'] ?? $artifactMetadata['sha256'] ?? '', 0, 12) }}…
                                                @if ($artifactMetadata['reused_via_304'] ?? false) · reused via HTTP 304 @endif
                                            </div>
                                            @if ($reviewStatus)
                                                <div class="addon-marketplace-artifact__review">
                                                    <div class="fi-in-text" style="font-size:0.8rem">
                                                        <strong>Review:</strong> {{ $row['review_label'] ?? $reviewStatus }}
                                                        @if (! empty($row['reviewed_by_name']))
                                                            · <strong>Reviewed by:</strong> {{ $row['reviewed_by_name'] }}
                                                        @endif
                                                        @if (! empty($row['reviewed_at']))
                                                            · <strong>Reviewed at:</strong> {{ $row['reviewed_at'] }}
                                                        @endif
                                                    </div>
                                                    @if (! empty($row['review_note']))
                                                        <div class="fi-in-text" style="font-size:0.8rem;margin-top:0.25rem"><strong>Review note:</strong> {{ $row['review_note'] }}</div>
                                                    @endif
                                                    @if ($row['approval_is_stale'] ?? false)
                                                        <div class="fi-in-text" style="font-size:0.8rem;margin-top:0.4rem;color:#b91c1c"><strong>Approval stale:</strong> integrity artifact змінилася після схвалення.</div>
                                                    @endif
                                                    @if (! empty($row['review_blocked_reasons']))
                                                        <ul class="fi-in-text" style="font-size:0.75rem;margin-top:0.4rem;color:#92400e">
                                                            @foreach ($row['review_blocked_reasons'] as $reason)
                                                                <li>{{ $reason }}</li>
                                                            @endforeach
                                                        </ul>
                                                    @endif
                                                    @if (! empty($row['review_history']))
                                                        <details class="addon-marketplace-artifact__details">
                                                            <summary>Історія перевірки ({{ count($row['review_history']) }})</summary>
                                                        <ul class="fi-in-text">
                                                            @foreach ($row['review_history'] as $entry)
                                                                <li>{{ $entry['action'] ?? 'unknown' }} · {{ $entry['actor_name'] ?? '—' }} · {{ $entry['created_at'] ?? '—' }}@if (! empty($entry['note'])) · {{ $entry['note'] }}@endif</li>
                                                            @endforeach
                                                        </ul>
                                                        </details>
                                                    @endif
                                                </div>
                                            @endif
                                            @if ($canReview)
                                                <div class="addon-marketplace-artifact__actions">
                                                    @if ($row['can_approve'] ?? false)
                                                        <x-filament::button wire:click="openApproveArtifactModal('{{ e($item->code) }}')" color="success" size="sm" title="Схвалити artifact" aria-label="Схвалити artifact">Схвалити</x-filament::button>
                                                    @endif
                                                    @if ($row['can_reject'] ?? false)
                                                        <x-filament::button wire:click="openRejectArtifactModal('{{ e($item->code) }}')" color="danger" size="sm" title="Відхилити artifact" aria-label="Відхилити artifact">Відхилити</x-filament::button>
                                                    @endif
                                                    @if ($row['can_revoke'] ?? false)
                                                        <x-filament::button wire:click="openRevokeArtifactModal('{{ e($item->code) }}')" color="warning" size="sm" title="Відкликати схвалення" aria-label="Відкликати схвалення">Відкликати</x-filament::button>
                                                    @endif
                                                </div>
                                            @endif
                                            @if ($trustStatus === 'trusted')
                                                <div class="addon-marketplace-artifact__notice addon-marketplace-artifact__notice--success">
                                                    <div class="fi-in-text" style="font-size:0.8rem;color:#15803d">
                                                        Artifact довірений (Trusted). Встановлення з quarantine буде доступне у наступній фазі.
                                                    </div>
                                                </div>
                                            @elseif ($trustStatus === 'rejected')
                                                <div class="addon-marketplace-artifact__notice addon-marketplace-artifact__notice--danger">
                                                    <div class="fi-in-text" style="font-size:0.8rem;color:#b91c1c">
                                                        Artifact відхилено (Rejected). Причина: {{ implode('; ', $artifactMetadata['artifact_diagnostics'] ?? []) ?: 'Невідома' }}.
                                                        Встановлення заблоковано.
                                                    </div>
                                                </div>
                                            @endif
                                            @if (($row['promotion_status'] ?? null) === 'promoted' && $canManagePromotion)
                                                <div class="addon-marketplace-artifact__actions">
                                                    <x-filament::button wire:click="installVerifiedArtifact('{{ e($item->code) }}')" color="success" size="sm">
                                                        {{ ($row['addon']?->is_installed ?? false) ? 'Оновити addon' : 'Встановити addon' }}
                                                    </x-filament::button>
                                                </div>
                                            @endif
                                            @if (! empty($row['install_operation_state']))
                                                <div class="fi-in-text" style="font-size:0.8rem">
                                                    Install operation: <strong>{{ $row['install_operation_state'] }}</strong>
                                                    · {{ $row['install_operation_previous_version'] ?? 'new' }} → {{ $row['install_operation_target_version'] ?? '—' }}
                                                    @if (! empty($row['install_operation_failure_code']))
                                                        · {{ $row['install_operation_failure_code'] }}: {{ implode('; ', $row['install_operation_diagnostics'] ?? []) }}
                                                    @endif
                                                </div>
                                            @endif
                                            <div class="addon-marketplace-staging">
                                                <div class="addon-marketplace-staging__header">
                                                    <strong>Staging</strong>
                                                    <x-filament::badge :color="$row['staging_color'] ?? 'gray'">{{ $row['staging_label'] ?? 'Не підготовлено' }}</x-filament::badge>
                                                </div>
                                                @if (! ($row['staging_enabled'] ?? false))
                                                    <p>Staging вимкнено в конфігурації.</p>
                                                @elseif ($row['staging_is_stale'] ?? false)
                                                    <p class="addon-marketplace-staging__danger">Artifact, review або integrity snapshot змінилися після підготовки. Використання staging-копії заблоковане.</p>
                                                @elseif (($row['staging_status'] ?? 'not_staged') === 'staged')
                                                    <dl class="addon-marketplace-staging__meta">
                                                        <dt>Файлів</dt><dd>{{ $row['staging_file_count'] ?? 0 }}</dd>
                                                        <dt>Розмір</dt><dd>{{ number_format($row['staging_total_size'] ?? 0) }} байт</dd>
                                                        <dt>Підготував</dt><dd>{{ $row['staged_by_name'] ?? '—' }}</dd>
                                                        <dt>Дата</dt><dd>{{ $row['staged_at'] ?? '—' }}</dd>
                                                    </dl>
                                                @elseif ($row['can_stage'] ?? false)
                                                    <p>Artifact відповідає вимогам для staging.</p>
                                                @endif
                                                @if (! empty($row['stage_blocked_reasons']) && ! ($row['can_stage'] ?? false))
                                                    <ul>
                                                        @foreach ($row['stage_blocked_reasons'] as $reason)<li>{{ $reason }}</li>@endforeach
                                                    </ul>
                                                @endif
                                                @if ($canManageStaging)
                                                    <div class="addon-marketplace-staging__actions">
                                                        @if (($row['staging_enabled'] ?? false) && ($row['can_stage'] ?? false))
                                                            <x-filament::button wire:click="openStageArtifactModal('{{ e($item->code) }}')" color="success" size="sm">Підготувати у staging</x-filament::button>
                                                        @endif
                                                        @if ($row['can_unstage'] ?? false)
                                                            <x-filament::button wire:click="openUnstageArtifactModal('{{ e($item->code) }}')" color="danger" size="sm">Видалити зі staging</x-filament::button>
                                                        @endif
                                                    </div>
                                                @endif
                                            </div>
                                            <div class="addon-marketplace-promotion">
                                                <div class="addon-marketplace-promotion__header">
                                                    <strong>Promotion</strong>
                                                    <x-filament::badge :color="$promotionColor">{{ $promotionLabel }}</x-filament::badge>
                                                </div>

                                                <div class="addon-marketplace-promotion__summary">
                                                    @if ($promotionStatus === 'not_promoted')
                                                        <div>Live directory: Не перенесено</div>
                                                    @elseif ($promotionStatus === 'ready')
                                                        <div>Artifact готовий до безпечного перенесення.</div>
                                                    @elseif ($promotionStatus === 'rolled_back')
                                                        <div>Live directory: Відкочено</div>
                                                    @elseif ($hasLiveMismatch)
                                                        <div>Live directory: Live-копія змінена</div>
                                                    @else
                                                        <div>Live directory: Файли перенесено</div>
                                                    @endif
                                                    <div class="addon-marketplace-promotion__meta">
                                                        <span>Version: {{ $row['promoted_version'] ?? '—' }}</span>
                                                        <span>Transaction: {{ $row['promotion_transaction_id'] ? Str::limit($row['promotion_transaction_id'], 12, '…') : '—' }}</span>
                                                        <span>Переніс: {{ $row['promoted_by_name'] ?? '—' }}</span>
                                                        <span>Дата: {{ $row['promoted_at'] ?? '—' }}</span>
                                                        <span>Rollback: {{ ($row['rollback_available'] ?? false) ? 'доступний' : 'недоступний' }}</span>
                                                    </div>
                                                </div>

                                                @if ($idempotentReady)
                                                    <div class="addon-marketplace-promotion__warnings addon-marketplace-promotion__warnings--info">
                                                        <p>Ця версія вже перенесена у live directory. Повторна операція не потрібна.</p>
                                                    </div>
                                                @endif

                                                @if ($hasLiveMismatch)
                                                    <div class="addon-marketplace-promotion__warnings addon-marketplace-promotion__warnings--danger">
                                                        <p>Live directory була змінена після перенесення. Автоматичне повторне перенесення заблоковане, щоб не затерти ручні зміни.</p>
                                                        <div class="addon-marketplace-promotion__meta">
                                                            <span>Expected inventory: {{ $row['promotion_inventory_hash'] ?? '—' }}</span>
                                                            <span>Current live inventory: {{ $row['current_live_inventory_hash'] ?? '—' }}</span>
                                                        </div>
                                                    </div>
                                                @endif

                                                @if (! empty($promotionBlockedReasons))
                                                    <div class="addon-marketplace-promotion__warnings">
                                                        <ul>
                                                            @foreach ($promotionBlockedReasons as $reason)
                                                                <li>{{ $reason }}</li>
                                                            @endforeach
                                                        </ul>
                                                    </div>
                                                @endif

                                                <div class="addon-marketplace-promotion__actions">
                                                    @if ($canRenderPromoteAction)
                                                        <x-filament::button wire:click="openPromoteArtifactModal('{{ e($item->code) }}')" color="success" size="sm">
                                                            Перенести у live directory
                                                        </x-filament::button>
                                                    @endif
                                                    @if ($canRenderRollbackAction)
                                                        <x-filament::button wire:click="openRollbackArtifactModal('{{ e($item->code) }}')" color="danger" size="sm">
                                                            Виконати rollback
                                                        </x-filament::button>
                                                    @endif
                                                </div>

                                                @if (($row['promotion_status'] ?? 'not_promoted') === 'promoted')
                                                    <div class="addon-marketplace-promotion__warnings addon-marketplace-promotion__warnings--info">
                                                        Файли присутні у live directory, але addon ще не зареєстрований і не активований.
                                                    </div>
                                                @endif
                                            </div>
                                            <div class="addon-marketplace-artifact__actions">
                                                <x-filament::button
                                                    wire:click="inspectArtifact('{{ e($item->code) }}')"
                                                    wire:loading.attr="disabled"
                                                    wire:target="inspectArtifact('{{ e($item->code) }}')"
                                                    color="info"
                                                    size="sm"
                                                    icon="heroicon-o-magnifying-glass"
                                                    title="Перевірити artifact"
                                                    aria-label="Перевірити artifact"
                                                >Перевірити</x-filament::button>
                                            </div>
                                            <details class="addon-marketplace-artifact__details">
                                                <summary>Технічні дані</summary>
                                                <dl class="addon-marketplace-card__meta addon-marketplace-card__technical">
                                                    <dt>Code</dt><dd>{{ $item->code }}</dd>
                                                    <dt>Key</dt><dd>{{ $signatureKeyId ?: '—' }}</dd>
                                                    <dt>SHA-256</dt><dd>{{ $artifactMetadata['sha256'] ?? ($artifact['sha256'] ?? '—') }}</dd>
                                                    <dt>Inventory hash</dt><dd>{{ $row['staging_inventory_hash'] ?? '—' }}</dd>
                                                    <dt>Staging SHA-256</dt><dd>{{ $row['staging_artifact_sha256'] ?? '—' }}</dd>
                                                    <dt>Approval snapshot</dt><dd>{{ $row['approval_snapshot_hash'] ?? '—' }}</dd>
                                                    <dt>Transaction ID</dt><dd>{{ $row['promotion_transaction_id'] ?? '—' }}</dd>
                                                    <dt>Promotion inventory hash</dt><dd>{{ $row['promotion_inventory_hash'] ?? '—' }}</dd>
                                                    <dt>Current live hash</dt><dd>{{ $row['current_live_inventory_hash'] ?? '—' }}</dd>
                                                    <dt>Source artifact SHA</dt><dd>{{ $row['promotion_source_artifact_sha256'] ?? '—' }}</dd>
                                                    <dt>Last rollback transaction</dt><dd>{{ $row['last_rollback_transaction_id'] ?? '—' }}</dd>
                                                    <dt>Promotion diagnostics</dt><dd>{{ json_encode($row['promotion_diagnostics'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</dd>
                                                </dl>
                                            </details>
                                        @endif
                                        @if (($actionPolicy['download']['allowed'] ?? false) && in_array($artifactStatus, ['not_downloaded', 'rejected', 'failed'], true))
                                            <div class="addon-marketplace-artifact__actions">
                                                <x-filament::button
                                                    wire:click="downloadArtifact('{{ e($item->code) }}')"
                                                    wire:loading.attr="disabled"
                                                    wire:target="downloadArtifact('{{ e($item->code) }}')"
                                                    color="primary"
                                                    size="sm"
                                                    icon="heroicon-o-arrow-down-tray"
                                                    title="Завантажити artifact"
                                                    aria-label="Завантажити artifact"
                                                >Завантажити</x-filament::button>
                                            </div>
                                        @elseif ($downloadsEnabled && ! ($actionPolicy['download']['allowed'] ?? false))
                                            <div class="fi-in-text" style="font-size:0.8rem;color:#b91c1c">Download blocked: {{ $actionPolicy['download']['reason'] ?? 'Дію заблоковано.' }}</div>
                                        @endif
                                    @endif
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
                            @if (($assessment['diagnostics'] ?? []) !== [])
                                <ul class="fi-in-text" style="margin-top:0.5rem;color:#b91c1c">
                                    @foreach ($assessment['diagnostics'] as $diagnostic)<li>{{ $diagnostic }}</li>@endforeach
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
                            <div class="addon-marketplace-card__actions addon-marketplace-card__footer">
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

        <?php } ?>

        @if ($reviewModalOpen)
            <div role="dialog" aria-modal="true" class="addon-marketplace-review-modal">
                <div class="addon-marketplace-review-modal__window">
                    <h2 style="font-size:1.1rem;font-weight:700">
                        {{ match ($reviewAction) { 'approve' => 'Схвалити artifact', 'reject' => 'Відхилити artifact', 'revoke' => 'Відкликати схвалення', default => 'Review artifact' } }}
                    </h2>
                    <p class="addon-marketplace-review-modal__code">Code: <strong>{{ $reviewingArtifactCode }}</strong></p>
                    <label style="display:block;margin-top:1rem;font-weight:600" for="review-note">
                        {{ $reviewAction === 'reject' ? 'Причина відхилення' : 'Review note (необов’язково)' }}
                    </label>
                    <textarea id="review-note" wire:model="reviewNote" maxlength="2000" rows="4" class="addon-marketplace-review-modal__textarea"></textarea>
                    @error('reviewNote') <p style="color:#b91c1c;font-size:0.8rem">{{ $message }}</p> @enderror
                    <p style="margin-top:0.75rem;font-size:0.8rem;color:#92400e">Artifact не буде встановлено, розпаковано або виконано.</p>
                    <div class="addon-marketplace-review-modal__actions">
                        <x-filament::button wire:click="closeReviewModal" color="gray">Скасувати</x-filament::button>
                        @if ($reviewAction === 'approve')
                            <x-filament::button wire:click="approveArtifact" color="success">Підтвердити схвалення</x-filament::button>
                        @elseif ($reviewAction === 'reject')
                            <x-filament::button wire:click="rejectArtifact" color="danger">Підтвердити відхилення</x-filament::button>
                        @elseif ($reviewAction === 'revoke')
                            <x-filament::button wire:click="revokeArtifactApproval" color="warning">Підтвердити відкликання</x-filament::button>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        @if ($stagingModalOpen)
            <div role="dialog" aria-modal="true" class="addon-marketplace-staging-modal">
                <div class="addon-marketplace-staging-modal__window">
                    <h2>{{ $stagingAction === 'stage' ? 'Підготувати artifact у staging' : 'Видалити artifact зі staging' }}</h2>
                    <p>Code: <strong>{{ $stagingArtifactCode }}</strong></p>
                    @if ($stagingAction === 'stage')
                        <p>Staging не встановлює addon і не змінює modules/extensions. Файли будуть розпаковані лише у захищену staging-директорію.</p>
                    @else
                        <p>Буде видалена лише staging-копія. Quarantine ZIP, trust, review status та review history залишаться без змін.</p>
                        <label for="staging-note">Примітка</label>
                        <textarea id="staging-note" wire:model="stagingNote" maxlength="2000" rows="3"></textarea>
                    @endif
                    <div class="addon-marketplace-staging-modal__actions">
                        <x-filament::button wire:click="closeStagingModal" color="gray">Скасувати</x-filament::button>
                        @if ($stagingAction === 'stage')
                            <x-filament::button wire:click="stageArtifact" color="success">Підготувати у staging</x-filament::button>
                        @else
                            <x-filament::button wire:click="unstageArtifact" color="danger">Видалити зі staging</x-filament::button>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        @if ($promotionModalOpen)
            <div role="dialog" aria-modal="true" class="addon-marketplace-promotion-modal">
                <div class="addon-marketplace-promotion-modal__window">
                    <h2>{{ $promotionAction === 'rollback' ? 'Відкотити перенесення artifact' : 'Перенести artifact у live directory' }}</h2>
                    <div class="addon-marketplace-promotion__meta">
                        <span>Code: {{ $promotionModalData['code'] ?? ($promotionArtifactCode ?: '—') }}</span>
                        <span>Version: {{ $promotionModalData['version'] ?? '—' }}</span>
                        <span>Type: {{ $promotionModalData['type'] ?? '—' }}</span>
                        <span>Destination: {{ $promotionModalData['destination'] ?? '—' }}</span>
                        <span>Trust: {{ $inspectionLabels[$promotionModalData['trust_status'] ?? 'not_required'] ?? ($promotionModalData['trust_status'] ?? '—') }}</span>
                        <span>Review: {{ $reviewLabels[$promotionModalData['review_status'] ?? 'pending'] ?? ($promotionModalData['review_status'] ?? '—') }}</span>
                        <span>Staging: {{ $promotionModalData['staging_label'] ?? ($promotionModalData['staging_status'] ?? '—') }}</span>
                        <span>Stale flags: {{ ($promotionModalData['has_live_mismatch'] ?? false) ? 'live_mismatch' : 'none' }}</span>
                    </div>

                    @if ($promotionModalData['has_live_mismatch'] ?? false)
                        <div class="addon-marketplace-promotion__warnings addon-marketplace-promotion__warnings--danger">
                            <p>Live directory була змінена після перенесення. Автоматичне повторне перенесення заблоковане, щоб не затерти ручні зміни.</p>
                        </div>
                    @endif

                    @if (! empty($promotionModalData['blocked_reasons'] ?? []))
                        <div class="addon-marketplace-promotion__warnings">
                            <ul>
                                @foreach (($promotionModalData['blocked_reasons'] ?? []) as $reason)
                                    <li>{{ $reason }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if ($promotionAction === 'rollback')
                        <p>Попередню live-версію буде відновлено з перевіреного backup. Quarantine, staging, trust і review history залишаться без змін.</p>
                        <p>Rollback не запускає discover/install/enable, provider execution, migrations, composer або npm.</p>
                        <div class="addon-marketplace-promotion__meta">
                            <span>Transaction: {{ $promotionTransactionId ?: ($promotionModalData['transaction_id'] ?? '—') }}</span>
                            <span>Rollback mode: {{ ($promotionModalData['backup_path'] ?? null) ? 'update' : 'first_install' }}</span>
                            <span>Promoted by: {{ $promotionModalData['promoted_by_name'] ?? '—' }}</span>
                            <span>Promoted at: {{ $promotionModalData['promoted_at'] ?? '—' }}</span>
                            <span>Live inventory match: {{ ($promotionModalData['live_inventory_matches'] ?? false) ? 'yes' : 'no' }}</span>
                        </div>
                        <label for="promotion-note">Примітка до відкату</label>
                        <textarea id="promotion-note" wire:model="promotionNote" maxlength="2000" rows="4"></textarea>
                        @error('promotionNote') <p style="color:#b91c1c;font-size:0.8rem">{{ $message }}</p> @enderror
                    @else
                        <p>Ця операція лише переносить перевірені файли у live addon directory. Addon не буде автоматично discovered, installed або enabled. Provider і migrations не запускатимуться.</p>
                    @endif
                    <div class="addon-marketplace-promotion-modal__actions">
                        <x-filament::button wire:click="closePromotionModal" color="gray">Скасувати</x-filament::button>
                        @if ($promotionAction === 'rollback')
                            <x-filament::button wire:click="rollbackArtifact" color="danger">Виконати відкат</x-filament::button>
                        @else
                            <x-filament::button wire:click="promoteArtifact" color="success">Перенести файли</x-filament::button>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
