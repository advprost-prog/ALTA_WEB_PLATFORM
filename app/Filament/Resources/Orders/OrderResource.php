<?php

namespace App\Filament\Resources\Orders;

use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\OrderNotificationEvent;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\CommerceSetting;
use App\Models\Currency;
use App\Models\DeliveryMethod;
use App\Models\NotificationOutbox;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Commerce\OrderLifecycleService;
use App\Services\Commerce\OrderNotificationService;
use App\Services\Commerce\ProductPricingService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

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
                            ->options(Order::statusOptions(includeLegacy: false))
                            ->default(OrderStatus::New->value)
                            ->disabled(fn (string $operation): bool => $operation !== 'create')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create')
                            ->required(),
                        Select::make('payment_status')
                            ->label('Оплата')
                            ->options(Order::paymentStatusOptions())
                            ->default(PaymentStatus::Unpaid->value)
                            ->disabled(fn (string $operation): bool => $operation !== 'create')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create')
                            ->required(),
                        Select::make('delivery_status')
                            ->label('Доставка')
                            ->options(Order::deliveryStatusOptions())
                            ->default(DeliveryStatus::Pending->value)
                            ->disabled(fn (string $operation): bool => $operation !== 'create')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create')
                            ->required(),
                        Select::make('delivery_method_id')
                            ->label('Спосіб доставки')
                            ->options(fn (): array => DeliveryMethod::query()->active()->ordered()->pluck('name', 'id')->all())
                            ->searchable()
                            ->preload()
                            ->disabled(fn (string $operation): bool => $operation !== 'create')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create'),
                        Select::make('payment_method_id')
                            ->label('Спосіб оплати')
                            ->options(fn (): array => PaymentMethod::query()->active()->ordered()->pluck('name', 'id')->all())
                            ->searchable()
                            ->preload()
                            ->disabled(fn (string $operation): bool => $operation !== 'create')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create'),
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
                    ->formatStateUsing(fn (?string $state): string => OrderStatus::labelFor($state))
                    ->color(fn (?string $state): string => OrderStatus::colorFor($state)),
                TextEntry::make('payment_status')
                    ->label('Статус оплати')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => PaymentStatus::labelFor($state))
                    ->color(fn (?string $state): string => PaymentStatus::colorFor($state)),
                TextEntry::make('delivery_status')
                    ->label('Статус доставки')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => DeliveryStatus::labelFor($state))
                    ->color(fn (?string $state): string => DeliveryStatus::colorFor($state)),
                TextEntry::make('delivery_method_name')
                    ->label('Спосіб доставки')
                    ->state(fn (Order $record): ?string => $record->delivery_method_name ?: $record->deliveryMethod?->name ?: $record->delivery_method)
                    ->placeholder('-'),
                TextEntry::make('payment_method_name')
                    ->label('Спосіб оплати')
                    ->state(fn (Order $record): ?string => $record->payment_method_name ?: $record->paymentMethod?->name ?: $record->payment_method)
                    ->placeholder('-'),
                TextEntry::make('confirmed_at')
                    ->label('Підтверджено')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('paid_at')
                    ->label('Оплачено')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('shipped_at')
                    ->label('Відправлено')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('completed_at')
                    ->label('Завершено')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('cancelled_at')
                    ->label('Скасовано')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('cancel_reason')
                    ->label('Причина скасування')
                    ->placeholder('-')
                    ->columnSpanFull(),
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
                Section::make('Історія статусів')
                    ->schema([
                        RepeatableEntry::make('statusHistories')
                            ->label('')
                            ->schema([
                                TextEntry::make('created_at')
                                    ->label('Дата')
                                    ->dateTime(),
                                TextEntry::make('type')
                                    ->label('Тип')
                                    ->formatStateUsing(fn (?string $state): string => OrderStatusHistory::TYPES[$state] ?? (string) $state)
                                    ->badge(),
                                TextEntry::make('from_value')
                                    ->label('Було')
                                    ->formatStateUsing(fn (?string $state, OrderStatusHistory $record): string => self::formatHistoryValue($record->type, $state))
                                    ->placeholder('-'),
                                TextEntry::make('to_value')
                                    ->label('Стало')
                                    ->formatStateUsing(fn (?string $state, OrderStatusHistory $record): string => self::formatHistoryValue($record->type, $state))
                                    ->placeholder('-'),
                                TextEntry::make('comment')
                                    ->label('Коментар')
                                    ->placeholder('-')
                                    ->columnSpan(2),
                                TextEntry::make('creator.name')
                                    ->label('Користувач')
                                    ->placeholder('-'),
                            ])
                            ->columns(6)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                Section::make('Повідомлення')
                    ->schema([
                        RepeatableEntry::make('notifications')
                            ->label('')
                            ->schema([
                                TextEntry::make('created_at')
                                    ->label('Дата')
                                    ->dateTime(),
                                TextEntry::make('event')
                                    ->label('Подія')
                                    ->formatStateUsing(fn (?string $state): string => OrderNotificationEvent::labelFor($state))
                                    ->badge(),
                                TextEntry::make('channel')
                                    ->label('Канал')
                                    ->formatStateUsing(fn (?string $state): string => NotificationChannel::labelFor($state))
                                    ->badge(),
                                TextEntry::make('recipient')
                                    ->label('Отримувач')
                                    ->placeholder('-'),
                                TextEntry::make('status')
                                    ->label('Статус')
                                    ->formatStateUsing(fn (?string $state): string => NotificationStatus::labelFor($state))
                                    ->color(fn (?string $state): string => NotificationStatus::colorFor($state))
                                    ->badge(),
                                TextEntry::make('sent_at')
                                    ->label('Надіслано')
                                    ->dateTime()
                                    ->placeholder('-'),
                                TextEntry::make('error_message')
                                    ->label('Помилка')
                                    ->placeholder('-')
                                    ->columnSpan(2),
                            ])
                            ->columns(7)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['customer', 'currency', 'warehouse', 'paymentMethod', 'deliveryMethod'])->latest())
            ->columns([
                TextColumn::make('created_at')
                    ->label('Дата')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('number')
                    ->label('Номер')
                    ->searchable(),
                TextColumn::make('customer_name')
                    ->label('Клієнт')
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
                    ->formatStateUsing(fn (?string $state): string => OrderStatus::labelFor($state))
                    ->color(fn (?string $state): string => OrderStatus::colorFor($state)),
                TextColumn::make('payment_status')
                    ->label('Оплата')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => PaymentStatus::labelFor($state))
                    ->color(fn (?string $state): string => PaymentStatus::colorFor($state)),
                TextColumn::make('delivery_status')
                    ->label('Доставка')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => DeliveryStatus::labelFor($state))
                    ->color(fn (?string $state): string => DeliveryStatus::colorFor($state)),
                TextColumn::make('payment_method_name')
                    ->label('Спосіб оплати')
                    ->state(fn (Order $record): ?string => $record->payment_method_name ?: $record->paymentMethod?->name ?: $record->payment_method)
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('delivery_method_name')
                    ->label('Спосіб доставки')
                    ->state(fn (Order $record): ?string => $record->delivery_method_name ?: $record->deliveryMethod?->name ?: $record->delivery_method)
                    ->placeholder('-')
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
                    ->options(Order::statusOptions()),
                SelectFilter::make('payment_status')
                    ->label('Статус оплати')
                    ->options(Order::paymentStatusOptions()),
                SelectFilter::make('delivery_status')
                    ->label('Статус доставки')
                    ->options(Order::deliveryStatusOptions()),
                SelectFilter::make('payment_method_id')
                    ->label('Спосіб оплати')
                    ->options(fn (): array => PaymentMethod::query()->ordered()->pluck('name', 'id')->all())
                    ->searchable(),
                SelectFilter::make('delivery_method_id')
                    ->label('Спосіб доставки')
                    ->options(fn (): array => DeliveryMethod::query()->ordered()->pluck('name', 'id')->all())
                    ->searchable(),
                Filter::make('created_at')
                    ->label('Дата')
                    ->schema([
                        DatePicker::make('created_from')->label('Від'),
                        DatePicker::make('created_until')->label('До'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['created_from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['created_until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                Action::make('confirm')
                    ->label('Підтвердити')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->visible(fn (Order $record): bool => app(OrderLifecycleService::class)->canTransitionTo($record, OrderStatus::Confirmed))
                    ->action(fn (Order $record): null => self::runLifecycleAction($record, 'confirm', 'Замовлення підтверджено')),
                Action::make('processing')
                    ->label('В обробці')
                    ->icon(Heroicon::OutlinedCog6Tooth)
                    ->visible(fn (Order $record): bool => app(OrderLifecycleService::class)->canTransitionTo($record, OrderStatus::Processing))
                    ->action(fn (Order $record): null => self::runLifecycleAction($record, 'markProcessing', 'Замовлення взято в обробку')),
                Action::make('readyToShip')
                    ->label('Готове')
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->visible(fn (Order $record): bool => app(OrderLifecycleService::class)->canTransitionTo($record, OrderStatus::ReadyToShip))
                    ->action(fn (Order $record): null => self::runLifecycleAction($record, 'markReadyToShip', 'Замовлення готове до відправки')),
                Action::make('shipped')
                    ->label('Відправити')
                    ->icon(Heroicon::OutlinedTruck)
                    ->visible(fn (Order $record): bool => app(OrderLifecycleService::class)->canTransitionTo($record, OrderStatus::Shipped))
                    ->action(fn (Order $record): null => self::runLifecycleAction($record, 'markShipped', 'Замовлення позначено як відправлене')),
                Action::make('completed')
                    ->label('Завершити')
                    ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                    ->visible(fn (Order $record): bool => app(OrderLifecycleService::class)->canTransitionTo($record, OrderStatus::Completed))
                    ->requiresConfirmation()
                    ->action(fn (Order $record): null => self::runLifecycleAction($record, 'markCompleted', 'Замовлення завершено')),
                Action::make('paid')
                    ->label('Оплачено')
                    ->icon(Heroicon::OutlinedCreditCard)
                    ->visible(fn (Order $record): bool => app(OrderLifecycleService::class)->canMarkPaid($record))
                    ->action(fn (Order $record): null => self::runLifecycleAction($record, 'markPaid', 'Оплату зафіксовано')),
                Action::make('cancel')
                    ->label('Скасувати')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (Order $record): bool => app(OrderLifecycleService::class)->canCancel($record))
                    ->schema([
                        Textarea::make('reason')
                            ->label('Причина')
                            ->required()
                            ->maxLength(2000)
                            ->rows(3),
                    ])
                    ->modalSubmitActionLabel('Скасувати замовлення')
                    ->action(fn (Order $record, array $data): null => self::runLifecycleAction($record, 'cancel', 'Замовлення скасовано', (string) ($data['reason'] ?? ''))),
                self::resendNotificationsAction(),
                ViewAction::make(),
                EditAction::make(),
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

    private static function runLifecycleAction(Order $record, string $method, string $successTitle, ?string $comment = null): null
    {
        try {
            app(OrderLifecycleService::class)->{$method}($record, self::currentUser(), $comment);

            Notification::make()
                ->title($successTitle)
                ->success()
                ->send();
        } catch (RuntimeException $exception) {
            Notification::make()
                ->title('Дію відхилено')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }

        return null;
    }

    public static function resendNotificationsAction(): Action
    {
        return Action::make('resendNotifications')
            ->label('Resend повідомлення')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->visible(fn (Order $record): bool => $record->notifications()->resendable()->exists())
            ->requiresConfirmation()
            ->action(fn (Order $record): null => self::resendNotifications($record));
    }

    private static function resendNotifications(Order $record): null
    {
        $notifications = $record->notifications()->resendable()->get();
        $sent = 0;

        foreach ($notifications as $notification) {
            if (! $notification instanceof NotificationOutbox) {
                continue;
            }

            app(OrderNotificationService::class)->resend($notification, self::currentUser());
            $sent++;
        }

        Notification::make()
            ->title('Повторну відправку запущено')
            ->body('Створено спроб: '.$sent)
            ->success()
            ->send();

        return null;
    }

    private static function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
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

    private static function formatHistoryValue(?string $type, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return match ($type) {
            OrderStatusHistory::TYPE_STATUS => OrderStatus::labelFor($value),
            OrderStatusHistory::TYPE_PAYMENT_STATUS => PaymentStatus::labelFor($value),
            OrderStatusHistory::TYPE_DELIVERY_STATUS => DeliveryStatus::labelFor($value),
            default => $value,
        };
    }
}
