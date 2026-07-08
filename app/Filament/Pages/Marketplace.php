<?php

namespace App\Filament\Pages;

use App\Support\Addons\Marketplace\MarketplaceItem;
use App\Support\Addons\Marketplace\MarketplaceManager;
use App\Support\Addons\Marketplace\MarketplaceStatus;
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
            'typeOptions' => $this->uniqueOptions($resolved['rows'], fn (MarketplaceItem $i): string => $i->type),
            'statusOptions' => MarketplaceStatus::LABELS,
            'categoryOptions' => $this->uniqueOptions($resolved['rows'], fn (MarketplaceItem $i): string => $i->category),
            'vendorOptions' => $this->uniqueOptions($resolved['rows'], fn (MarketplaceItem $i): string => $i->vendor),
            'featuredOptions' => ['1' => 'Так', '0' => 'Ні'],
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

    public function discover(): void
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

    public function install(string $code): void
    {
        $this->runLifecycle('install', $code, 'Встановлення', 'Встановлено');
    }

    public function enable(string $code): void
    {
        $this->runLifecycle('enable', $code, 'Увімкнення', 'Увімкнено');
    }

    public function disable(string $code): void
    {
        $this->runLifecycle('disable', $code, 'Вимкнення', 'Вимкнено');
    }

    public function uninstall(string $code): void
    {
        $this->runLifecycle('uninstall', $code, 'Видалення', 'Видалено');
    }

    private function runLifecycle(string $method, string $code, string $label, string $done): void
    {
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
}
