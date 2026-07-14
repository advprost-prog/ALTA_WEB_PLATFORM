<?php

namespace App\Filament\Pages;

use App\Policies\AddonArtifactReviewPolicy;
use App\Support\Addons\Marketplace\CompatibilityStatus;
use App\Support\Addons\Marketplace\MarketplaceItem;
use App\Support\Addons\Marketplace\MarketplaceManager;
use App\Support\Addons\Marketplace\MarketplaceStatus;
use App\Support\Addons\Marketplace\UpdateStatus;
use App\Support\Addons\Registry\AddonLivePathResolver;
use App\Support\Addons\Registry\ArtifactPromotionStatus;
use App\Support\Addons\Registry\ArtifactReviewActor;
use App\Support\Addons\Registry\ArtifactReviewResult;
use App\Support\Addons\Registry\ArtifactReviewStatus;
use App\Support\Addons\Registry\RegistryCatalog;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use UnitEnum;

class Marketplace extends Page
{
    protected static ?string $title = 'Marketplace модулів';

    protected static ?string $navigationLabel = 'Marketplace';

    protected static string|UnitEnum|null $navigationGroup = 'Система';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?int $navigationSort = 91;

    protected string $view = 'filament.pages.marketplace';

    public ?string $filterType = null;

    public ?string $filterStatus = null;

    public ?string $filterCategory = null;

    public ?string $filterVendor = null;

    public ?string $filterFeatured = null;

    public ?string $expandedCode = null;

    // Review workflow modal state (Phase 3.3).
    public ?string $reviewingArtifactCode = null;

    public ?string $reviewAction = null;

    public ?string $reviewNote = null;

    public bool $reviewModalOpen = false;

    public ?string $stagingArtifactCode = null;

    public ?string $stagingAction = null;

    public ?string $stagingNote = null;

    public bool $stagingModalOpen = false;

    public ?string $promotionArtifactCode = null;

    public ?string $promotionAction = null;

    public ?string $promotionNote = null;

    public ?string $promotionTransactionId = null;

    public bool $promotionModalOpen = false;

    /**
     * @var array<string, mixed>
     */
    public array $promotionModalData = [];

    public function mount(): void
    {
        //
    }

    public function canReviewArtifacts(): bool
    {
        return AddonArtifactReviewPolicy::canReviewAddonArtifacts(auth()->user());
    }

    public function canManageStaging(): bool
    {
        return Gate::allows('stage-addon-artifacts');
    }

    public function canPromoteArtifacts(): bool
    {
        return Gate::allows('promote-addon-artifacts');
    }

    public function canRollbackArtifacts(): bool
    {
        return Gate::allows('rollback-addon-artifacts');
    }

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        $resolved = app(MarketplaceManager::class)->resolve();
        $rows = $this->applyFilters($resolved['rows']);

        return [
            'rows' => $rows,
            'diagnostics' => $resolved['diagnostics'],
            'warnings' => $resolved['warnings'],
            'statusLabels' => MarketplaceStatus::LABELS,
            'updateStatusLabels' => UpdateStatus::LABELS,
            'compatibilityLabels' => CompatibilityStatus::LABELS,
            'typeOptions' => $this->uniqueOptions($resolved['rows'], fn (MarketplaceItem $i): string => $i->type),
            'statusOptions' => MarketplaceStatus::LABELS,
            'categoryOptions' => $this->uniqueOptions($resolved['rows'], fn (MarketplaceItem $i): string => $i->category),
            'vendorOptions' => $this->uniqueOptions($resolved['rows'], fn (MarketplaceItem $i): string => $i->vendor),
            'featuredOptions' => ['1' => 'Так', '0' => 'Ні'],
            'summary' => $this->buildSummary($rows),
            'statusColors' => $this->getStatusColors(),
            'updateStatusColors' => UpdateStatus::COLORS,
            'compatibilityColors' => CompatibilityStatus::COLORS,
            'inspectionLabels' => $this->getInspectionLabels(),
            'inspectionColors' => $this->getInspectionColors(),
            'reviewLabels' => ArtifactReviewStatus::LABELS,
            'reviewColors' => ArtifactReviewStatus::COLORS,
            'canReview' => $this->canReviewArtifacts(),
            'canManageStaging' => $this->canManageStaging(),
            'canManagePromotion' => $this->canPromoteArtifacts() || $this->canRollbackArtifacts(),
            'promotionLabels' => ArtifactPromotionStatus::LABELS,
            'registryState' => $resolved['registry_state'],
            'registryMeta' => $resolved['registry_meta'],
            'registryHeader' => $resolved['registry_header'],
        ];
    }

    public function openPromoteArtifactModal(string $code): void
    {
        $this->guardCode($code);

        if (! Gate::allows('promote-addon-artifacts')) {
            Notification::make()
                ->title('Дія заборонена')
                ->body('Немає прав для перенесення artifact у live directory.')
                ->warning()
                ->send();

            return;
        }

        $row = $this->resolveRowByCode($code);
        $this->promotionArtifactCode = $code;
        $this->promotionAction = 'promote';
        $this->promotionNote = null;
        $this->promotionTransactionId = (string) ($row['promotion_transaction_id'] ?? '');
        $this->promotionModalData = $this->buildPromotionModalData($row);
        $this->promotionModalOpen = true;
    }

    public function openRollbackArtifactModal(string $code): void
    {
        $this->guardCode($code);

        if (! Gate::allows('rollback-addon-artifacts')) {
            Notification::make()
                ->title('Дія заборонена')
                ->body('Немає прав для відкату перенесення artifact.')
                ->warning()
                ->send();

            return;
        }

        $row = $this->resolveRowByCode($code);
        $this->promotionArtifactCode = $code;
        $this->promotionAction = 'rollback';
        $this->promotionNote = null;
        $this->promotionTransactionId = (string) ($row['promotion_transaction_id'] ?? '');
        $this->promotionModalData = $this->buildPromotionModalData($row);
        $this->promotionModalOpen = true;
    }

    public function closePromotionModal(): void
    {
        $this->promotionArtifactCode = null;
        $this->promotionAction = null;
        $this->promotionNote = null;
        $this->promotionTransactionId = null;
        $this->promotionModalData = [];
        $this->promotionModalOpen = false;
    }

    public function promoteArtifact(): void
    {
        $code = trim((string) $this->promotionArtifactCode);

        if ($code === '') {
            Notification::make()
                ->title('Перенесення не виконано')
                ->body('Оберіть artifact для перенесення у live directory.')
                ->warning()
                ->send();

            return;
        }

        $this->guardCode($code);

        if (! Gate::allows('promote-addon-artifacts')) {
            Notification::make()
                ->title('Дія заборонена')
                ->body('Promotion metadata не змінено.')
                ->danger()
                ->send();

            return;
        }

        $manager = app(MarketplaceManager::class);
        $actor = ArtifactReviewActor::fromUser(auth()->user());
        try {
            $result = $manager->promoteArtifact($code, $actor);
        } catch (RuntimeException $exception) {
            Notification::make()
                ->title('Перенесення не виконано')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        if (! $result->success) {
            Notification::make()
                ->title('Перенесення не виконано')
                ->body($this->formatDiagnostics($result->diagnostics, $result->blockedReasons))
                ->danger()
                ->send();

            return;
        }

        if ($result->idempotent) {
            Notification::make()
                ->title('Artifact уже перенесено')
                ->body('Файлова система не змінювалась.')
                ->warning()
                ->send();

            $this->closePromotionModal();

            return;
        }

        Notification::make()
            ->title('Файли перенесено')
            ->body('Artifact перенесено у live directory. Addon ще не зареєстрований і не активований.')
            ->success()
            ->send();

        $this->closePromotionModal();
    }

    public function rollbackArtifact(): void
    {
        $code = trim((string) $this->promotionArtifactCode);

        if ($code === '') {
            Notification::make()
                ->title('Відкат не виконано')
                ->body('Оберіть artifact для відкату перенесення.')
                ->warning()
                ->send();

            return;
        }

        $this->guardCode($code);

        if (! Gate::allows('rollback-addon-artifacts')) {
            Notification::make()
                ->title('Дія заборонена')
                ->body('Rollback metadata не змінено.')
                ->danger()
                ->send();

            return;
        }

        $this->validate([
            'promotionNote' => ['nullable', 'string', 'max:2000'],
        ], [
            'promotionNote.max' => 'Примітка до відкату не може перевищувати 2000 символів.',
        ]);

        $manager = app(MarketplaceManager::class);
        $actor = ArtifactReviewActor::fromUser(auth()->user());
        try {
            $result = $manager->rollbackArtifact(
                $code,
                $this->promotionTransactionId,
                trim((string) $this->promotionNote) ?: null,
                $actor,
            );
        } catch (RuntimeException $exception) {
            Notification::make()
                ->title('Відкат не виконано')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        if (! $result->success) {
            Notification::make()
                ->title('Відкат не виконано')
                ->body($this->formatDiagnostics($result->diagnostics, $result->blockedReasons))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Відкат виконано')
            ->body('Live directory оновлено без discover/install/enable.')
            ->success()
            ->send();

        $this->closePromotionModal();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{label: string, value: int, description: string}>
     */
    private function buildSummary(array $rows): array
    {
        $enabled = 0;
        $installed = 0;
        $needsAttention = 0;

        foreach ($rows as $row) {
            $status = $row['status'];

            if ($status === 'enabled') {
                $enabled++;
            }

            if (in_array($status, ['installed', 'enabled', 'disabled'], true)) {
                $installed++;
            }

            $canEnable = in_array('enable', $row['actions'], true);
            $dependencyBlocked = $canEnable && $row['dependency_issues'] !== [];

            if (in_array($status, ['missing_files', 'invalid', 'failed'], true) || $row['warnings'] !== [] || $dependencyBlocked) {
                $needsAttention++;
            }
        }

        return [
            ['label' => 'Всього позицій', 'value' => count($rows), 'description' => 'У каталозі Marketplace'],
            ['label' => 'Увімкнено', 'value' => $enabled, 'description' => 'Активні модулі та розширення'],
            ['label' => 'Встановлено', 'value' => $installed, 'description' => 'Встановлені локально'],
            ['label' => 'Потребують уваги', 'value' => $needsAttention, 'description' => 'Помилки, попередження, залежності'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function applyFilters(array $rows): array
    {
        return array_values(array_filter($rows, function (array $row): bool {
            $item = $row['item'];

            if ($this->filterType !== null && $this->filterType !== '' && $item->type !== $this->filterType) {
                return false;
            }

            if ($this->filterStatus !== null && $this->filterStatus !== '' && $row['status'] !== $this->filterStatus) {
                return false;
            }

            if ($this->filterCategory !== null && $this->filterCategory !== '' && $item->category !== $this->filterCategory) {
                return false;
            }

            if ($this->filterVendor !== null && $this->filterVendor !== '' && $item->vendor !== $this->filterVendor) {
                return false;
            }

            if ($this->filterFeatured !== null && $this->filterFeatured !== '') {
                $isFeatured = $item->isFeatured ? '1' : '0';

                if ($isFeatured !== $this->filterFeatured) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  callable(MarketplaceItem): string  $picker
     * @return array<string, string>
     */
    private function uniqueOptions(array $rows, callable $picker): array
    {
        $options = [];

        foreach ($rows as $row) {
            $value = $picker($row['item']);

            if ($value !== '') {
                $options[$value] = $value;
            }
        }

        return $options;
    }

    public function resetFilters(): void
    {
        $this->filterType = null;
        $this->filterStatus = null;
        $this->filterCategory = null;
        $this->filterVendor = null;
        $this->filterFeatured = null;
    }

    public function toggleDetails(string $code): void
    {
        $this->expandedCode = $this->expandedCode === $code ? null : $code;
    }

    public function rescan(): void
    {
        try {
            $result = app(MarketplaceManager::class)->discover();
            Notification::make()
                ->title('Discover завершено')
                ->body("Виявлено: {$result['discovered']}; некоректні: {$result['invalid']}; дублікати: {$result['duplicates']}")
                ->success()
                ->send();
        } catch (RuntimeException $exception) {
            Notification::make()
                ->title('Discover не вдалося')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    public function installAddon(string $code): void
    {
        $this->runLifecycle('install', $code, 'Встановлення', 'Встановлено');
    }

    public function enableAddon(string $code): void
    {
        $this->runLifecycle('enable', $code, 'Увімкнення', 'Увімкнено');
    }

    public function disableAddon(string $code): void
    {
        $this->runLifecycle('disable', $code, 'Вимкнення', 'Вимкнено');
    }

    public function uninstallAddon(string $code): void
    {
        $this->runLifecycle('uninstall', $code, 'Видалення', 'Видалено');
    }

    public function updateAddon(string $code): void
    {
        $this->runLifecycle('update', $code, 'Оновлення', 'Оновлено');
    }

    public function installDependencies(string $code): void
    {
        $this->guardCode($code);

        try {
            $installed = app(MarketplaceManager::class)->installDependencies($code);
            Notification::make()
                ->title('Залежності встановлено')
                ->body('Встановлено: '.implode(', ', $installed))
                ->success()
                ->send();
        } catch (RuntimeException $exception) {
            Notification::make()
                ->title('Встановлення залежностей не вдалося')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    public function refreshRegistry(): void
    {
        try {
            $result = app(RegistryCatalog::class)->refresh();

            Notification::make()
                ->title(($result['state'] ?? null) === 'fresh' ? 'Registry оновлено' : 'Registry недоступний')
                ->body(($result['meta']['last_http_status'] ?? null) === 304 ? 'Каталог не змінився.' : (implode(' ', $result['diagnostics']) ?: 'Каталог registry перевірено.'))
                ->color(($result['state'] ?? null) === 'fresh' ? 'success' : 'warning')
                ->send();
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Registry не вдалося оновити')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    public function downloadArtifact(string $code): void
    {
        $this->guardCode($code);

        try {
            $result = app(MarketplaceManager::class)->downloadArtifact($code);

            if ($result->success) {
                Notification::make()
                    ->title('Artifact завантажено')
                    ->body("Файл [{$code}] поміщено у quarantine. Встановлення недоступне.")
                    ->success()
                    ->send();

                return;
            }

            Notification::make()
                ->title('Artifact не завантажено')
                ->body(implode(' ', $result->diagnostics) ?: 'Невідома помилка завантаження.')
                ->danger()
                ->send();
        } catch (RuntimeException $exception) {
            Notification::make()
                ->title('Artifact не завантажено')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    public function inspectArtifact(string $code): void
    {
        $this->guardCode($code);

        try {
            $result = app(MarketplaceManager::class)->inspectArtifact($code);

            if (! $result['success']) {
                Notification::make()
                    ->title('Перевірку artifact не виконано')
                    ->body(implode(' ', $result['diagnostics']) ?: 'Artifact ще не завантажено.')
                    ->warning()
                    ->send();

                return;
            }

            $report = $result['report'];
            $trust = $report['trust_status'] ?? 'untrusted';

            if ($trust === 'trusted') {
                Notification::make()
                    ->title('Artifact translations перевірено')
                    ->body("Підпис: {$report['signature_label']}; Manifest: {$report['manifest_label']}; Trust: {$report['trust_label']}.")
                    ->success()
                    ->send();

                return;
            }

            if ($trust === 'rejected') {
                Notification::make()
                    ->title('Artifact відхилено')
                    ->body(implode(' ', $result['diagnostics']) ?: 'Артефакт не пройшов перевірку цілісності.')
                    ->danger()
                    ->send();

                return;
            }

            Notification::make()
                ->title('Artifact перевірено')
                ->body("Підпис: {$report['signature_label']}; Manifest: {$report['manifest_label']}; Trust: {$report['trust_label']}.")
                ->warning()
                ->send();
        } catch (RuntimeException $exception) {
            Notification::make()
                ->title('Перевірку artifact не виконано')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    public function openApproveArtifactModal(string $code): void
    {
        $this->openReviewModal($code, 'approve');
    }

    public function openRejectArtifactModal(string $code): void
    {
        $this->openReviewModal($code, 'reject');
    }

    public function openRevokeArtifactModal(string $code): void
    {
        $this->openReviewModal($code, 'revoke');
    }

    private function openReviewModal(string $code): void
    {
        $this->guardCode($code);

        if (! $this->canReviewArtifacts()) {
            Notification::make()
                ->title('Дія заборонена')
                ->body('Тільки адміністратори можуть виконувати review artifact.')
                ->danger()
                ->send();

            return;
        }

        $this->reviewingArtifactCode = $code;
        $this->reviewAction = func_get_arg(1);
        $this->reviewNote = null;
        $this->reviewModalOpen = true;
    }

    public function closeReviewModal(): void
    {
        $this->reviewModalOpen = false;
        $this->reviewingArtifactCode = null;
        $this->reviewAction = null;
        $this->reviewNote = null;
    }

    public function openStageArtifactModal(string $code): void
    {
        $this->openStagingModal($code, 'stage');
    }

    public function openUnstageArtifactModal(string $code): void
    {
        $this->openStagingModal($code, 'unstage');
    }

    private function openStagingModal(string $code, string $action): void
    {
        $this->guardCode($code);
        if (! $this->canManageStaging()) {
            Notification::make()->title('Дія заборонена')->body('Тільки адміністратори можуть керувати staging artifact.')->danger()->send();

            return;
        }
        $this->stagingArtifactCode = $code;
        $this->stagingAction = $action;
        $this->stagingNote = null;
        $this->stagingModalOpen = true;
    }

    public function closeStagingModal(): void
    {
        $this->stagingArtifactCode = null;
        $this->stagingAction = null;
        $this->stagingNote = null;
        $this->stagingModalOpen = false;
    }

    public function stageArtifact(): void
    {
        $this->submitStaging('stage');
    }

    public function unstageArtifact(): void
    {
        $this->submitStaging('unstage');
    }

    private function submitStaging(string $action): void
    {
        $code = (string) $this->stagingArtifactCode;
        $this->guardCode($code);
        if (! $this->canManageStaging()) {
            Notification::make()->title('Дія заборонена')->body('Staging metadata не змінено.')->danger()->send();

            return;
        }
        $actor = ArtifactReviewActor::fromUser(auth()->user());
        $manager = app(MarketplaceManager::class);
        $result = $action === 'stage'
            ? $manager->stageArtifact($code, $actor)
            : $manager->unstageArtifact($code, trim((string) $this->stagingNote) ?: null, $actor);
        if (! $result->success) {
            Notification::make()->title('Staging operation не виконано')->body(implode(' ', $result->blockedReasons ?: $result->diagnostics) ?: $result->message)->danger()->send();

            return;
        }
        Notification::make()->title($action === 'stage' ? 'Artifact підготовлено у staging' : 'Staging-копію видалено')->body($result->message)->success()->send();
        $this->closeStagingModal();
    }

    public function approveArtifact(): void
    {
        $this->submitReview('approve', fn (MarketplaceManager $manager, string $code, ?string $note, $actor) => $manager->approveArtifact($code, $note, $actor));
    }

    public function rejectArtifact(): void
    {
        $this->submitReview('reject', fn (MarketplaceManager $manager, string $code, ?string $note, $actor) => $manager->rejectArtifact($code, (string) $note, $actor));
    }

    public function revokeArtifactApproval(): void
    {
        $this->submitReview('revoke', fn (MarketplaceManager $manager, string $code, ?string $note, $actor) => $manager->revokeArtifactApproval($code, $note, $actor));
    }

    /**
     * @param  callable(MarketplaceManager, string, ?string, mixed): ArtifactReviewResult  $operation
     */
    private function submitReview(string $action, callable $operation): void
    {
        $code = (string) $this->reviewingArtifactCode;
        $this->guardCode($code);

        if (! $this->canReviewArtifacts()) {
            Notification::make()
                ->title('Дія заборонена')
                ->body('Тільки адміністратори можуть виконувати review artifact.')
                ->danger()
                ->send();

            $this->closeReviewModal();

            return;
        }

        $note = trim((string) $this->reviewNote);

        $this->validate([
            'reviewNote' => $action === 'reject' && (bool) config('addons-registry.review.require_note_on_reject', true)
                ? ['required', 'string', 'max:2000']
                : ['nullable', 'string', 'max:2000'],
        ], [
            'reviewNote.required' => 'Вкажіть причину відхилення artifact.',
            'reviewNote.max' => 'Review note не може перевищувати 2000 символів.',
        ]);

        try {
            $result = $operation(app(MarketplaceManager::class), $code, $note, ArtifactReviewActor::fromUser(auth()->user()));
        } catch (RuntimeException $exception) {
            Notification::make()
                ->title('Review не виконано')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        if (! $result->success) {
            Notification::make()
                ->title('Review не виконано')
                ->body(implode(' ', $result->diagnostics) ?: 'Операцію заблоковано.')
                ->danger()
                ->send();

            return;
        }

        $labels = [
            'approve' => 'Artifact схвалено',
            'reject' => 'Artifact відхилено',
            'revoke' => 'Схвалення відкликано',
        ];

        Notification::make()
            ->title($labels[$action] ?? 'Review виконано')
            ->body('['.$code.'] review status: '.$result->label().'.')
            ->success()
            ->send();

        $this->closeReviewModal();
    }

    private function guardCode(string $code): void
    {
        if ($code === '' || ! preg_match('/^[a-z0-9._-]+$/i', $code)) {
            throw new RuntimeException("Некоректний код модуля: [{$code}].");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveRowByCode(string $code): array
    {
        $resolved = app(MarketplaceManager::class)->resolve();
        $row = collect($resolved['rows'])->first(static fn (array $candidate): bool => $candidate['item']->code === $code);

        if (! is_array($row)) {
            throw new RuntimeException("Addon [{$code}] не знайдено у Marketplace.");
        }

        return $row;
    }

    /**
     * @param  array<int, mixed>  $diagnostics
     * @param  array<int, string>  $fallback
     */
    private function formatDiagnostics(array $diagnostics, array $fallback): string
    {
        if ($diagnostics === [] && $fallback === []) {
            return 'Операцію заблоковано.';
        }

        $lines = [];

        foreach (($diagnostics !== [] ? $diagnostics : $fallback) as $diagnostic) {
            if (is_array($diagnostic)) {
                $message = (string) ($diagnostic['message'] ?? '');
                $code = (string) ($diagnostic['code'] ?? 'diagnostic');
                $lines[] = trim($code.': '.$message);

                continue;
            }

            $lines[] = (string) $diagnostic;
        }

        return implode(' ', array_filter($lines, static fn (string $line): bool => trim($line) !== ''));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function buildPromotionModalData(array $row): array
    {
        $item = $row['item'] ?? null;
        $diagnostics = is_array($row['promotion_diagnostics'] ?? null) ? $row['promotion_diagnostics'] : [];
        $resolvedLivePath = null;

        if ($item instanceof MarketplaceItem) {
            try {
                $resolved = app(AddonLivePathResolver::class)->resolve([
                    'type' => $item->type,
                    'code' => $item->code,
                    'vendor' => $item->vendor,
                ]);
                $resolvedLivePath = (string) ($resolved['live_path'] ?? '');
            } catch (\Throwable) {
                $resolvedLivePath = null;
            }
        }

        $hasPromotionSnapshot = in_array((string) ($row['promotion_status'] ?? ''), ['promoted', 'stale', 'rollback_available'], true);

        $hasFingerprintMismatch = false;
        foreach ($diagnostics as $diagnostic) {
            if (is_array($diagnostic) && (($diagnostic['code'] ?? null) === 'artifact_promotion_live_fingerprint_mismatch')) {
                $hasFingerprintMismatch = true;
                break;
            }
        }

        return [
            'code' => $item instanceof MarketplaceItem ? $item->code : ($row['code'] ?? null),
            'version' => $row['promoted_version'] ?? ($item instanceof MarketplaceItem ? $item->version : null),
            'type' => $item instanceof MarketplaceItem ? $item->type : null,
            'destination' => $row['promotion_live_path'] ?? $resolvedLivePath,
            'trust_status' => $row['trust_status'] ?? null,
            'review_status' => $row['review_status'] ?? null,
            'staging_status' => $row['staging_status'] ?? null,
            'staging_label' => $row['staging_label'] ?? null,
            'transaction_id' => $row['promotion_transaction_id'] ?? null,
            'live_path' => $row['promotion_live_path'] ?? $resolvedLivePath,
            'backup_path' => $row['promotion_backup_path'] ?? null,
            'promoted_by_name' => $row['promoted_by_name'] ?? null,
            'promoted_at' => $row['promoted_at'] ?? null,
            'live_inventory_matches' => (bool) ($row['live_inventory_matches'] ?? false),
            'has_live_mismatch' => $hasPromotionSnapshot && (
                (bool) ($row['promotion_is_stale'] ?? false)
                || ! (bool) ($row['live_inventory_matches'] ?? true)
                || $hasFingerprintMismatch
            ),
            'blocked_reasons' => is_array($row['promotion_blocked_reasons'] ?? null) ? $row['promotion_blocked_reasons'] : [],
        ];
    }

    private function runLifecycle(string $method, string $code, string $label, string $done): void
    {
        $this->guardCode($code);

        try {
            app(MarketplaceManager::class)->{$method}($code);
            Notification::make()
                ->title("{$label} завершено")
                ->body("Модуль/розширення [{$code}] — {$done}.")
                ->success()
                ->send();
        } catch (RuntimeException $exception) {
            Notification::make()
                ->title("{$label} не вдалося")
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * @return array<string, string>
     */
    public function getStatusColors(): array
    {
        return [
            'enabled' => 'success',
            'installed' => 'info',
            'disabled' => 'warning',
            'discovered' => 'gray',
            'available' => 'gray',
            'missing_files' => 'danger',
            'invalid' => 'danger',
            'failed' => 'danger',
            'removed' => 'gray',
        ];
    }

    public function getStatusColor(string $status): string
    {
        return $this->getStatusColors()[$status] ?? 'gray';
    }

    /**
     * @return array<string, string>
     */
    public function getInspectionLabels(): array
    {
        return [
            'not_required' => 'Не вимагається',
            'missing' => 'Підпис відсутній',
            'unknown_key' => 'Невідомий ключ',
            'unsupported_type' => 'Непідтримуваний тип',
            'invalid' => 'Підпис недійсний',
            'valid' => 'Підпис дійсний',
            'error' => 'Помилка перевірки',
            'not_inspected' => 'Не перевірено',
            'manifest_missing' => 'Manifest відсутній',
            'manifest_invalid' => 'Manifest некоректний',
            'identity_mismatch' => 'Code/version не збігаються',
            'untrusted' => 'Недовірений',
            'partially_trusted' => 'Частково довірений',
            'trusted' => 'Довірений',
            'rejected' => 'Відхилений',
            'pending' => 'Очікує review',
            'approved' => 'Схвалено',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getInspectionColors(): array
    {
        return [
            'not_required' => 'gray',
            'missing' => 'warning',
            'unknown_key' => 'warning',
            'unsupported_type' => 'warning',
            'invalid' => 'danger',
            'valid' => 'success',
            'error' => 'danger',
            'not_inspected' => 'gray',
            'manifest_missing' => 'danger',
            'manifest_invalid' => 'danger',
            'identity_mismatch' => 'danger',
            'untrusted' => 'warning',
            'partially_trusted' => 'warning',
            'trusted' => 'success',
            'rejected' => 'danger',
            'pending' => 'gray',
            'approved' => 'success',
        ];
    }

    public function inspectionLabel(?string $status, array $labels): string
    {
        if ($status === null || $status === '') {
            return '—';
        }

        return $labels[$status] ?? $status;
    }

    public function inspectionColor(?string $status, array $colors): string
    {
        if ($status === null || $status === '') {
            return 'gray';
        }

        return $colors[$status] ?? 'gray';
    }
}
