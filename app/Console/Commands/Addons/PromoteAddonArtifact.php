<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\Registry\ArtifactPromotionManager;
use App\Support\Addons\Registry\ArtifactReviewActor;
use Illuminate\Console\Command;

class PromoteAddonArtifact extends Command
{
    protected $signature = 'addons:promote-artifact {code : Addon code to promote into live directory}';

    protected $description = 'Safely promote a trusted, approved, staged artifact into live addon directory without enabling it.';

    public function handle(ArtifactPromotionManager $manager): int
    {
        $code = (string) $this->argument('code');
        $result = $manager->promote($code, ArtifactReviewActor::cli());

        if (! $result->success) {
            $this->error($result->message);
            $diagnostics = is_array($result->diagnostics) && $result->diagnostics !== [] ? $result->diagnostics : $result->blockedReasons;
            foreach ($this->renderDiagnostics($diagnostics) as $line) {
                $this->line('  - '.$line);
            }

            return self::FAILURE;
        }

        $this->info('Code: '.$result->code);
        $this->line('Version: '.$result->version);
        $this->line('Type: '.$result->addonType);
        $this->line('Status: '.($result->idempotent ? 'Already promoted' : $result->status));
        $this->line('Idempotent: '.($result->idempotent ? 'yes' : 'no'));
        $this->line('Transaction: '.($result->transactionId ?? '—'));
        $this->line('Live path: '.$result->livePath);
        $this->line('Backup path: '.($result->backupPath ?? '—'));
        $this->line('Inventory hash: '.($result->inventoryHash ?? '—'));
        $this->line('Rollback available: '.($result->rollbackAvailable ? 'yes' : 'no'));
        if ($result->idempotent) {
            $this->info('No filesystem changes were made.');
        }
        $this->warn('Addon files are promoted only. Addon is not discovered, installed, or enabled automatically.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, mixed>  $diagnostics
     * @return array<int, string>
     */
    private function renderDiagnostics(array $diagnostics): array
    {
        $lines = [];

        foreach ($diagnostics as $reason) {
            if (is_array($reason)) {
                $line = ($reason['code'] ?? 'diagnostic').': '.($reason['message'] ?? '');
                if (! empty($reason['details'] ?? [])) {
                    $line .= ' ['.implode('; ', array_map('strval', (array) $reason['details'])).']';
                }
                $lines[] = $line;

                continue;
            }

            $lines[] = (string) $reason;
        }

        return $lines;
    }
}
