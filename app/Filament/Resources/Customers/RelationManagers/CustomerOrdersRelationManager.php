<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Customer;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class CustomerOrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    protected static ?string $title = 'Замовлення';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Customer && Gate::allows('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Дата')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('number')
                    ->label('Номер')
                    ->searchable(),
                TextColumn::make('customer_name')
                    ->label('Snapshot імʼя')
                    ->searchable(),
                TextColumn::make('phone')
                    ->label('Snapshot телефон')
                    ->searchable(),
                TextColumn::make('total_amount')
                    ->label('Сума')
                    ->formatStateUsing(fn (mixed $state, Order $record): string => number_format((float) $state, 2, '.', ' ').' '.($record->currency_code ?: 'UAH'))
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => OrderStatus::labelFor($state))
                    ->color(fn (?string $state): string => OrderStatus::colorFor($state)),
            ])
            ->recordActions([
                Action::make('viewOrder')
                    ->label('Переглянути')
                    ->icon(Heroicon::OutlinedEye)
                    ->url(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
