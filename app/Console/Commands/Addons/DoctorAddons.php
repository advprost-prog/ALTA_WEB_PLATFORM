<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\AddonHealthCheck;
use Illuminate\Console\Command;

class DoctorAddons extends Command
{
    protected $signature = 'addons:doctor {--json : Output machine-readable JSON}';

    protected $description = 'Report addon manifest, dependency, compatibility, and lifecycle diagnostics.';

    public function handle(AddonHealthCheck $healthCheck): int
    {
        $diagnostics = $healthCheck->diagnostics();

        if ($this->option('json')) {
            $this->line(json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $diagnostics['issues'] === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Addon doctor');

        if ($diagnostics['issues'] === [] && $diagnostics['warnings'] === []) {
            $this->info('No addon issues found.');

            return self::SUCCESS;
        }

        if ($diagnostics['issues'] !== []) {
            $this->warn('Issues:');
            $this->renderDiagnostics($diagnostics['issues']);
        }

        if ($diagnostics['warnings'] !== []) {
            $this->warn('Warnings:');
            $this->renderDiagnostics($diagnostics['warnings']);
        }

        return $diagnostics['issues'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<int, array{code: string, message: string, count: int, examples: array<int, string>}>  $diagnostics
     */
    private function renderDiagnostics(array $diagnostics): void
    {
        foreach ($diagnostics as $diagnostic) {
            $this->line('- '.$diagnostic['code'].': '.$diagnostic['message']);

            foreach ($diagnostic['examples'] as $example) {
                $this->line('  example: '.$example);
            }
        }
    }
}
