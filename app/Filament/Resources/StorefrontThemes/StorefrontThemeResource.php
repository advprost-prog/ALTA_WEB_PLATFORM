<?php

namespace App\Filament\Resources\StorefrontThemes;

use App\Filament\Resources\StorefrontThemes\Pages\CreateStorefrontTheme;
use App\Filament\Resources\StorefrontThemes\Pages\EditStorefrontTheme;
use App\Filament\Resources\StorefrontThemes\Pages\ListStorefrontThemes;
use App\Filament\Resources\StorefrontThemes\Pages\ViewStorefrontTheme;
use App\Models\StorefrontTheme;
use App\Services\Themes\AiThemeGenerationService;
use App\Services\Themes\ThemePayloadValidator;
use App\Services\Themes\ThemeSchema;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class StorefrontThemeResource extends Resource
{
    protected static ?string $model = StorefrontTheme::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|\UnitEnum|null $navigationGroup = 'Дизайн';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'тема storefront';

    protected static ?string $pluralModelLabel = 'Теми storefront';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Основне')
                    ->schema([
                        TextInput::make('name')
                            ->label('Назва')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (string $operation, ?string $state, callable $set) => $operation === 'create'
                                ? $set('slug', Str::slug((string) $state))
                                : null),
                        TextInput::make('slug')
                            ->label('Slug')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Select::make('type')
                            ->label('Тип')
                            ->options(StorefrontTheme::TYPES)
                            ->default(StorefrontTheme::TYPE_CUSTOM)
                            ->required(),
                        Select::make('status')
                            ->label('Статус')
                            ->options(StorefrontTheme::STATUSES)
                            ->default(StorefrontTheme::STATUS_DRAFT)
                            ->required(),
                        TextInput::make('style_family')
                            ->label('Style family')
                            ->maxLength(255),
                        TextInput::make('selected_preset')
                            ->label('Selected preset')
                            ->disabled()
                            ->dehydrated(true)
                            ->maxLength(255),
                        TextInput::make('source_url')
                            ->label('Source URL')
                            ->url()
                            ->maxLength(2000),
                        Textarea::make('description')
                            ->label('Опис')
                            ->rows(3)
                            ->columnSpanFull(),
                        FileUpload::make('preview_image')
                            ->label('Preview image')
                            ->image()
                            ->disk('public')
                            ->directory('theme-previews')
                            ->visibility('public')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Tabs::make('Theme payload')
                    ->tabs([
                        Tab::make('Colors')
                            ->schema([
                                Grid::make(3)
                                    ->schema(self::colorInputs()),
                            ]),
                        Tab::make('Typography & shape')
                            ->schema([
                                Grid::make(3)
                                    ->schema([
                                        TextInput::make('tokens.typography.fontFamily')->label('Body font')->required(),
                                        TextInput::make('tokens.typography.headingFamily')->label('Heading font')->required(),
                                        TextInput::make('tokens.typography.baseSize')->label('Base size')->required(),
                                        TextInput::make('tokens.typography.headingWeight')->label('Heading weight')->numeric()->required(),
                                        TextInput::make('tokens.typography.bodyWeight')->label('Body weight')->numeric()->required(),
                                        TextInput::make('tokens.typography.letterSpacing')->label('Letter spacing')->default('0')->required(),
                                        TextInput::make('tokens.radius.sm')->label('Radius sm')->required(),
                                        TextInput::make('tokens.radius.md')->label('Radius md')->required(),
                                        TextInput::make('tokens.radius.lg')->label('Radius lg')->required(),
                                        TextInput::make('tokens.radius.xl')->label('Radius xl')->required(),
                                        TextInput::make('tokens.spacing.sectionY')->label('Section Y')->required(),
                                        TextInput::make('tokens.spacing.gridGap')->label('Grid gap')->required(),
                                    ]),
                            ]),
                        Tab::make('Layout')
                            ->schema([
                                Grid::make(3)
                                    ->schema([
                                        Select::make('layout_config.headerVariant')->label('Header')->options(array_combine(ThemeSchema::HEADER_VARIANTS, ThemeSchema::HEADER_VARIANTS))->required(),
                                        Select::make('layout_config.topBarVariant')->label('Top bar')->options(array_combine(ThemeSchema::TOP_BAR_VARIANTS, ThemeSchema::TOP_BAR_VARIANTS))->required(),
                                        Select::make('layout_config.heroVariant')->label('Hero')->options(array_combine(ThemeSchema::HERO_VARIANTS, ThemeSchema::HERO_VARIANTS))->required(),
                                        Select::make('layout_config.categoryGridVariant')->label('Category grid')->options(array_combine(ThemeSchema::CATEGORY_GRID_VARIANTS, ThemeSchema::CATEGORY_GRID_VARIANTS))->required(),
                                        Select::make('layout_config.productCardVariant')->label('Product card')->options(array_combine(ThemeSchema::PRODUCT_CARD_VARIANTS, ThemeSchema::PRODUCT_CARD_VARIANTS))->required(),
                                        Select::make('layout_config.productPageVariant')->label('Product page')->options(array_combine(ThemeSchema::PRODUCT_PAGE_VARIANTS, ThemeSchema::PRODUCT_PAGE_VARIANTS))->required(),
                                        Select::make('layout_config.footerVariant')->label('Footer')->options(array_combine(ThemeSchema::FOOTER_VARIANTS, ThemeSchema::FOOTER_VARIANTS))->required(),
                                        Select::make('layout_config.containerWidth')->label('Container')->options(array_combine(ThemeSchema::CONTAINER_WIDTHS, ThemeSchema::CONTAINER_WIDTHS))->required(),
                                        Select::make('layout_config.density')->label('Density')->options(array_combine(ThemeSchema::DENSITIES, ThemeSchema::DENSITIES))->required(),
                                        Select::make('layout_config.mobileNavVariant')->label('Mobile nav')->options(array_combine(ThemeSchema::MOBILE_NAV_VARIANTS, ThemeSchema::MOBILE_NAV_VARIANTS))->required(),
                                    ]),
                            ]),
                        Tab::make('Components')
                            ->schema([
                                Grid::make(4)
                                    ->schema([
                                        Toggle::make('component_config.showTopBar')->label('Top bar'),
                                        Toggle::make('component_config.showSearch')->label('Search'),
                                        Toggle::make('component_config.stickyHeader')->label('Sticky header'),
                                        Toggle::make('component_config.showCategoryMenu')->label('Category menu'),
                                        Toggle::make('component_config.showBadges')->label('Badges'),
                                        Toggle::make('component_config.showBrandInCard')->label('Brand in card'),
                                        Toggle::make('component_config.showSkuInCard')->label('SKU in card'),
                                        Toggle::make('component_config.showQuickBuy')->label('Quick buy'),
                                        Toggle::make('component_config.showProductShortSpecs')->label('Short specs'),
                                        Toggle::make('component_config.heroOverlay')->label('Hero overlay'),
                                        Select::make('component_config.cardImageRatio')->label('Card image ratio')->options(array_combine(ThemeSchema::CARD_IMAGE_RATIOS, ThemeSchema::CARD_IMAGE_RATIOS))->required(),
                                    ]),
                            ]),
                        Tab::make('Custom CSS')
                            ->schema([
                                Textarea::make('custom_css')
                                    ->label('Custom CSS')
                                    ->rows(10)
                                    ->helperText('Без @import, scripts, remote url(...) або зовнішніх assets. Буде sanitized.'),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name')->label('Назва'),
                TextEntry::make('slug')->label('Slug'),
                TextEntry::make('type')->badge(),
                TextEntry::make('status')->badge(),
                IconEntry::make('is_active')->label('Active')->boolean(),
                IconEntry::make('generated_by_ai')->label('AI')->boolean(),
                TextEntry::make('style_family')->placeholder('-'),
                TextEntry::make('selected_preset')->label('Selected preset')->placeholder('-'),
                TextEntry::make('source_url')->placeholder('-')->columnSpanFull(),
                TextEntry::make('style_profile')
                    ->label('Detected style profile')
                    ->formatStateUsing(fn (mixed $state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '-')
                    ->columnSpanFull(),
                TextEntry::make('guardrails_applied')
                    ->label('Guardrails applied')
                    ->formatStateUsing(fn (mixed $state): string => is_array($state) && $state !== [] ? implode(', ', $state) : '-')
                    ->columnSpanFull(),
                TextEntry::make('generation_warnings')
                    ->label('Warnings')
                    ->formatStateUsing(fn (mixed $state): string => is_array($state) && $state !== [] ? implode("\n", $state) : '-')
                    ->columnSpanFull(),
                TextEntry::make('description')->placeholder('-')->columnSpanFull(),
                TextEntry::make('layout_config')
                    ->formatStateUsing(fn (mixed $state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '-')
                    ->columnSpanFull(),
                TextEntry::make('component_config')
                    ->formatStateUsing(fn (mixed $state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '-')
                    ->columnSpanFull(),
                TextEntry::make('updated_at')->dateTime(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->latest('updated_at'))
            ->columns([
                TextColumn::make('name')->label('Назва')->searchable()->sortable(),
                TextColumn::make('slug')->label('Slug')->searchable()->toggleable(),
                TextColumn::make('type')->label('Тип')->badge()->sortable(),
                TextColumn::make('status')->label('Статус')->badge()->sortable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                IconColumn::make('generated_by_ai')->label('AI')->boolean(),
                TextColumn::make('selected_preset')->label('Preset')->badge()->toggleable(),
                TextColumn::make('source_url')->label('Source')->limit(36)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label('Оновлено')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')->label('Тип')->options(StorefrontTheme::TYPES),
                SelectFilter::make('status')->label('Статус')->options(StorefrontTheme::STATUSES),
                TernaryFilter::make('is_active')->label('Active'),
                TernaryFilter::make('generated_by_ai')->label('AI'),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    self::previewAction(),
                    self::regenerateAction(),
                    self::activateAction(),
                    self::duplicateAction(),
                    self::archiveAction(),
                    self::versionAction(),
                ])
                    ->label('Дії')
                    ->icon(Heroicon::EllipsisVertical)
                    ->iconButton()
                    ->size(Size::Small)
                    ->color('gray'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStorefrontThemes::route('/'),
            'create' => CreateStorefrontTheme::route('/create'),
            'view' => ViewStorefrontTheme::route('/{record}'),
            'edit' => EditStorefrontTheme::route('/{record}/edit'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeFormData(array $data): array
    {
        try {
            $payload = app(ThemePayloadValidator::class)->validate([
                'name' => $data['name'] ?? '',
                'tokens' => $data['tokens'] ?? [],
                'layout_config' => $data['layout_config'] ?? [],
                'component_config' => $data['component_config'] ?? [],
                'css_variables' => $data['css_variables'] ?? [],
                'custom_css' => $data['custom_css'] ?? null,
            ], $data['source_url'] ?? null);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'custom_css' => $exception->getMessage(),
            ]);
        }

        $data['tokens'] = $payload['tokens'];
        $data['layout_config'] = $payload['layout_config'];
        $data['component_config'] = $payload['component_config'];
        $data['css_variables'] = $payload['css_variables'];
        $data['custom_css'] = $payload['custom_css'];
        $data['style_profile'] = $data['style_profile'] ?? [];
        $data['guardrails_applied'] = $data['guardrails_applied'] ?? [];
        $data['generation_warnings'] = $data['generation_warnings'] ?? [];
        $data['created_by'] = $data['created_by'] ?? auth()->id();

        return $data;
    }

    public static function previewAction(): Action
    {
        return Action::make('preview')
            ->label('Preview')
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->authorize(fn (StorefrontTheme $record): bool => auth()->user()?->can('preview', $record) ?? false)
            ->url(fn (StorefrontTheme $record): string => route('home', ['theme' => $record->slug]))
            ->openUrlInNewTab();
    }

    public static function activateAction(): Action
    {
        return Action::make('activate')
            ->label('Activate')
            ->icon(Heroicon::OutlinedBolt)
            ->color('success')
            ->requiresConfirmation()
            ->authorize(fn (StorefrontTheme $record): bool => auth()->user()?->can('activate', $record) ?? false)
            ->action(function (StorefrontTheme $record): void {
                $record->activate();

                Notification::make()
                    ->success()
                    ->title('Тему активовано')
                    ->body($record->name)
                    ->send();
            });
    }

    public static function regenerateAction(): Action
    {
        return Action::make('regenerateFromSource')
            ->label('Regenerate from source')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Regenerate AI theme from source URL?')
            ->modalDescription('A new draft payload and version will be created from the saved source URL. The theme will not be activated automatically.')
            ->authorize(fn (StorefrontTheme $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->visible(fn (StorefrontTheme $record): bool => $record->generated_by_ai && filled($record->source_url) && ! $record->is_active)
            ->action(function (StorefrontTheme $record): void {
                $run = app(AiThemeGenerationService::class)->regenerateTheme($record, auth()->user());

                if ($run->status === \App\Models\ThemeGenerationRun::STATUS_FAILED) {
                    Notification::make()
                        ->danger()
                        ->title('Regeneration failed')
                        ->body($run->error)
                        ->send();

                    return;
                }

                $record->refresh();

                Notification::make()
                    ->success()
                    ->title('Theme regenerated')
                    ->body('Preset: '.($record->selected_preset ?: '-').'. Guardrails: '.implode(', ', $record->guardrails_applied ?? []))
                    ->actions([
                        Action::make('preview')
                            ->label('Preview')
                            ->url(route('home', ['theme' => $record->slug]), shouldOpenInNewTab: true),
                    ])
                    ->send();
            });
    }

    public static function duplicateAction(): Action
    {
        return Action::make('duplicate')
            ->label('Duplicate')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->authorize(fn (StorefrontTheme $record): bool => auth()->user()?->can('create', StorefrontTheme::class) ?? false)
            ->action(function (StorefrontTheme $record): void {
                $copy = $record->replicate(['is_active']);
                $copy->forceFill([
                    'name' => $record->name.' copy',
                    'slug' => self::uniqueSlug($record->slug.'-copy'),
                    'type' => StorefrontTheme::TYPE_CUSTOM,
                    'status' => StorefrontTheme::STATUS_DRAFT,
                    'is_active' => false,
                    'created_by' => auth()->id(),
                    'generated_by_ai' => false,
                    'ai_run_id' => null,
                ])->save();

                $copy->createVersion('Duplicated from '.$record->slug.'.');

                Notification::make()->success()->title('Тему продубльовано')->send();
            });
    }

    public static function archiveAction(): Action
    {
        return Action::make('archive')
            ->label('Archive')
            ->icon(Heroicon::OutlinedXMark)
            ->color('gray')
            ->requiresConfirmation()
            ->authorize(fn (StorefrontTheme $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->action(function (StorefrontTheme $record): void {
                if ($record->is_active) {
                    Notification::make()
                        ->warning()
                        ->title('Активну тему не архівовано')
                        ->body('Спершу активуйте іншу тему.')
                        ->send();

                    return;
                }

                $record->update(['status' => StorefrontTheme::STATUS_ARCHIVED]);

                Notification::make()->success()->title('Тему архівовано')->send();
            });
    }

    public static function versionAction(): Action
    {
        return Action::make('version')
            ->label('Generate version')
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->authorize(fn (StorefrontTheme $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->action(function (StorefrontTheme $record): void {
                $record->createVersion('Manual version snapshot.');

                Notification::make()->success()->title('Версію створено')->send();
            });
    }

    /**
     * @return array<int, TextInput>
     */
    private static function colorInputs(): array
    {
        return collect(array_keys(ThemeSchema::defaultTokens()['colors']))
            ->map(fn (string $key): TextInput => TextInput::make('tokens.colors.'.$key)
                ->label(Str::headline($key))
                ->required()
                ->regex('/^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/'))
            ->all();
    }

    private static function uniqueSlug(string $base): string
    {
        $base = Str::slug($base) ?: 'theme-copy';
        $slug = $base;
        $suffix = 2;

        while (StorefrontTheme::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
