<?php

namespace App\Filament\Resources\ProductVariants\RelationManagers;

use App\Models\ProductImage;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductVariantImagesRelationManager extends RelationManager
{
    protected static string $relationship = 'images';

    protected static ?string $title = 'Фото SKU';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('product_id')->default(fn (): ?int => $this->getOwnerRecord()?->product_id),
            Hidden::make('type')->default('image'),
            FileUpload::make('image')
                ->label('Фото')
                ->image()
                ->disk('public')
                ->directory('products/variants')
                ->visibility('public')
                ->required(),
            TextInput::make('alt')->label('Alt')->maxLength(255),
            TextInput::make('sort_order')->label('Порядок')->numeric()->required()->default(0)->minValue(0),
            Toggle::make('is_main')->label('Головне')->default(false),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                ImageColumn::make('image_url')->label('Фото')->square(),
                TextColumn::make('alt')->label('Alt')->placeholder('-'),
                IconColumn::make('is_main')->label('Головне')->boolean(),
                TextColumn::make('sort_order')->label('Порядок'),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                Action::make('setMain')
                    ->label('Головне')
                    ->icon(Heroicon::OutlinedStar)
                    ->visible(fn (ProductImage $record): bool => ! $record->is_main)
                    ->action(function (ProductImage $record): void {
                        $record->setAsMain();

                        Notification::make()
                            ->success()
                            ->title('Фото встановлено як головне')
                            ->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
