<?php

namespace App\Services\Ai;

use App\Exceptions\Ai\AiInvalidApiKeyException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class AiClient
{
    /**
     * @var array<string, int|null>
     */
    private array $lastUsage = [
        'input_tokens' => null,
        'output_tokens' => null,
    ];

    private ?float $lastCostEstimate = null;

    private ?string $lastModel = null;

    public function __construct(private readonly AiSettingsService $settings)
    {
        //
    }

    public function isEnabled(): bool
    {
        return $this->settings->canRunAi();
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function generateStructured(string $systemPrompt, string $userPrompt, array $schema): array
    {
        return $this->sendStructured($systemPrompt, $userPrompt, $schema);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function generateStructuredWithSchema(
        string $systemPrompt,
        string $userPrompt,
        array $schema,
        string $schemaName,
        bool $allowDisabled = false,
    ): array
    {
        return $this->sendStructured($systemPrompt, $userPrompt, $schema, allowDisabled: $allowDisabled, schemaName: $schemaName);
    }

    /**
     * @return array<string, mixed>
     */
    public function testConnection(bool $allowDisabled = true): array
    {
        return $this->sendStructured(
            'Ти сервіс перевірки підключення. Поверни JSON строго за схемою.',
            'Поверни status ok і коротке повідомлення українською.',
            [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'status' => ['type' => 'string'],
                    'message' => ['type' => 'string'],
                ],
                'required' => ['status', 'message'],
            ],
            allowDisabled: $allowDisabled,
            schemaName: 'ai_connection_test',
        );
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function sendStructured(
        string $systemPrompt,
        string $userPrompt,
        array $schema,
        bool $allowDisabled = false,
        string $schemaName = 'product_enrichment_result',
    ): array
    {
        $this->settings->assertCanRunAi(allowDisabled: $allowDisabled);

        $apiKey = (string) $this->settings->getApiKey();
        $model = $this->settings->getModel();
        $this->lastModel = $model;

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout($this->settings->getTimeout())
            ->post((string) config('ai.openai.endpoint'), [
                'model' => $model,
                'input' => [
                    [
                        'role' => 'system',
                        'content' => [
                            ['type' => 'input_text', 'text' => $systemPrompt],
                        ],
                    ],
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'input_text', 'text' => $userPrompt],
                        ],
                    ],
                ],
                'max_output_tokens' => $this->settings->getMaxOutputTokens(),
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => $schemaName,
                        'strict' => true,
                        'schema' => $schema,
                    ],
                ],
            ]);

        if ($response->failed()) {
            $this->throwSafeOpenAiException($response);
        }

        $this->captureUsage($response, $model);

        $text = $this->extractText($response);
        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('AI response was not valid JSON.');
        }

        return $decoded;
    }

    /**
     * @return array<string, int|null>
     */
    public function lastUsage(): array
    {
        return $this->lastUsage;
    }

    public function lastCostEstimate(): ?float
    {
        return $this->lastCostEstimate;
    }

    public function lastModel(): ?string
    {
        return $this->lastModel;
    }

    private function extractText(Response $response): string
    {
        $payload = $response->json();

        if (is_string($payload['output_text'] ?? null)) {
            return $payload['output_text'];
        }

        $chatContent = Arr::get($payload, 'choices.0.message.content');

        if (is_string($chatContent)) {
            return $chatContent;
        }

        foreach (($payload['output'] ?? []) as $outputItem) {
            foreach (($outputItem['content'] ?? []) as $contentItem) {
                $text = $contentItem['text'] ?? null;

                if (is_string($text) && $text !== '') {
                    return $text;
                }
            }
        }

        throw new RuntimeException('AI response did not include structured text.');
    }

    private function captureUsage(Response $response, string $model): void
    {
        $usage = $response->json('usage', []);

        $this->lastUsage = [
            'input_tokens' => $usage['input_tokens'] ?? $usage['prompt_tokens'] ?? null,
            'output_tokens' => $usage['output_tokens'] ?? $usage['completion_tokens'] ?? null,
        ];

        $this->lastCostEstimate = $this->settings->estimateCost(
            $model,
            $this->lastUsage['input_tokens'],
            $this->lastUsage['output_tokens'],
        );
    }

    private function throwSafeOpenAiException(Response $response): never
    {
        $code = $response->json('error.code');
        $type = $response->json('error.type');

        if ($code === 'invalid_api_key' || ($type === 'invalid_request_error' && $response->status() === 401)) {
            throw new AiInvalidApiKeyException('API key недійсний або не має доступу.');
        }

        if ($code === 'insufficient_quota') {
            throw new RuntimeException('Ключ прийнято, але OpenAI повернув insufficient_quota. Потрібно поповнити API billing або перевірити Project budget.');
        }

        throw new RuntimeException('OpenAI request failed with HTTP ' . $response->status() . $this->safeErrorMessage($response));
    }

    private function safeErrorMessage(Response $response): string
    {
        $message = $response->json('error.message');

        if (! is_string($message) || trim($message) === '') {
            return '.';
        }

        $message = Str::limit(trim(preg_replace('/sk-[A-Za-z0-9_\-]+/', 'sk-...', $message) ?? $message), 500, '...');

        return ': '.$message;
    }
}
