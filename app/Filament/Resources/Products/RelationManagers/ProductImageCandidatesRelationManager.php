<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Models\Product;
use App\Models\ProductImageCandidate;
use App\Services\Images\ProductImageImportService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;

class ProductImageCandidatesRelationManager extends RelationManager
{
    protected static string $relationship = 'imageCandidates';

    protected static ?string $title = 'Кандидати фото';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Product && Gate::allows('view', $ownerRecord);
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $product = $this->getOwnerRecord();

        return [
            'importable' => Tab::make('Придатні')
                ->badge(fn (): int => ProductImageCandidate::query()->forProduct($product)->importable()->count())
                ->query(fn (Builder $query): Builder => $query->importable()),
            'review' => Tab::make('Потребують перевірки')
                ->badge(fn (): int => ProductImageCandidate::query()->forProduct($product)->review()->count())
                ->query(fn (Builder $query): Builder => $query->review()),
            'rejected' => Tab::make('Відхилені / діагностика')
                ->badge(fn (): int => ProductImageCandidate::query()->forProduct($product)->rejected()->count())
                ->query(fn (Builder $query): Builder => $query->rejected()),
            'all' => Tab::make('Усі')
                ->badge(fn (): int => ProductImageCandidate::query()->forProduct($product)->count()),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('source_url')
            ->defaultSort('created_at', 'desc')
            ->columns([
                Split::make([
                    ImageColumn::make('thumbnail_url')
                        ->label('Фото')
                        ->state(fn (ProductImageCandidate $record): string => $record->thumbnail_url ?: $record->image_url)
                        ->imageSize(88)
                        ->width('96px')
                        ->grow(false)
                        ->extraImgAttributes([
                            'style' => 'object-fit: contain; width: 88px; height: 88px; background: #f8fafc; border-radius: 6px;',
                        ]),
                    Stack::make([
                        TextColumn::make('source_domain')
                            ->label('Кандидат')
                            ->state(fn (ProductImageCandidate $record): string => $this->candidateSourceLabel($record))
                            ->description(fn (ProductImageCandidate $record): string => $this->candidateMetaLine($record))
                            ->searchable()
                            ->lineClamp(1),
                        TextColumn::make('candidate_check')
                            ->label('Перевірка')
                            ->state(fn (ProductImageCandidate $record): string => $this->candidateCheckLine($record))
                            ->color(fn (ProductImageCandidate $record): string => $this->candidateCheckColor($record))
                            ->lineClamp(2)
                            ->wrap(),
                    ])
                        ->space(1)
                        ->grow(),
                    Stack::make([
                        TextColumn::make('status')
                            ->label('Статус')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => ProductImageCandidate::STATUSES[$state] ?? (string) $state)
                            ->color(fn (ProductImageCandidate $record): string => $record->statusColor()),
                        TextColumn::make('quality_score')
                            ->label('Quality')
                            ->state(fn (ProductImageCandidate $record): string => $record->quality_score !== null ? $record->quality_score.'%' : '-')
                            ->badge()
                            ->color(fn (ProductImageCandidate $record): string => (int) ($record->quality_score ?? 0) >= 70 ? 'success' : 'warning'),
                    ])
                        ->alignment(Alignment::End)
                        ->space(1)
                        ->visibleFrom('md')
                        ->grow(false),
                ])
                    ->from('md')
                    ->extraAttributes(['class' => 'fi-gap-md']),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('preview')
                        ->label('Переглянути')
                        ->icon(Heroicon::OutlinedMagnifyingGlassPlus)
                        ->modalHeading(fn (ProductImageCandidate $record): string => 'Preview candidate #'.$record->id)
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Закрити')
                        ->modalContent(fn (ProductImageCandidate $record): HtmlString => $this->previewModalContent($record)),
                    Action::make('import')
                        ->label('Імпорт')
                        ->icon(Heroicon::OutlinedCloudArrowDown)
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn (ProductImageCandidate $record): bool => $record->isImportable() && Gate::allows('update', $this->getOwnerRecord()))
                        ->action(fn (ProductImageCandidate $record) => $this->importCandidate($record, false)),
                    Action::make('reject')
                        ->label('Відхилити')
                        ->icon(Heroicon::OutlinedXMark)
                        ->color('danger')
                        ->visible(fn (ProductImageCandidate $record): bool => $this->canRejectCandidate($record) && Gate::allows('update', $this->getOwnerRecord()))
                        ->action(function (ProductImageCandidate $record): void {
                            $this->rejectCandidate($record);

                            Notification::make()
                                ->success()
                                ->title('Кандидат відхилено')
                                ->send();

                            $this->resetTable();
                        }),
                    Action::make('restoreRejected')
                        ->label('Повернути в схвалені')
                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalDescription('Кандидат знову стане придатним до імпорту. Під час імпорту система повторно завантажить і перевірить фото.')
                        ->visible(fn (ProductImageCandidate $record): bool => $this->canManageRejectedCandidate($record) && Gate::allows('update', $this->getOwnerRecord()))
                        ->action(function (ProductImageCandidate $record): void {
                            $this->restoreCandidate($record);

                            Notification::make()
                                ->success()
                                ->title('Кандидат повернено в схвалені')
                                ->body('Його знову можна імпортувати в галерею.')
                                ->send();

                            $this->resetTable();
                        }),
                    Action::make('deleteRejected')
                        ->label('Видалити')
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription('Буде видалено тільки цей запис кандидата. Галерея товару та вже імпортовані фото не зміняться.')
                        ->visible(fn (ProductImageCandidate $record): bool => $this->canManageRejectedCandidate($record) && Gate::allows('update', $this->getOwnerRecord()))
                        ->action(function (ProductImageCandidate $record): void {
                            $record->delete();

                            Notification::make()
                                ->success()
                                ->title('Відхилений кандидат видалено')
                                ->send();

                            $this->resetTable();
                        }),
                    Action::make('openSource')
                        ->label('Джерело')
                        ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                        ->url(fn (ProductImageCandidate $record): string => $record->source_url, shouldOpenInNewTab: true)
                        ->visible(fn (ProductImageCandidate $record): bool => filled($record->source_url)),
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
                    BulkAction::make('importSelected')
                        ->label('Додати вибрані в галерею')
                        ->icon(Heroicon::OutlinedCloudArrowDown)
                        ->schema([
                            Checkbox::make('set_first_as_main')
                                ->label('Перше імпортоване встановити як головне')
                                ->default(false),
                        ])
                        ->visible(fn (): bool => Gate::allows('update', $this->getOwnerRecord()) && $this->activeTab !== 'rejected')
                        ->action(function (EloquentCollection $records, array $data): void {
                            $this->importCandidates($records, (bool) ($data['set_first_as_main'] ?? false));
                        }),
                    BulkAction::make('rejectSelected')
                        ->label('Відхилити вибрані')
                        ->icon(Heroicon::OutlinedXMark)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription('Вибрані кандидати будуть позначені як відхилені оператором. Імпортовані та вже відхилені записи буде пропущено.')
                        ->visible(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
                        ->action(function (EloquentCollection $records): void {
                            $rejectable = $records->filter(fn (ProductImageCandidate $record): bool => $this->canRejectCandidate($record));
                            $skipped = $records->count() - $rejectable->count();

                            if ($rejectable->isEmpty()) {
                                Notification::make()
                                    ->warning()
                                    ->title('Немає кандидатів для відхилення')
                                    ->body('Виберіть записи, які ще не імпортовані та не відхилені.')
                                    ->send();

                                return;
                            }

                            $rejected = $rejectable->count();
                            $rejectable->each(fn (ProductImageCandidate $record): ProductImageCandidate => $this->rejectCandidate($record));

                            Notification::make()
                                ->success()
                                ->title('Кандидатів відхилено')
                                ->body('Відхилено: '.$rejected.'. Пропущено: '.$skipped.'.')
                                ->send();

                            $this->resetTable();
                        }),
                    BulkAction::make('deleteRejectedSelected')
                        ->label('Видалити відхилені')
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription('Буде видалено тільки вибрані відхилені/debug кандидати. Імпортовані фото та галерея товару не зміняться.')
                        ->visible(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
                        ->action(function (EloquentCollection $records): void {
                            $rejected = $records->filter(fn (ProductImageCandidate $record): bool => $this->canManageRejectedCandidate($record));
                            $skipped = $records->count() - $rejected->count();

                            if ($rejected->isEmpty()) {
                                Notification::make()
                                    ->warning()
                                    ->title('Немає відхилених кандидатів для видалення')
                                    ->body('Виберіть записи зі статусом "Відхилено", "Помилка" або debug-кандидати без імпорту.')
                                    ->send();

                                return;
                            }

                            $deleted = $rejected->count();
                            $rejected->each->delete();

                            Notification::make()
                                ->success()
                                ->title('Відхилені кандидати видалено')
                                ->body('Видалено: '.$deleted.'. Пропущено: '.$skipped.'.')
                                ->send();

                            $this->resetTable();
                        }),
                ]),
            ]);
    }

    #[On('product-image-candidates-created')]
    public function refreshAfterProductImageCandidatesCreated(): void
    {
        $this->resetTable();
    }

    private function importCandidate(ProductImageCandidate $record, bool $setFirstAsMain): void
    {
        $this->importCandidates(new EloquentCollection([$record]), $setFirstAsMain);
    }

    private function importCandidates(EloquentCollection $records, bool $setFirstAsMain): void
    {
        if ($records->isEmpty()) {
            Notification::make()
                ->warning()
                ->title('Нічого не вибрано')
                ->body('Виберіть один або кілька фото-кандидатів для імпорту.')
                ->send();

            return;
        }

        Log::info('Product image candidates selected for import.', [
            'product_id' => $this->getOwnerRecord()->id,
            'selected_candidate_ids_count' => $records->count(),
            'selected_candidate_ids' => $records->pluck('id')->values()->all(),
            'importable_selected_count' => $records->filter(fn (ProductImageCandidate $record): bool => $record->isImportable())->count(),
        ]);

        $result = app(ProductImageImportService::class)
            ->importCandidates($this->getOwnerRecord(), $records, auth()->user(), $setFirstAsMain);

        $body = 'Імпортовано: '.$result['imported'].'. Пропущено: '.$result['skipped'].'. Помилки: '.$result['failed'].'.';
        $details = $this->resultDetails($result);

        if ($details !== '') {
            $body .= "\n".$details;
        }

        $notification = Notification::make()
            ->title($result['imported'] > 0 ? 'Імпорт фото завершено' : 'Жодне фото не імпортовано')
            ->body($body);

        if ($result['imported'] > 0) {
            $notification->success();
        } elseif ($result['failed'] > 0) {
            $notification->danger();
        } else {
            $notification->warning();
        }

        $notification->send();
        $this->dispatch('product-image-candidates-created');
        $this->dispatch('product-images-imported');
        $this->resetTable();
    }

    private function previewModalContent(ProductImageCandidate $record): HtmlString
    {
        $imageUrl = e($record->image_url ?: $record->thumbnail_url ?: '');
        $sourceDomain = e($record->source_domain ?: '-');
        $dimensions = e($record->width && $record->height ? $record->width.'x'.$record->height : '-');
        $mime = e($record->mime_type ?: '-');
        $score = e($record->quality_score !== null ? $record->quality_score.'%' : '-');
        $warnings = e(implode('; ', array_slice($record->warnings ?? [], 0, 3)) ?: '-');

        return new HtmlString(<<<HTML
            <div style="display: grid; gap: 12px;">
                <div style="display: flex; justify-content: center; background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px;">
                    <img src="{$imageUrl}" alt="Product image candidate preview" style="max-width: 100%; width: min(760px, 100%); max-height: 70vh; object-fit: contain;">
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 8px; font-size: 13px;">
                    <div><strong>Джерело:</strong> {$sourceDomain}</div>
                    <div><strong>Розмір:</strong> {$dimensions}</div>
                    <div><strong>MIME:</strong> {$mime}</div>
                    <div><strong>Quality:</strong> {$score}</div>
                </div>
                <div style="font-size: 13px;"><strong>Warnings:</strong> {$warnings}</div>
            </div>
        HTML);
    }

    private function canManageRejectedCandidate(ProductImageCandidate $record): bool
    {
        if ($record->status === ProductImageCandidate::STATUS_IMPORTED) {
            return false;
        }

        return $record->status === ProductImageCandidate::STATUS_REJECTED
            || $record->status === ProductImageCandidate::STATUS_FAILED
            || ! $record->can_import
            || filled($record->rejection_reason);
    }

    private function canRejectCandidate(ProductImageCandidate $record): bool
    {
        return ! in_array($record->status, [
            ProductImageCandidate::STATUS_IMPORTED,
            ProductImageCandidate::STATUS_REJECTED,
        ], true);
    }

    private function rejectCandidate(ProductImageCandidate $record): ProductImageCandidate
    {
        $record->forceFill([
            'status' => ProductImageCandidate::STATUS_REJECTED,
            'can_import' => false,
            'rejection_reason' => $record->rejection_reason ?: 'Відхилено оператором.',
            'metadata' => array_merge((array) ($record->metadata ?? []), [
                'operator_reject' => [
                    'rejected_at' => now()->toIso8601String(),
                    'rejected_by' => auth()->id(),
                    'previous_status' => $record->status,
                ],
            ]),
        ])->save();

        return $record;
    }

    private function restoreCandidate(ProductImageCandidate $record): void
    {
        $record->forceFill([
            'status' => ProductImageCandidate::STATUS_APPROVED,
            'can_import' => true,
            'quality_score' => max(50, (int) ($record->quality_score ?? 0)),
            'rejection_reason' => null,
            'metadata' => array_merge((array) ($record->metadata ?? []), [
                'operator_restore' => [
                    'restored_at' => now()->toIso8601String(),
                    'restored_by' => auth()->id(),
                    'previous_status' => $record->status,
                    'previous_rejection_reason' => $record->rejection_reason,
                ],
            ]),
        ])->save();
    }

    /**
     * @param  array{results: array<int, array<string, mixed>>}  $result
     */
    private function resultDetails(array $result): string
    {
        return collect($result['results'] ?? [])
            ->reject(fn (array $item): bool => ($item['status'] ?? null) === 'imported')
            ->take(3)
            ->map(fn (array $item): string => '#'.($item['candidate_id'] ?? '-').' '.($item['reason'] ?? 'unknown').': '.($item['message'] ?? '-'))
            ->implode("\n");
    }

    private function candidateMetaLine(ProductImageCandidate $record): string
    {
        $parts = [
            $record->statusLabel(),
        ];

        if ($record->width && $record->height) {
            $parts[] = $record->width.'x'.$record->height;
        }

        if (filled($record->mime_type)) {
            $parts[] = $record->mime_type;
        }

        if ($record->quality_score !== null) {
            $parts[] = 'Q '.$record->quality_score.'%';
        }

        $parts[] = $record->can_import ? 'можна імпортувати' : 'не імпортується';

        return implode(' · ', $parts);
    }

    private function candidateSourceLabel(ProductImageCandidate $record): string
    {
        if (filled($record->source_domain)) {
            return (string) $record->source_domain;
        }

        $host = parse_url((string) $record->source_url, PHP_URL_HOST);

        return filled($host) ? (string) $host : '-';
    }

    private function candidateCheckLine(ProductImageCandidate $record): string
    {
        if (filled($record->rejection_reason)) {
            return Str::limit((string) $record->rejection_reason, 180);
        }

        $warnings = implode('; ', array_slice($record->warnings ?? [], 0, 2));

        if (filled($warnings)) {
            return Str::limit($warnings, 180);
        }

        if (filled($record->query)) {
            return Str::limit('Query: '.$record->query, 180);
        }

        return 'Без попереджень';
    }

    private function candidateCheckColor(ProductImageCandidate $record): string
    {
        if (filled($record->rejection_reason) || ! $record->can_import) {
            return 'danger';
        }

        if (filled($record->warnings)) {
            return 'warning';
        }

        return 'gray';
    }
}
