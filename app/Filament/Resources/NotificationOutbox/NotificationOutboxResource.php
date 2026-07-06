<?php

namespace App\Filament\Resources\NotificationOutbox;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\OrderNotificationEvent;
use App\Filament\Resources\NotificationOutbox\Pages\ListNotificationOutbox;
use App\Filament\Resources\NotificationOutbox\Pages\ViewNotificationOutbox;
use App\Models\NotificationOutbox;
use App\Models\Order;
use App\Models\User;
use App\Services\Commerce\OrderNotificationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class NotificationOutboxResource extends Resource
{
    protected static ?string $model = NotificationOutbox::class;

    protected static ?string $slug = 'notification-outbox';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static string|\UnitEnum|null $navigationGroup = 'Продажі';

    protected static ?int $navigationSort = 31;

    protected static ?string $modelLabel = 'повідомлення';

    protected static ?string $pluralModelLabel = 'Outbox повідомлень';

    protected static ?string $recordTitleAttribute = 'subject';

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('created_at')->label('Створено')->dateTime(),
                TextEntry::make('order.number')->label('Замовлення')->placeholder('-'),
                TextEntry::make('event')
                    ->label('Подія')
                    ->formatStateUsing(fn (?string $state): string => OrderNotificationEvent::labelFor($state))
                    ->badge(),
                TextEntry::make('channel')
                    ->label('Канал')
                    ->formatStateUsing(fn (?string $state): string => NotificationChannel::labelFor($state))
                    ->badge(),
                TextEntry::make('mail_source')
                    ->label('Mail source')
                    ->state(fn (NotificationOutbox $record): string => (string) data_get($record->payload, 'mail.source', '-'))
                    ->badge(),
                TextEntry::make('mailer')
                    ->label('Mailer')
                    ->state(fn (NotificationOutbox $record): string => (string) data_get($record->payload, 'mail.mailer', '-'))
                    ->badge(),
                TextEntry::make('recipient')->label('Отримувач')->placeholder('-'),
                TextEntry::make('status')
                    ->label('Статус')
                    ->formatStateUsing(fn (?string $state): string => NotificationStatus::labelFor($state))
                    ->color(fn (?string $state): string => NotificationStatus::colorFor($state))
                    ->badge(),
                TextEntry::make('sent_at')->label('Надіслано')->dateTime()->placeholder('-'),
                TextEntry::make('subject')->label('Тема')->placeholder('-')->columnSpanFull(),
                TextEntry::make('body')->label('Текст')->placeholder('-')->columnSpanFull(),
                TextEntry::make('error_message')->label('Помилка')->placeholder('-')->columnSpanFull(),
                TextEntry::make('creator.name')->label('Користувач')->placeholder('-'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('subject')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['order', 'creator'])->latest())
            ->columns([
                TextColumn::make('created_at')
                    ->label('Дата')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('order.number')
                    ->label('Замовлення')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('event')
                    ->label('Подія')
                    ->formatStateUsing(fn (?string $state): string => OrderNotificationEvent::labelFor($state))
                    ->badge(),
                TextColumn::make('channel')
                    ->label('Канал')
                    ->formatStateUsing(fn (?string $state): string => NotificationChannel::labelFor($state))
                    ->badge(),
                TextColumn::make('mail_source')
                    ->label('Mail source')
                    ->state(fn (NotificationOutbox $record): string => (string) data_get($record->payload, 'mail.source', '-'))
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('mailer')
                    ->label('Mailer')
                    ->state(fn (NotificationOutbox $record): string => (string) data_get($record->payload, 'mail.mailer', '-'))
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('recipient')
                    ->label('Отримувач')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('status')
                    ->label('Статус')
                    ->formatStateUsing(fn (?string $state): string => NotificationStatus::labelFor($state))
                    ->color(fn (?string $state): string => NotificationStatus::colorFor($state))
                    ->badge(),
                TextColumn::make('sent_at')
                    ->label('Надіслано')
                    ->dateTime()
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('error_message')
                    ->label('Помилка')
                    ->limit(40)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('order_id')
                    ->label('Замовлення')
                    ->options(fn (): array => Order::query()->latest()->limit(50)->pluck('number', 'id')->all())
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Order::query()
                        ->where('number', 'like', '%'.$search.'%')
                        ->latest()
                        ->limit(50)
                        ->pluck('number', 'id')
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => Order::query()->whereKey($value)->value('number')),
                SelectFilter::make('event')
                    ->label('Подія')
                    ->options(OrderNotificationEvent::options()),
                SelectFilter::make('channel')
                    ->label('Канал')
                    ->options(NotificationChannel::options()),
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(NotificationStatus::options()),
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
                Action::make('resend')
                    ->label('Resend')
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->visible(fn (NotificationOutbox $record): bool => $record->canResend())
                    ->requiresConfirmation()
                    ->action(fn (NotificationOutbox $record): null => self::resend($record)),
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotificationOutbox::route('/'),
            'view' => ViewNotificationOutbox::route('/{record}'),
        ];
    }

    private static function resend(NotificationOutbox $record): null
    {
        try {
            app(OrderNotificationService::class)->resend($record, self::currentUser());

            FilamentNotification::make()
                ->title('Повідомлення додано в повторну відправку')
                ->success()
                ->send();
        } catch (RuntimeException $exception) {
            FilamentNotification::make()
                ->title('Повторну відправку відхилено')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }

        return null;
    }

    private static function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
