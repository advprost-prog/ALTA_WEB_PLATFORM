<?php

namespace App\Filament\Resources\SystemAddons;

use App\Filament\Resources\SystemAddons\Pages\ListSystemAddons;
use App\Filament\Resources\SystemAddons\Pages\ViewSystemAddon;
use App\Models\SystemAddon;
use App\Support\Addons\AddonManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use RuntimeException;

class SystemAddonResource extends Resource
{
    protected static ?string $model = SystemAddon::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static string|\UnitEnum|null $navigationGroup = 'Система';

    protected static ?int $navigationSort = 90;

    protected static ?string $modelLabel = 'модуль або розширення';

    protected static ?string $pluralModelLabel = 'Модулі та розширення';

    protected static ?string $recordTitleAttribute = 'name';

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Addon')
                    ->schema([
                        TextEntry::make('name')->label('Назва'),
                        TextEntry::make('code')->label('Code')->copyable(),
                        TextEntry::make('type')->label('Type')->badge(),
                        TextEntry::make('version')->label('Version'),
                        TextEntry::make('vendor')->label('Vendor')->placeholder('-'),
                        TextEntry::make('source')->label('Source')->badge(),
                        TextEntry::make('status')->label('Status')->badge(),
                        IconEntry::make('is_installed')->label('Installed')->boolean(),
                        IconEntry::make('is_enabled')->label('Enabled')->boolean(),
                        TextEntry::make('service_provider')->label('Service provider')->placeholder('-')->columnSpanFull(),
                        TextEntry::make('manifest_path')->label('Manifest')->placeholder('-')->columnSpanFull(),
                        TextEntry::make('last_error')->label('Last error')->placeholder('-')->columnSpanFull(),
                    ])
                    ->columns(3),
                Section::make('Manifest')
                    ->schema([
                        TextEntry::make('metadata.manifest')
                            ->label('manifest.json')
                            ->formatStateUsing(fn (mixed $state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '-')
                            ->columnSpanFull(),
                    ]),
                Section::make('Останні події')
                    ->schema([
                        TextEntry::make('events_preview')
                            ->label('Logs')
                            ->state(fn (SystemAddon $record): string => $record->events()
                                ->limit(20)
                                ->get()
                                ->map(fn ($event): string => '['.$event->created_at?->format('Y-m-d H:i:s').'] '.$event->level.' '.$event->event.': '.$event->message)
                                ->implode("\n") ?: '-')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Назва')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('version')
                    ->label('Version'),
                TextColumn::make('vendor')
                    ->label('Vendor')
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),
                IconColumn::make('is_enabled')
                    ->label('Enabled')
                    ->boolean(),
                TextColumn::make('source')
                    ->label('Source')
                    ->badge(),
                TextColumn::make('last_error')
                    ->label('Last error')
                    ->limit(48)
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Type')
                    ->options(SystemAddon::TYPES),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(SystemAddon::STATUSES),
                SelectFilter::make('source')
                    ->label('Source')
                    ->options(SystemAddon::SOURCES),
                TernaryFilter::make('is_enabled')
                    ->label('Enabled'),
            ])
            ->recordActions([
                ViewAction::make(),
                self::lifecycleAction('install', 'Install'),
                self::lifecycleAction('enable', 'Enable'),
                self::lifecycleAction('disable', 'Disable'),
                self::lifecycleAction('uninstall', 'Uninstall'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSystemAddons::route('/'),
            'view' => ViewSystemAddon::route('/{record}'),
        ];
    }

    private static function lifecycleAction(string $method, string $label): Action
    {
        return Action::make($method)
            ->label($label)
            ->requiresConfirmation()
            ->visible(fn (SystemAddon $record): bool => match ($method) {
                'install' => ! $record->is_installed && $record->status !== SystemAddon::STATUS_REMOVED,
                'enable' => ! $record->is_enabled && $record->status !== SystemAddon::STATUS_REMOVED,
                'disable' => $record->is_enabled,
                'uninstall' => $record->is_installed,
                default => false,
            })
            ->action(function (SystemAddon $record) use ($method, $label): void {
                try {
                    app(AddonManager::class)->{$method}($record->code);
                    Notification::make()
                        ->title($label.' complete')
                        ->success()
                        ->send();
                } catch (RuntimeException $exception) {
                    Notification::make()
                        ->title($label.' failed')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
