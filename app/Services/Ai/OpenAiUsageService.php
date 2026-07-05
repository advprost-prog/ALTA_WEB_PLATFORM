<?php

namespace App\Services\Ai;

use App\Models\AiUsageSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiUsageService
{
    public function __construct(private readonly AiSettingsService $settings)
    {
        //
    }

    /**
     * @return array<string, mixed>
     */
    public function syncCostsForCurrentMonth(): array
    {
        $start = now()->startOfMonth()->toDateString();
        $end = now()->addDay()->toDateString();

        $costs = $this->getCosts($start, $end);
        $settings = $this->settings->getSettings();

        AiUsageSnapshot::create([
            'period_start' => $start,
            'period_end' => now()->toDateString(),
            'provider' => $this->settings->getProvider(),
            'currency' => $costs['currency'] ?? config('ai_pricing.currency', 'USD'),
            'cost_value' => $costs['cost_value'] ?? null,
            'raw_payload' => $costs['raw_payload'] ?? null,
            'synced_at' => now(),
        ]);

        if (isset($costs['cost_value'])) {
            $settings->forceFill([
                'current_month_spend_estimate' => (float) $costs['cost_value'],
            ])->save();
        }

        $settings->forceFill([
            'last_usage_synced_at' => now(),
        ])->save();

        return $costs;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCosts(string $startDate, string $endDate): array
    {
        $adminApiKey = $this->settings->getAdminApiKey();

        if (blank($adminApiKey)) {
            throw new RuntimeException('Для синхронізації фактичних OpenAI costs потрібен Admin API key.');
        }

        $response = Http::withToken($adminApiKey)
            ->acceptJson()
            ->timeout($this->settings->getTimeout())
            ->get((string) config('ai.openai.costs_endpoint'), [
                'start_time' => Carbon::parse($startDate)->startOfDay()->timestamp,
                'end_time' => Carbon::parse($endDate)->startOfDay()->timestamp,
                'bucket_width' => '1d',
            ]);

        if ($response->failed()) {
            throw new RuntimeException('OpenAI costs sync failed with HTTP ' . $response->status() . '.');
        }

        $payload = $response->json();
        $cost = $this->sumCostValues($payload);

        return [
            'currency' => $this->findCurrency($payload) ?? config('ai_pricing.currency', 'USD'),
            'cost_value' => $cost,
            'raw_payload' => $payload,
        ];
    }

    private function sumCostValues(mixed $value): float
    {
        if (! is_array($value)) {
            return 0;
        }

        $sum = 0.0;

        if (isset($value['amount']) && is_array($value['amount']) && is_numeric($value['amount']['value'] ?? null)) {
            $sum += (float) $value['amount']['value'];
        }

        if (is_numeric($value['cost_value'] ?? null)) {
            $sum += (float) $value['cost_value'];
        }

        foreach ($value as $child) {
            if (is_array($child)) {
                $sum += $this->sumCostValues($child);
            }
        }

        return round($sum, 6);
    }

    private function findCurrency(mixed $value): ?string
    {
        if (! is_array($value)) {
            return null;
        }

        if (isset($value['amount']) && is_array($value['amount']) && is_string($value['amount']['currency'] ?? null)) {
            return $value['amount']['currency'];
        }

        if (is_string($value['currency'] ?? null)) {
            return $value['currency'];
        }

        foreach ($value as $child) {
            $currency = $this->findCurrency($child);

            if ($currency !== null) {
                return $currency;
            }
        }

        return null;
    }
}
