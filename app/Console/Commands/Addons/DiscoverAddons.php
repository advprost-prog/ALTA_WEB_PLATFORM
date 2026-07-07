<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\AddonManager;
use Illuminate\Console\Command;

class DiscoverAddons extends Command
{
    protected $signature = 'addons:discover';

    protected $description = 'Discover local modules and extensions from modules/ and extensions/.';

    public function handle(AddonManager $addons): int
    {
        $result = $addons->discover();

        $this->info('Addon discovery complete.');
        $this->line('discovered: '.$result['discovered']);
        $this->line('invalid: '.$result['invalid']);
        $this->line('duplicates: '.$result['duplicates']);

        return $result['invalid'] > 0 || $result['duplicates'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
