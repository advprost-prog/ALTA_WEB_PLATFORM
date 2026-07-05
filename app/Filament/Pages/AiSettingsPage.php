<?php

namespace App\Filament\Pages;

use App\Models\AiRun;
use App\Models\AiSetting;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\OpenAiUsageService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Throwable;

class AiSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static ?string $navigationLabel = 'AI налаштування';

    protected static ?string $title = 'AI налаштування';

    protected static string|\UnitEnum|null $navigationGroup = 'AI';

    protected static ?int $navigationSort = 0;

    protected static ?string $slug = 'ai-settings';

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
                Section::make('Статус і бюджет')
                    ->schema([
                        Toggle::make('enabled')
                            ->label('AI увімкнено'),
                        Select::make('provider')
                            ->label('Provider')
                            ->options(['openai' => 'OpenAI'])
                            ->required(),
                        Select::make('mode')
                            ->label('Режим')
                            ->options([
                                'test' => 'Test',
                                'production' => 'Production',
                            ])
                            ->required(),
                        TextInput::make('model')
                            ->label('Model')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('timeout')
                            ->label('Timeout, секунд')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        TextInput::make('max_input_chars')
                            ->label('Max input chars')
                            ->numeric()
                            ->minValue(1000)
                            ->required(),
                        TextInput::make('max_output_tokens')
                            ->label('Max output tokens')
                            ->numeric()
                            ->minValue(100),
                        TextInput::make('monthly_budget')
                            ->label('Monthly internal budget')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('$'),
                        TextInput::make('warning_threshold_percent')
                            ->label('Warning threshold, %')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100)
                            ->required(),
                        Toggle::make('hard_limit_enabled')
                            ->label('Hard limit enabled'),
                    ])
                    ->columns(3),
                Section::make('Ключі')
                    ->description('Повний ключ не показується після збереження. Порожнє поле не стирає наявний ключ.')
                    ->schema([
                        Placeholder::make('api_key_masked')
                            ->label('Saved API key')
                            ->content(fn (): string => $this->settings()->maskedApiKey()),
                        TextInput::make('api_key')
                            ->label('OpenAI API key')
                            ->password()
                            ->autocomplete('off')
                            ->helperText('Стандартний ключ для model requests. Зберігається encrypted.'),
                        Placeholder::make('admin_api_key_masked')
                            ->label('Saved Admin API key')
                            ->content(fn (): string => $this->settings()->maskedAdminApiKey()),
                        TextInput::make('admin_api_key')
                            ->label('OpenAI Admin API key')
                            ->password()
                            ->autocomplete('off')
                            ->helperText('Опційний ключ для синхронізації фактичних OpenAI costs.'),
                        Placeholder::make('image_search_api_key_masked')
                            ->label('Saved Image Search API key')
                            ->content(fn (): string => $this->settings()->maskedImageSearchApiKey()),
                        TextInput::make('image_search_api_key')
                            ->label('Image Search API key')
                            ->password()
                            ->autocomplete('off')
                            ->helperText('Опційний ключ зовнішнього provider-а. Manual URL provider працює без нього.'),
                    ])
                    ->columns(2),
                Section::make('Image Search Settings')
                    ->description('Provider-based підбір фото без uncontrolled scraping.')
                    ->schema([
                        Toggle::make('image_search_enabled')
                            ->label('Image search увімкнено'),
                        Select::make('image_search_provider')
                            ->label('Provider')
                            ->options([
                                'manual_url' => 'Manual URL',
                                'serpapi' => 'SerpAPI Google Images',
                                'external_stub' => 'External provider stub',
                            ])
                            ->required(),
                        Toggle::make('image_search_safe_mode')
                            ->label('Safe mode')
                            ->default(true),
                        Toggle::make('allow_manual_url_candidates')
                            ->label('Manual URL candidates')
                            ->default(true),
                        TextInput::make('image_search_max_candidates')
                            ->label('Max candidates')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(10)
                            ->required(),
                        TextInput::make('image_search_min_width')
                            ->label('Min width')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        TextInput::make('image_search_min_height')
                            ->label('Min height')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        Select::make('image_search_preferred_format')
                            ->label('Preferred format')
                            ->options(['webp' => 'WebP'])
                            ->required(),
                        TextInput::make('image_search_max_download_size_mb')
                            ->label('Max download, MB')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(20)
                            ->required(),
                    ])
                    ->columns(3),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('AI dashboard')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                Placeholder::make('status')
                                    ->label('AI status')
                                    ->content(fn (): string => $this->settings()->enabled ? 'Enabled' : 'Disabled'),
                                Placeholder::make('model_status')
                                    ->label('Model')
                                    ->content(fn (): string => app(AiSettingsService::class)->getModel()),
                                Placeholder::make('budget')
                                    ->label('Monthly budget')
                                    ->content(fn (): string => $this->formatMoney(app(AiSettingsService::class)->getMonthlyBudget())),
                                Placeholder::make('spend')
                                    ->label('Estimated spend')
                                    ->content(fn (): string => $this->formatMoney(app(AiSettingsService::class)->getCurrentMonthSpendEstimate())),
                                Placeholder::make('remaining')
                                    ->label('Remaining')
                                    ->content(fn (): string => $this->formatMoney(app(AiSettingsService::class)->getEstimatedRemainingBudget())),
                                Placeholder::make('runs_month')
                                    ->label('Runs this month')
                                    ->content(fn (): string => (string) $this->runsThisMonth()),
                                Placeholder::make('failed_runs_month')
                                    ->label('Failed this month')
                                    ->content(fn (): string => (string) $this->failedRunsThisMonth()),
                                Placeholder::make('health')
                                    ->label('Last health')
                                    ->content(fn (): string => $this->settings()->last_health_status ?: 'Not checked'),
                                Placeholder::make('health_checked')
                                    ->label('Health checked at')
                                    ->content(fn (): string => $this->settings()->last_health_checked_at?->format('Y-m-d H:i') ?? '-'),
                                Placeholder::make('usage_synced')
                                    ->label('Usage synced at')
                                    ->content(fn (): string => $this->settings()->last_usage_synced_at?->format('Y-m-d H:i') ?? '-'),
                                Placeholder::make('admin_key_warning')
                                    ->label('Costs sync')
                                    ->content(fn (): string => $this->settings()->hasAdminApiKey()
                                        ? 'Admin API key saved'
                                        : 'Для фактичних OpenAI costs потрібен Admin API key.'),
                                Placeholder::make('hard_limit')
                                    ->label('Hard limit')
                                    ->content(fn (): string => app(AiSettingsService::class)->isHardLimitReached() ? 'Reached' : 'OK'),
                                Placeholder::make('image_search')
                                    ->label('Image search')
                                    ->content(fn (): string => $this->settings()->image_search_enabled ? 'Enabled' : 'Disabled'),
                                Placeholder::make('image_search_provider')
                                    ->label('Image provider')
                                    ->content(fn (): string => $this->settings()->image_search_provider ?: 'manual_url'),
                            ]),
                    ]),
                Form::make([EmbeddedSchema::make('form')])
                    ->livewireSubmitHandler('save')
                    ->footer([
                        SchemaActions::make([
                            Action::make('save')
                                ->label('Зберегти налаштування')
                                ->submit('save')
                                ->keyBindings(['mod+s']),
                            Action::make('deleteApiKey')
                                ->label('Видалити API key')
                                ->color('danger')
                                ->requiresConfirmation()
                                ->action('deleteApiKey'),
                            Action::make('deleteAdminApiKey')
                                ->label('Видалити Admin API key')
                                ->color('danger')
                                ->requiresConfirmation()
                                ->action('deleteAdminApiKey'),
                            Action::make('deleteImageSearchApiKey')
                                ->label('Видалити Image Search API key')
                                ->color('danger')
                                ->requiresConfirmation()
                                ->action('deleteImageSearchApiKey'),
                        ]),
                    ]),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('testConnection')
                ->label('Test connection')
                ->icon(Heroicon::OutlinedBolt)
                ->action('testConnection'),
            Action::make('syncOpenAiCosts')
                ->label('Sync OpenAI costs')
                ->icon(Heroicon::OutlinedArrowPath)
                ->action('syncOpenAiCosts'),
            Action::make('resetEstimate')
                ->label('Reset estimate')
                ->color('gray')
                ->requiresConfirmation()
                ->action('resetEstimate'),
            Action::make('disableAi')
                ->label('Disable AI')
                ->color('danger')
                ->requiresConfirmation()
                ->action('disableAi'),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $settings = $this->settings();

        $settings->forceFill([
            'enabled' => (bool) ($data['enabled'] ?? false),
            'provider' => (string) ($data['provider'] ?? 'openai'),
            'mode' => (string) ($data['mode'] ?? 'test'),
            'model' => (string) ($data['model'] ?? config('ai.openai.model')),
            'timeout' => (int) ($data['timeout'] ?? 60),
            'max_input_chars' => (int) ($data['max_input_chars'] ?? 12000),
            'max_output_tokens' => filled($data['max_output_tokens'] ?? null) ? (int) $data['max_output_tokens'] : null,
            'monthly_budget' => filled($data['monthly_budget'] ?? null) ? (float) $data['monthly_budget'] : null,
            'warning_threshold_percent' => (int) ($data['warning_threshold_percent'] ?? 80),
            'hard_limit_enabled' => (bool) ($data['hard_limit_enabled'] ?? true),
            'image_search_enabled' => (bool) ($data['image_search_enabled'] ?? false),
            'image_search_provider' => (string) ($data['image_search_provider'] ?? 'manual_url'),
            'image_search_safe_mode' => (bool) ($data['image_search_safe_mode'] ?? true),
            'image_search_max_candidates' => max(1, min(10, (int) ($data['image_search_max_candidates'] ?? 5))),
            'image_search_min_width' => max(1, (int) ($data['image_search_min_width'] ?? 600)),
            'image_search_min_height' => max(1, (int) ($data['image_search_min_height'] ?? 600)),
            'image_search_preferred_format' => (string) ($data['image_search_preferred_format'] ?? 'webp'),
            'image_search_max_download_size_mb' => max(1, min(20, (int) ($data['image_search_max_download_size_mb'] ?? 5))),
            'allow_manual_url_candidates' => (bool) ($data['allow_manual_url_candidates'] ?? true),
        ]);

        if (filled($data['api_key'] ?? null)) {
            $settings->api_key = (string) $data['api_key'];
        }

        if (filled($data['admin_api_key'] ?? null)) {
            $settings->admin_api_key = (string) $data['admin_api_key'];
        }

        if (filled($data['image_search_api_key'] ?? null)) {
            $settings->image_search_api_key = (string) $data['image_search_api_key'];
        }

        $settings->save();
        $this->fillForm();

        Notification::make()
            ->success()
            ->title('AI налаштування збережено')
            ->send();
    }

    public function testConnection(): void
    {
        $settings = $this->settings();
        $run = AiRun::create([
            'user_id' => Auth::id(),
            'entity_type' => AiSetting::class,
            'entity_id' => $settings->id,
            'task_type' => 'connection_test',
            'provider' => app(AiSettingsService::class)->getProvider(),
            'model' => app(AiSettingsService::class)->getModel(),
            'input_payload' => ['source' => 'ai_settings_page'],
            'status' => AiRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        try {
            $client = app(AiClient::class);
            $output = $client->testConnection(allowDisabled: true);
            $usage = $client->lastUsage();
            $costEstimate = $client->lastCostEstimate();

            $run->forceFill([
                'output_payload' => $output,
                'status' => AiRun::STATUS_COMPLETED,
                'tokens_input' => $usage['input_tokens'] ?? null,
                'tokens_output' => $usage['output_tokens'] ?? null,
                'cost_estimate' => $costEstimate,
                'finished_at' => now(),
            ])->save();

            if ($costEstimate !== null) {
                app(AiSettingsService::class)->recordSpendEstimate($costEstimate);
            }

            $settings->forceFill([
                'last_health_status' => 'success',
                'last_health_message' => 'AI підключення працює.',
                'last_health_checked_at' => now(),
            ])->save();

            Notification::make()
                ->success()
                ->title('AI підключення працює')
                ->send();
        } catch (Throwable $exception) {
            $message = $exception->getMessage();

            $run->forceFill([
                'status' => AiRun::STATUS_FAILED,
                'error' => $message,
                'finished_at' => now(),
            ])->save();

            $settings->forceFill([
                'last_health_status' => 'failed',
                'last_health_message' => $message,
                'last_health_checked_at' => now(),
            ])->save();

            Notification::make()
                ->danger()
                ->title('AI підключення не працює')
                ->body($message)
                ->send();
        }
    }

    public function syncOpenAiCosts(): void
    {
        try {
            $costs = app(OpenAiUsageService::class)->syncCostsForCurrentMonth();

            Notification::make()
                ->success()
                ->title('OpenAI costs synchronized')
                ->body('Current month cost: ' . $this->formatMoney((float) ($costs['cost_value'] ?? 0)))
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->warning()
                ->title('OpenAI costs sync unavailable')
                ->body($exception->getMessage())
                ->send();
        }
    }

    public function resetEstimate(): void
    {
        app(AiSettingsService::class)->resetCurrentMonthEstimate();
        $this->fillForm();

        Notification::make()
            ->success()
            ->title('Internal estimate reset')
            ->send();
    }

    public function disableAi(): void
    {
        $this->settings()->forceFill(['enabled' => false])->save();
        $this->fillForm();

        Notification::make()
            ->success()
            ->title('AI вимкнено')
            ->send();
    }

    public function deleteApiKey(): void
    {
        $this->settings()->forceFill(['encrypted_api_key' => null])->save();
        $this->fillForm();

        Notification::make()
            ->success()
            ->title('API key видалено')
            ->send();
    }

    public function deleteAdminApiKey(): void
    {
        $this->settings()->forceFill(['encrypted_admin_api_key' => null])->save();
        $this->fillForm();

        Notification::make()
            ->success()
            ->title('Admin API key видалено')
            ->send();
    }

    public function deleteImageSearchApiKey(): void
    {
        $this->settings()->forceFill(['encrypted_image_search_api_key' => null])->save();
        $this->fillForm();

        Notification::make()
            ->success()
            ->title('Image Search API key видалено')
            ->send();
    }

    private function fillForm(): void
    {
        $settings = $this->settings();

        $this->form->fill([
            'enabled' => $settings->enabled,
            'provider' => $settings->provider,
            'mode' => $settings->mode,
            'model' => $settings->model,
            'timeout' => $settings->timeout,
            'max_input_chars' => $settings->max_input_chars,
            'max_output_tokens' => $settings->max_output_tokens,
            'monthly_budget' => $settings->monthly_budget,
            'warning_threshold_percent' => $settings->warning_threshold_percent,
            'hard_limit_enabled' => $settings->hard_limit_enabled,
            'image_search_enabled' => $settings->image_search_enabled,
            'image_search_provider' => $settings->image_search_provider ?: 'manual_url',
            'image_search_safe_mode' => $settings->image_search_safe_mode,
            'image_search_max_candidates' => $settings->image_search_max_candidates ?: 5,
            'image_search_min_width' => $settings->image_search_min_width ?: 600,
            'image_search_min_height' => $settings->image_search_min_height ?: 600,
            'image_search_preferred_format' => $settings->image_search_preferred_format ?: 'webp',
            'image_search_max_download_size_mb' => $settings->image_search_max_download_size_mb ?: 5,
            'allow_manual_url_candidates' => $settings->allow_manual_url_candidates,
            'api_key' => null,
            'admin_api_key' => null,
            'image_search_api_key' => null,
        ]);
    }

    private function settings(): AiSetting
    {
        return app(AiSettingsService::class)->getSettings()->refresh();
    }

    private function runsThisMonth(): int
    {
        return AiRun::query()
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();
    }

    private function failedRunsThisMonth(): int
    {
        return AiRun::query()
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->where('status', AiRun::STATUS_FAILED)
            ->count();
    }

    private function formatMoney(?float $amount): string
    {
        if ($amount === null) {
            return '-';
        }

        return '$' . number_format($amount, 6);
    }
}
