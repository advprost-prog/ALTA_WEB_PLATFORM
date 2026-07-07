<?php

namespace App\Filament\Resources\ProductVariants;

use App\Filament\Resources\ProductVariants\Pages\CreateProductVariant;
use App\Filament\Resources\ProductVariants\Pages\EditProductVariant;
use App\Filament\Resources\ProductVariants\Pages\ListProductVariants;
use App\Filament\Resources\ProductVariants\RelationManagers\ProductBarcodesRelationManager;
use App\Filament\Resources\ProductVariants\RelationManagers\ProductVariantImagesRelationManager;
use App\Filament\Resources\ProductVariants\RelationManagers\VariantPackagesRelationManager;
use App\Models\ProductVariant;
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

class ProductVariantResource extends Resource
{
    protected static ?string $model = ProductVariant::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static string|\UnitEnum|null $navigationGroup = 'Каталог';

    protected static ?int $navigationSort = 15;

    protected static ?string $modelLabel = 'SKU';

    protected static ?string $pluralModelLabel = 'SKU / Варіанти';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('SKU / Варіант')
                ->schema([
                    Select::make('product_id')->label('Товар')->relationship('product', 'name')->searchable()->preload()->required(),
                    TextInput::make('sku')->label('SKU')->unique(ignoreRecord: true)->maxLength(255),
                    TextInput::make('name')->label('Назва варіанту')->maxLength(255),
                    TextInput::make('barcode')->label('Штрихкод')->maxLength(255),
                    Select::make('base_unit_id')->label('Базова одиниця')->relationship('baseUnit', 'name')->searchable()->preload()->required(),
                    Select::make('sales_unit_id')->label('Одиниця продажу')->relationship('salesUnit', 'name')->searchable()->preload(),
                    Select::make('purchase_unit_id')->label('Одиниця закупівлі')->relationship('purchaseUnit', 'name')->searchable()->preload(),
                    Select::make('tax_profile_id')->label('Оподаткування')->relationship('taxProfile', 'name')->searchable()->preload()->required(),
                    Toggle::make('is_excise_applicable')
                        ->label('Акцизний товар')
                        ->live()
                        ->afterStateUpdated(function (bool $state, callable $get, callable $set): void {
                            if (! $state) {
                                $set('excise_rate', null);
                                $set('requires_excise_stamp_entry', false);

                                return;
                            }

                            if (blank($get('excise_rate'))) {
                                $set('excise_rate', '5.00');
                            }
                        }),
                    TextInput::make('excise_rate')
                        ->label('Ставка акцизу, %')
                        ->numeric()
                        ->visible(fn (callable $get): bool => (bool) $get('is_excise_applicable')),
                    Toggle::make('requires_excise_stamp_entry')
                        ->label('Потребує введення акцизної марки')
                        ->visible(fn (callable $get): bool => (bool) $get('is_excise_applicable')),
                    Toggle::make('is_default')->label('Основний SKU')->default(false),
                    Toggle::make('is_active')->label('Активний')->default(true),
                    TextInput::make('sort_order')->label('Порядок')->numeric()->required()->default(0)->minValue(0),
                    TextInput::make('weight')->label('Вага')->numeric(),
                    TextInput::make('length')->label('Довжина')->numeric(),
                    TextInput::make('width')->label('Ширина')->numeric(),
                    TextInput::make('height')->label('Висота')->numeric(),
                    TextInput::make('external_source')->label('External source')->maxLength(255),
                    TextInput::make('external_id')->label('External ID')->maxLength(255),
                    TextInput::make('external_code')->label('External code')->maxLength(255),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('product.name')->label('Товар')->searchable(),
                TextColumn::make('sku')->label('SKU')->searchable(),
                TextColumn::make('name')->label('Назва')->placeholder('-'),
                TextColumn::make('baseUnit.short_name')->label('Базова од.')->placeholder('-'),
                TextColumn::make('taxProfile.code')->label('Оподаткування')->placeholder('-'),
                IconColumn::make('is_excise_applicable')->label('Акциз')->boolean(),
                TextColumn::make('excise_rate')->label('Акциз, %')->placeholder('-'),
                IconColumn::make('is_default')->label('Основний')->boolean(),
                IconColumn::make('is_active')->label('Активний')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Активність'),
                TernaryFilter::make('is_excise_applicable')->label('Акциз'),
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

    public static function getRelations(): array
    {
        return [
            VariantPackagesRelationManager::class,
            ProductBarcodesRelationManager::class,
            ProductVariantImagesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductVariants::route('/'),
            'create' => CreateProductVariant::route('/create'),
            'edit' => EditProductVariant::route('/{record}/edit'),
        ];
    }
}
