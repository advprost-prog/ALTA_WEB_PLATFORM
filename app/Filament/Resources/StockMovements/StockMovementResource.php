<?php

namespace App\Filament\Resources\StockMovements;

use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Resources\StockMovements\Pages\ViewStockMovement;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockMovementResource extends Resource
{
    protected static ?string $model = StockMovement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static string|\UnitEnum|null $navigationGroup = 'Продажі';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'рух товару';

    protected static ?string $pluralModelLabel = 'Рух товарів';

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('created_at')->label('Дата')->dateTime(),
                TextEntry::make('product.name')->label('Товар')->placeholder('-'),
                TextEntry::make('warehouse.name')->label('Склад')->placeholder('-'),
                TextEntry::make('type')
                    ->label('Тип')
                    ->formatStateUsing(fn (string $state): string => StockMovement::TYPES[$state] ?? $state)
                    ->badge(),
                TextEntry::make('quantity')->label('Кількість'),
                TextEntry::make('balance_after')->label('Залишок після'),
                TextEntry::make('related_document')
                    ->label('Документ')
                    ->state(fn (StockMovement $record): string => self::relatedDocumentLabel($record))
                    ->placeholder('-'),
                TextEntry::make('note')->label('Примітка')->placeholder('-')->columnSpanFull(),
                TextEntry::make('creator.name')->label('Користувач')->placeholder('-'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['product', 'warehouse', 'creator', 'related'])->latest())
            ->columns([
                TextColumn::make('created_at')
                    ->label('Дата')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('product.name')
                    ->label('Товар')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('warehouse.name')
                    ->label('Склад')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Тип')
                    ->formatStateUsing(fn (string $state): string => StockMovement::TYPES[$state] ?? $state)
                    ->badge(),
                TextColumn::make('quantity')
                    ->label('Кількість')
                    ->sortable(),
                TextColumn::make('balance_after')
                    ->label('Після')
                    ->sortable(),
                TextColumn::make('related_document')
                    ->label('Документ')
                    ->state(fn (StockMovement $record): string => self::relatedDocumentLabel($record))
                    ->placeholder('-'),
                TextColumn::make('note')
                    ->label('Примітка')
                    ->limit(48)
                    ->tooltip(fn (?string $state): ?string => $state),
                TextColumn::make('creator.name')
                    ->label('Користувач')
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('product_id')
                    ->label('Товар')
                    ->options(fn (): array => Product::query()->orderBy('name')->limit(50)->pluck('name', 'id')->all())
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Product::query()
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('sku', 'like', '%'.$search.'%')
                        ->orderBy('name')
                        ->limit(50)
                        ->pluck('name', 'id')
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => Product::query()->whereKey($value)->value('name')),
                SelectFilter::make('warehouse_id')
                    ->label('Склад')
                    ->options(fn (): array => Warehouse::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                SelectFilter::make('type')
                    ->label('Тип')
                    ->options(StockMovement::TYPES),
                Filter::make('created_at')
                    ->label('Період')
                    ->schema([
                        DatePicker::make('created_from')->label('Від'),
                        DatePicker::make('created_until')->label('До'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['created_from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['created_until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockMovements::route('/'),
            'view' => ViewStockMovement::route('/{record}'),
        ];
    }

    private static function relatedDocumentLabel(StockMovement $record): string
    {
        if (! $record->related_type || ! $record->related_id) {
            return '-';
        }

        $related = $record->related;

        if ($related instanceof \App\Models\Order) {
            return 'Замовлення '.$related->number;
        }

        return class_basename($record->related_type).' #'.$record->related_id;
    }
}
