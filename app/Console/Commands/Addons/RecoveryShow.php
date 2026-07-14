<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\Registry\AddonRecoveryService;
use Illuminate\Console\Command;

final class RecoveryShow extends Command
{
    protected $signature = 'addons:recovery:show {operation-id}';

    protected $description = 'Show a sanitized addon recovery assessment.';

    public function handle(AddonRecoveryService $recovery): int
    {
        $assessment = $recovery->inspect((string) $this->argument('operation-id'));
        if ($assessment === null) {
            $this->error('journal_invalid');

            return self::FAILURE;
        }
        $data = $assessment->toArray();
        unset($data['evidence']);
        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $assessment->automaticEligible ? self::SUCCESS : self::FAILURE;
    }
}
