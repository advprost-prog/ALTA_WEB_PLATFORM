<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\Marketplace\MarketplaceManager;
use App\Support\Addons\Registry\ArtifactReviewActor;
use Illuminate\Console\Command;

class ApproveAddonArtifact extends Command
{
    protected $signature = 'addons:approve-artifact {code} {--note=}';

    protected $description = 'Approve a trusted quarantined addon artifact without installing it.';

    public function handle(MarketplaceManager $manager): int
    {
        $code = (string) $this->argument('code');
        $before = $manager->getArtifactReviewReport($code)['report'] ?? [];
        $this->line('Trust: '.($before['trust_status'] ?? 'unknown'));
        $this->line('Signature: '.($before['signature_status'] ?? 'unknown'));
        $this->line('Manifest: '.($before['manifest_status'] ?? 'unknown'));
        $this->line('Review: '.($before['review_status'] ?? 'unknown'));

        $result = $manager->approveArtifact($code, $this->note(), ArtifactReviewActor::cli());

        return $this->renderResult($result->success, $code, 'approved', $result->toArray());
    }

    private function note(): ?string
    {
        $note = trim((string) $this->option('note'));

        return $note === '' ? null : $note;
    }

    /** @param array<string, mixed> $result */
    private function renderResult(bool $success, string $code, string $action, array $result): int
    {
        if (! $success) {
            $this->error("Artifact [{$code}] не схвалено.");
            foreach ($result['blocked_reasons'] ?: $result['diagnostics'] as $reason) {
                $this->line('  - '.$reason);
            }

            return self::FAILURE;
        }

        $report = $result['report'] ?? [];
        $this->info("Code: {$code}");
        $this->line("Action: {$action}");
        $this->line('Review status: '.($result['review_status'] ?? 'unknown'));
        $this->line('Actor: '.($report['reviewed_by_name'] ?? 'CLI'));
        $this->line('Timestamp: '.($report['reviewed_at'] ?? '—'));
        $this->line('Note: '.($report['review_note'] ?? '—'));
        $this->line('Approval stale: '.(($report['approval_is_stale'] ?? false) ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
