<?php

namespace App\Filament\Resources\Orders;

use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\CommerceSetting;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Commerce\ProductPricingService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|\UnitEnum|null $navigationGroup = 'Продажі';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'замовлення';

    protected static ?string $pluralModelLabel = 'Замовлення';

    protected static ?string $recordTitleAttribute = 'number';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Клієнт')
                    ->description('Контактні дані покупця і номер замовлення.')
                    ->schema([
                        Select::make('customer_id')
                            ->label('Клієнт з бази')
                            ->relationship('customer', 'name')
                            ->searchable()
                            ->preload(),
                        TextInput::make('number')
                            ->label('Номер')
                            ->maxLength(255),
                        TextInput::make('customer_name')
                            ->label('Імʼя клієнта')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->label('Телефон')
                            ->tel()
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255),
                    ])
                    ->columns(2),
                Section::make('Оплата і доставка')
                    ->description('Статус, сума й операційні дані для менеджера.')
                    ->schema([
                        Select::make('status')
                            ->label('Статус')
                            ->options(Order::STATUSES)
                            ->default('new')
                            ->required(),
                        TextInput::make('delivery_method')
                            ->label('Спосіб доставки')
                            ->maxLength(255),
                        TextInput::make('payment_method')
                            ->label('Спосіб оплати')
                            ->maxLength(255),
                        Select::make('currency_id')
                            ->label('Валюта')
                            ->options(fn (): array => Currency::query()
                                ->where('is_active', true)
                                ->orderByDesc('is_base')
                                ->orderBy('code')
                                ->pluck('code', 'id')
                                ->all())
                            ->searchable()
                            ->disabled(fn (string $operation): bool => $operation !== 'create')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create')
                            ->visible(fn (): bool => self::multiCurrencyEnabled()),
                        Select::make('warehouse_id')
                            ->label('Склад')
                            ->options(fn (): array => Warehouse::query()
                                ->where('is_active', true)
                                ->orderByDesc('is_default')
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->disabled(fn (string $operation): bool => $operation !== 'create')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create')
                            ->visible(fn (): bool => self::multiWarehouseEnabled()),
                        TextInput::make('total_amount')
                            ->label('Сума')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->prefix('₴')
                            ->default(0),
                    ])
                    ->columns(4),
                Section::make('Товари')
                    ->description('Позиції замовлення з фіксацією назви, артикулу, ціни й суми.')
                    ->schema([
                        Repeater::make('items')
                            ->label('Позиції замовлення')
                            ->relationship()
                            ->schema([
                                Select::make('product_id')
                                    ->label('Товар')
                                    ->relationship('product', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->afterStateUpdated(function (?int $state, Set $set): void {
                                        $product = $state ? Product::find($state) : null;

                                        if (! $product) {
                                            return;
                                        }

                                        $set('product_name', $product->name);
                                        $set('sku', $product->sku);
                                        $set('unit_price', $product->price);
                                        $set('price', $product->price);
                                        $set('quantity', 1);
                                        $set('total', $product->price);
                                        $set('warehouse_id', CommerceSetting::current()->default_warehouse_id);
                                    }),
                                Select::make('warehouse_id')
                                    ->label('Склад списання')
                                    ->options(fn (): array => Warehouse::query()
                                        ->orderByDesc('is_default')
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->all())
                                    ->searchable()
                                    ->disabled(fn (string $operation): bool => $operation !== 'create')
                                    ->dehydrated(fn (string $operation): bool => $operation === 'create')
                                    ->placeholder('За замовчуванням'),
                                Hidden::make('unit_price'),
                                TextInput::make('product_name')
                                    ->label('Назва')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('sku')
                                    ->label('Артикул')
                                    ->maxLength(255),
                                TextInput::make('quantity')
                                    ->label('К-сть')
                                    ->numeric()
                                    ->minValue(1)
                                    ->default(1)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Get $get, Set $set): mixed => $set('total', (float) $get('price') * max(1, (int) $get('quantity')))),
                                TextInput::make('price')
                                    ->label('Ціна')
                                    ->numeric()
                                    ->minValue(0)
                                    ->prefix('₴')
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Get $get, Set $set): mixed => $set('total', (float) $get('price') * max(1, (int) $get('quantity')))),
                                TextInput::make('total')
                                    ->label('Разом')
                                    ->numeric()
                                    ->minValue(0)
                                    ->prefix('₴')
                                    ->required(),
                            ])
                            ->columns(7)
                            ->defaultItems(1)
                            ->disabled(fn (string $operation): bool => $operation !== 'create')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create'),
                    ]),
                Section::make('Коментарі')
                    ->description('Коментар покупця та внутрішня нотатка менеджера.')
                    ->schema([
                        Textarea::make('customer_comment')
                            ->label('Коментар клієнта')
                            ->rows(3),
                        Textarea::make('manager_comment')
                            ->label('Коментар менеджера')
                            ->rows(3),
                    ])
                    ->columns(2),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('customer.name')
                    ->label('Клієнт')
                    ->placeholder('-'),
                TextEntry::make('number')
                    ->label('Номер'),
                TextEntry::make('customer_name')
                    ->label('Імʼя'),
                TextEntry::make('phone')
                    ->label('Телефон'),
                TextEntry::make('email')
                    ->label('Email')
                    ->placeholder('-'),
                TextEntry::make('total_amount')
                    ->label('Сума')
                    ->formatStateUsing(fn (mixed $state, Order $record): string => self::formatOrderMoney($state, $record)),
                TextEntry::make('currency_code')
                    ->label('Валюта')
                    ->placeholder('-'),
                TextEntry::make('warehouse.name')
                    ->label('Склад')
                    ->placeholder('-'),
                TextEntry::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Order::STATUSES[$state] ?? $state)
                    ->color(fn (?string $state): string => self::statusColor($state)),
                TextEntry::make('delivery_method')
                    ->label('Доставка')
                    ->placeholder('-'),
                TextEntry::make('payment_method')
                    ->label('Оплата')
                    ->placeholder('-'),
                TextEntry::make('customer_comment')
                    ->label('Коментар клієнта')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('manager_comment')
                    ->label('Коментар менеджера')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->columns([
                TextColumn::make('customer.name')
                    ->label('Клієнт')
                    ->searchable(),
                TextColumn::make('number')
                    ->label('Номер')
                    ->searchable(),
                TextColumn::make('customer_name')
                    ->label('Імʼя')
                    ->searchable(),
                TextColumn::make('phone')
                    ->label('Телефон')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),
                TextColumn::make('total_amount')
                    ->label('Сума')
                    ->formatStateUsing(fn (mixed $state, Order $record): string => self::formatOrderMoney($state, $record))
                    ->sortable(),
                TextColumn::make('currency_code')
                    ->label('Валюта')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('warehouse.name')
                    ->label('Склад')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Order::STATUSES[$state] ?? $state)
                    ->color(fn (?string $state): string => self::statusColor($state)),
                TextColumn::make('created_at')
                    ->label('Створено')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label('Оновлено')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(Order::STATUSES),
            ])
            ->recordActions([
                Action::make('quickStatus')
                    ->label('Статус')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->fillForm(fn (Order $record): array => ['status' => $record->status])
                    ->schema([
                        Select::make('status')
                            ->label('Новий статус')
                            ->options(Order::STATUSES)
                            ->required(),
                    ])
                    ->action(fn (Order $record, array $data): bool => $record->update(['status' => $data['status']])),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'create' => CreateOrder::route('/create'),
            'view' => ViewOrder::route('/{record}'),
            'edit' => ViewOrder::route('/{record}/edit'),
        ];
    }

    private static function statusColor(?string $state): string
    {
        return match ($state) {
            'new' => 'info',
            'confirmed', 'processing' => 'warning',
            'awaiting_payment' => 'gray',
            'shipped' => 'primary',
            'completed' => 'success',
            'cancelled' => 'danger',
            default => 'gray',
        };
    }

    private static function multiCurrencyEnabled(): bool
    {
        return CommerceSetting::current()->multi_currency_enabled;
    }

    private static function multiWarehouseEnabled(): bool
    {
        return CommerceSetting::current()->multi_warehouse_enabled;
    }

    private static function formatOrderMoney(mixed $state, ?Order $record = null): string
    {
        return app(ProductPricingService::class)->formatAmount(
            $state,
            $record?->currency ?: $record?->currency_code,
        );
    }
}
