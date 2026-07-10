<?php

namespace App\Filament\Pages;

use App\Support\Addons\Marketplace\CompatibilityStatus;
use App\Support\Addons\Marketplace\MarketplaceItem;
use App\Support\Addons\Marketplace\MarketplaceManager;
use App\Support\Addons\Marketplace\MarketplaceStatus;
use App\Support\Addons\Marketplace\UpdateStatus;
use App\Support\Addons\Registry\RegistryCatalog;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use RuntimeException;
use UnitEnum;

class Marketplace extends Page
{
    protected static ?string $title = 'Marketplace модулів';

    protected static ?string $navigationLabel = 'Marketplace';

    protected static string|UnitEnum|null $navigationGroup = 'Система';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?int $navigationSort = 91;

    protected string $view = 'filament.pages.marketplace';

    public ?string $filterType = null;

    public ?string $filterStatus = null;

    public ?string $filterCategory = null;

    public ?string $filterVendor = null;

    public ?string $filterFeatured = null;

    public ?string $expandedCode = null;

    public function mount(): void
    {
        //
    }

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        $resolved = app(MarketplaceManager::class)->resolve();
        $rows = $this->applyFilters($resolved['rows']);

        return [
            'rows' => $rows,
            'diagnostics' => $resolved['diagnostics'],
            'warnings' => $resolved['warnings'],
            'statusLabels' => MarketplaceStatus::LABELS,
            'updateStatusLabels' => UpdateStatus::LABELS,
            'compatibilityLabels' => CompatibilityStatus::LABELS,
            'typeOptions' => $this->uniqueOptions($resolved['rows'], fn (MarketplaceItem $i): string => $i->type),
            'statusOptions' => MarketplaceStatus::LABELS,
            'categoryOptions' => $this->uniqueOptions($resolved['rows'], fn (MarketplaceItem $i): string => $i->category),
            'vendorOptions' => $this->uniqueOptions($resolved['rows'], fn (MarketplaceItem $i): string => $i->vendor),
            'featuredOptions' => ['1' => 'Так', '0' => 'Ні'],
            'summary' => $this->buildSummary($rows),
            'statusColors' => $this->getStatusColors(),
            'updateStatusColors' => UpdateStatus::COLORS,
            'compatibilityColors' => CompatibilityStatus::COLORS,
            'inspectionLabels' => $this->getInspectionLabels(),
            'inspectionColors' => $this->getInspectionColors(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{label: string, value: int, description: string}>
     */
    private function buildSummary(array $rows): array
    {
        $enabled = 0;
        $installed = 0;
        $needsAttention = 0;

        foreach ($rows as $row) {
            $status = $row['status'];

            if ($status === 'enabled') {
                $enabled++;
            }

            if (in_array($status, ['installed', 'enabled', 'disabled'], true)) {
                $installed++;
            }

            $canEnable = in_array('enable', $row['actions'], true);
            $dependencyBlocked = $canEnable && $row['dependency_issues'] !== [];

            if (in_array($status, ['missing_files', 'invalid', 'failed'], true) || $row['warnings'] !== [] || $dependencyBlocked) {
                $needsAttention++;
            }
        }

        return [
            ['label' => 'Всього позицій', 'value' => count($rows), 'description' => 'У каталозі Marketplace'],
            ['label' => 'Увімкнено', 'value' => $enabled, 'description' => 'Активні модулі та розширення'],
            ['label' => 'Встановлено', 'value' => $installed, 'description' => 'Встановлені локально'],
            ['label' => 'Потребують уваги', 'value' => $needsAttention, 'description' => 'Помилки, попередження, залежності'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function applyFilters(array $rows): array
    {
        return array_values(array_filter($rows, function (array $row): bool {
            $item = $row['item'];

            if ($this->filterType !== null && $this->filterType !== '' && $item->type !== $this->filterType) {
                return false;
            }

            if ($this->filterStatus !== null && $this->filterStatus !== '' && $row['status'] !== $this->filterStatus) {
                return false;
            }

            if ($this->filterCategory !== null && $this->filterCategory !== '' && $item->category !== $this->filterCategory) {
                return false;
            }

            if ($this->filterVendor !== null && $this->filterVendor !== '' && $item->vendor !== $this->filterVendor) {
                return false;
            }

            if ($this->filterFeatured !== null && $this->filterFeatured !== '') {
                $isFeatured = $item->isFeatured ? '1' : '0';

                if ($isFeatured !== $this->filterFeatured) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  callable(MarketplaceItem): string  $picker
     * @return array<string, string>
     */
    private function uniqueOptions(array $rows, callable $picker): array
    {
        $options = [];

        foreach ($rows as $row) {
            $value = $picker($row['item']);

            if ($value !== '') {
                $options[$value] = $value;
            }
        }

        return $options;
    }

    public function resetFilters(): void
    {
        $this->filterType = null;
        $this->filterStatus = null;
        $this->filterCategory = null;
        $this->filterVendor = null;
        $this->filterFeatured = null;
    }

    public function toggleDetails(string $code): void
    {
        $this->expandedCode = $this->expandedCode === $code ? null : $code;
    }

    public function rescan(): void
    {
        try {
            $result = app(MarketplaceManager::class)->discover();
            Notification::make()
                ->title('Discover завершено')
                ->body("Виявлено: {$result['discovered']}; некоректні: {$result['invalid']}; дублікати: {$result['duplicates']}")
                ->success()
                ->send();
        } catch (RuntimeException $exception) {
            Notification::make()
                ->title('Discover не вдалося')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    public function installAddon(string $code): void
    {
        $this->runLifecycle('install', $code, 'Встановлення', 'Встановлено');
    }

    public function enableAddon(string $code): void
    {
        $this->runLifecycle('enable', $code, 'Увімкнення', 'Увімкнено');
    }

    public function disableAddon(string $code): void
    {
        $this->runLifecycle('disable', $code, 'Вимкнення', 'Вимкнено');
    }

    public function uninstallAddon(string $code): void
    {
        $this->runLifecycle('uninstall', $code, 'Видалення', 'Видалено');
    }

    public function updateAddon(string $code): void
    {
        $this->runLifecycle('update', $code, 'Оновлення', 'Оновлено');
    }

    public function installDependencies(string $code): void
    {
        $this->guardCode($code);

        try {
            $installed = app(MarketplaceManager::class)->installDependencies($code);
            Notification::make()
                ->title('Залежності встановлено')
                ->body('Встановлено: '.implode(', ', $installed))
                ->success()
                ->send();
        } catch (RuntimeException $exception) {
            Notification::make()
                ->title('Встановлення залежностей не вдалося')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    public function refreshRegistry(): void
    {
        try {
            $manager = app(RegistryCatalog::class);
            $manager->flush();
            app(MarketplaceManager::class)->resolve();

            Notification::make()
                ->title('Registry оновлено')
                ->body('Каталог registry перечитано.')
                ->success()
                ->send();
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Registry не вдалося оновити')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    public function downloadArtifact(string $code): void
    {
        $this->guardCode($code);

        try {
            $result = app(MarketplaceManager::class)->downloadArtifact($code);

            if ($result->success) {
                Notification::make()
                    ->title('Artifact завантажено')
                    ->body("Файл [{$code}] поміщено у quarantine. Встановлення недоступне.")
                    ->success()
                    ->send();

                return;
            }

            Notification::make()
                ->title('Artifact не завантажено')
                ->body(implode(' ', $result->diagnostics) ?: 'Невідома помилка завантаження.')
                ->danger()
                ->send();
        } catch (RuntimeException $exception) {
            Notification::make()
                ->title('Artifact не завантажено')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    public function inspectArtifact(string $code): void
    {
        $this->guardCode($code);

        try {
            $result = app(MarketplaceManager::class)->inspectArtifact($code);

            if (! $result['success']) {
                Notification::make()
                    ->title('Перевірку artifact не виконано')
                    ->body(implode(' ', $result['diagnostics']) ?: 'Artifact ще не завантажено.')
                    ->warning()
                    ->send();

                return;
            }

            $report = $result['report'];
            $trust = $report['trust_status'] ?? 'untrusted';

            if ($trust === 'trusted') {
                Notification::make()
                    ->title('Artifact translations перевірено')
                    ->body("Підпис: {$report['signature_label']}; Manifest: {$report['manifest_label']}; Trust: {$report['trust_label']}.")
                    ->success()
                    ->send();

                return;
            }

            if ($trust === 'rejected') {
                Notification::make()
                    ->title('Artifact відхилено')
                    ->body(implode(' ', $result['diagnostics']) ?: 'Артефакт не пройшов перевірку цілісності.')
                    ->danger()
                    ->send();

                return;
            }

            Notification::make()
                ->title('Artifact перевірено')
                ->body("Підпис: {$report['signature_label']}; Manifest: {$report['manifest_label']}; Trust: {$report['trust_label']}.")
                ->warning()
                ->send();
        } catch (RuntimeException $exception) {
            Notification::make()
                ->title('Перевірку artifact не виконано')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    private function guardCode(string $code): void
    {
        if ($code === '' || ! preg_match('/^[a-z0-9._-]+$/i', $code)) {
            throw new RuntimeException("Некоректний код модуля: [{$code}].");
        }
    }

    private function runLifecycle(string $method, string $code, string $label, string $done): void
    {
        $this->guardCode($code);

        try {
            app(MarketplaceManager::class)->{$method}($code);
            Notification::make()
                ->title("{$label} завершено")
                ->body("Модуль/розширення [{$code}] — {$done}.")
                ->success()
                ->send();
        } catch (RuntimeException $exception) {
            Notification::make()
                ->title("{$label} не вдалося")
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * @return array<string, string>
     */
    public function getStatusColors(): array
    {
        return [
            'enabled' => 'success',
            'installed' => 'info',
            'disabled' => 'warning',
            'discovered' => 'gray',
            'available' => 'gray',
            'missing_files' => 'danger',
            'invalid' => 'danger',
            'failed' => 'danger',
            'removed' => 'gray',
        ];
    }

    public function getStatusColor(string $status): string
    {
        return $this->getStatusColors()[$status] ?? 'gray';
    }

    /**
     * @return array<string, string>
     */
    public function getInspectionLabels(): array
    {
        return [
            'not_required' => 'Не вимагається',
            'missing' => 'Підпис відсутній',
            'unknown_key' => 'Невідомий ключ',
            'unsupported_type' => 'Непідтримуваний тип',
            'invalid' => 'Підпис недійсний',
            'valid' => 'Підпис дійсний',
            'error' => 'Помилка перевірки',
            'not_inspected' => 'Не перевірено',
            'manifest_missing' => 'Manifest відсутній',
            'manifest_invalid' => 'Manifest некоректний',
            'identity_mismatch' => 'Code/version не збігаються',
            'untrusted' => 'Недовірений',
            'partially_trusted' => 'Частково довірений',
            'trusted' => 'Довірений',
            'rejected' => 'Відхилений',
            'pending' => 'Очікує review',
            'approved' => 'Схвалено',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getInspectionColors(): array
    {
        return [
            'not_required' => 'gray',
            'missing' => 'warning',
            'unknown_key' => 'warning',
            'unsupported_type' => 'warning',
            'invalid' => 'danger',
            'valid' => 'success',
            'error' => 'danger',
            'not_inspected' => 'gray',
            'manifest_missing' => 'danger',
            'manifest_invalid' => 'danger',
            'identity_mismatch' => 'danger',
            'untrusted' => 'warning',
            'partially_trusted' => 'warning',
            'trusted' => 'success',
            'rejected' => 'danger',
            'pending' => 'gray',
            'approved' => 'success',
        ];
    }

    public function inspectionLabel(?string $status, array $labels): string
    {
        if ($status === null || $status === '') {
            return '—';
        }

        return $labels[$status] ?? $status;
    }

    public function inspectionColor(?string $status, array $colors): string
    {
        if ($status === null || $status === '') {
            return 'gray';
        }

        return $colors[$status] ?? 'gray';
    }
}
