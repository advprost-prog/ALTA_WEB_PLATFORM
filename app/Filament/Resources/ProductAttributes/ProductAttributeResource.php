<?php

namespace App\Filament\Resources\ProductAttributes;

use App\Filament\Resources\ProductAttributes\Pages\CreateProductAttribute;
use App\Filament\Resources\ProductAttributes\Pages\EditProductAttribute;
use App\Filament\Resources\ProductAttributes\Pages\ListProductAttributes;
use App\Models\ProductAttribute;
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

class ProductAttributeResource extends Resource
{
    protected static ?string $model = ProductAttribute::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|\UnitEnum|null $navigationGroup = 'Каталог';

    protected static ?int $navigationSort = 37;

    protected static ?string $modelLabel = 'атрибут';

    protected static ?string $pluralModelLabel = 'Атрибути';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Атрибут')
                ->schema([
                    TextInput::make('name')->label('Назва')->required()->maxLength(255),
                    TextInput::make('code')->label('Code')->required()->unique(ignoreRecord: true)->maxLength(100),
                    Select::make('type')->label('Тип')->options([
                        'text' => 'Text',
                        'number' => 'Number',
                        'boolean' => 'Boolean',
                    ])->default('text')->required(),
                    Select::make('unit_id')->label('Одиниця')->relationship('unit', 'name')->searchable()->preload(),
                    Toggle::make('is_filterable')->label('Фільтр')->default(false),
                    Toggle::make('is_comparable')->label('Порівняння')->default(false),
                    Toggle::make('is_visible_on_product')->label('Видимий на товарі')->default(true),
                    Toggle::make('is_required')->label('Обовʼязковий')->default(false),
                    Toggle::make('is_active')->label('Активний')->default(true),
                    TextInput::make('sort_order')->label('Порядок')->numeric()->required()->default(0)->minValue(0),
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
                TextColumn::make('code')->label('Code')->searchable(),
                TextColumn::make('type')->label('Тип'),
                TextColumn::make('unit.short_name')->label('Од.')->placeholder('-'),
                IconColumn::make('is_filterable')->label('Фільтр')->boolean(),
                IconColumn::make('is_comparable')->label('Comp')->boolean(),
                IconColumn::make('is_active')->label('Активний')->boolean(),
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
            'index' => ListProductAttributes::route('/'),
            'create' => CreateProductAttribute::route('/create'),
            'edit' => EditProductAttribute::route('/{record}/edit'),
        ];
    }
}
