<?php

namespace App\Services\Ai;

use App\Exceptions\Ai\AiBudgetExceededException;
use App\Exceptions\Ai\AiSettingsMissingException;
use App\Models\AiRun;
use App\Models\AiSetting;
use Illuminate\Support\Facades\DB;

class AiSettingsService
{
    public function getSettings(): AiSetting
    {
        return AiSetting::getActive();
    }

    public function isEnabled(): bool
    {
        return (bool) $this->getSettings()->enabled;
    }

    public function getProvider(): string
    {
        return $this->getSettings()->provider ?: (string) config('ai.provider', 'openai');
    }

    public function getModel(): string
    {
        return $this->getSettings()->model ?: (string) config('ai.openai.model', 'gpt-4.1-mini');
    }

    public function getTimeout(): int
    {
        return $this->getSettings()->timeout ?: (int) config('ai.openai.timeout', 60);
    }

    public function getMaxInputChars(): int
    {
        return $this->getSettings()->max_input_chars ?: (int) config('ai.max_input_chars', 12000);
    }

    public function getMaxOutputTokens(): int
    {
        return $this->getSettings()->max_output_tokens ?: (int) config('ai.max_output_tokens', 2000);
    }

    public function getApiKey(): ?string
    {
        return $this->getSettings()->api_key ?: config('ai.openai.api_key');
    }

    public function getAdminApiKey(): ?string
    {
        return $this->getSettings()->admin_api_key ?: config('ai.openai.admin_api_key');
    }

    public function canRunAi(): bool
    {
        return $this->isEnabled()
            && filled($this->getApiKey())
            && ! $this->isHardLimitReached();
    }

    public function assertCanRunAi(bool $allowDisabled = false): void
    {
        if (! $allowDisabled && ! $this->isEnabled()) {
            throw new AiSettingsMissingException('AI модуль вимкнено. Увімкніть AI в адмінці: AI налаштування.');
        }

        if (blank($this->getApiKey())) {
            throw new AiSettingsMissingException('AI API key не задано. Додайте OpenAI API key в адмінці: AI налаштування.');
        }

        if ($this->isHardLimitReached()) {
            throw new AiBudgetExceededException('AI-запит заблоковано: внутрішній місячний бюджет вичерпано.');
        }
    }

    public function getMonthlyBudget(): ?float
    {
        $budget = $this->getSettings()->monthly_budget;

        return $budget === null ? null : (float) $budget;
    }

    public function getCurrentMonthSpendEstimate(): float
    {
        $sum = (float) AiRun::query()
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->whereNotNull('cost_estimate')
            ->sum('cost_estimate');

        return max($sum, (float) $this->getSettings()->current_month_spend_estimate);
    }

    public function getEstimatedRemainingBudget(): ?float
    {
        $budget = $this->getMonthlyBudget();

        if ($budget === null) {
            return null;
        }

        return max(0, $budget - $this->getCurrentMonthSpendEstimate());
    }

    public function isHardLimitReached(): bool
    {
        $settings = $this->getSettings();
        $budget = $this->getMonthlyBudget();

        if (! $settings->hard_limit_enabled || $budget === null || $budget <= 0) {
            return false;
        }

        return $this->getCurrentMonthSpendEstimate() >= $budget;
    }

    public function recordSpendEstimate(float $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        DB::transaction(function () use ($amount): void {
            $settings = AiSetting::query()->lockForUpdate()->first() ?? $this->getSettings();
            $settings->forceFill([
                'current_month_spend_estimate' => (float) $settings->current_month_spend_estimate + $amount,
            ])->save();
        });
    }

    public function resetCurrentMonthEstimate(): void
    {
        $this->getSettings()->forceFill([
            'current_month_spend_estimate' => 0,
        ])->save();
    }

    public function estimateCost(string $model, ?int $inputTokens, ?int $outputTokens): ?float
    {
        if ($inputTokens === null && $outputTokens === null) {
            return null;
        }

        $price = config('ai_pricing.models')[$model] ?? null;

        if (! is_array($price)) {
            return null;
        }

        $inputCost = (($inputTokens ?? 0) / 1_000_000) * (float) ($price['input_per_1m_tokens'] ?? 0);
        $outputCost = (($outputTokens ?? 0) / 1_000_000) * (float) ($price['output_per_1m_tokens'] ?? 0);

        return round($inputCost + $outputCost, 6);
    }
}
