<x-filament-panels::page>
    <div class="addon-marketplace">

        {{-- Header --}}
        <x-filament::section
            :aside="true"
            heading="Marketplace модулів"
            description="Керування локальними модулями та розширеннями платформи."
            icon="heroicon-o-squares-2x2"
        >
            <div class="addon-marketplace__toolbar">
            <x-filament::button wire:click="rescan" icon="heroicon-o-arrow-path" size="sm">
                Discover / rescan
            </x-filament::button>
            @php
                $registryConfig = config('addons-registry', []);
                $registryEnabled = (bool) ($registryConfig['enabled'] ?? false);
            @endphp
            @if ($registryEnabled)
                <x-filament::button wire:click="refreshRegistry" icon="heroicon-o-cloud-arrow-down" size="sm" color="gray">
                    Оновити registry
                </x-filament::button>
            @endif
            </div>
        </x-filament::section>

        @if ($registryEnabled)
            <x-filament::section heading="Registry: {{ $registryState }}" icon="heroicon-o-cloud" style="margin-top:1rem">
                <div class="fi-in-text" style="font-size:0.875rem">
                    <div><strong>Host:</strong> {{ $registryMeta['source_host'] ?? '—' }}</div>
                    <div><strong>Last success:</strong> {{ $registryMeta['last_successful_refresh_at'] ?? '—' }}</div>
                    <div><strong>Last check:</strong> {{ $registryMeta['checked_at'] ?? '—' }}</div>
                    <div><strong>Application:</strong> {{ $registryHeader['application_version'] ?? '—' }}</div>
                    <div><strong>Build:</strong> {{ $registryHeader['build_version'] ?? '—' }}</div>
                    <div><strong>Schema:</strong> {{ $registryHeader['schema_version'] ?? '—' }}</div>
                    @if (! empty($registryMeta['last_error']))<div><strong>Error:</strong> {{ $registryMeta['last_error'] }}</div>@endif
                </div>
            </x-filament::section>
        @endif

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
                                @endif
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
                                @if ($availableVersion)
                                    <dt>Доступно</dt><dd>{{ $availableVersion }}</dd>
                                @endif
                                @if ($remoteVersion && $remoteVersion !== $availableVersion)
                                    <dt>Registry</dt><dd>{{ $remoteVersion }}</dd>
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
                                                    <dt>Quarantine</dt><dd>{{ $artifactMetadata['path'] ?? '—' }}</dd>
                                                    <dt>Staging path</dt><dd>{{ $row['staging_path'] ?? '—' }}</dd>
                                                    <dt>Inventory hash</dt><dd>{{ $row['staging_inventory_hash'] ?? '—' }}</dd>
                                                    <dt>Staging SHA-256</dt><dd>{{ $row['staging_artifact_sha256'] ?? '—' }}</dd>
                                                    <dt>Approval snapshot</dt><dd>{{ $row['approval_snapshot_hash'] ?? '—' }}</dd>
                                                    <dt>Live path</dt><dd>{{ $row['promotion_live_path'] ?? '—' }}</dd>
                                                    <dt>Backup path</dt><dd>{{ $row['promotion_backup_path'] ?? '—' }}</dd>
                                                    <dt>Transaction ID</dt><dd>{{ $row['promotion_transaction_id'] ?? '—' }}</dd>
                                                    <dt>Promotion inventory hash</dt><dd>{{ $row['promotion_inventory_hash'] ?? '—' }}</dd>
                                                    <dt>Current live hash</dt><dd>{{ $row['current_live_inventory_hash'] ?? '—' }}</dd>
                                                    <dt>Source artifact SHA</dt><dd>{{ $row['promotion_source_artifact_sha256'] ?? '—' }}</dd>
                                                    <dt>Last rollback transaction</dt><dd>{{ $row['last_rollback_transaction_id'] ?? '—' }}</dd>
                                                    <dt>Promotion diagnostics</dt><dd>{{ json_encode($row['promotion_diagnostics'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</dd>
                                                </dl>
                                            </details>
                                        @endif
                                        @if ($downloadsEnabled && in_array($artifactStatus, ['not_downloaded', 'rejected', 'failed'], true))
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
                        <span>Live path: {{ $promotionModalData['live_path'] ?? '—' }}</span>
                        <span>Backup path: {{ $promotionModalData['backup_path'] ?? '—' }}</span>
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
