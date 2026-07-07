<?php

namespace App\Filament\Resources\Customers;

use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\CustomerAddressesRelationManager;
use App\Filament\Resources\Customers\RelationManagers\CustomerOrdersRelationManager;
use App\Models\Customer;
use App\Services\Commerce\CustomerService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|\UnitEnum|null $navigationGroup = 'Продажі';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'клієнта';

    protected static ?string $pluralModelLabel = 'Клієнти';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Master data')
                    ->schema([
                        Select::make('type')
                            ->label('Тип')
                            ->options(Customer::typeOptions())
                            ->default(Customer::TYPE_INDIVIDUAL)
                            ->required(),
                        Toggle::make('is_active')
                            ->label('Активний')
                            ->default(true),
                        TextInput::make('full_name')
                            ->label('Повне імʼя')
                            ->maxLength(255),
                        TextInput::make('company_name')
                            ->label('Компанія')
                            ->maxLength(255),
                        TextInput::make('first_name')
                            ->label('Імʼя')
                            ->maxLength(255),
                        TextInput::make('last_name')
                            ->label('Прізвище')
                            ->maxLength(255),
                        TextInput::make('middle_name')
                            ->label('По батькові')
                            ->maxLength(255),
                    ])
                    ->columns(2),
                Section::make('Контакти')
                    ->schema([
                        TextInput::make('phone')
                            ->label('Телефон')
                            ->tel()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('tax_id')
                            ->label('ІПН')
                            ->maxLength(255),
                        TextInput::make('edrpou')
                            ->label('ЄДРПОУ')
                            ->maxLength(255),
                        Toggle::make('marketing_consent')
                            ->label('Marketing consent')
                            ->default(false),
                    ])
                    ->columns(2),
                Section::make('Адреса і нотатки')
                    ->schema([
                        TextInput::make('city')
                            ->label('Місто')
                            ->maxLength(255),
                        Textarea::make('address')
                            ->label('Legacy адреса')
                            ->rows(3),
                        Textarea::make('notes')
                            ->label('Нотатки')
                            ->rows(3),
                    ])
                    ->columns(2),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Клієнт')
                    ->schema([
                        TextEntry::make('display_name')
                            ->label('Клієнт'),
                        TextEntry::make('type')
                            ->label('Тип')
                            ->formatStateUsing(fn (?string $state): string => Customer::typeOptions()[$state] ?? (string) $state)
                            ->badge(),
                        TextEntry::make('phone')
                            ->label('Телефон')
                            ->placeholder('-'),
                        TextEntry::make('email')
                            ->label('Email')
                            ->placeholder('-'),
                        TextEntry::make('normalized_phone')
                            ->label('Normalized phone')
                            ->placeholder('-'),
                        TextEntry::make('normalized_email')
                            ->label('Normalized email')
                            ->placeholder('-'),
                        TextEntry::make('tax_id')
                            ->label('ІПН')
                            ->placeholder('-'),
                        TextEntry::make('edrpou')
                            ->label('ЄДРПОУ')
                            ->placeholder('-'),
                        TextEntry::make('is_active')
                            ->label('Активний')
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Так' : 'Ні')
                            ->badge()
                            ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                        TextEntry::make('marketing_consent')
                            ->label('Marketing consent')
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Так' : 'Ні')
                            ->badge()
                            ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                        TextEntry::make('city')
                            ->label('Legacy місто')
                            ->placeholder('-'),
                        TextEntry::make('address')
                            ->label('Legacy адреса')
                            ->placeholder('-')
                            ->columnSpanFull(),
                        TextEntry::make('notes')
                            ->label('Нотатки')
                            ->placeholder('-')
                            ->columnSpanFull(),
                        TextEntry::make('potential_duplicates')
                            ->label('Potential duplicates')
                            ->state(fn (Customer $record): string => app(CustomerService::class)
                                ->findPotentialDuplicates($record)
                                ->map(fn (Customer $duplicate): string => '#'.$duplicate->id.' '.$duplicate->display_name)
                                ->join(', ') ?: '-')
                            ->columnSpanFull(),
                        TextEntry::make('created_at')
                            ->label('Створено')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('updated_at')
                            ->label('Оновлено')
                            ->dateTime()
                            ->placeholder('-'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withCount('orders')
                ->withSum('orders', 'total_amount')
                ->withMax('orders', 'created_at')
                ->latest())
            ->columns([
                TextColumn::make('display_name')
                    ->label('Клієнт')
                    ->state(fn (Customer $record): string => $record->display_name)
                    ->description(fn (Customer $record): ?string => $record->company_name && $record->type !== Customer::TYPE_COMPANY ? $record->company_name : null)
                    ->searchable(['name', 'full_name', 'company_name', 'first_name', 'last_name']),
                TextColumn::make('phone')
                    ->label('Телефон')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('type')
                    ->label('Тип')
                    ->formatStateUsing(fn (?string $state): string => Customer::typeOptions()[$state] ?? (string) $state)
                    ->badge(),
                TextColumn::make('orders_count')
                    ->label('Замовлень')
                    ->sortable(),
                TextColumn::make('orders_sum_total_amount')
                    ->label('Витрачено')
                    ->formatStateUsing(fn (mixed $state): string => number_format((float) ($state ?? 0), 2, '.', ' ').' UAH')
                    ->sortable(),
                TextColumn::make('orders_max_created_at')
                    ->label('Останнє')
                    ->dateTime()
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('is_active')
                    ->label('Активний')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Так' : 'Ні')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                TextColumn::make('created_at')
                    ->label('Створено')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Тип')
                    ->options(Customer::typeOptions()),
                TernaryFilter::make('is_active')
                    ->label('Активний'),
                Filter::make('has_orders')
                    ->label('Має замовлення')
                    ->query(fn (Builder $query): Builder => $query->has('orders')),
                Filter::make('created_at')
                    ->label('Дата створення')
                    ->schema([
                        DatePicker::make('created_from')->label('Від'),
                        DatePicker::make('created_until')->label('До'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['created_from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['created_until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                Action::make('deactivate')
                    ->label('Деактивувати')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('gray')
                    ->visible(fn (Customer $record): bool => $record->is_active)
                    ->requiresConfirmation()
                    ->action(fn (Customer $record): bool => $record->forceFill(['is_active' => false])->save()),
                ViewAction::make(),
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
            CustomerAddressesRelationManager::class,
            CustomerOrdersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'view' => ViewCustomer::route('/{record}'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
}
