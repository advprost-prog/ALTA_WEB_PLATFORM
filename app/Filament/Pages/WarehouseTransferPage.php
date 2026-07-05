<?php

namespace App\Filament\Pages;

use App\Models\CommerceSetting;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Commerce\StockService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class WarehouseTransferPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $navigationLabel = 'Переміщення між складами';

    protected static ?string $title = 'Переміщення між складами';

    protected static string|\UnitEnum|null $navigationGroup = 'Продажі';

    protected static ?int $navigationSort = 22;

    protected static ?string $slug = 'warehouse-transfer';

    protected string $view = 'filament-panels::pages.page';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (Auth::user()?->canAccessArea('sales') ?? false)
            && CommerceSetting::current()->multi_warehouse_enabled;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Переміщення')
                    ->schema([
                        Select::make('product_id')
                            ->label('Товар')
                            ->options(fn (): array => Product::query()
                                ->orderBy('name')
                                ->limit(50)
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => Product::query()
                                ->where('name', 'like', '%'.$search.'%')
                                ->orWhere('sku', 'like', '%'.$search.'%')
                                ->orderBy('name')
                                ->limit(50)
                                ->pluck('name', 'id')
                                ->all())
                            ->getOptionLabelUsing(fn ($value): ?string => Product::query()->whereKey($value)->value('name'))
                            ->required(),
                        Select::make('source_warehouse_id')
                            ->label('Склад-джерело')
                            ->options(fn (): array => $this->warehouseOptions())
                            ->searchable()
                            ->required(),
                        Select::make('target_warehouse_id')
                            ->label('Склад-отримувач')
                            ->options(fn (): array => $this->warehouseOptions())
                            ->searchable()
                            ->required(),
                        TextInput::make('quantity')
                            ->label('Кількість')
                            ->numeric()
                            ->minValue(0.001)
                            ->required(),
                        Textarea::make('note')
                            ->label('Примітка')
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->livewireSubmitHandler('save')
                    ->footer([
                        SchemaActions::make([
                            Action::make('save')
                                ->label('Перемістити')
                                ->submit('save'),
                        ]),
                    ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $product = Product::query()->findOrFail((int) $data['product_id']);

        try {
            app(StockService::class)->transfer(
                product: $product,
                sourceWarehouseId: (int) $data['source_warehouse_id'],
                targetWarehouseId: (int) $data['target_warehouse_id'],
                quantity: (float) $data['quantity'],
                note: filled($data['note'] ?? null) ? (string) $data['note'] : 'Переміщення між складами',
                createdBy: Auth::id(),
            );
        } catch (RuntimeException $exception) {
            Notification::make()
                ->warning()
                ->title('Переміщення не виконано')
                ->body($exception->getMessage())
                ->send();

            return;
        }

        $this->form->fill();

        Notification::make()
            ->success()
            ->title('Товар переміщено')
            ->send();
    }

    /**
     * @return array<int, string>
     */
    private function warehouseOptions(): array
    {
        return Warehouse::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
