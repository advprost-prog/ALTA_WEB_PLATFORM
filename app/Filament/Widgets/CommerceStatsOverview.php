<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use App\Models\Product;
use App\Models\CommerceSetting;
use App\Services\Catalog\ProductCompletenessService;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CommerceStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -10;

    protected function getStats(): array
    {
        $products = Product::query()
            ->with(['images', 'specifications'])
            ->get();
        $completeness = app(ProductCompletenessService::class);
        $readyProducts = $products
            ->filter(fn (Product $product): bool => $completeness->score($product) >= 90)
            ->count();
        $needsCompletion = max(0, $products->count() - $readyProducts);

        $activeRevenue = (float) Order::query()
            ->where('status', '!=', 'cancelled')
            ->sum('total_amount');

        $withoutPhoto = Product::query()
            ->where(fn ($query) => $query
                ->whereNull('main_image')
                ->orWhere('main_image', ''))
            ->count();

        $withoutSeo = Product::query()
            ->where(fn ($query) => $query
                ->whereNull('seo_title')
                ->orWhere('seo_title', '')
                ->orWhereNull('seo_description')
                ->orWhere('seo_description', ''))
            ->count();

        $outOfStock = Product::query()
            ->where(fn ($query) => $query
                ->where('stock', '<=', 0)
                ->orWhere('stock_status', 'out_of_stock'))
            ->count();
        $settings = CommerceSetting::current();
        $negativeStock = Product::query()
            ->where('stock', '<', 0)
            ->count();
        $withoutDefaultPrice = Product::query()
            ->whereDoesntHave('prices', fn ($query) => $query->where('currency_id', $settings->default_currency_id))
            ->count();
        $withoutDefaultStockBalance = Product::query()
            ->whereDoesntHave('stockBalances', fn ($query) => $query->where('warehouse_id', $settings->default_warehouse_id))
            ->count();

        return [
            Stat::make('Замовлень', Order::count())
                ->description('Усі створені замовлення')
                ->descriptionIcon(Heroicon::OutlinedClipboardDocumentList)
                ->color('primary'),
            Stat::make('Нові замовлення', Order::where('status', 'new')->count())
                ->description('Очікують першої обробки')
                ->descriptionIcon(Heroicon::OutlinedBellAlert)
                ->color('info'),
            Stat::make('Товарів', Product::count())
                ->description('Позиції каталогу')
                ->descriptionIcon(Heroicon::OutlinedShoppingBag)
                ->color('success'),
            Stat::make('Готові товари', $readyProducts)
                ->description('Заповненість 90%+')
                ->descriptionIcon(Heroicon::OutlinedClipboardDocumentCheck)
                ->color('success'),
            Stat::make('Потребують заповнення', $needsCompletion)
                ->description('Є прогалини у картці товару')
                ->descriptionIcon(Heroicon::OutlinedPencilSquare)
                ->color($needsCompletion > 0 ? 'warning' : 'success'),
            Stat::make('Без фото', $withoutPhoto)
                ->description('Немає основного фото')
                ->descriptionIcon(Heroicon::OutlinedPhoto)
                ->color($withoutPhoto > 0 ? 'danger' : 'success'),
            Stat::make('Без SEO', $withoutSeo)
                ->description('Немає SEO title або description')
                ->descriptionIcon(Heroicon::OutlinedMagnifyingGlass)
                ->color($withoutSeo > 0 ? 'warning' : 'success'),
            Stat::make('Немає на складі', $outOfStock)
                ->description('Потребують уваги')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color($outOfStock > 0 ? 'danger' : 'success'),
            Stat::make('Відʼємний залишок', $negativeStock)
                ->description('Має бути 0')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color($negativeStock > 0 ? 'danger' : 'success'),
            Stat::make('Без дефолтної ціни', $withoutDefaultPrice)
                ->description('Немає product_price')
                ->descriptionIcon(Heroicon::OutlinedBanknotes)
                ->color($withoutDefaultPrice > 0 ? 'warning' : 'success'),
            Stat::make('Без дефолтного складу', $withoutDefaultStockBalance)
                ->description('Немає stock_balance')
                ->descriptionIcon(Heroicon::OutlinedArchiveBox)
                ->color($withoutDefaultStockBalance > 0 ? 'warning' : 'success'),
            Stat::make('Оборот', number_format($activeRevenue, 0, '.', ' ') . ' ₴')
                ->description('Без скасованих замовлень')
                ->descriptionIcon(Heroicon::OutlinedBanknotes)
                ->color('warning'),
        ];
    }
}
