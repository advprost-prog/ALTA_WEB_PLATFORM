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
use App\Filament\Resources\Products\RelationManagers\ProductVariantsRelationManager;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\AiSuggestion;
use App\Models\CommerceSetting;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductImageCandidate;
use App\Models\ProductVariant;
use App\Models\TaxProfile;
use App\Models\Unit;
use App\Models\VariantPackage;
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
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
use Livewire\Component;

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
                Tabs::make('Товар')
                    ->tabs([
                        Tab::make('Основне')
                            ->schema([
                                Section::make('Основне')
                                    ->description('Назва, URL і привʼязка до каталогу.')
                                    ->schema([
                                        TextInput::make('name')
                                            ->label('Назва товару')
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
                                        Select::make('status')
                                            ->label('Статус')
                                            ->options([
                                                'draft' => 'Чернетка',
                                                'active' => 'Активний',
                                                'archived' => 'Архів',
                                            ])
                                            ->default('draft')
                                            ->required(),
                                        Toggle::make('is_active')
                                            ->label('Активний')
                                            ->default(true)
                                            ->required(),
                                        Toggle::make('is_featured')
                                            ->label('Рекомендований')
                                            ->default(false),
                                        Toggle::make('is_new')
                                            ->label('Новинка')
                                            ->required(),
                                        Toggle::make('is_hit')
                                            ->label('Хіт')
                                            ->required(),
                                        Toggle::make('is_sale')
                                            ->label('Акція')
                                            ->required(),
                                        TextInput::make('sort_order')
                                            ->label('Порядок')
                                            ->numeric()
                                            ->default(0)
                                            ->required(),
                                    ])
                                    ->columns(2),
                                Section::make('Опис')
                                    ->schema([
                                        Textarea::make('short_description')
                                            ->label('Короткий опис')
                                            ->rows(3),
                                        Textarea::make('description')
                                            ->label('Повний опис')
                                            ->rows(7),
                                    ]),
                            ]),
                        Tab::make('Продаж')
                            ->schema([
                                Section::make('Режим продажу')
                                    ->schema([
                                        Toggle::make('has_variants')
                                            ->label('Товар має варіанти')
                                            ->helperText('Для простого товару артикул, одиниці, податки, ціни й залишки редагуються як властивості товару.')
                                            ->default(false)
                                            ->live(),
                                        Placeholder::make('variants_sales_notice')
                                            ->label('Продажні налаштування')
                                            ->content('Продажні налаштування задаються окремо для кожного варіанту у вкладці "Варіанти".')
                                            ->visible(fn (callable $get): bool => (bool) $get('has_variants')),
                                    ])
                                    ->columns(2),
                                Section::make('Продажні налаштування')
                                    ->description('Для простого товару ці поля виглядають як властивості товару, а технічно зберігаються у службовому default SKU.')
                                    ->visible(fn (callable $get): bool => ! (bool) $get('has_variants'))
                                    ->schema([
                                        TextInput::make('sku')
                                            ->label('Артикул')
                                            ->required()
                                            ->unique(ignoreRecord: true)
                                            ->maxLength(255),
                                        TextInput::make('default_variant_name')
                                            ->label('Назва для продажу')
                                            ->maxLength(255),
                                        TextInput::make('default_variant_barcode')
                                            ->label('Штрихкод')
                                            ->maxLength(255),
                                        Select::make('default_variant_base_unit_id')
                                            ->label('Базова одиниця')
                                            ->options(fn (): array => Unit::query()->orderBy('sort_order')->pluck('name', 'id')->all())
                                            ->default(fn (): ?int => Unit::ensurePiece()->id)
                                            ->searchable()
                                            ->required(),
                                        Select::make('default_variant_sales_unit_id')
                                            ->label('Одиниця продажу')
                                            ->options(fn (): array => Unit::query()->orderBy('sort_order')->pluck('name', 'id')->all())
                                            ->searchable(),
                                        Select::make('default_variant_purchase_unit_id')
                                            ->label('Одиниця закупівлі')
                                            ->options(fn (): array => Unit::query()->orderBy('sort_order')->pluck('name', 'id')->all())
                                            ->searchable(),
                                        Toggle::make('default_variant_is_active')
                                            ->label('Активний для продажу')
                                            ->default(true),
                                        Toggle::make('default_variant_is_default')
                                            ->label('Основний запис')
                                            ->default(true)
                                            ->disabled()
                                            ->hidden()
                                            ->dehydrated(false),
                                    ])
                                    ->columns(2),
                                Section::make('Ціни та склад')
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
                                        Select::make('stock_status')
                                            ->label('Статус наявності')
                                            ->options(Product::STOCK_STATUSES)
                                            ->default('in_stock')
                                            ->required(),
                                        TextInput::make('stock')
                                            ->label('Залишок')
                                            ->required()
                                            ->numeric()
                                            ->minValue(0)
                                            ->default(0)
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
                            ]),
                        Tab::make('Податки / Акциз')
                            ->schema([
                                Section::make('Податки та акциз задаються у варіантах')
                                    ->schema([
                                        Placeholder::make('variant_tax_notice')
                                            ->label('Оподаткування')
                                            ->content('Податки та акциз задаються окремо для кожного варіанту.'),
                                    ])
                                    ->visible(fn (callable $get): bool => (bool) $get('has_variants')),
                                Section::make('Оподаткування товару')
                                    ->visible(fn (callable $get): bool => ! (bool) $get('has_variants'))
                                    ->schema([
                                        Select::make('default_variant_tax_profile_id')
                                            ->label('Оподаткування')
                                            ->options(fn (): array => TaxProfile::query()->orderBy('sort_order')->pluck('name', 'id')->all())
                                            ->default(fn (): ?int => TaxProfile::ensureDefault()->id)
                                            ->searchable()
                                            ->required(),
                                        Toggle::make('default_variant_is_excise_applicable')
                                            ->label('Акцизний товар')
                                            ->live()
                                            ->afterStateUpdated(function (bool $state, callable $get, callable $set): void {
                                                if (! $state) {
                                                    $set('default_variant_excise_rate', null);
                                                    $set('default_variant_requires_excise_stamp_entry', false);

                                                    return;
                                                }

                                                if (blank($get('default_variant_excise_rate'))) {
                                                    $set('default_variant_excise_rate', '5.00');
                                                }
                                            }),
                                        TextInput::make('default_variant_excise_rate')
                                            ->label('Ставка акцизу, %')
                                            ->numeric()
                                            ->visible(fn (callable $get): bool => (bool) $get('default_variant_is_excise_applicable')),
                                        Toggle::make('default_variant_requires_excise_stamp_entry')
                                            ->label('Потребує введення акцизної марки')
                                            ->visible(fn (callable $get): bool => (bool) $get('default_variant_is_excise_applicable')),
                                    ])
                                    ->columns(2),
                            ]),
                        Tab::make('Пакування')
                            ->schema([
                                Section::make('Пакування товару')
                                    ->visible(fn (callable $get): bool => ! (bool) $get('has_variants'))
                                    ->schema([
                                        Placeholder::make('default_variant_packages_hint')
                                            ->label('Пакування')
                                            ->content('Для простого товару пакування редагується як властивість товару. Дані зберігаються у службовому SKU без ручного вибору варіанту.'),
                                        Repeater::make('default_variant_packages')
                                            ->label('Пакування товару')
                                            ->schema([
                                                Hidden::make('id'),
                                                TextInput::make('name')
                                                    ->label('Назва')
                                                    ->required()
                                                    ->maxLength(255),
                                                Select::make('unit_id')
                                                    ->label('Одиниця')
                                                    ->options(fn (): array => Unit::query()->orderBy('sort_order')->pluck('name', 'id')->all())
                                                    ->searchable()
                                                    ->required(),
                                                TextInput::make('quantity_in_base_unit')
                                                    ->label('Кількість у базовій одиниці')
                                                    ->numeric()
                                                    ->minValue(0.001)
                                                    ->default(1)
                                                    ->required(),
                                                TextInput::make('barcode')
                                                    ->label('Штрихкод')
                                                    ->maxLength(255),
                                                Toggle::make('is_default_sales_package')
                                                    ->label('Основне для продажу')
                                                    ->default(false),
                                                Toggle::make('is_active')
                                                    ->label('Активне')
                                                    ->default(true),
                                                TextInput::make('sort_order')
                                                    ->label('Порядок')
                                                    ->numeric()
                                                    ->default(0)
                                                    ->required(),
                                            ])
                                            ->columns(4)
                                            ->defaultItems(0)
                                            ->columnSpanFull(),
                                    ]),
                                Section::make('Пакування задається у варіантах')
                                    ->visible(fn (callable $get): bool => (bool) $get('has_variants'))
                                    ->schema([
                                        Placeholder::make('variant_packages_hint')
                                            ->label('Пакування')
                                            ->content('Пакування налаштовується всередині конкретного варіанту.'),
                                    ]),
                            ]),
                        Tab::make('Штрихкоди')
                            ->schema([
                                Section::make('Штрихкоди товару')
                                    ->visible(fn (callable $get): bool => ! (bool) $get('has_variants'))
                                    ->schema([
                                        Placeholder::make('default_variant_barcodes_hint')
                                            ->label('Штрихкоди')
                                            ->content('Для простого товару штрихкоди редагуються як властивість товару. Дані зберігаються у службовому SKU без ручного вибору варіанту.'),
                                        Repeater::make('default_variant_barcodes')
                                            ->label('Штрихкоди товару')
                                            ->schema([
                                                Hidden::make('id'),
                                                TextInput::make('barcode')
                                                    ->label('Штрихкод')
                                                    ->required()
                                                    ->maxLength(255),
                                                Select::make('type')
                                                    ->label('Тип')
                                                    ->options([
                                                        'ean13' => 'EAN-13',
                                                        'ean8' => 'EAN-8',
                                                        'code128' => 'Code 128',
                                                        'upc' => 'UPC',
                                                        'internal' => 'Внутрішній',
                                                    ])
                                                    ->default('ean13')
                                                    ->required(),
                                                Toggle::make('is_primary')
                                                    ->label('Основний')
                                                    ->default(false),
                                                Toggle::make('is_active')
                                                    ->label('Активний')
                                                    ->default(true),
                                            ])
                                            ->columns(4)
                                            ->defaultItems(0)
                                            ->columnSpanFull(),
                                    ]),
                                Section::make('Штрихкоди задаються у варіантах')
                                    ->visible(fn (callable $get): bool => (bool) $get('has_variants'))
                                    ->schema([
                                        Placeholder::make('variant_barcodes_hint')
                                            ->label('Штрихкоди')
                                            ->content('Штрихкоди налаштовуються всередині конкретного варіанту.'),
                                    ]),
                            ]),
                        Tab::make('Фото / SEO')
                            ->schema([
                                Section::make('Фото')
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
                                Section::make('SEO')
                                    ->schema([
                                        TextInput::make('seo_title')
                                            ->label('SEO title')
                                            ->maxLength(255),
                                        Textarea::make('seo_description')
                                            ->label('SEO description')
                                            ->rows(3),
                                    ]),
                            ]),
                        Tab::make('Характеристики')
                            ->schema([
                                Section::make('Характеристики товару')
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
                            ]),
                        Tab::make('Варіанти')
                            ->visible(fn (callable $get): bool => (bool) $get('has_variants'))
                            ->schema([
                                Section::make('Варіанти товару')
                                    ->description('Тут адміністратор працює з реальними SKU тільки для товарів з кількома варіантами.')
                                    ->schema([
                                        Placeholder::make('variants_hint')
                                            ->label('Пояснення')
                                            ->content('Default SKU залишається першим варіантом. Додаткові варіанти мають власні одиниці, податки, пакування, штрихкоди, ціни й залишки.'),
                                    ]),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillDefaultVariantFormState(array $data, ?Product $product): array
    {
        if (! $product) {
            return $data;
        }

        $variant = $product->hasVariants()
            ? $product->resolveDefaultVariant()
            : $product->ensureDefaultVariant();

        if (! $variant) {
            return $data;
        }

        $data['default_variant_name'] = $variant->name;
        $data['default_variant_barcode'] = $variant->barcode;
        $data['default_variant_is_active'] = (bool) $variant->is_active;
        $data['default_variant_is_default'] = true;
        $data['default_variant_base_unit_id'] = $variant->base_unit_id;
        $data['default_variant_sales_unit_id'] = $variant->sales_unit_id;
        $data['default_variant_purchase_unit_id'] = $variant->purchase_unit_id;
        $data['default_variant_tax_profile_id'] = $variant->tax_profile_id;
        $data['default_variant_is_excise_applicable'] = (bool) $variant->is_excise_applicable;
        $data['default_variant_excise_rate'] = $variant->excise_rate;
        $data['default_variant_requires_excise_stamp_entry'] = (bool) $variant->requires_excise_stamp_entry;
        $data['default_variant_packages'] = $variant->packages()
            ->get()
            ->map(fn (VariantPackage $package): array => [
                'id' => $package->id,
                'name' => $package->name,
                'unit_id' => $package->unit_id,
                'quantity_in_base_unit' => $package->quantity_in_base_unit,
                'barcode' => $package->barcode,
                'is_default_sales_package' => (bool) $package->is_default_sales_package,
                'is_active' => (bool) $package->is_active,
                'sort_order' => $package->sort_order,
            ])
            ->all();
        $data['default_variant_barcodes'] = $variant->barcodes()
            ->get()
            ->map(fn (ProductBarcode $barcode): array => [
                'id' => $barcode->id,
                'barcode' => $barcode->barcode,
                'type' => $barcode->type,
                'is_primary' => (bool) $barcode->is_primary,
                'is_active' => (bool) $barcode->is_active,
            ])
            ->all();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function extractDefaultVariantPayload(array $data): array
    {
        $variantPayload = [];
        $keys = [
            'default_variant_name',
            'default_variant_barcode',
            'default_variant_is_active',
            'default_variant_base_unit_id',
            'default_variant_sales_unit_id',
            'default_variant_purchase_unit_id',
            'default_variant_tax_profile_id',
            'default_variant_is_excise_applicable',
            'default_variant_excise_rate',
            'default_variant_requires_excise_stamp_entry',
            'default_variant_packages',
            'default_variant_barcodes',
        ];

        foreach ($keys as $key) {
            $variantPayload[$key] = $data[$key] ?? null;
            unset($data[$key]);
        }

        return [$data, $variantPayload];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function syncDefaultVariantFromPayload(Product $product, array $payload): void
    {
        $product->refresh();
        $variant = $product->ensureDefaultVariant();

        $pieceUnit = Unit::ensurePiece();
        $defaultTaxProfile = TaxProfile::ensureDefault();

        $isExcise = (bool) ($payload['default_variant_is_excise_applicable'] ?? false);
        $exciseRate = $payload['default_variant_excise_rate'] ?? null;

        if ($isExcise && blank($exciseRate)) {
            $exciseRate = 5.00;
        }

        if (! $isExcise) {
            $exciseRate = null;
        }

        $variant->forceFill([
            'sku' => $product->sku,
            'name' => $payload['default_variant_name'] ?: null,
            'barcode' => $payload['default_variant_barcode'] ?: null,
            'base_unit_id' => $payload['default_variant_base_unit_id'] ?: $pieceUnit->id,
            'sales_unit_id' => $payload['default_variant_sales_unit_id'] ?: ($payload['default_variant_base_unit_id'] ?: $pieceUnit->id),
            'purchase_unit_id' => $payload['default_variant_purchase_unit_id'] ?: ($payload['default_variant_base_unit_id'] ?: $pieceUnit->id),
            'tax_profile_id' => $payload['default_variant_tax_profile_id'] ?: $defaultTaxProfile->id,
            'is_excise_applicable' => $isExcise,
            'excise_rate' => $exciseRate,
            'requires_excise_stamp_entry' => $isExcise ? (bool) ($payload['default_variant_requires_excise_stamp_entry'] ?? false) : false,
            'is_active' => (bool) ($payload['default_variant_is_active'] ?? $product->is_active),
            'is_default' => true,
            'sort_order' => 0,
        ]);

        $variant->save();

        if (! $product->hasVariants()) {
            self::syncDefaultVariantPackages($variant, $payload['default_variant_packages'] ?? []);
            self::syncDefaultVariantBarcodes($variant, $payload['default_variant_barcodes'] ?? []);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private static function syncDefaultVariantPackages(ProductVariant $variant, array $rows): void
    {
        $seenIds = [];

        foreach ($rows as $row) {
            if (blank($row['name'] ?? null) || blank($row['unit_id'] ?? null)) {
                continue;
            }

            $package = isset($row['id'])
                ? $variant->packages()->whereKey($row['id'])->first()
                : null;

            $package ??= new VariantPackage(['product_variant_id' => $variant->id]);

            $package->forceFill([
                'name' => $row['name'],
                'unit_id' => $row['unit_id'],
                'quantity_in_base_unit' => $row['quantity_in_base_unit'] ?? 1,
                'barcode' => $row['barcode'] ?? null,
                'is_default_sales_package' => (bool) ($row['is_default_sales_package'] ?? false),
                'is_active' => (bool) ($row['is_active'] ?? true),
                'sort_order' => $row['sort_order'] ?? 0,
            ])->save();

            $seenIds[] = $package->id;
        }

        $variant->packages()
            ->when($seenIds !== [], fn ($query) => $query->whereNotIn('id', $seenIds))
            ->delete();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private static function syncDefaultVariantBarcodes(ProductVariant $variant, array $rows): void
    {
        $seenIds = [];

        foreach ($rows as $row) {
            if (blank($row['barcode'] ?? null)) {
                continue;
            }

            $barcode = isset($row['id'])
                ? $variant->barcodes()->whereKey($row['id'])->first()
                : null;

            $barcode ??= new ProductBarcode(['product_variant_id' => $variant->id]);

            $barcode->forceFill([
                'barcode' => $row['barcode'],
                'type' => $row['type'] ?? 'ean13',
                'is_primary' => (bool) ($row['is_primary'] ?? false),
                'is_active' => (bool) ($row['is_active'] ?? true),
            ])->save();

            $seenIds[] = $barcode->id;
        }

        $variant->barcodes()
            ->when($seenIds !== [], fn ($query) => $query->whereNotIn('id', $seenIds))
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeSkuForVariantMode(array $data): array
    {
        if (! ($data['has_variants'] ?? false) || filled($data['sku'] ?? null)) {
            return $data;
        }

        $base = Str::upper(Str::slug((string) ($data['slug'] ?? $data['name'] ?? 'product')));
        $base = $base !== '' ? $base : 'PRODUCT';
        $sku = $base;
        $counter = 1;

        while (Product::query()->where('sku', $sku)->exists()) {
            $sku = $base.'-'.$counter++;
        }

        $data['sku'] = $sku;

        return $data;
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
            ProductVariantsRelationManager::class,
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
            ->action(function (Product $record, array $data, Component $livewire): void {
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
