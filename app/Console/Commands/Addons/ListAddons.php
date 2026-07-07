<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\AddonRegistry;
use Illuminate\Console\Command;

class ListAddons extends Command
{
    protected $signature = 'addons:list';

    protected $description = 'List discovered, installed, and enabled addons.';

    public function handle(AddonRegistry $registry): int
    {
        $addons = $registry->all();

        if ($addons->isEmpty()) {
            $this->warn('No addons registered. Run php artisan addons:discover.');

            return self::SUCCESS;
        }

        $this->table(
            ['Code', 'Type', 'Version', 'Status', 'Enabled', 'Source'],
            $addons->map(fn ($addon): array => [
                $addon->code,
                $addon->type,
                $addon->version,
                $addon->status,
                $addon->is_enabled ? 'yes' : 'no',
                $addon->source,
            ])->all(),
        );

        return self::SUCCESS;
    }
}
