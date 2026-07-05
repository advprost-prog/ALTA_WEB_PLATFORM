<?php

namespace App\Filament\Resources\AiSuggestions;

use App\Filament\Resources\AiSuggestions\Pages\EditAiSuggestion;
use App\Filament\Resources\AiSuggestions\Pages\ListAiSuggestions;
use App\Filament\Resources\AiSuggestions\Pages\ViewAiSuggestion;
use App\Filament\Resources\Products\ProductResource;
use App\Models\AiSuggestion;
use App\Models\Product;
use App\Services\Ai\ProductEnrichmentService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Grid;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Throwable;

class AiSuggestionResource extends Resource
{
    protected static ?string $model = AiSuggestion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|\UnitEnum|null $navigationGroup = 'AI';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'AI-пропозиція';

    protected static ?string $pluralModelLabel = 'AI-пропозиції';

    protected static ?string $recordTitleAttribute = 'field';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Пропозиція')
                    ->schema([
                        TextInput::make('entity_type')
                            ->label('Тип сутності')
                            ->disabled(),
                        TextInput::make('entity_id')
                            ->label('ID сутності')
                            ->disabled(),
                        TextInput::make('field')
                            ->label('Поле')
                            ->disabled(),
                        Select::make('status')
                            ->label('Статус')
                            ->options([
                                AiSuggestion::STATUS_PENDING => AiSuggestion::STATUSES[AiSuggestion::STATUS_PENDING],
                                AiSuggestion::STATUS_ACCEPTED => AiSuggestion::STATUSES[AiSuggestion::STATUS_ACCEPTED],
                                AiSuggestion::STATUS_REJECTED => AiSuggestion::STATUSES[AiSuggestion::STATUS_REJECTED],
                                AiSuggestion::STATUS_APPLIED => AiSuggestion::STATUSES[AiSuggestion::STATUS_APPLIED],
                            ])
                            ->disabled(fn (string $operation): bool => $operation !== 'edit' || ! in_array(auth()->user()?->role, [\App\Enums\UserRole::Admin, \App\Enums\UserRole::Manager], true)),
                        Textarea::make('old_value')
                            ->label('Поточне значення')
                            ->rows(5)
                            ->disabled(),
                        Textarea::make('suggested_value')
                            ->label('AI-пропозиція')
                            ->helperText('Відредагуйте текст перед застосуванням. Застосовані та відхилені пропозиції редагувати не можна.')
                            ->rows(7)
                            ->disabled(fn (AiSuggestion $record): bool => ! $record->canBeEdited()),
                        Textarea::make('suggested_payload_json')
                            ->label('Payload JSON')
                            ->helperText('Для attributes, GTIN та image candidates редагуйте JSON уважно. Збереження не застосовує пропозицію автоматично.')
                            ->rows(10)
                            ->visible(fn (AiSuggestion $record): bool => filled($record->suggested_payload))
                            ->disabled(fn (AiSuggestion $record): bool => ! $record->canBeEdited()),
                    ])
                    ->columns(2),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('field')
                    ->label('Поле'),
                TextEntry::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AiSuggestion::STATUSES[$state] ?? $state)
                    ->color(fn (?string $state): string => self::statusColor($state)),
                TextEntry::make('apply_status')
                    ->label('Apply')
                    ->state(fn (AiSuggestion $record): string => $record->applyStatusLabel())
                    ->badge()
                    ->color(fn (AiSuggestion $record): string => $record->applyStatusColor()),
                TextEntry::make('apply_reason')
                    ->label('Причина')
                    ->state(fn (AiSuggestion $record): ?string => $record->applyUnavailableReason())
                    ->placeholder('Можна застосувати'),
                TextEntry::make('product_link')
                    ->label('Товар')
                    ->state(fn (AiSuggestion $record): string => self::productLabel($record))
                    ->url(fn (AiSuggestion $record): ?string => self::productUrl($record)),
                TextEntry::make('aiRun.id')
                    ->label('AI Run'),
                TextEntry::make('old_value')
                    ->label('Поточне значення')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('suggested_value')
                    ->label('AI-пропозиція')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('suggested_payload')
                    ->label('Payload')
                    ->state(fn (AiSuggestion $record): ?string => self::formatPayload($record->suggested_payload))
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->label('Створено')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('applied_at')
                    ->label('Застосовано')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('appliedBy.name')
                    ->label('Застосував')
                    ->placeholder('-'),
                TextEntry::make('edited_at')
                    ->label('Відредаговано')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('editedBy.name')
                    ->label('Відредагував')
                    ->placeholder('-'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('field')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['product', 'aiRun', 'appliedBy']))
            ->columns([
                Grid::make([
                    'default' => 1,
                    'lg' => 2,
                    '2xl' => 4,
                ])
                    ->schema([
                        TextColumn::make('field')
                            ->label('Пропозиція')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => AiSuggestion::FIELD_LABELS[$state] ?? (string) $state)
                            ->description(fn (AiSuggestion $record): string => self::suggestionMetaLine($record))
                            ->searchable()
                            ->sortable(),
                        TextColumn::make('product_link')
                            ->label('Товар')
                            ->state(fn (AiSuggestion $record): string => self::productLabel($record))
                            ->description(fn (AiSuggestion $record): string => self::entityMetaLine($record))
                            ->url(fn (AiSuggestion $record): ?string => self::productUrl($record))
                            ->searchable(query: fn (Builder $query, string $search): Builder => self::applyProductSearch($query, $search))
                            ->lineClamp(2),
                        TextColumn::make('workflow')
                            ->label('Workflow')
                            ->state(fn (AiSuggestion $record): string => $record->applyStatusLabel())
                            ->badge()
                            ->color(fn (AiSuggestion $record): string => $record->applyStatusColor())
                            ->description(fn (AiSuggestion $record): string => self::workflowMetaLine($record))
                            ->tooltip(fn (AiSuggestion $record): ?string => $record->applyUnavailableReason()),
                        TextColumn::make('suggested_value')
                            ->label('Превʼю')
                            ->state(fn (AiSuggestion $record): string => self::preview($record))
                            ->description(fn (AiSuggestion $record): string => self::previewMetaLine($record))
                            ->searchable()
                            ->lineClamp(2)
                            ->wrap(),
                    ]),
            ])
            ->filters([
                SelectFilter::make('workflow')
                    ->label('Робочий список')
                    ->options([
                        'active' => 'Активні pending/accepted',
                        'history' => 'Історія applied/rejected',
                        'all' => 'Усі',
                    ])
                    ->default('active')
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? 'active') {
                        'history' => $query->whereIn('status', [AiSuggestion::STATUS_APPLIED, AiSuggestion::STATUS_REJECTED]),
                        'all' => $query,
                        default => $query->whereIn('status', [AiSuggestion::STATUS_PENDING, AiSuggestion::STATUS_ACCEPTED]),
                    }),
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(AiSuggestion::STATUSES),
                SelectFilter::make('field')
                    ->label('Поле')
                    ->options(AiSuggestion::FIELD_LABELS),
                SelectFilter::make('product_id')
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
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $query): Builder => $query
                            ->where('entity_type', Product::class)
                            ->where('entity_id', (int) $data['value'])
                    )),
                SelectFilter::make('entity_type')
                    ->label('Тип сутності')
                    ->options([
                        Product::class => 'Товар',
                    ]),
                Filter::make('created_at')
                    ->label('Створено')
                    ->schema([
                        DatePicker::make('created_from')
                            ->label('Від'),
                        DatePicker::make('created_until')
                            ->label('До'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['created_from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['created_until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date))),
                SelectFilter::make('applied_by')
                    ->label('Застосував')
                    ->relationship('appliedBy', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make()
                        ->visible(fn (AiSuggestion $record): bool => Gate::allows('update', $record)),
                    self::applyAction(),
                    self::rejectAction(),
                ])
                    ->label('Дії')
                    ->icon(Heroicon::EllipsisVertical)
                    ->iconButton()
                    ->size(Size::Small)
                    ->color('gray'),
            ])
            ->recordActionsAlignment('end');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiSuggestions::route('/'),
            'view' => ViewAiSuggestion::route('/{record}'),
            'edit' => EditAiSuggestion::route('/{record}/edit'),
        ];
    }

    public static function applyAction(): Action
    {
        return Action::make('apply')
            ->label('Застосувати')
            ->icon(Heroicon::OutlinedCheck)
            ->color('success')
            ->visible(fn (AiSuggestion $record): bool => $record->canBeAppliedAutomatically() && Gate::allows('apply', $record))
            ->authorize(fn (AiSuggestion $record): bool => Gate::allows('apply', $record))
            ->requiresConfirmation()
            ->action(function (AiSuggestion $record): void {
                try {
                    app(ProductEnrichmentService::class)->applySuggestion($record, auth()->user());

                    Notification::make()
                        ->success()
                        ->title('AI-пропозицію застосовано і прибрано з активного списку')
                        ->send();
                } catch (Throwable $exception) {
                    Notification::make()
                        ->danger()
                        ->title('AI-пропозицію не застосовано')
                        ->body($exception->getMessage())
                        ->send();
                }
            });
    }

    public static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Відхилити')
            ->icon(Heroicon::OutlinedXMark)
            ->color('danger')
            ->visible(fn (AiSuggestion $record): bool => $record->canBeRejected() && Gate::allows('reject', $record))
            ->authorize(fn (AiSuggestion $record): bool => Gate::allows('reject', $record))
            ->requiresConfirmation()
            ->action(function (AiSuggestion $record): void {
                try {
                    app(ProductEnrichmentService::class)->rejectSuggestion($record);

                    Notification::make()
                        ->success()
                        ->title('AI-пропозицію відхилено')
                        ->send();
                } catch (Throwable $exception) {
                    Notification::make()
                        ->danger()
                        ->title('AI-пропозицію не відхилено')
                        ->body($exception->getMessage())
                        ->send();
                }
            });
    }

    public static function canCreate(): bool
    {
        return false;
    }

    private static function productLabel(AiSuggestion $record): string
    {
        if ($record->entity_type !== Product::class) {
            return class_basename($record->entity_type) . ' #' . $record->entity_id;
        }

        $product = $record->relationLoaded('product')
            ? $record->product
            : Product::query()->find($record->entity_id);

        return $product?->name ?? ('Товар #' . $record->entity_id);
    }

    private static function productUrl(AiSuggestion $record): ?string
    {
        if ($record->entity_type !== Product::class) {
            return null;
        }

        $product = $record->relationLoaded('product')
            ? $record->product
            : Product::query()->find($record->entity_id);

        return $product ? ProductResource::getUrl('edit', ['record' => $product]) : null;
    }

    private static function applyProductSearch(Builder $query, string $search): Builder
    {
        $like = '%'.$search.'%';

        return $query
            ->where('entity_type', Product::class)
            ->where(function (Builder $query) use ($like, $search): void {
                if (ctype_digit($search)) {
                    $query->orWhere('entity_id', (int) $search);
                }

                $query->orWhereHas('product', fn (Builder $query): Builder => $query
                    ->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like));
            });
    }

    private static function suggestionMetaLine(AiSuggestion $record): string
    {
        return '#'.$record->id.' · '.self::entityTypeLabel($record).' · '.optional($record->created_at)->format('d.m.Y H:i');
    }

    private static function entityMetaLine(AiSuggestion $record): string
    {
        if ($record->entity_type !== Product::class) {
            return 'ID '.$record->entity_id;
        }

        $product = $record->relationLoaded('product') ? $record->product : null;
        $sku = $product?->sku;

        return filled($sku) ? 'SKU '.$sku.' · ID '.$record->entity_id : 'ID '.$record->entity_id;
    }

    private static function workflowMetaLine(AiSuggestion $record): string
    {
        $status = AiSuggestion::STATUSES[$record->status] ?? $record->status;
        $reason = $record->applyUnavailableReason();

        return $reason ? $status.' · '.Str::limit($reason, 42) : $status;
    }

    private static function previewMetaLine(AiSuggestion $record): string
    {
        if (filled($record->suggested_payload)) {
            return 'Payload JSON';
        }

        if (filled($record->suggested_value)) {
            return 'Текстова пропозиція';
        }

        return 'Порожньо';
    }

    private static function entityTypeLabel(AiSuggestion $record): string
    {
        return $record->entity_type === Product::class
            ? 'Товар'
            : class_basename((string) $record->entity_type);
    }

    private static function preview(AiSuggestion $record): string
    {
        if (filled($record->suggested_value)) {
            return Str::limit((string) $record->suggested_value, 120);
        }

        return Str::limit(self::formatPayload($record->suggested_payload) ?? '-', 120);
    }

    /**
     * @param  array<mixed>|null  $payload
     */
    private static function formatPayload(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: null;
    }

    private static function statusColor(?string $state): string
    {
        return match ($state) {
            AiSuggestion::STATUS_APPLIED => 'success',
            AiSuggestion::STATUS_ACCEPTED => 'info',
            AiSuggestion::STATUS_REJECTED => 'danger',
            AiSuggestion::STATUS_PENDING => 'warning',
            default => 'gray',
        };
    }
}
