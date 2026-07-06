<?php

namespace App\Filament\Resources\NotificationTemplates;

use App\Enums\NotificationChannel;
use App\Enums\OrderNotificationEvent;
use App\Filament\Resources\NotificationTemplates\Pages\EditNotificationTemplate;
use App\Filament\Resources\NotificationTemplates\Pages\ListNotificationTemplates;
use App\Filament\Resources\NotificationTemplates\Pages\ViewNotificationTemplate;
use App\Models\NotificationTemplate;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class NotificationTemplateResource extends Resource
{
    protected static ?string $model = NotificationTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|\UnitEnum|null $navigationGroup = 'Продажі';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'шаблон повідомлення';

    protected static ?string $pluralModelLabel = 'Шаблони повідомлень';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Шаблон')
                    ->schema([
                        TextInput::make('code')
                            ->label('Код')
                            ->disabled()
                            ->dehydrated(false),
                        Select::make('event')
                            ->label('Подія')
                            ->options(OrderNotificationEvent::options())
                            ->disabled()
                            ->dehydrated(false),
                        Select::make('channel')
                            ->label('Канал')
                            ->options(NotificationChannel::options())
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('name')
                            ->label('Назва')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('subject')
                            ->label('Тема')
                            ->maxLength(255)
                            ->helperText('Доступні змінні: '.NotificationTemplate::variablesText().'. PHP, Blade та eval не виконуються.'),
                        Textarea::make('body')
                            ->label('Текст')
                            ->required()
                            ->rows(10)
                            ->helperText('Доступні змінні: '.NotificationTemplate::variablesText().'. HTML з даних клієнта екранується у email view; PHP, Blade та eval не виконуються.'),
                        Toggle::make('is_active')
                            ->label('Активний'),
                        Toggle::make('is_system')
                            ->label('Системний')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('sort_order')
                            ->label('Сортування')
                            ->numeric()
                            ->default(0),
                    ])
                    ->columns(2),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('code')->label('Код'),
                TextEntry::make('event')
                    ->label('Подія')
                    ->formatStateUsing(fn (?string $state): string => OrderNotificationEvent::labelFor($state))
                    ->badge(),
                TextEntry::make('channel')
                    ->label('Канал')
                    ->formatStateUsing(fn (?string $state): string => NotificationChannel::labelFor($state))
                    ->badge(),
                TextEntry::make('name')->label('Назва'),
                IconEntry::make('is_active')->label('Активний')->boolean(),
                IconEntry::make('is_system')->label('Системний')->boolean(),
                TextEntry::make('subject')->label('Тема')->placeholder('-')->columnSpanFull(),
                TextEntry::make('body')->label('Текст')->placeholder('-')->columnSpanFull(),
                TextEntry::make('created_at')->label('Створено')->dateTime()->placeholder('-'),
                TextEntry::make('updated_at')->label('Оновлено')->dateTime()->placeholder('-'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Назва')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('event')
                    ->label('Подія')
                    ->formatStateUsing(fn (?string $state): string => OrderNotificationEvent::labelFor($state))
                    ->badge(),
                TextColumn::make('channel')
                    ->label('Канал')
                    ->formatStateUsing(fn (?string $state): string => NotificationChannel::labelFor($state))
                    ->badge(),
                TextColumn::make('subject')
                    ->label('Тема')
                    ->limit(48)
                    ->tooltip(fn (?string $state): ?string => $state),
                IconColumn::make('is_active')
                    ->label('Активний')
                    ->boolean(),
                IconColumn::make('is_system')
                    ->label('Системний')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->label('Оновлено')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label('Подія')
                    ->options(OrderNotificationEvent::options()),
                SelectFilter::make('channel')
                    ->label('Канал')
                    ->options(NotificationChannel::options()),
                TernaryFilter::make('is_active')
                    ->label('Активний'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotificationTemplates::route('/'),
            'view' => ViewNotificationTemplate::route('/{record}'),
            'edit' => EditNotificationTemplate::route('/{record}/edit'),
        ];
    }
}
