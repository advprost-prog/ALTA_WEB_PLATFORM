<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\Registry\MarketplaceReadinessService;
use Illuminate\Console\Command;

final class MarketplacePreflight extends Command
{
    protected $signature = 'addons:marketplace:preflight {--json} {--production}';

    protected $description = 'Read-only Marketplace client runtime, configuration, trust, storage, and operations preflight.';

    public function handle(MarketplaceReadinessService $readiness): int
    {
        $result = $readiness->inspect((bool) $this->option('production'));
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('Marketplace client readiness: '.$result['status']);
            $this->table(['Code', 'Severity', 'Message', 'Remediation'], array_map(fn (array $item): array => array_values($item), $result['items']));
        }

        return $result['status'] === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
