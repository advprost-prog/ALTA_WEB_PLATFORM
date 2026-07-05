<?php

namespace App\Filament\Pages;

use App\Models\CommerceSetting;
use App\Models\Currency;
use App\Models\Warehouse;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class CommerceSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Налаштування магазину';

    protected static ?string $title = 'Налаштування магазину';

    protected static string|\UnitEnum|null $navigationGroup = 'Система';

    protected static ?int $navigationSort = 69;

    protected static ?string $slug = 'commerce-settings';

    protected string $view = 'filament-panels::pages.page';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        $this->fillForm();
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Базовий режим')
                    ->schema([
                        Toggle::make('multi_currency_enabled')
                            ->label('Увімкнути мультивалютність'),
                        Toggle::make('multi_warehouse_enabled')
                            ->label('Увімкнути декілька складів'),
                        Select::make('default_currency_id')
                            ->label('Валюта за замовчуванням')
                            ->options(fn (): array => Currency::query()
                                ->where('is_active', true)
                                ->orderByDesc('is_base')
                                ->orderBy('code')
                                ->pluck('code', 'id')
                                ->all())
                            ->searchable()
                            ->required(),
                        Select::make('default_warehouse_id')
                            ->label('Склад за замовчуванням')
                            ->options(fn (): array => Warehouse::query()
                                ->where('is_active', true)
                                ->orderByDesc('is_default')
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->required(),
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
                                ->label('Зберегти налаштування')
                                ->submit('save')
                                ->keyBindings(['mod+s']),
                        ]),
                    ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $settings = $this->settings();

        $settings->forceFill([
            'multi_currency_enabled' => (bool) ($data['multi_currency_enabled'] ?? false),
            'multi_warehouse_enabled' => (bool) ($data['multi_warehouse_enabled'] ?? false),
            'default_currency_id' => (int) $data['default_currency_id'],
            'default_warehouse_id' => (int) $data['default_warehouse_id'],
        ])->save();

        $this->fillForm();

        Notification::make()
            ->success()
            ->title('Налаштування магазину збережено')
            ->send();
    }

    private function fillForm(): void
    {
        $settings = $this->settings();

        $this->form->fill([
            'multi_currency_enabled' => $settings->multi_currency_enabled,
            'multi_warehouse_enabled' => $settings->multi_warehouse_enabled,
            'default_currency_id' => $settings->default_currency_id,
            'default_warehouse_id' => $settings->default_warehouse_id,
        ]);
    }

    private function settings(): CommerceSetting
    {
        return CommerceSetting::current()->refresh();
    }
}
