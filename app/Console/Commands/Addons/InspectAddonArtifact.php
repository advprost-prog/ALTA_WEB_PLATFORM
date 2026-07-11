<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\Marketplace\MarketplaceManager;
use App\Support\Addons\Registry\ArtifactStagingManager;
use Illuminate\Console\Command;

class InspectAddonArtifact extends Command
{
    protected $signature = 'addons:inspect-artifact {code : Addon code to inspect in quarantine}';

    protected $description = 'Verify signature, manifest, and trust of a quarantined addon artifact without installing it.';

    public function handle(MarketplaceManager $manager): int
    {
        $code = (string) $this->argument('code');

        $result = $manager->inspectArtifact($code);

        if (! $result['success']) {
            $this->error("Artifact для [{$code}] не перевірено.");

            foreach ($result['diagnostics'] as $diagnostic) {
                $this->line('  - '.$diagnostic);
            }

            return self::FAILURE;
        }

        $report = $result['report'];

        $this->info("Artifact для [{$code}] перевірено.");
        $this->line('  path:            '.$report['path']);
        $this->line('  checksum_valid:  '.($report['checksum_valid'] ? 'yes' : 'no'));
        $this->line('  sha256:          '.$report['sha256']);
        $this->line('  signature:       '.$report['signature_status'].' ('.$report['signature_label'].')');
        $this->line('  signature_key:   '.($report['signature_key_id'] ?? '—'));
        $this->line('  manifest:        '.$report['manifest_status'].' ('.$report['manifest_label'].')');
        $this->line('  trust:           '.$report['trust_status'].' ('.$report['trust_label'].')');
        $this->line('  review:          '.($report['review_status'] ?? '—'));

        $review = $manager->getArtifactReviewReport($code)['report'] ?? [];
        $this->line('  reviewed_by:     '.($review['reviewed_by_name'] ?? '—'));
        $this->line('  reviewed_at:     '.($review['reviewed_at'] ?? '—'));
        $this->line('  review_note:     '.($review['review_note'] ?? '—'));
        $this->line('  approval_stale:  '.(($review['approval_is_stale'] ?? false) ? 'yes' : 'no'));
        $this->line('  review_history:  '.count($review['review_history'] ?? []));
        $staging = app(ArtifactStagingManager::class)->getStagingReport($code)['review'] ?? [];
        $this->line('  staging_status:  '.($staging['staging_status'] ?? 'not_staged'));
        $this->line('  staging_path:    '.($staging['staging_path'] ?? '—'));
        $this->line('  staging_stale:   '.(($staging['staging_is_stale'] ?? false) ? 'yes' : 'no'));
        $this->line('  staged_at:       '.($staging['staged_at'] ?? '—'));
        $this->line('  staged_by:       '.($staging['staged_by_name'] ?? '—'));

        if ($report['diagnostics'] !== []) {
            $this->warn('Diagnostics:');
            foreach ($report['diagnostics'] as $diagnostic) {
                $this->line('  - '.$diagnostic);
            }
        }

        $this->warn('Addon НЕ встановлено і не розпаковано. Це лише integrity/trust перевірка.');

        return self::SUCCESS;
    }
}
