<?php

namespace App\Filament\Widgets;

use App\Models\StockMovement;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class LatestStockMovementsWidget extends TableWidget
{
    protected static ?int $sort = -5;

    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->canAccessArea('sales') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Останні рухи товарів')
            ->query(StockMovement::query()->with(['product', 'warehouse'])->latest())
            ->defaultPaginationPageOption(10)
            ->columns([
                TextColumn::make('created_at')
                    ->label('Дата')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('product.name')
                    ->label('Товар')
                    ->wrap(),
                TextColumn::make('warehouse.name')
                    ->label('Склад')
                    ->placeholder('-'),
                TextColumn::make('type')
                    ->label('Тип')
                    ->formatStateUsing(fn (string $state): string => StockMovement::TYPES[$state] ?? $state)
                    ->badge(),
                TextColumn::make('quantity')
                    ->label('Кількість'),
                TextColumn::make('balance_after')
                    ->label('Після'),
            ]);
    }
}
