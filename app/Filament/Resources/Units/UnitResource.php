<?php

namespace App\Filament\Resources\Units;

use App\Filament\Resources\Units\Pages\CreateUnit;
use App\Filament\Resources\Units\Pages\EditUnit;
use App\Filament\Resources\Units\Pages\ListUnits;
use App\Models\Unit;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UnitResource extends Resource
{
    protected static ?string $model = Unit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|\UnitEnum|null $navigationGroup = 'Каталог';

    protected static ?int $navigationSort = 35;

    protected static ?string $modelLabel = 'одиницю виміру';

    protected static ?string $pluralModelLabel = 'Одиниці виміру';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Одиниця виміру')
                ->schema([
                    TextInput::make('name')->label('Назва')->required()->maxLength(255),
                    TextInput::make('short_name')->label('Коротка назва')->required()->maxLength(50),
                    TextInput::make('code')->label('Code')->required()->unique(ignoreRecord: true)->maxLength(50),
                    TextInput::make('international_code')->label('International code')->maxLength(50),
                    Select::make('type')->label('Тип')->options([
                        'count' => 'Count',
                        'weight' => 'Weight',
                        'length' => 'Length',
                        'area' => 'Area',
                        'volume' => 'Volume',
                    ]),
                    TextInput::make('precision')->label('Точність')->numeric()->required()->default(0)->minValue(0)->maxValue(6),
                    TextInput::make('sort_order')->label('Порядок')->numeric()->required()->default(0)->minValue(0),
                    Toggle::make('is_fractional')->label('Дробова')->default(false),
                    Toggle::make('is_active')->label('Активна')->default(true),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->label('Назва')->searchable(),
                TextColumn::make('short_name')->label('Коротко'),
                TextColumn::make('code')->label('Code')->searchable(),
                TextColumn::make('type')->label('Тип')->placeholder('-'),
                TextColumn::make('precision')->label('Точність')->sortable(),
                IconColumn::make('is_fractional')->label('Дробова')->boolean(),
                IconColumn::make('is_active')->label('Активна')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Активність'),
            ])
            ->recordActions([
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
            'index' => ListUnits::route('/'),
            'create' => CreateUnit::route('/create'),
            'edit' => EditUnit::route('/{record}/edit'),
        ];
    }
}
