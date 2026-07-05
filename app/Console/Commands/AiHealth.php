<?php

namespace App\Console\Commands;

use App\Models\AiRun;
use App\Models\AiSetting;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiSettingsService;
use Illuminate\Console\Command;
use Throwable;

class AiHealth extends Command
{
    protected $signature = 'alta:ai-health {--live : Run a live OpenAI connection test}';

    protected $description = 'Show Alta-Trade AI settings and health without exposing secrets.';

    public function handle(AiSettingsService $settingsService): int
    {
        $settings = $settingsService->getSettings();

        $this->info('Alta-Trade AI health');
        $this->line('enabled: ' . ($settingsService->isEnabled() ? 'yes' : 'no'));
        $this->line('provider: ' . $settingsService->getProvider());
        $this->line('model: ' . $settingsService->getModel());
        $this->line('has_api_key: ' . (filled($settingsService->getApiKey()) ? 'yes' : 'no'));
        $this->line('has_admin_api_key: ' . (filled($settingsService->getAdminApiKey()) ? 'yes' : 'no'));
        $this->line('internal_monthly_budget: ' . $this->formatMoney($settingsService->getMonthlyBudget()));
        $this->line('current_month_estimated_spend: ' . $this->formatMoney($settingsService->getCurrentMonthSpendEstimate()));
        $this->line('hard_limit_status: ' . ($settingsService->isHardLimitReached() ? 'reached' : 'ok'));
        $this->line('last_health_status: ' . ($settings->last_health_status ?: '-'));
        $this->line('last_health_check: ' . ($settings->last_health_checked_at?->toDateTimeString() ?? '-'));

        if (! $this->option('live')) {
            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Running live connection test...');

        $run = AiRun::create([
            'entity_type' => AiSetting::class,
            'entity_id' => $settings->id,
            'task_type' => 'connection_test',
            'provider' => $settingsService->getProvider(),
            'model' => $settingsService->getModel(),
            'input_payload' => ['source' => 'alta:ai-health'],
            'status' => AiRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        try {
            $client = app(AiClient::class);
            $output = $client->testConnection(allowDisabled: true);
            $usage = $client->lastUsage();
            $costEstimate = $client->lastCostEstimate();

            $run->forceFill([
                'output_payload' => $output,
                'status' => AiRun::STATUS_COMPLETED,
                'tokens_input' => $usage['input_tokens'] ?? null,
                'tokens_output' => $usage['output_tokens'] ?? null,
                'cost_estimate' => $costEstimate,
                'finished_at' => now(),
            ])->save();

            if ($costEstimate !== null) {
                $settingsService->recordSpendEstimate($costEstimate);
            }

            $settings->forceFill([
                'last_health_status' => 'success',
                'last_health_message' => 'AI підключення працює.',
                'last_health_checked_at' => now(),
            ])->save();

            $this->info('live_status: success');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $message = $exception->getMessage();

            $run->forceFill([
                'status' => AiRun::STATUS_FAILED,
                'error' => $message,
                'finished_at' => now(),
            ])->save();

            $settings->forceFill([
                'last_health_status' => 'failed',
                'last_health_message' => $message,
                'last_health_checked_at' => now(),
            ])->save();

            $this->error('live_status: failed');
            $this->line('live_message: ' . $message);

            return self::FAILURE;
        }
    }

    private function formatMoney(?float $amount): string
    {
        return $amount === null ? '-' : '$' . number_format($amount, 6);
    }
}
