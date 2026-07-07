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

class VariantPackagesRelationManager extends RelationManager
{
    protected static string $relationship = 'packages';

    protected static ?string $title = 'Пакування';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('unit_id')->label('Одиниця')->relationship('unit', 'name')->searchable()->preload()->required(),
            TextInput::make('name')->label('Назва')->required()->maxLength(255),
            TextInput::make('quantity_in_base_unit')->label('Кількість у базовій одиниці')->numeric()->required()->default(1),
            TextInput::make('barcode')->label('Штрихкод')->maxLength(255),
            Toggle::make('is_default_sales_package')->label('Продажне за замовчуванням')->default(false),
            Toggle::make('is_active')->label('Активне')->default(true),
            TextInput::make('sort_order')->label('Порядок')->numeric()->required()->default(0)->minValue(0),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->label('Назва'),
                TextColumn::make('unit.short_name')->label('Од.'),
                TextColumn::make('quantity_in_base_unit')->label('Базова кількість'),
                TextColumn::make('barcode')->label('Штрихкод')->placeholder('-'),
                IconColumn::make('is_default_sales_package')->label('Основне')->boolean(),
                IconColumn::make('is_active')->label('Активне')->boolean(),
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
