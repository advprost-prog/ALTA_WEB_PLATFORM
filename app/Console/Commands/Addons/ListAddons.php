<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\AddonRegistry;
use App\Support\Addons\Marketplace\MarketplaceManager;
use Illuminate\Console\Command;

class ListAddons extends Command
{
    protected $signature = 'addons:list';

    protected $description = 'List discovered, installed, and enabled addons.';

    public function handle(AddonRegistry $registry, MarketplaceManager $marketplace): int
    {
        $addons = $registry->all();

        if ($addons->isEmpty()) {
            $this->warn('No addons registered. Run php artisan addons:discover.');

            return self::SUCCESS;
        }

        $availableVersions = [];
        foreach ($marketplace->resolve()['rows'] as $row) {
            $availableVersions[$row['item']->code] = $row['available_version'];
        }

        $this->table(
            ['Code', 'Type', 'Installed', 'Available', 'Status', 'Enabled', 'Source', 'Last error'],
            $addons->map(fn ($addon): array => [
                $addon->code,
                $addon->type,
                $addon->version,
                $availableVersions[$addon->code] ?? '-',
                $addon->status,
                $addon->is_enabled ? 'yes' : 'no',
                $addon->source,
                $addon->last_error ? mb_strimwidth($addon->last_error, 0, 72, '...') : '-',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
