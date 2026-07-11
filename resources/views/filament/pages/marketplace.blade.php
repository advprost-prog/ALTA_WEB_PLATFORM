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
            @php
                $registryConfig = config('addons-registry', []);
                $registryEnabled = (bool) ($registryConfig['enabled'] ?? false);
            @endphp
            @if ($registryEnabled)
                <x-filament::button wire:click="refreshRegistry" icon="heroicon-o-cloud-arrow-down" size="sm" color="gray" style="margin-left:0.5rem">
                    Оновити registry
                </x-filament::button>
            @endif
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
                        $remoteVersion = $row['remote_version'] ?? null;
                        $source = $row['source'] ?? 'local';
                        $artifact = $row['artifact'] ?? null;
                        $artifactStatus = $row['artifact_status'] ?? 'not_available';
                        $artifactMetadata = $row['artifact_metadata'] ?? null;
                        $downloadsEnabled = (bool) (config('addons-registry.downloads.enabled') ?? false);
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
                                @if ($source === 'local_remote')
                                    <x-filament::badge color="info">Local + Registry</x-filament::badge>
                                @elseif ($source === 'remote')
                                    <x-filament::badge color="warning">Registry</x-filament::badge>
                                @endif
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
                                @endif
                                @if ($availableVersion)
                                    · Доступно: <strong>{{ $availableVersion }}</strong>
                                @endif
                                @if ($remoteVersion && $remoteVersion !== $availableVersion)
                                    · У registry: <strong>{{ $remoteVersion }}</strong>
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
                                <div class="fi-callout" style="margin-top:0.5rem;padding:0.5rem;--ctn-color:var(--warning-600)">
                                    @if ($status === 'remote_only')
                                        <div class="fi-in-text" style="font-size:0.8rem;color:#92400e">
                                            Цей addon доступний тільки у registry (remote-only). Встановлення/оновлення недоступні.
                                        </div>
                                    @endif
                                    @if ($artifact !== null)
                                        <div class="fi-in-text" style="font-size:0.8rem;margin-top:0.25rem">
                                            <x-filament::badge :color="match ($artifactStatus) {
                                                'quarantined' => 'success',
                                                'not_downloaded' => 'gray',
                                                'downloads_disabled' => 'warning',
                                                'rejected' => 'danger',
                                                'failed' => 'danger',
                                                default => 'gray',
                                            }">{{ $statusLabels[$artifactStatus] ?? $artifactStatus }}</x-filament::badge>
                                            @if ($downloadsEnabled)
                                                · Розмір: <strong>{{ number_format($artifact['size'] ?? 0) }}</strong> байт
                                                @if (! empty($artifact['sha256']))
                                                    · SHA256: <strong>{{ Str::substr($artifact['sha256'], 0, 12) }}…</strong>
                                                @endif
                                            @else
                                                · Завантаження вимкнено (ADDONS_REGISTRY_DOWNLOADS_ENABLED=false)
                                            @endif
                                        </div>
                                        @if ($downloadsEnabled && in_array($artifactStatus, ['not_downloaded', 'rejected', 'failed'], true))
                                            <div style="margin-top:0.5rem">
                                                <x-filament::button
                                                    wire:click="downloadArtifact('{{ e($item->code) }}')"
                                                    wire:loading.attr="disabled"
                                                    wire:target="downloadArtifact('{{ e($item->code) }}')"
                                                    color="primary"
                                                    size="sm"
                                                    icon="heroicon-o-arrow-down-tray"
                                                >Завантажити artifact</x-filament::button>
                                            </div>
                                        @endif
                                        @if ($artifactStatus === 'quarantined' && $artifactMetadata !== null)
                                            @php
                                                $signatureStatus = $row['signature_status'] ?? null;
                                                $manifestStatus = $row['manifest_status'] ?? null;
                                                $trustStatus = $row['trust_status'] ?? null;
                                                $reviewStatus = $row['review_status'] ?? null;
                                                $signatureKeyId = $artifactMetadata['signature_key_id'] ?? null;
                                            @endphp
                                            <div class="fi-in-text" style="font-size:0.75rem;margin-top:0.25rem;font-family:var(--mono-font-family),monospace">
                                                {{ $artifactMetadata['status'] ?? 'quarantined' }}
                                                @if (! empty($artifactMetadata['path']))
                                                    · {{ $artifactMetadata['path'] }}
                                                @endif
                                            </div>
                                            <div style="margin-top:0.5rem;display:flex;flex-wrap:wrap;gap:0.35rem">
                                                <x-filament::badge :color="$this->inspectionColor($signatureStatus, $inspectionColors)">
                                                    Підпис: {{ $this->inspectionLabel($signatureStatus, $inspectionLabels) }}
                                                </x-filament::badge>
                                                <x-filament::badge :color="$this->inspectionColor($manifestStatus, $inspectionColors)">
                                                    Manifest: {{ $this->inspectionLabel($manifestStatus, $inspectionLabels) }}
                                                </x-filament::badge>
                                                <x-filament::badge :color="$this->inspectionColor($trustStatus, $inspectionColors)">
                                                    Trust: {{ $this->inspectionLabel($trustStatus, $inspectionLabels) }}
                                                </x-filament::badge>
                                                @if ($reviewStatus)
                                                    <x-filament::badge :color="$this->inspectionColor($reviewStatus, $inspectionColors)">
                                                        Review: {{ $this->inspectionLabel($reviewStatus, $inspectionLabels) }}
                                                    </x-filament::badge>
                                                @endif
                                                @if ($signatureKeyId)
                                                    <x-filament::badge color="gray">Key: {{ $signatureKeyId }}</x-filament::badge>
                                                @endif
                                            </div>
                                            @if ($reviewStatus)
                                                <div class="fi-callout" style="margin-top:0.5rem;padding:0.65rem">
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
                                                        <div class="fi-in-text" style="font-size:0.75rem;margin-top:0.4rem"><strong>Review history</strong></div>
                                                        <ul class="fi-in-text" style="font-size:0.75rem">
                                                            @foreach ($row['review_history'] as $entry)
                                                                <li>{{ $entry['action'] ?? 'unknown' }} · {{ $entry['actor_name'] ?? '—' }} · {{ $entry['created_at'] ?? '—' }}@if (! empty($entry['note'])) · {{ $entry['note'] }}@endif</li>
                                                            @endforeach
                                                        </ul>
                                                    @endif
                                                </div>
                                            @endif
                                            @if ($canReview)
                                                <div style="margin-top:0.5rem;display:flex;gap:0.35rem;flex-wrap:wrap">
                                                    @if ($row['can_approve'] ?? false)
                                                        <x-filament::button wire:click="openApproveArtifactModal('{{ e($item->code) }}')" color="success" size="sm">Схвалити artifact</x-filament::button>
                                                    @endif
                                                    @if ($row['can_reject'] ?? false)
                                                        <x-filament::button wire:click="openRejectArtifactModal('{{ e($item->code) }}')" color="danger" size="sm">Відхилити artifact</x-filament::button>
                                                    @endif
                                                    @if ($row['can_revoke'] ?? false)
                                                        <x-filament::button wire:click="openRevokeArtifactModal('{{ e($item->code) }}')" color="warning" size="sm">Відкликати схвалення</x-filament::button>
                                                    @endif
                                                </div>
                                            @endif
                                            @if ($trustStatus === 'trusted')
                                                <div class="fi-callout" style="margin-top:0.5rem;padding:0.5rem;--ctn-color:var(--success-600)">
                                                    <div class="fi-in-text" style="font-size:0.8rem;color:#15803d">
                                                        Artifact довірений (Trusted). Встановлення з quarantine буде доступне у наступній фазі.
                                                    </div>
                                                </div>
                                            @elseif ($trustStatus === 'rejected')
                                                <div class="fi-callout" style="margin-top:0.5rem;padding:0.5rem;--ctn-color:var(--danger-600)">
                                                    <div class="fi-in-text" style="font-size:0.8rem;color:#b91c1c">
                                                        Artifact відхилено (Rejected). Причина: {{ implode('; ', $artifactMetadata['artifact_diagnostics'] ?? []) ?: 'Невідома' }}.
                                                        Встановлення заблоковано.
                                                    </div>
                                                </div>
                                            @endif
                                            <div style="margin-top:0.5rem">
                                                <x-filament::button
                                                    wire:click="inspectArtifact('{{ e($item->code) }}')"
                                                    wire:loading.attr="disabled"
                                                    wire:target="inspectArtifact('{{ e($item->code) }}')"
                                                    color="info"
                                                    size="sm"
                                                    icon="heroicon-o-magnifying-glass"
                                                >Перевірити artifact</x-filament::button>
                                            </div>
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

        @if ($reviewModalOpen)
            <div role="dialog" aria-modal="true" class="fi-modal-window" style="position:fixed;inset:0;z-index:50;display:grid;place-items:center;background:rgba(0,0,0,.45);padding:1rem">
                <div style="width:min(36rem,100%);border-radius:0.75rem;background:white;padding:1.25rem;box-shadow:0 20px 50px rgba(0,0,0,.25)">
                    <h2 style="font-size:1.1rem;font-weight:700">
                        {{ match ($reviewAction) { 'approve' => 'Схвалити artifact', 'reject' => 'Відхилити artifact', 'revoke' => 'Відкликати схвалення', default => 'Review artifact' } }}
                    </h2>
                    <p style="margin-top:0.4rem">Code: <strong>{{ $reviewingArtifactCode }}</strong></p>
                    <label style="display:block;margin-top:1rem;font-weight:600" for="review-note">
                        {{ $reviewAction === 'reject' ? 'Причина відхилення' : 'Review note (необов’язково)' }}
                    </label>
                    <textarea id="review-note" wire:model="reviewNote" maxlength="2000" rows="4" style="margin-top:0.35rem;width:100%;border:1px solid #d1d5db;border-radius:0.5rem;padding:0.65rem"></textarea>
                    @error('reviewNote') <p style="color:#b91c1c;font-size:0.8rem">{{ $message }}</p> @enderror
                    <p style="margin-top:0.75rem;font-size:0.8rem;color:#92400e">Artifact не буде встановлено, розпаковано або виконано.</p>
                    <div style="margin-top:1rem;display:flex;justify-content:flex-end;gap:0.5rem">
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
    </div>
</x-filament-panels::page>
