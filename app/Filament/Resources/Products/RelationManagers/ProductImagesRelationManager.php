<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Models\Product;
use App\Models\ProductImage;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;

class ProductImagesRelationManager extends RelationManager
{
    protected static string $relationship = 'images';

    protected static ?string $title = 'Галерея фото';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Product && Gate::allows('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('image')
            ->columns([
                ImageColumn::make('image_url')
                    ->label('Фото')
                    ->square(),
                TextColumn::make('image')
                    ->label('Файл')
                    ->wrap()
                    ->searchable(),
                IconColumn::make('is_main')
                    ->label('Головне')
                    ->boolean(),
                TextColumn::make('sort_order')
                    ->label('Порядок')
                    ->sortable(),
                TextColumn::make('source_domain')
                    ->label('Джерело')
                    ->placeholder('-'),
                TextColumn::make('quality_score')
                    ->label('Quality')
                    ->suffix('%')
                    ->placeholder('-'),
                TextColumn::make('importedBy.name')
                    ->label('Імпортував')
                    ->placeholder('-'),
            ])
            ->recordActions([
                Action::make('setMain')
                    ->label('Головне')
                    ->icon(Heroicon::OutlinedStar)
                    ->color('warning')
                    ->visible(fn (ProductImage $record): bool => ! $record->is_main && Gate::allows('update', $this->getOwnerRecord()))
                    ->action(function (ProductImage $record): void {
                        $record->setAsMain();

                        Notification::make()
                            ->success()
                            ->title('Фото встановлено як головне')
                            ->send();
                    }),
                Action::make('openSource')
                    ->label('Джерело')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (ProductImage $record): ?string => $record->source_url, shouldOpenInNewTab: true)
                    ->visible(fn (ProductImage $record): bool => filled($record->source_url)),
                Action::make('deleteGalleryRecord')
                    ->label('Видалити')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Запис буде видалено з галереї. Фізичний файл не видаляється автоматично.')
                    ->visible(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
                    ->action(function (ProductImage $record): void {
                        $record->delete();

                        Notification::make()
                            ->success()
                            ->title('Фото прибрано з галереї')
                            ->send();
                    }),
            ]);
    }

    #[On('product-images-imported')]
    public function refreshAfterProductImagesImported(): void
    {
        $this->resetTable();
    }
}
