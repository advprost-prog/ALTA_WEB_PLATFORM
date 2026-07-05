<?php

namespace App\Filament\Resources\Currencies;

use App\Filament\Resources\Currencies\Pages\CreateCurrency;
use App\Filament\Resources\Currencies\Pages\EditCurrency;
use App\Filament\Resources\Currencies\Pages\ListCurrencies;
use App\Filament\Resources\Currencies\Pages\ViewCurrency;
use App\Models\Currency;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
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
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CurrencyResource extends Resource
{
    protected static ?string $model = Currency::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|\UnitEnum|null $navigationGroup = 'Система';

    protected static ?int $navigationSort = 70;

    protected static ?string $modelLabel = 'валюта';

    protected static ?string $pluralModelLabel = 'Валюти';

    protected static ?string $recordTitleAttribute = 'code';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Валюта')
                    ->schema([
                        TextInput::make('code')
                            ->label('Код')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(3)
                            ->dehydrateStateUsing(fn (?string $state): ?string => $state ? mb_strtoupper($state) : null),
                        TextInput::make('name')
                            ->label('Назва')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('symbol')
                            ->label('Символ')
                            ->maxLength(16),
                        TextInput::make('precision')
                            ->label('Точність')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(6)
                            ->default(2)
                            ->required(),
                        TextInput::make('rate_to_base')
                            ->label('Курс до базової')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('Заповнюється вручну. Автоматичні курси не ввімкнені у першій фазі.'),
                        Toggle::make('is_base')
                            ->label('Базова')
                            ->default(false),
                        Toggle::make('is_active')
                            ->label('Активна')
                            ->default(true),
                    ])
                    ->columns(3),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('code')->label('Код'),
                TextEntry::make('name')->label('Назва'),
                TextEntry::make('symbol')->label('Символ')->placeholder('-'),
                TextEntry::make('precision')->label('Точність'),
                TextEntry::make('rate_to_base')->label('Курс до базової')->placeholder('-'),
                IconEntry::make('is_base')->label('Базова')->boolean(),
                IconEntry::make('is_active')->label('Активна')->boolean(),
                TextEntry::make('created_at')->label('Створено')->dateTime()->placeholder('-'),
                TextEntry::make('updated_at')->label('Оновлено')->dateTime()->placeholder('-'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code')
            ->columns([
                TextColumn::make('code')
                    ->label('Код')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Назва')
                    ->searchable(),
                TextColumn::make('symbol')
                    ->label('Символ')
                    ->placeholder('-'),
                TextColumn::make('rate_to_base')
                    ->label('Курс')
                    ->placeholder('-'),
                IconColumn::make('is_base')
                    ->label('Базова')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Активна')
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_base')->label('Базова'),
                TernaryFilter::make('is_active')->label('Активна'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCurrencies::route('/'),
            'create' => CreateCurrency::route('/create'),
            'view' => ViewCurrency::route('/{record}'),
            'edit' => EditCurrency::route('/{record}/edit'),
        ];
    }
}
