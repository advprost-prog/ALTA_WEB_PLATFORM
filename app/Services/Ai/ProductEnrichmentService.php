<?php

namespace App\Services\Ai;

use App\Models\AiRun;
use App\Models\AiSuggestion;
use App\Models\Product;
use App\Models\User;
use App\Support\Ai\ProductEnrichmentResult;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

class ProductEnrichmentService
{
    public function __construct(
        private readonly AiClient $aiClient,
        private readonly AiSettingsService $settings,
    ) {
        //
    }

    /**
     * @param  array<string, bool>  $options
     */
    public function generateForProduct(Product $product, User $user, array $options = []): AiRun
    {
        $product->loadMissing(['brand', 'category', 'specifications', 'images']);
        $options = $this->normalizeOptions($options);
        $inputPayload = $this->buildInputPayload($product, $options);

        $run = AiRun::create([
            'user_id' => $user->id,
            'entity_type' => Product::class,
            'entity_id' => $product->id,
            'task_type' => 'product_enrichment',
            'provider' => $this->settings->getProvider(),
            'model' => $this->settings->getModel(),
            'input_payload' => $inputPayload,
            'status' => AiRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        try {
            $outputPayload = $this->aiClient->generateStructured(
                $this->systemPrompt(),
                $this->userPrompt($inputPayload),
                ProductEnrichmentResult::schema(),
            );

            $outputPayload = $this->normalizeOutput($outputPayload);
            $usage = $this->aiClient->lastUsage();
            $costEstimate = $this->aiClient->lastCostEstimate();

            DB::transaction(function () use ($run, $product, $inputPayload, $outputPayload, $options, $usage, $costEstimate): void {
                $run->forceFill([
                    'output_payload' => $outputPayload,
                    'status' => AiRun::STATUS_COMPLETED,
                    'tokens_input' => $usage['input_tokens'] ?? null,
                    'tokens_output' => $usage['output_tokens'] ?? null,
                    'cost_estimate' => $costEstimate,
                    'finished_at' => now(),
                ])->save();

                $this->createSuggestions($run, $product, $outputPayload, $options, $inputPayload);
            });

            if ($costEstimate !== null) {
                $this->settings->recordSpendEstimate($costEstimate);
            }
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => AiRun::STATUS_FAILED,
                'error' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();

            Log::warning('AI product enrichment failed.', [
                'ai_run_id' => $run->id,
                'product_id' => $product->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return $run->refresh();
    }

    public function applySuggestion(AiSuggestion $suggestion, User $user): void
    {
        if (! $suggestion->isPendingOrAccepted()) {
            throw new LogicException('Цю AI-пропозицію вже не можна застосувати.');
        }

        if (! $suggestion->isProductSuggestion()) {
            throw new LogicException('Автоматичне застосування підтримується лише для товарів.');
        }

        $product = Product::query()->find($suggestion->entity_id);

        if (! $product) {
            throw new LogicException('Товар для цієї AI-пропозиції не знайдено.');
        }

        if (! $suggestion->canBeAppliedAutomatically()) {
            throw new LogicException($suggestion->applyUnavailableReason() ?? $this->unsupportedApplyMessage($suggestion));
        }

        match ($suggestion->field) {
            'short_description' => $product->short_description = $suggestion->suggested_value,
            'full_description' => $product->description = $suggestion->suggested_value,
            'description' => $product->description = $suggestion->suggested_value,
            'seo_title' => $product->seo_title = $suggestion->suggested_value,
            'seo_description' => $product->seo_description = $suggestion->suggested_value,
            'image_alt_text' => $product->image_alt_text = $suggestion->suggested_value,
            'main_image' => $product->main_image = $this->localImagePathForApply($suggestion),
            'main_image_candidate' => $product->main_image = $this->localImagePathForApply($suggestion),
            default => throw new LogicException('Непідтримуване поле AI-пропозиції.'),
        };

        DB::transaction(function () use ($product, $suggestion, $user): void {
            $product->save();

            $suggestion->forceFill([
                'status' => AiSuggestion::STATUS_APPLIED,
                'applied_by' => $user->id,
                'applied_at' => now(),
            ])->save();
        });
    }

    public function rejectSuggestion(AiSuggestion $suggestion): void
    {
        if (! $suggestion->canBeRejected()) {
            throw new LogicException('Цю AI-пропозицію вже не можна відхилити.');
        }

        $suggestion->forceFill([
            'status' => AiSuggestion::STATUS_REJECTED,
        ])->save();
    }

    private function unsupportedApplyMessage(AiSuggestion $suggestion): string
    {
        if ($suggestion->field === 'image_alt_text' && ! Schema::hasColumn('products', 'image_alt_text')) {
            return 'У товару немає поля image_alt_text, тому alt-текст можна лише переглянути як пропозицію.';
        }

        if (in_array($suggestion->field, AiSuggestion::REVIEW_ONLY_FIELDS, true)) {
            return 'Характеристики та GTIN-кандидати не застосовуються автоматично на цьому етапі.';
        }

        return 'Поле "' . $suggestion->fieldLabel() . '" не підтримує автоматичне застосування.';
    }

    private function localImagePathForApply(AiSuggestion $suggestion): string
    {
        $path = $suggestion->suggested_payload['local_path']
            ?? $suggestion->suggested_payload['storage_path']
            ?? null;

        if (blank($path) || preg_match('/^https?:\/\//i', (string) $path) === 1 || str_contains((string) $path, "\0")) {
            throw new LogicException('Основне фото можна застосувати тільки з локального файлу.');
        }

        $path = ltrim((string) $path, '/');

        if (str_starts_with($path, 'images/')) {
            if (! is_file(public_path($path))) {
                throw new LogicException('Локальний файл фото не знайдено.');
            }

            return '/'.$path;
        }

        if (str_starts_with($path, 'storage/')) {
            $storagePath = substr($path, strlen('storage/'));

            if (! is_file(public_path($path)) && ($storagePath === '' || ! Storage::disk('public')->exists($storagePath))) {
                throw new LogicException('Локальний storage-файл фото не знайдено.');
            }

            return $path;
        }

        if (! Storage::disk('public')->exists($path)) {
            throw new LogicException('Локальний файл фото не знайдено у public storage.');
        }

        return $path;
    }

    /**
     * @param  array<string, bool>  $options
     * @return array<string, bool>
     */
    private function normalizeOptions(array $options): array
    {
        return array_merge([
            'short_description' => true,
            'full_description' => true,
            'seo' => true,
            'attributes' => true,
            'image_alt_text' => true,
            'gtin_candidates' => true,
        ], array_map('boolval', $options));
    }

    /**
     * @param  array<string, bool>  $options
     * @return array<string, mixed>
     */
    private function buildInputPayload(Product $product, array $options): array
    {
        return [
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'article' => $product->sku,
            'brand' => $product->brand?->name,
            'category' => $product->category?->name,
            'current_short_description' => $product->short_description,
            'current_full_description' => $product->description,
            'current_attributes' => $product->specifications
                ->map(fn ($specification): array => [
                    'name' => $specification->name,
                    'value' => $specification->value,
                    'unit' => $specification->unit,
                    'sort_order' => $specification->sort_order,
                ])
                ->values()
                ->all(),
            'current_seo_title' => $product->seo_title,
            'current_seo_description' => $product->seo_description,
            'current_product_image_alt_text' => $product->image_alt_text,
            'current_image_alt_texts' => $product->images
                ->pluck('alt')
                ->filter()
                ->values()
                ->all(),
            'requested_options' => $options,
        ];
    }

    private function systemPrompt(): string
    {
        return implode("\n", [
            'Ти AI-помічник для e-commerce автотоварів Alta-Trade.',
            'Генеруй корисний, чесний і не перебільшений опис товару українською мовою.',
            'Не вигадуй сертифікацію, сумісність, GTIN, країну походження або технічні параметри без підстав у наданих даних.',
            'Якщо даних недостатньо, додай короткий warning.',
            'GTIN candidates можна пропонувати лише як неперевірені гіпотези, але краще лишати порожнім без джерела.',
            'Відповідь має відповідати тільки JSON schema без markdown і додаткового тексту.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $inputPayload
     */
    private function userPrompt(array $inputPayload): string
    {
        $json = json_encode($inputPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $maxInputChars = max(1000, $this->settings->getMaxInputChars());

        return Str::limit((string) $json, $maxInputChars, "\n... обрізано за AI_MAX_INPUT_CHARS");
    }

    /**
     * @param  array<string, mixed>  $outputPayload
     * @return array<string, mixed>
     */
    private function normalizeOutput(array $outputPayload): array
    {
        return [
            'name' => $outputPayload['name'] ?? null,
            'short_description' => $outputPayload['short_description'] ?? null,
            'full_description' => $outputPayload['full_description'] ?? null,
            'seo_title' => $outputPayload['seo_title'] ?? null,
            'seo_description' => $outputPayload['seo_description'] ?? null,
            'attributes' => is_array($outputPayload['attributes'] ?? null) ? $outputPayload['attributes'] : [],
            'gtin_candidates' => is_array($outputPayload['gtin_candidates'] ?? null) ? $outputPayload['gtin_candidates'] : [],
            'image_alt_text' => $outputPayload['image_alt_text'] ?? null,
            'confidence' => (float) ($outputPayload['confidence'] ?? 0),
            'warnings' => is_array($outputPayload['warnings'] ?? null) ? $outputPayload['warnings'] : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $outputPayload
     * @param  array<string, bool>  $options
     * @param  array<string, mixed>  $inputPayload
     */
    private function createSuggestions(AiRun $run, Product $product, array $outputPayload, array $options, array $inputPayload): void
    {
        if ($options['short_description']) {
            $this->createTextSuggestion($run, $product, 'short_description', $product->short_description, $outputPayload['short_description'] ?? null, $outputPayload);
        }

        if ($options['full_description']) {
            $this->createTextSuggestion($run, $product, 'full_description', $product->description, $outputPayload['full_description'] ?? null, $outputPayload);
        }

        if ($options['seo']) {
            $this->createTextSuggestion($run, $product, 'seo_title', $product->seo_title, $outputPayload['seo_title'] ?? null, $outputPayload);
            $this->createTextSuggestion($run, $product, 'seo_description', $product->seo_description, $outputPayload['seo_description'] ?? null, $outputPayload);
        }

        if ($options['image_alt_text']) {
            $this->createTextSuggestion(
                $run,
                $product,
                'image_alt_text',
                $product->image_alt_text ?: Arr::first($inputPayload['current_image_alt_texts'] ?? []),
                $outputPayload['image_alt_text'] ?? null,
                $outputPayload,
            );
        }

        if ($options['attributes']) {
            $this->createPayloadSuggestion($run, $product, 'attributes', $outputPayload['attributes'] ?? []);
        }

        if ($options['gtin_candidates']) {
            $this->createPayloadSuggestion($run, $product, 'gtin_candidates', $outputPayload['gtin_candidates'] ?? []);
        }
    }

    /**
     * @param  array<string, mixed>  $outputPayload
     */
    private function createTextSuggestion(AiRun $run, Product $product, string $field, ?string $oldValue, mixed $suggestedValue, array $outputPayload): void
    {
        AiSuggestion::create([
            'ai_run_id' => $run->id,
            'entity_type' => Product::class,
            'entity_id' => $product->id,
            'field' => $field,
            'old_value' => $oldValue,
            'suggested_value' => is_scalar($suggestedValue) ? (string) $suggestedValue : null,
            'suggested_payload' => [
                'confidence' => $outputPayload['confidence'] ?? null,
                'warnings' => $outputPayload['warnings'] ?? [],
            ],
            'status' => AiSuggestion::STATUS_PENDING,
        ]);
    }

    /**
     * @param  array<int, mixed>  $payload
     */
    private function createPayloadSuggestion(AiRun $run, Product $product, string $field, array $payload): void
    {
        AiSuggestion::create([
            'ai_run_id' => $run->id,
            'entity_type' => Product::class,
            'entity_id' => $product->id,
            'field' => $field,
            'suggested_payload' => $payload,
            'status' => AiSuggestion::STATUS_PENDING,
        ]);
    }
}
