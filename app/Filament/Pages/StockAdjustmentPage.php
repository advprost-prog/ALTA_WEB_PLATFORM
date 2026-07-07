<?php

namespace App\Filament\Pages;

use App\Models\CommerceSetting;
use App\Models\Product;
use App\Models\StockMovement;
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

class StockAdjustmentPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $navigationLabel = 'Коригування залишку';

    protected static ?string $title = 'Коригування залишку';

    protected static string|\UnitEnum|null $navigationGroup = 'Продажі';

    protected static ?int $navigationSort = 21;

    protected static ?string $slug = 'stock-adjustment';

    protected string $view = 'filament-panels::pages.page';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->canAccessArea('sales') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'mode' => 'absolute',
        ]);
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Операція')
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
                        Select::make('warehouse_id')
                            ->label('Склад')
                            ->options(fn (): array => Warehouse::query()
                                ->where('is_active', true)
                                ->orderByDesc('is_default')
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->required(fn (): bool => CommerceSetting::current()->multi_warehouse_enabled)
                            ->visible(fn (): bool => CommerceSetting::current()->multi_warehouse_enabled),
                        Select::make('mode')
                            ->label('Режим')
                            ->options([
                                'absolute' => 'Нова кількість',
                                'delta' => 'Зміна на +/-',
                            ])
                            ->default('absolute')
                            ->required(),
                        TextInput::make('quantity')
                            ->label('Кількість')
                            ->numeric()
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
                                ->label('Застосувати коригування')
                                ->submit('save'),
                        ]),
                    ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $settings = CommerceSetting::current();
        $product = Product::query()->findOrFail((int) $data['product_id']);
        $warehouseId = (int) ($settings->multi_warehouse_enabled
            ? $data['warehouse_id']
            : $settings->default_warehouse_id);
        $quantity = (float) $data['quantity'];
        $note = filled($data['note'] ?? null)
            ? (string) $data['note']
            : 'Ручне коригування залишку';

        try {
            if (($data['mode'] ?? 'absolute') === 'delta') {
                app(StockService::class)->applyDelta(
                    subject: $product,
                    warehouseId: $warehouseId,
                    delta: $quantity,
                    type: StockMovement::TYPE_ADJUSTMENT,
                    note: $note,
                    createdBy: Auth::id(),
                );
            } else {
                app(StockService::class)->setQuantity(
                    subject: $product,
                    warehouseId: $warehouseId,
                    newQuantity: $quantity,
                    note: $note,
                    createdBy: Auth::id(),
                );
            }
        } catch (RuntimeException $exception) {
            Notification::make()
                ->warning()
                ->title('Коригування не застосовано')
                ->body($exception->getMessage())
                ->send();

            return;
        }

        $this->form->fill(['mode' => 'absolute']);

        Notification::make()
            ->success()
            ->title('Залишок оновлено')
            ->send();
    }
}
