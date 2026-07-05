<?php

namespace App\Filament\Pages;

use App\Filament\Resources\StorefrontThemes\StorefrontThemeResource;
use App\Models\ThemeGenerationRun;
use App\Services\Ai\AiClient;
use App\Services\Themes\AiThemeGenerationService;
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
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class AiThemeStudioPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $navigationLabel = 'AI Theme Studio';

    protected static ?string $title = 'AI Theme Studio';

    protected static string|\UnitEnum|null $navigationGroup = 'Дизайн';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'ai-theme-studio';

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
        $this->form->fill([
            'source_url' => null,
            'theme_name' => null,
            'style_intensity' => 'medium',
            'target_category' => 'auto_parts',
            'base_layout' => 'marketplace',
            'avoid_dark_theme' => false,
            'avoid_copying_assets' => true,
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
                TextInput::make('source_url')
                    ->label('Source storefront URL')
                    ->url()
                    ->required()
                    ->maxLength(2000),
                TextInput::make('theme_name')
                    ->label('Theme name')
                    ->maxLength(255),
                Select::make('style_intensity')
                    ->label('Style intensity')
                    ->options([
                        'low' => 'Low',
                        'medium' => 'Medium',
                        'high' => 'High',
                    ])
                    ->required(),
                Select::make('target_category')
                    ->label('Target category')
                    ->options([
                        'auto_parts' => 'Auto parts',
                        'electronics' => 'Electronics',
                        'fashion' => 'Fashion',
                        'universal' => 'Universal',
                    ])
                    ->required(),
                Select::make('base_layout')
                    ->label('Base layout')
                    ->options([
                        'keep_current' => 'Keep current',
                        'marketplace' => 'Marketplace',
                        'premium' => 'Premium',
                        'promo' => 'Promo',
                    ])
                    ->required(),
                Toggle::make('avoid_dark_theme')
                    ->label('Avoid dark theme'),
                Toggle::make('avoid_copying_assets')
                    ->label('Do not copy logos/images/text/CSS')
                    ->default(true)
                    ->disabled()
                    ->dehydrated(true),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Generate original theme')
                    ->description('AI аналізує стилістику сайту і створює оригінальний шаблон. Система не копіює HTML, CSS, логотипи, тексти або фото.')
                    ->schema([
                        Placeholder::make('safety')
                            ->label('Safety')
                            ->content('Generated themes are saved as draft and must be reviewed, previewed and activated manually.'),
                        Form::make([EmbeddedSchema::make('form')])
                            ->livewireSubmitHandler('generate')
                            ->footer([
                                SchemaActions::make([
                                    Action::make('generate')
                                        ->label('Generate theme')
                                        ->icon(Heroicon::OutlinedSparkles)
                                        ->submit('generate'),
                                ]),
                            ]),
                    ]),
            ]);
    }

    public function generate(): void
    {
        $data = $this->form->getState();

        if (! app(AiClient::class)->isEnabled()) {
            Notification::make()
                ->warning()
                ->title('AI вимкнено')
                ->body('Буде створено heuristic draft за доступними style signals без OpenAI-запиту.')
                ->send();
        }

        $run = app(AiThemeGenerationService::class)
            ->generateFromUrl((string) $data['source_url'], Auth::user(), $data);

        if ($run->status === ThemeGenerationRun::STATUS_FAILED) {
            Notification::make()
                ->danger()
                ->title('Theme generation failed')
                ->body($run->error)
                ->send();

            return;
        }

        $theme = $run->themes()->first();
        $profile = (array) ($run->style_profile ?? []);
        $warnings = (array) ($run->generation_warnings ?? []);
        $guardrails = (array) ($run->guardrails_applied ?? []);

        Notification::make()
            ->success()
            ->title('Draft theme created')
            ->body(implode("\n", array_filter([
                'Source: '.$run->source_url,
                'Detected style: '.$this->styleProfileSummary($profile),
                'Confidence: '.(isset($profile['confidence']) ? round((float) $profile['confidence'] * 100).'%' : '-'),
                'Selected preset: '.($run->selected_preset ?: '-'),
                $guardrails !== [] ? 'Guardrails applied: '.implode(', ', $guardrails) : null,
                $warnings !== [] ? 'Warnings: '.implode('; ', $warnings) : null,
                $theme ? 'Draft theme: '.$theme->name : 'Theme is ready for review.',
            ])))
            ->actions(array_filter([
                $theme ? Action::make('preview')
                    ->label('Preview')
                    ->url(route('home', ['theme' => $theme->slug]), shouldOpenInNewTab: true) : null,
                $theme ? Action::make('edit')
                    ->label('Edit')
                    ->url(StorefrontThemeResource::getUrl('edit', ['record' => $theme])) : null,
            ]))
            ->send();
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function styleProfileSummary(array $profile): string
    {
        if ($profile === []) {
            return 'not available';
        }

        $fingerprint = is_array($profile['style_fingerprint'] ?? null) ? $profile['style_fingerprint'] : [];
        $styleLock = is_array($profile['style_lock'] ?? null) ? $profile['style_lock'] : [];
        $segments = array_filter([
            $profile['visual_mode'] ?? null,
            $profile['density'] ?? null,
            $profile['ecommerce_type'] ?? null,
            $profile['card_style'] ?? null,
            $fingerprint['visual_mode'] ?? null,
            $styleLock['primary_cta_style'] ?? null,
            $styleLock['background_system'] ?? null,
            $styleLock['product_card_style'] ?? null,
        ], fn (mixed $value): bool => is_scalar($value) && (string) $value !== '');

        if ($segments === []) {
            return 'not available';
        }

        return implode(' ', $segments);
    }
}
