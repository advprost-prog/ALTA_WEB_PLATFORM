<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\Marketplace\MarketplaceManager;
use App\Support\Addons\Registry\ArtifactReviewActor;
use Illuminate\Console\Command;

class RejectAddonArtifact extends Command
{
    protected $signature = 'addons:reject-artifact {code} {--note=}';

    protected $description = 'Reject a quarantined addon artifact without deleting it.';

    public function handle(MarketplaceManager $manager): int
    {
        $code = (string) $this->argument('code');
        $note = trim((string) $this->option('note'));
        $result = $manager->rejectArtifact($code, $note, ArtifactReviewActor::cli());

        if (! $result->success) {
            $this->error("Artifact [{$code}] не відхилено.");
            foreach ($result->blockedReasons ?: $result->diagnostics as $reason) {
                $this->line('  - '.$reason);
            }

            return self::FAILURE;
        }

        $report = $result->report ?? [];
        $this->info("Code: {$code}");
        $this->line('Action: rejected');
        $this->line('Review status: '.$result->reviewStatus);
        $this->line('Actor: '.($report['reviewed_by_name'] ?? 'CLI'));
        $this->line('Timestamp: '.($report['reviewed_at'] ?? '—'));
        $this->line('Note: '.($report['review_note'] ?? '—'));
        $this->line('Approval stale: '.(($report['approval_is_stale'] ?? false) ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
