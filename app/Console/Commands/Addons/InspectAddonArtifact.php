<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\Marketplace\MarketplaceManager;
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
