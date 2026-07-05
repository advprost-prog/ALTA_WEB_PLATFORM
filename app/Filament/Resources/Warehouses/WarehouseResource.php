<?php

namespace App\Filament\Resources\Warehouses;

use App\Filament\Resources\Warehouses\Pages\CreateWarehouse;
use App\Filament\Resources\Warehouses\Pages\EditWarehouse;
use App\Filament\Resources\Warehouses\Pages\ListWarehouses;
use App\Filament\Resources\Warehouses\Pages\ViewWarehouse;
use App\Models\Warehouse;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
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

class WarehouseResource extends Resource
{
    protected static ?string $model = Warehouse::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|\UnitEnum|null $navigationGroup = 'Система';

    protected static ?int $navigationSort = 71;

    protected static ?string $modelLabel = 'склад';

    protected static ?string $pluralModelLabel = 'Склади';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Склад')
                    ->schema([
                        TextInput::make('name')
                            ->label('Назва')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('code')
                            ->label('Код')
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Toggle::make('is_default')
                            ->label('За замовчуванням')
                            ->default(false),
                        Toggle::make('is_active')
                            ->label('Активний')
                            ->default(true),
                    ])
                    ->columns(2),
                Section::make('Адреса')
                    ->schema([
                        Textarea::make('address')
                            ->label('Адреса')
                            ->rows(4),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name')->label('Назва'),
                TextEntry::make('code')->label('Код')->placeholder('-'),
                TextEntry::make('address')->label('Адреса')->placeholder('-')->columnSpanFull(),
                IconEntry::make('is_default')->label('За замовчуванням')->boolean(),
                IconEntry::make('is_active')->label('Активний')->boolean(),
                TextEntry::make('created_at')->label('Створено')->dateTime()->placeholder('-'),
                TextEntry::make('updated_at')->label('Оновлено')->dateTime()->placeholder('-'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Назва')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->label('Код')
                    ->placeholder('-')
                    ->searchable(),
                IconColumn::make('is_default')
                    ->label('Дефолтний')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Активний')
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_default')->label('Дефолтний'),
                TernaryFilter::make('is_active')->label('Активний'),
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
            'index' => ListWarehouses::route('/'),
            'create' => CreateWarehouse::route('/create'),
            'view' => ViewWarehouse::route('/{record}'),
            'edit' => EditWarehouse::route('/{record}/edit'),
        ];
    }
}
