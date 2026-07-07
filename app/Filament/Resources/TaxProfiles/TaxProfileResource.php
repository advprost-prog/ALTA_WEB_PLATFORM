<?php

namespace App\Filament\Resources\TaxProfiles;

use App\Filament\Resources\TaxProfiles\Pages\CreateTaxProfile;
use App\Filament\Resources\TaxProfiles\Pages\EditTaxProfile;
use App\Filament\Resources\TaxProfiles\Pages\ListTaxProfiles;
use App\Models\TaxProfile;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class TaxProfileResource extends Resource
{
    protected static ?string $model = TaxProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|\UnitEnum|null $navigationGroup = 'Каталог';

    protected static ?int $navigationSort = 36;

    protected static ?string $modelLabel = 'податковий профіль';

    protected static ?string $pluralModelLabel = 'Податкові профілі';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Податковий профіль')
                ->schema([
                    TextInput::make('name')->label('Назва')->required()->maxLength(255),
                    TextInput::make('code')->label('Code')->required()->unique(ignoreRecord: true)->maxLength(100),
                    TextInput::make('vat_rate')->label('VAT %')->required()->numeric()->default(0)->minValue(0)->maxValue(100),
                    TextInput::make('fiscal_group_code')->label('Fiscal group code')->maxLength(100),
                    Textarea::make('description')->label('Опис')->rows(4)->columnSpanFull(),
                    Toggle::make('price_includes_tax')->label('Ціна включає податок')->default(true),
                    Toggle::make('is_default')->label('За замовчуванням')->default(false),
                    Toggle::make('is_active')->label('Активний')->default(true),
                    TextInput::make('sort_order')->label('Порядок')->numeric()->required()->default(0)->minValue(0),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->label('Назва')->searchable(),
                TextColumn::make('code')->label('Code')->searchable(),
                TextColumn::make('vat_rate')->label('VAT %')->sortable(),
                IconColumn::make('price_includes_tax')->label('Incl tax')->boolean(),
                IconColumn::make('is_default')->label('Default')->boolean(),
                IconColumn::make('is_active')->label('Активний')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Активність'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTaxProfiles::route('/'),
            'create' => CreateTaxProfile::route('/create'),
            'edit' => EditTaxProfile::route('/{record}/edit'),
        ];
    }
}
