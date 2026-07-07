<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\DeliveryMethod;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class CustomerAddressesRelationManager extends RelationManager
{
    protected static string $relationship = 'addresses';

    protected static ?string $title = 'Адреси';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Customer && Gate::allows('view', $ownerRecord);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->label('Тип')
                    ->options(CustomerAddress::typeOptions())
                    ->default(CustomerAddress::TYPE_DELIVERY)
                    ->required(),
                Toggle::make('is_default')
                    ->label('Default')
                    ->default(false),
                TextInput::make('recipient_name')
                    ->label('Отримувач')
                    ->maxLength(255),
                TextInput::make('recipient_phone')
                    ->label('Телефон отримувача')
                    ->tel()
                    ->maxLength(255),
                TextInput::make('city')
                    ->label('Місто')
                    ->maxLength(255),
                TextInput::make('postal_code')
                    ->label('Індекс')
                    ->maxLength(255),
                Select::make('delivery_method_id')
                    ->label('Спосіб доставки')
                    ->options(fn (): array => DeliveryMethod::query()->ordered()->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload(),
                TextInput::make('provider')
                    ->label('Provider')
                    ->maxLength(255),
                TextInput::make('warehouse_ref')
                    ->label('Warehouse ref')
                    ->maxLength(255),
                Textarea::make('address')
                    ->label('Адреса')
                    ->rows(3)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('address')
            ->columns([
                TextColumn::make('type')
                    ->label('Тип')
                    ->formatStateUsing(fn (?string $state): string => CustomerAddress::typeOptions()[$state] ?? (string) $state)
                    ->badge(),
                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),
                TextColumn::make('recipient_name')
                    ->label('Отримувач')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('recipient_phone')
                    ->label('Телефон')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('city')
                    ->label('Місто')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('address')
                    ->label('Адреса')
                    ->placeholder('-')
                    ->wrap()
                    ->searchable(),
                TextColumn::make('deliveryMethod.name')
                    ->label('Доставка')
                    ->placeholder('-'),
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
