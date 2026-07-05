<?php

namespace App\Filament\Resources\Products;

use App\Filament\Pages\AiSettingsPage;
use App\Filament\Resources\AiSuggestions\AiSuggestionResource;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Filament\Resources\Products\RelationManagers\ProductImageCandidatesRelationManager;
use App\Filament\Resources\Products\RelationManagers\ProductImagesRelationManager;
use App\Models\AiRun;
use App\Models\AiSuggestion;
use App\Models\AiSetting;
use App\Models\CommerceSetting;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductImageCandidate;
use App\Models\Warehouse;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\ProductEnrichmentService;
use App\Services\Catalog\ProductCompletenessService;
use App\Services\Images\ProductImageQueryBuilder;
use App\Services\Images\ProductImageSearchService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Grid;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|\UnitEnum|null $navigationGroup = 'Каталог';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'товар';

    protected static ?string $pluralModelLabel = 'Товари';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Основне')
                    ->description('Назва, URL, артикул і привʼязка до каталогу.')
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
                            ->maxLength(255)
                            ->helperText('Використовується у публічному URL товару.'),
                        TextInput::make('sku')
                            ->label('Артикул')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255)
                            ->helperText('Унікальний код для пошуку, складу та замовлень.'),
                        Select::make('brand_id')
                            ->label('Бренд')
                            ->relationship('brand', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('category_id')
                            ->label('Категорія')
                            ->relationship('category', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('stock_status')
                            ->label('Статус наявності')
                            ->options(Product::STOCK_STATUSES)
                            ->default('in_stock')
                            ->required(),
                    ])
                    ->columns(2),
                Section::make('Опис')
                    ->description('Короткий опис показується у картках, повний - на сторінці товару.')
                    ->schema([
                        Textarea::make('short_description')
                            ->label('Короткий опис')
                            ->rows(3),
                        Textarea::make('description')
                            ->label('Повний опис')
                            ->rows(7),
                    ]),
                Section::make('Ціни та склад')
                    ->description('Комерційні ціни, закупівля й залишок для кошика.')
                    ->schema([
                        TextInput::make('price')
                            ->label('Ціна')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->prefix('₴')
                            ->visible(fn (): bool => ! self::multiCurrencyEnabled()),
                        TextInput::make('old_price')
                            ->label('Стара ціна')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('₴')
                            ->helperText('Якщо більше за поточну ціну, storefront покаже відсоток знижки.')
                            ->visible(fn (): bool => ! self::multiCurrencyEnabled()),
                        Repeater::make('prices')
                            ->label('Ціни за валютами')
                            ->relationship()
                            ->schema([
                                Select::make('currency_id')
                                    ->label('Валюта')
                                    ->options(fn (): array => self::currencyOptions())
                                    ->searchable()
                                    ->distinct()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                    ->required(),
                                TextInput::make('price')
                                    ->label('Ціна')
                                    ->numeric()
                                    ->minValue(0)
                                    ->required(),
                                TextInput::make('compare_at_price')
                                    ->label('Стара ціна')
                                    ->numeric()
                                    ->minValue(0),
                                Toggle::make('is_active')
                                    ->label('Активна')
                                    ->default(true),
                            ])
                            ->columns(4)
                            ->defaultItems(0)
                            ->columnSpanFull()
                            ->visible(fn (): bool => self::multiCurrencyEnabled()),
                        TextInput::make('purchase_price')
                            ->label('Закупівельна ціна')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('₴'),
                        TextInput::make('stock')
                            ->label('Залишок')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->helperText('Кошик не дозволяє замовити більше доступного залишку.')
                            ->visible(fn (): bool => ! self::multiWarehouseEnabled()),
                        Repeater::make('stockBalances')
                            ->label('Залишки за складами')
                            ->relationship()
                            ->schema([
                                Select::make('warehouse_id')
                                    ->label('Склад')
                                    ->options(fn (): array => self::warehouseOptions())
                                    ->searchable()
                                    ->distinct()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                    ->required(),
                                TextInput::make('quantity')
                                    ->label('Кількість')
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(0)
                                    ->required(),
                                TextInput::make('reserved_quantity')
                                    ->label('Зарезервовано')
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(0)
                                    ->disabled()
                                    ->dehydrated(false),
                                Placeholder::make('available_quantity')
                                    ->label('Доступно')
                                    ->content(fn (callable $get): string => number_format(
                                        max(0, (float) ($get('quantity') ?? 0) - (float) ($get('reserved_quantity') ?? 0)),
                                        3,
                                        ',',
                                        ' ',
                                    )),
                            ])
                            ->columns(4)
                            ->defaultItems(0)
                            ->columnSpanFull()
                            ->visible(fn (): bool => self::multiWarehouseEnabled()),
                    ])
                    ->columns(4),
                Section::make('Публікація та бейджі')
                    ->description('Керує видимістю товару й промо-позначками на storefront.')
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Активний')
                            ->default(true)
                            ->required(),
                        Toggle::make('is_new')
                            ->label('Новинка')
                            ->required(),
                        Toggle::make('is_hit')
                            ->label('Хіт')
                            ->required(),
                        Toggle::make('is_sale')
                            ->label('Акція')
                            ->required(),
                    ])
                    ->columns(4),
                Section::make('Фото')
                    ->description('Основне фото використовується в каталозі, галерея - на сторінці товару.')
                    ->schema([
                        FileUpload::make('main_image')
                            ->label('Основне фото')
                            ->image()
                            ->disk('public')
                            ->directory('products')
                            ->visibility('public')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize(2048)
                            ->imagePreviewHeight('180')
                            ->openable()
                            ->downloadable()
                            ->preserveFilenames(false),
                        TextInput::make('image_alt_text')
                            ->label('Alt-текст основного фото')
                            ->maxLength(500),
                        Repeater::make('images')
                            ->label('Галерея фото')
                            ->relationship()
                            ->schema([
                                FileUpload::make('image')
                                    ->label('Фото')
                                    ->image()
                                    ->disk('public')
                                    ->directory('products/gallery')
                                    ->visibility('public')
                                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                    ->maxSize(2048)
                                    ->imagePreviewHeight('120')
                                    ->openable()
                                    ->downloadable()
                                    ->preserveFilenames(false)
                                    ->required(),
                                TextInput::make('alt')
                                    ->label('Alt-текст')
                                    ->maxLength(255),
                                TextInput::make('sort_order')
                                    ->label('Порядок')
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(0),
                                Toggle::make('is_main')
                                    ->label('Головне')
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('source_domain')
                                    ->label('Джерело')
                                    ->disabled()
                                    ->dehydrated(false),
                            ])
                            ->columns(5)
                            ->defaultItems(0),
                    ]),
                Section::make('Характеристики')
                    ->description('Короткі характеристики показуються у картці й повний список на сторінці товару.')
                    ->schema([
                        Repeater::make('specifications')
                            ->label('Характеристики товару')
                            ->relationship()
                            ->schema([
                                TextInput::make('name')
                                    ->label('Назва')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('value')
                                    ->label('Значення')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('unit')
                                    ->label('Одиниця')
                                    ->maxLength(255),
                                TextInput::make('sort_order')
                                    ->label('Порядок')
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(0),
                            ])
                            ->columns(4)
                            ->defaultItems(0),
                    ]),
                Section::make('SEO')
                    ->description('Мета-поля для сторінки товару.')
                    ->schema([
                        TextInput::make('seo_title')
                            ->label('SEO title')
                            ->maxLength(255),
                        Textarea::make('seo_description')
                            ->label('SEO description')
                            ->rows(3),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('brand.name')
                    ->label('Бренд')
                    ->placeholder('-'),
                TextEntry::make('category.name')
                    ->label('Категорія'),
                TextEntry::make('name')
                    ->label('Назва'),
                TextEntry::make('slug')
                    ->label('Slug'),
                TextEntry::make('sku')
                    ->label('Артикул'),
                TextEntry::make('short_description')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('description')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('price')
                    ->money(),
                TextEntry::make('old_price')
                    ->money()
                    ->placeholder('-'),
                TextEntry::make('purchase_price')
                    ->money()
                    ->placeholder('-'),
                TextEntry::make('stock')
                    ->numeric(),
                TextEntry::make('stock_status')
                    ->label('Наявність')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Product::STOCK_STATUSES[$state] ?? $state)
                    ->color(fn (?string $state): string => self::stockStatusColor($state)),
                IconEntry::make('is_active')
                    ->label('Активний')
                    ->boolean(),
                IconEntry::make('is_new')
                    ->label('Новинка')
                    ->boolean(),
                IconEntry::make('is_hit')
                    ->label('Хіт')
                    ->boolean(),
                IconEntry::make('is_sale')
                    ->label('Акція')
                    ->boolean(),
                TextEntry::make('seo_title')
                    ->placeholder('-'),
                TextEntry::make('seo_description')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('image_alt_text')
                    ->label('Alt-текст основного фото')
                    ->placeholder('-')
                    ->columnSpanFull(),
                ImageEntry::make('image_url')
                    ->label('Основне фото')
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['brand', 'category', 'images', 'prices.currency', 'stockBalances.warehouse']))
            ->columns([
                Split::make([
                    ImageColumn::make('image_url')
                        ->label('Фото')
                        ->imageSize(56)
                        ->width('64px')
                        ->grow(false)
                        ->extraImgAttributes([
                            'style' => 'object-fit: contain; width: 56px; height: 56px; background: #f8fafc; border-radius: 6px;',
                        ]),
                    Grid::make([
                        'default' => 1,
                        'lg' => 3,
                        '2xl' => 5,
                    ])
                        ->schema([
                            Stack::make([
                                TextColumn::make('name')
                                    ->label('Товар')
                                    ->searchable(query: fn (Builder $query, string $search): Builder => self::applyProductListSearch($query, $search))
                                    ->sortable()
                                    ->lineClamp(2)
                                    ->wrap(),
                                TextColumn::make('product_taxonomy')
                                    ->label('Бренд / категорія')
                                    ->state(fn (Product $record): string => self::productMetaLine($record))
                                    ->color('gray')
                                    ->lineClamp(1),
                            ])->space(1),
                            Stack::make([
                                TextColumn::make('product_sku')
                                    ->label('Артикул')
                                    ->state(fn (Product $record): string => self::productSkuLine($record))
                                    ->color('gray')
                                    ->lineClamp(1),
                                TextColumn::make('product_flags')
                                    ->label('Прапорці')
                                    ->state(fn (Product $record): string => self::productFlagsLine($record))
                                    ->color(fn (Product $record): string => $record->is_active ? 'gray' : 'danger')
                                    ->lineClamp(1),
                            ])->space(1),
                            Stack::make([
                                TextColumn::make('price')
                                    ->label('Ціна')
                                    ->state(fn (Product $record): string => self::productPriceLine($record))
                                    ->tooltip(fn (Product $record): ?string => self::productPriceTooltip($record))
                                    ->sortable(),
                                TextColumn::make('product_old_price')
                                    ->label('Стара ціна')
                                    ->state(fn (Product $record): string => self::productPriceMetaLine($record))
                                    ->color('gray')
                                    ->lineClamp(1),
                            ])->space(1),
                            Stack::make([
                                TextColumn::make('stock_status')
                                    ->label('Наявність')
                                    ->badge()
                                    ->formatStateUsing(fn (string $state): string => Product::STOCK_STATUSES[$state] ?? $state)
                                    ->color(fn (?string $state): string => self::stockStatusColor($state)),
                                TextColumn::make('product_stock')
                                    ->label('Залишок')
                                    ->state(fn (Product $record): string => self::productStockLine($record))
                                    ->tooltip(fn (Product $record): ?string => self::productStockTooltip($record))
                                    ->color('gray')
                                    ->lineClamp(1),
                            ])->space(1),
                            Stack::make([
                                TextColumn::make('completeness')
                                    ->label('Заповненість')
                                    ->state(fn (Product $record): string => $record->completenessScore().'% '.$record->completenessStatusLabel())
                                    ->badge()
                                    ->color(fn (Product $record): string => app(ProductCompletenessService::class)->color($record))
                                    ->tooltip(fn (Product $record): string => $record->completenessMissingSummary()),
                                TextColumn::make('product_completeness_hint')
                                    ->label('Пробіли')
                                    ->state(fn (Product $record): string => self::productCompletenessHint($record))
                                    ->color(fn (Product $record): string => $record->completenessScore() >= 90 ? 'gray' : 'warning')
                                    ->lineClamp(1)
                                    ->tooltip(fn (Product $record): string => $record->completenessMissingSummary()),
                            ])->space(1),
                        ])
                        ->grow(),
                ])
                    ->from('md')
                    ->extraAttributes(['class' => 'fi-gap-md']),
            ])
            ->filters([
                SelectFilter::make('category_id')
                    ->label('Категорія')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('brand_id')
                    ->label('Бренд')
                    ->relationship('brand', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('stock_status')
                    ->label('Наявність')
                    ->options(Product::STOCK_STATUSES),
                TernaryFilter::make('is_active')
                    ->label('Активність'),
                TernaryFilter::make('is_sale')
                    ->label('Акція'),
                TernaryFilter::make('is_new')
                    ->label('Новинка'),
                TernaryFilter::make('is_hit')
                    ->label('Хіт'),
                Filter::make('low_completeness')
                    ->label('Заповненість < 50')
                    ->query(fn (Builder $query): Builder => app(ProductCompletenessService::class)->applyLowCompletenessScope($query)),
                Filter::make('without_photo')
                    ->label('Без фото')
                    ->query(fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query
                        ->whereNull('main_image')
                        ->orWhere('main_image', ''))),
                Filter::make('without_seo')
                    ->label('Без SEO')
                    ->query(fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query
                        ->whereNull('seo_title')
                        ->orWhere('seo_title', '')
                        ->orWhereNull('seo_description')
                        ->orWhere('seo_description', ''))),
                Filter::make('without_description')
                    ->label('Без опису')
                    ->query(fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query
                        ->whereNull('short_description')
                        ->orWhere('short_description', '')
                        ->orWhereNull('description')
                        ->orWhere('description', ''))),
                Filter::make('without_specifications')
                    ->label('Без характеристик')
                    ->query(fn (Builder $query): Builder => $query->whereDoesntHave('specifications')),
            ])
            ->recordActions([
                ActionGroup::make([
                    self::aiEnrichmentAction(),
                    self::productImagePickerAction(),
                    ViewAction::make(),
                    EditAction::make(),
                ])
                    ->label('Дії')
                    ->icon(Heroicon::EllipsisVertical)
                    ->iconButton()
                    ->size(Size::Small)
                    ->color('gray'),
            ])
            ->recordActionsAlignment('end')
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('checkCompleteness')
                        ->label('Перевірити заповненість')
                        ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                        ->action(function (): void {
                            Notification::make()
                                ->success()
                                ->title('Заповненість рахується наживо')
                                ->body('Оновлення кешу не потрібне: індикатор перераховується для кожного товару.')
                                ->send();
                        }),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ProductImageCandidatesRelationManager::class,
            ProductImagesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'view' => ViewProduct::route('/{record}'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }

    public static function aiEnrichmentAction(): Action
    {
        return Action::make('aiEnrichment')
            ->label('AI-заповнення')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('warning')
            ->authorize(fn (): bool => auth()->user()?->can('create', AiSuggestion::class) ?? false)
            ->tooltip(fn (): ?string => app(AiSettingsService::class)->canRunAi()
                ? null
                : 'AI модуль потребує налаштування або доступного бюджету')
            ->modalHeading('AI-заповнення товару')
            ->modalDescription('AI створить пропозиції, але не застосує їх до товару автоматично.')
            ->modalSubmitActionLabel('Згенерувати')
            ->schema([
                Checkbox::make('short_description')
                    ->label('Згенерувати короткий опис')
                    ->default(true),
                Checkbox::make('full_description')
                    ->label('Згенерувати повний опис')
                    ->default(true),
                Checkbox::make('seo')
                    ->label('Згенерувати SEO')
                    ->default(true),
                Checkbox::make('attributes')
                    ->label('Запропонувати характеристики')
                    ->default(true),
                Checkbox::make('image_alt_text')
                    ->label('Запропонувати alt-текст фото')
                    ->default(true),
            ])
            ->action(function (Product $record, array $data): void {
                $settings = app(AiSettingsService::class);

                if (! $settings->canRunAi()) {
                    $notification = Notification::make()
                        ->warning()
                        ->title('AI не налаштований')
                        ->body(self::aiUnavailableMessage($settings));

                    if (auth()->user()?->isAdmin()) {
                        $notification->actions([
                            Action::make('openAiSettings')
                                ->label('AI налаштування')
                                ->url(AiSettingsPage::getUrl()),
                        ]);
                    }

                    $notification->send();

                    return;
                }

                $run = app(ProductEnrichmentService::class)
                    ->generateForProduct($record, auth()->user(), $data);

                if ($run->status === AiRun::STATUS_FAILED) {
                    Notification::make()
                        ->danger()
                        ->title('AI-заповнення не виконано')
                        ->body($run->error)
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('AI-пропозиції створено')
                    ->body('Перегляньте їх у розділі AI-пропозиції та застосуйте потрібні поля вручну.')
                    ->actions([
                        Action::make('openAiSuggestions')
                            ->label('Відкрити пропозиції')
                            ->url(AiSuggestionResource::getUrl('index')),
                    ])
                    ->send();
            });
    }

    public static function productImagePickerAction(): Action
    {
        return Action::make('productImagePicker')
            ->label('Підібрати фото')
            ->icon(Heroicon::OutlinedPhoto)
            ->color('info')
            ->authorize(fn (?Product $record = null): bool => $record ? Gate::allows('update', $record) : false)
            ->modalHeading('Підібрати фото')
            ->modalDescription('Використовуйте лише фото, на які маєте право. Система відсіює очевидно непридатні зображення, але остаточне рішення приймає оператор.')
            ->modalSubmitActionLabel('Створити кандидатів')
            ->schema([
                Radio::make('mode')
                    ->label('Режим')
                    ->options([
                        'serpapi' => 'Автоматичний пошук',
                        'page_url' => 'URL сторінки товару',
                        'direct_image_url' => 'Прямі URL фото',
                    ])
                    ->descriptions([
                        'serpapi' => 'Google Images через SerpAPI з fallback-запитами.',
                        'page_url' => 'HTML сторінка товару: og:image, JSON-LD, img і srcset.',
                        'direct_image_url' => 'Тільки прямі image/jpeg, image/png або image/webp URL.',
                    ])
                    ->default(fn (): string => AiSetting::getActive()->image_search_provider === 'serpapi' ? 'serpapi' : 'direct_image_url')
                    ->inline()
                    ->live()
                    ->required(),
                TextInput::make('limit')
                    ->label('Макс. кандидатів')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(10)
                    ->default(fn (): int => AiSetting::getActive()->image_search_max_candidates ?: 5)
                    ->required(),
                Tabs::make('Product Image Picker')
                    ->activeTab(fn (callable $get): int => match ($get('mode')) {
                        'serpapi' => 1,
                        'page_url' => 2,
                        'direct_image_url' => 3,
                        default => 1,
                    })
                    ->tabs([
                        Tab::make('Автоматичний пошук')
                            ->schema([
                                Placeholder::make('generated_queries')
                                    ->label('Generated queries')
                                    ->content(fn (?Product $record): string => $record
                                        ? implode("\n", app(ProductImageQueryBuilder::class)->buildQueries($record))
                                        : '-'),
                            ]),
                        Tab::make('URL сторінки товару')
                            ->schema([
                                Textarea::make('page_urls')
                                    ->label('URL сторінок товару')
                                    ->rows(6)
                                    ->helperText('1-5 URL. text/html тут є валідним входом: система витягне фото зі сторінки.'),
                            ]),
                        Tab::make('Прямі URL фото')
                            ->schema([
                                Textarea::make('direct_image_urls')
                                    ->label('Прямі URL фото')
                                    ->rows(6)
                                    ->helperText('1-10 прямих image URL. Якщо це HTML-сторінка, система підкаже використати вкладку URL сторінки товару.'),
                            ]),
                        Tab::make('Завантажити файл')
                            ->schema([
                                Placeholder::make('existing_upload')
                                    ->label('Upload')
                                    ->content('Локальне завантаження доступне в секції "Фото" форми товару; імпорт з picker працює через кандидатів.'),
                            ]),
                    ]),
            ])
            ->action(function (Product $record, array $data, \Livewire\Component $livewire): void {
                try {
                    $candidates = app(ProductImageSearchService::class)
                        ->search($record, auth()->user(), $data + ['provider' => $data['mode'] ?? 'direct_image_url']);
                } catch (\Throwable) {
                    Notification::make()
                        ->danger()
                        ->title('Пошук фото не виконано')
                        ->body('Сталася системна помилка під час підбору фото. Очікувані помилки джерел на кшталт timeout/HTTP 403/468 обробляються як діагностика; повторіть запит або перевірте журнал.')
                        ->send();

                    return;
                }

                $accepted = $candidates->filter(fn (ProductImageCandidate $candidate): bool => $candidate->isImportable())->count();
                $review = $candidates->filter(fn (ProductImageCandidate $candidate): bool => $candidate->isImportable() && filled($candidate->warnings))->count();
                $rejected = $candidates->filter(fn (ProductImageCandidate $candidate): bool => $candidate->isRejectedForImport())->count();

                $livewire->dispatch('product-image-candidates-created');
                $livewire->dispatch('refresh-page');

                $notification = Notification::make()
                    ->title('Кандидати фото створено')
                    ->body($candidates->isEmpty()
                        ? 'Кандидатів не знайдено. Для SerpAPI запустіть php artisan alta:image-search-test '.$record->slug.', щоб побачити HTTP status, image_results count і причину.'
                        : 'Придатні до імпорту: '.$accepted.'. Потребують перевірки: '.$review.'. Відхилені/debug: '.$rejected.'. Частина фото може бути відхилена, бо сайти блокують завантаження.');

                ($accepted > 0 ? $notification->success() : $notification->warning())->send();
            });
    }

    public static function aiImageAssistantAction(): Action
    {
        return self::productImagePickerAction();
    }

    private static function aiUnavailableMessage(AiSettingsService $settings): string
    {
        if (! $settings->isEnabled()) {
            return 'AI модуль вимкнено. Увімкніть AI в адмінці.';
        }

        if (blank($settings->getApiKey())) {
            return 'OpenAI API key не задано. Додайте ключ в AI налаштуваннях.';
        }

        if ($settings->isHardLimitReached()) {
            return 'AI-запит заблоковано: внутрішній місячний бюджет вичерпано.';
        }

        return 'AI модуль потребує перевірки налаштувань.';
    }

    private static function applyProductListSearch(Builder $query, string $search): Builder
    {
        $like = '%'.$search.'%';

        return $query
            ->where('name', 'like', $like)
            ->orWhere('sku', 'like', $like)
            ->orWhereHas('brand', fn (Builder $query): Builder => $query->where('name', 'like', $like))
            ->orWhereHas('category', fn (Builder $query): Builder => $query->where('name', 'like', $like));
    }

    private static function productMetaLine(Product $record): string
    {
        return implode(' · ', [
            $record->brand?->name ?: 'Без бренду',
            $record->category?->name ?: 'Без категорії',
        ]);
    }

    private static function productSkuLine(Product $record): string
    {
        return filled($record->sku) ? 'SKU '.$record->sku : 'SKU -';
    }

    private static function productFlagsLine(Product $record): string
    {
        $flags = [
            $record->is_active ? 'Активний' : 'Неактивний',
        ];

        if ($record->is_new) {
            $flags[] = 'Новинка';
        }

        if ($record->is_hit) {
            $flags[] = 'Хіт';
        }

        if ($record->is_sale) {
            $flags[] = 'Акція';
        }

        return implode(' · ', $flags);
    }

    private static function productOldPriceLine(Product $record): string
    {
        return 'Стара: '.($record->old_price ? self::formatMoney($record->old_price) : '-');
    }

    private static function productPriceLine(Product $record): string
    {
        if (! self::multiCurrencyEnabled()) {
            return self::formatMoney($record->price);
        }

        $settings = CommerceSetting::current();
        $price = $record->prices->firstWhere('currency_id', $settings->default_currency_id);

        if (! $price) {
            return 'Без деф. ціни';
        }

        return self::formatMoney($price->price, $price->currency?->symbol ?: $price->currency?->code ?: '₴');
    }

    private static function productPriceMetaLine(Product $record): string
    {
        if (! self::multiCurrencyEnabled()) {
            return self::productOldPriceLine($record);
        }

        $settings = CommerceSetting::current();
        $otherPrices = $record->prices
            ->where('currency_id', '!=', $settings->default_currency_id)
            ->where('is_active', true)
            ->count();

        return $otherPrices > 0 ? '+'.$otherPrices.' валют' : 'Інших валют немає';
    }

    private static function productPriceTooltip(Product $record): ?string
    {
        if (! self::multiCurrencyEnabled() || $record->prices->isEmpty()) {
            return null;
        }

        return $record->prices
            ->sortBy(fn ($price): string => $price->currency?->code ?? '')
            ->map(fn ($price): string => ($price->currency?->code ?? '-').': '.self::formatMoney($price->price, $price->currency?->symbol ?: $price->currency?->code ?: ''))
            ->implode("\n");
    }

    private static function productStockLine(Product $record): string
    {
        if (self::multiWarehouseEnabled()) {
            return 'Разом: '.number_format((float) $record->stockBalances->sum('quantity'), 3, ',', ' ');
        }

        return 'Залишок: '.$record->stock;
    }

    private static function productStockTooltip(Product $record): ?string
    {
        if (! self::multiWarehouseEnabled() || $record->stockBalances->isEmpty()) {
            return null;
        }

        return $record->stockBalances
            ->sortBy(fn ($balance): string => $balance->warehouse?->name ?? '')
            ->map(fn ($balance): string => ($balance->warehouse?->name ?? '-').': '.number_format((float) $balance->quantity, 3, ',', ' '))
            ->implode("\n");
    }

    private static function productCompletenessHint(Product $record): string
    {
        return Str::limit($record->completenessMissingSummary(), 48);
    }

    private static function formatMoney(mixed $value, string $symbol = '₴'): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return trim(number_format((float) $value, 2, ',', ' ').' '.$symbol);
    }

    private static function stockStatusColor(?string $state): string
    {
        return match ($state) {
            'in_stock' => 'success',
            'low_stock', 'preorder' => 'warning',
            'out_of_stock' => 'danger',
            default => 'gray',
        };
    }

    private static function multiCurrencyEnabled(): bool
    {
        return CommerceSetting::current()->multi_currency_enabled;
    }

    private static function multiWarehouseEnabled(): bool
    {
        return CommerceSetting::current()->multi_warehouse_enabled;
    }

    /**
     * @return array<int, string>
     */
    private static function currencyOptions(): array
    {
        $defaultCurrencyId = CommerceSetting::current()->default_currency_id;

        return Currency::query()
            ->where('is_active', true)
            ->orderByDesc('is_base')
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (Currency $currency): array => [
                $currency->id => $currency->code.((int) $currency->id === (int) $defaultCurrencyId ? ' (за замовчуванням)' : ''),
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function warehouseOptions(): array
    {
        $defaultWarehouseId = CommerceSetting::current()->default_warehouse_id;

        return Warehouse::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Warehouse $warehouse): array => [
                $warehouse->id => $warehouse->name.((int) $warehouse->id === (int) $defaultWarehouseId ? ' (за замовчуванням)' : ''),
            ])
            ->all();
    }
}
