<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\Marketplace\MarketplaceManager;
use App\Support\Addons\Registry\ArtifactReviewActor;
use Illuminate\Console\Command;

class RevokeAddonArtifact extends Command
{
    protected $signature = 'addons:revoke-artifact {code} {--note=}';

    protected $description = 'Revoke approval of a quarantined addon artifact.';

    public function handle(MarketplaceManager $manager): int
    {
        $code = (string) $this->argument('code');
        $note = trim((string) $this->option('note'));
        $result = $manager->revokeArtifactApproval($code, $note === '' ? null : $note, ArtifactReviewActor::cli());

        if (! $result->success) {
            $this->error("Схвалення artifact [{$code}] не відкликано.");
            foreach ($result->blockedReasons ?: $result->diagnostics as $reason) {
                $this->line('  - '.$reason);
            }

            return self::FAILURE;
        }

        $report = $result->report ?? [];
        $this->info("Code: {$code}");
        $this->line('Action: revoked');
        $this->line('Review status: '.$result->reviewStatus);
        $this->line('Actor: '.($report['approval_revoked_by_name'] ?? 'CLI'));
        $this->line('Timestamp: '.($report['approval_revoked_at'] ?? '—'));
        $this->line('Note: '.($report['approval_revoke_note'] ?? '—'));
        $this->line('Approval stale: '.(($report['approval_is_stale'] ?? false) ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
