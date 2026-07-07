<?php

namespace App\Filament\Resources\ProductVariants\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductBarcodesRelationManager extends RelationManager
{
    protected static string $relationship = 'barcodes';

    protected static ?string $title = 'Штрихкоди';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('barcode')->label('Штрихкод')->required()->maxLength(255),
            Select::make('type')->label('Тип')->options([
                'ean13' => 'EAN13',
                'ean8' => 'EAN8',
                'upc' => 'UPC',
                'internal' => 'Internal',
                'supplier' => 'Supplier',
            ])->default('ean13')->required(),
            Select::make('variant_package_id')->label('Пакування')->relationship('package', 'name')->searchable()->preload(),
            Toggle::make('is_primary')->label('Основний')->default(false),
            Toggle::make('is_active')->label('Активний')->default(true),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('barcode')->label('Штрихкод')->searchable(),
                TextColumn::make('type')->label('Тип'),
                TextColumn::make('package.name')->label('Пакування')->placeholder('-'),
                IconColumn::make('is_primary')->label('Основний')->boolean(),
                IconColumn::make('is_active')->label('Активний')->boolean(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
