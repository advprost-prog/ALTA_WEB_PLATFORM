<?php

namespace App\Filament\Resources\Banners;

use App\Filament\Resources\Banners\Pages\CreateBanner;
use App\Filament\Resources\Banners\Pages\EditBanner;
use App\Filament\Resources\Banners\Pages\ListBanners;
use App\Filament\Resources\Banners\Pages\ViewBanner;
use App\Models\Banner;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class BannerResource extends Resource
{
    protected static ?string $model = Banner::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|\UnitEnum|null $navigationGroup = 'Контент';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'банер';

    protected static ?string $pluralModelLabel = 'Банери';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Банер')
                    ->tabs([
                        Tab::make('Контент')
                            ->schema([
                                Grid::make(2)
                                    ->schema([
                                        TextInput::make('eyebrow')
                                            ->label('Лейбл / badge')
                                            ->helperText('Короткий верхній напис: акція, сезон, новинка.')
                                            ->maxLength(255),
                                        TextInput::make('title')
                                            ->label('Заголовок')
                                            ->required()
                                            ->maxLength(255),
                                        TextInput::make('subtitle')
                                            ->label('Підзаголовок / опис')
                                            ->maxLength(255)
                                            ->columnSpanFull(),
                                        TextInput::make('button_text')
                                            ->label('Текст основної кнопки')
                                            ->maxLength(255),
                                        TextInput::make('button_url')
                                            ->label('URL основної кнопки')
                                            ->helperText('Дозволені абсолютні URL, внутрішні шляхи з / або якір #.')
                                            ->rules(self::linkRules())
                                            ->maxLength(255),
                                        TextInput::make('secondary_button_text')
                                            ->label('Текст другої кнопки')
                                            ->maxLength(255),
                                        TextInput::make('secondary_button_url')
                                            ->label('URL другої кнопки')
                                            ->helperText('Друга кнопка показується тільки коли є і текст, і URL.')
                                            ->rules(self::linkRules())
                                            ->maxLength(255),
                                    ]),
                            ]),
                        Tab::make('Зображення')
                            ->schema([
                                Grid::make(2)
                                    ->schema([
                                        FileUpload::make('image')
                                            ->label('Desktop зображення')
                                            ->image()
                                            ->disk('public')
                                            ->directory('banners')
                                            ->visibility('public')
                                            ->imageEditor(),
                                        FileUpload::make('mobile_image')
                                            ->label('Mobile зображення')
                                            ->helperText('Якщо порожнє, на mobile буде використано desktop зображення.')
                                            ->image()
                                            ->disk('public')
                                            ->directory('banners/mobile')
                                            ->visibility('public')
                                            ->imageEditor(),
                                        Select::make('image_fit')
                                            ->label('Заповнення')
                                            ->options(Banner::IMAGE_FITS)
                                            ->rules(self::enumRules(Banner::IMAGE_FITS))
                                            ->default(Banner::DESIGN_DEFAULTS['image_fit'])
                                            ->required(),
                                        Select::make('image_position')
                                            ->label('Позиція зображення')
                                            ->options(Banner::IMAGE_POSITIONS)
                                            ->rules(self::enumRules(Banner::IMAGE_POSITIONS))
                                            ->default(Banner::DESIGN_DEFAULTS['image_position'])
                                            ->required(),
                                    ]),
                            ]),
                        Tab::make('Розміщення')
                            ->schema([
                                Grid::make(3)
                                    ->schema([
                                        Select::make('placement')
                                            ->label('Розташування')
                                            ->options(Banner::PLACEMENTS)
                                            ->rules(self::enumRules(Banner::PLACEMENTS))
                                            ->default('home_hero')
                                            ->required(),
                                        TextInput::make('sort_order')
                                            ->label('Порядок')
                                            ->required()
                                            ->numeric()
                                            ->minValue(0)
                                            ->default(0),
                                        Toggle::make('is_active')
                                            ->label('Активний')
                                            ->default(true)
                                            ->required(),
                                        DateTimePicker::make('starts_at')
                                            ->label('Початок'),
                                        DateTimePicker::make('ends_at')
                                            ->label('Завершення'),
                                    ]),
                            ]),
                        Tab::make('Стиль')
                            ->schema([
                                Grid::make(3)
                                    ->schema([
                                        Select::make('style_preset')
                                            ->label('Пресет')
                                            ->helperText('Швидко виставляє базові налаштування стилю. Поля нижче можна змінити вручну.')
                                            ->options(Banner::STYLE_PRESETS)
                                            ->rules(self::enumRules(Banner::STYLE_PRESETS))
                                            ->default(Banner::DESIGN_DEFAULTS['style_preset'])
                                            ->live()
                                            ->afterStateUpdated(function (?string $state, callable $set): void {
                                                foreach (Banner::presetDefaults((string) $state) as $field => $value) {
                                                    $set($field, $value);
                                                }
                                            })
                                            ->required(),
                                        Select::make('layout_variant')
                                            ->label('Layout')
                                            ->options(Banner::LAYOUT_VARIANTS)
                                            ->rules(self::enumRules(Banner::LAYOUT_VARIANTS))
                                            ->default(Banner::DESIGN_DEFAULTS['layout_variant'])
                                            ->required(),
                                        Select::make('visual_style')
                                            ->label('Visual style')
                                            ->options(Banner::VISUAL_STYLES)
                                            ->rules(self::enumRules(Banner::VISUAL_STYLES))
                                            ->default(Banner::DESIGN_DEFAULTS['visual_style'])
                                            ->required(),
                                        Select::make('color_scheme')
                                            ->label('Color scheme')
                                            ->options(Banner::COLOR_SCHEMES)
                                            ->rules(self::enumRules(Banner::COLOR_SCHEMES))
                                            ->default(Banner::DESIGN_DEFAULTS['color_scheme'])
                                            ->required(),
                                        Select::make('text_align')
                                            ->label('Вирівнювання тексту')
                                            ->options(Banner::TEXT_ALIGNMENTS)
                                            ->rules(self::enumRules(Banner::TEXT_ALIGNMENTS))
                                            ->default(Banner::DESIGN_DEFAULTS['text_align'])
                                            ->required(),
                                        Select::make('content_position')
                                            ->label('Позиція контенту')
                                            ->options(Banner::CONTENT_POSITIONS)
                                            ->rules(self::enumRules(Banner::CONTENT_POSITIONS))
                                            ->default(Banner::DESIGN_DEFAULTS['content_position'])
                                            ->required(),
                                        Select::make('vertical_align')
                                            ->label('Вертикаль')
                                            ->options(Banner::VERTICAL_ALIGNMENTS)
                                            ->rules(self::enumRules(Banner::VERTICAL_ALIGNMENTS))
                                            ->default(Banner::DESIGN_DEFAULTS['vertical_align'])
                                            ->required(),
                                        Select::make('height_variant')
                                            ->label('Висота')
                                            ->options(Banner::HEIGHT_VARIANTS)
                                            ->rules(self::enumRules(Banner::HEIGHT_VARIANTS))
                                            ->default(Banner::DESIGN_DEFAULTS['height_variant'])
                                            ->required(),
                                        Select::make('button_style')
                                            ->label('Стиль кнопки')
                                            ->options(Banner::BUTTON_STYLES)
                                            ->rules(self::enumRules(Banner::BUTTON_STYLES))
                                            ->default(Banner::DESIGN_DEFAULTS['button_style'])
                                            ->required(),
                                        Select::make('border_radius')
                                            ->label('Округлення')
                                            ->options(Banner::BORDER_RADII)
                                            ->rules(self::enumRules(Banner::BORDER_RADII))
                                            ->default(Banner::DESIGN_DEFAULTS['border_radius'])
                                            ->required(),
                                        Select::make('shadow')
                                            ->label('Тінь')
                                            ->options(Banner::SHADOWS)
                                            ->rules(self::enumRules(Banner::SHADOWS))
                                            ->default(Banner::DESIGN_DEFAULTS['shadow'])
                                            ->required(),
                                    ]),
                            ]),
                        Tab::make('Оверлей')
                            ->schema([
                                Grid::make(3)
                                    ->schema([
                                        Toggle::make('overlay_enabled')
                                            ->label('Увімкнути оверлей')
                                            ->helperText('Допомагає зберегти читабельність тексту поверх фото.')
                                            ->default(true)
                                            ->live(),
                                        Select::make('overlay_style')
                                            ->label('Стиль оверлею')
                                            ->options(Banner::OVERLAY_STYLES)
                                            ->rules(self::enumRules(Banner::OVERLAY_STYLES))
                                            ->default(Banner::DESIGN_DEFAULTS['overlay_style'])
                                            ->visible(fn (callable $get): bool => (bool) $get('overlay_enabled'))
                                            ->required(),
                                        TextInput::make('overlay_opacity')
                                            ->label('Прозорість')
                                            ->helperText('0-90. Більше значення означає темніший/сильніший оверлей.')
                                            ->numeric()
                                            ->minValue(0)
                                            ->maxValue(90)
                                            ->default(30)
                                            ->visible(fn (callable $get): bool => (bool) $get('overlay_enabled'))
                                            ->required(),
                                    ]),
                            ]),
                        Tab::make('Кольори')
                            ->schema([
                                Grid::make(3)
                                    ->schema([
                                        ColorPicker::make('background_color')
                                            ->label('Фон')
                                            ->helperText('Опційно. Якщо порожньо, використовується пресет/тема.')
                                            ->rules(self::colorRules()),
                                        ColorPicker::make('text_color')
                                            ->label('Текст')
                                            ->helperText('Опційно. Задавайте тільки коли потрібен ручний контраст.')
                                            ->rules(self::colorRules()),
                                        ColorPicker::make('accent_color')
                                            ->label('Акцент')
                                            ->default('#ffb703')
                                            ->rules(self::colorRules())
                                            ->required(),
                                    ]),
                            ]),
                        Tab::make('Анімація')
                            ->schema([
                                Grid::make(4)
                                    ->schema([
                                        Toggle::make('animation_enabled')
                                            ->label('Увімкнути анімацію')
                                            ->helperText('Легка CSS-анімація. За замовчуванням вимкнена.')
                                            ->default(false)
                                            ->live(),
                                        Select::make('animation_type')
                                            ->label('Тип')
                                            ->options(Banner::ANIMATION_TYPES)
                                            ->rules(self::enumRules(Banner::ANIMATION_TYPES))
                                            ->default(Banner::DESIGN_DEFAULTS['animation_type'])
                                            ->visible(fn (callable $get): bool => (bool) $get('animation_enabled'))
                                            ->required(),
                                        TextInput::make('animation_delay_ms')
                                            ->label('Delay, ms')
                                            ->numeric()
                                            ->minValue(0)
                                            ->maxValue(5000)
                                            ->default(0)
                                            ->visible(fn (callable $get): bool => (bool) $get('animation_enabled'))
                                            ->required(),
                                        TextInput::make('animation_duration_ms')
                                            ->label('Duration, ms')
                                            ->numeric()
                                            ->minValue(100)
                                            ->maxValue(3000)
                                            ->default(500)
                                            ->visible(fn (callable $get): bool => (bool) $get('animation_enabled'))
                                            ->required(),
                                    ]),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('eyebrow')
                    ->label('Лейбл')
                    ->placeholder('-'),
                TextEntry::make('title')
                    ->label('Заголовок'),
                TextEntry::make('subtitle')
                    ->label('Підзаголовок')
                    ->placeholder('-'),
                TextEntry::make('button_text')
                    ->label('Кнопка')
                    ->placeholder('-'),
                TextEntry::make('button_url')
                    ->label('URL кнопки')
                    ->placeholder('-'),
                TextEntry::make('secondary_button_text')
                    ->label('Друга кнопка')
                    ->placeholder('-'),
                TextEntry::make('secondary_button_url')
                    ->label('URL другої кнопки')
                    ->placeholder('-'),
                ImageEntry::make('image')
                    ->label('Desktop')
                    ->placeholder('-'),
                ImageEntry::make('mobile_image')
                    ->label('Mobile')
                    ->placeholder('-'),
                TextEntry::make('placement')
                    ->label('Розташування')
                    ->badge(),
                TextEntry::make('style_preset')
                    ->label('Пресет')
                    ->badge(),
                TextEntry::make('layout_variant')
                    ->label('Layout')
                    ->badge(),
                TextEntry::make('visual_style')
                    ->label('Visual style')
                    ->badge(),
                TextEntry::make('color_scheme')
                    ->label('Color scheme')
                    ->badge(),
                TextEntry::make('text_align')
                    ->label('Текст'),
                TextEntry::make('content_position')
                    ->label('Контент'),
                TextEntry::make('vertical_align')
                    ->label('Вертикаль'),
                IconEntry::make('overlay_enabled')
                    ->label('Оверлей')
                    ->boolean(),
                TextEntry::make('overlay_style')
                    ->label('Оверлей стиль'),
                TextEntry::make('overlay_opacity')
                    ->label('Оверлей %')
                    ->numeric(),
                TextEntry::make('background_color')
                    ->label('Фон')
                    ->placeholder('-'),
                TextEntry::make('text_color')
                    ->label('Колір тексту')
                    ->placeholder('-'),
                TextEntry::make('accent_color')
                    ->label('Акцент'),
                TextEntry::make('button_style')
                    ->label('Кнопки'),
                TextEntry::make('height_variant')
                    ->label('Висота'),
                TextEntry::make('border_radius')
                    ->label('Округлення'),
                TextEntry::make('shadow')
                    ->label('Тінь'),
                TextEntry::make('image_fit')
                    ->label('Fit'),
                TextEntry::make('image_position')
                    ->label('Image position'),
                IconEntry::make('animation_enabled')
                    ->label('Анімація')
                    ->boolean(),
                TextEntry::make('animation_type')
                    ->label('Тип анімації'),
                TextEntry::make('animation_delay_ms')
                    ->label('Delay')
                    ->numeric(),
                TextEntry::make('animation_duration_ms')
                    ->label('Duration')
                    ->numeric(),
                TextEntry::make('starts_at')
                    ->label('Початок')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('ends_at')
                    ->label('Завершення')
                    ->dateTime()
                    ->placeholder('-'),
                IconEntry::make('is_active')
                    ->label('Активний')
                    ->boolean(),
                TextEntry::make('sort_order')
                    ->label('Порядок')
                    ->numeric(),
                TextEntry::make('created_at')
                    ->label('Створено')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->label('Оновлено')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title')
                    ->label('Заголовок')
                    ->searchable(),
                ImageColumn::make('image')
                    ->label('Фото')
                    ->square(),
                TextColumn::make('placement')
                    ->label('Розташування')
                    ->badge()
                    ->searchable(),
                TextColumn::make('style_preset')
                    ->label('Пресет')
                    ->badge()
                    ->sortable(),
                TextColumn::make('layout_variant')
                    ->label('Layout')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('visual_style')
                    ->label('Стиль')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('starts_at')
                    ->label('Початок')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->label('Завершення')
                    ->dateTime()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Активний')
                    ->boolean(),
                TextColumn::make('sort_order')
                    ->label('Порядок')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Створено')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Оновлено')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('placement')
                    ->label('Розташування')
                    ->options(Banner::PLACEMENTS),
                SelectFilter::make('style_preset')
                    ->label('Пресет')
                    ->options(Banner::STYLE_PRESETS),
                TernaryFilter::make('is_active')
                    ->label('Активність'),
            ])
            ->recordActions([
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
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBanners::route('/'),
            'create' => CreateBanner::route('/create'),
            'view' => ViewBanner::route('/{record}'),
            'edit' => EditBanner::route('/{record}/edit'),
        ];
    }

    private static function enumRules(array $options): array
    {
        return ['in:'.implode(',', array_keys($options))];
    }

    private static function linkRules(): array
    {
        return [
            'nullable',
            'string',
            'max:255',
            'regex:/^(https?:\/\/|\/(?!\/)|#)[^\s\x00-\x1F\x7F]*$/i',
        ];
    }

    private static function colorRules(): array
    {
        return [
            'nullable',
            'regex:/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/',
        ];
    }
}
