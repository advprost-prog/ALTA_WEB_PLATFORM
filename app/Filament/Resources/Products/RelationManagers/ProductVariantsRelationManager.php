<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Models\ProductVariant;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductVariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    protected static ?string $title = 'Варіанти / SKU';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('sku')->label('SKU')->unique(ignoreRecord: true)->maxLength(255),
            TextInput::make('name')->label('Назва варіанту')->maxLength(255),
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
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('sku')->label('SKU')->searchable(),
                TextColumn::make('name')->label('Назва')->placeholder('-'),
                TextColumn::make('baseUnit.short_name')->label('Базова од.')->placeholder('-'),
                TextColumn::make('taxProfile.code')->label('Оподаткування')->placeholder('-'),
                IconColumn::make('is_excise_applicable')->label('Акциз')->boolean(),
                IconColumn::make('requires_excise_stamp_entry')->label('Марка')->boolean(),
                IconColumn::make('is_default')->label('Основний')->boolean(),
                IconColumn::make('is_active')->label('Активний')->boolean(),
            ])
            ->headerActions([
                CreateAction::make()->label('Створити варіант'),
            ])
            ->recordActions([
                Action::make('openVariant')
                    ->label('Відкрити')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (ProductVariant $record): string => ProductVariantResource::getUrl('edit', ['record' => $record])),
                Action::make('makeDefault')
                    ->label('Зробити основним')
                    ->icon(Heroicon::OutlinedStar)
                    ->visible(fn (ProductVariant $record): bool => ! $record->is_default)
                    ->action(fn (ProductVariant $record) => $record->forceFill(['is_default' => true, 'is_active' => true])->save()),
                Action::make('deactivate')
                    ->label('Деактивувати')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->visible(fn (ProductVariant $record): bool => $record->is_active)
                    ->requiresConfirmation()
                    ->action(fn (ProductVariant $record) => $record->forceFill(['is_active' => false])->save()),
                EditAction::make(),
            ]);
    }
}
