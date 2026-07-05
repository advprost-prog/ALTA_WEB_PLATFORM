<?php

namespace App\Services\Ai;

use App\Models\AiRun;
use App\Models\AiSuggestion;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductImageAssistantService
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function generateForProduct(Product $product, User $user, array $options): AiRun
    {
        $product->loadMissing(['brand', 'category', 'images']);

        $inputPayload = [
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'brand' => $product->brand?->name,
            'category' => $product->category?->name,
            'main_image' => $product->main_image,
            'image_alt_text' => $product->image_alt_text,
            'manual_url' => $options['manual_url'] ?? null,
            'local_path' => $options['local_path'] ?? null,
        ];

        $outputPayload = $this->buildOutputPayload($product, $options);

        $run = AiRun::create([
            'user_id' => $user->id,
            'entity_type' => Product::class,
            'entity_id' => $product->id,
            'task_type' => 'product_image_assistant',
            'provider' => 'local',
            'model' => 'safe-image-assistant-v1',
            'input_payload' => $inputPayload,
            'output_payload' => $outputPayload,
            'status' => AiRun::STATUS_COMPLETED,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $this->createSuggestions($run, $product, $outputPayload, $options);

        return $run->refresh();
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function buildOutputPayload(Product $product, array $options): array
    {
        $base = trim(collect([$product->brand?->name, $product->name, $product->sku])->filter()->implode(' '));
        $category = $product->category?->name;

        return [
            'image_alt_text' => $base !== '' ? Str::limit($base.' - фото товару Alta-Trade', 500, '') : null,
            'image_description' => trim(collect([
                'Предметне фото товару для каталогу Alta-Trade.',
                $category ? 'Категорія: '.$category.'.' : null,
                'Без оманливих логотипів, сертифікацій або сумісностей, яких немає у даних товару.',
            ])->filter()->implode(' ')),
            'image_search_queries' => [
                trim($base.' фото товару'),
                trim($base.' packshot'),
                trim(($product->brand?->name ?: '').' '.$category.' product image'),
            ],
            'branded_placeholder_prompt' => trim(collect([
                'Створити нейтральний брендований placeholder для товару Alta-Trade.',
                $product->name,
                'Світлий фон, чистий e-commerce стиль, без сторонніх логотипів і без імітації реального пакування.',
            ])->filter()->implode(' ')),
            'manual_url_candidate' => $this->manualUrlCandidate($options['manual_url'] ?? null),
            'local_candidate' => $this->localCandidate($options['local_path'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $outputPayload
     * @param  array<string, mixed>  $options
     */
    private function createSuggestions(AiRun $run, Product $product, array $outputPayload, array $options): void
    {
        if ($options['image_alt_text'] ?? true) {
            $this->createTextSuggestion($run, $product, 'image_alt_text', $product->image_alt_text, $outputPayload['image_alt_text'] ?? null, [
                'source' => 'safe_image_assistant',
                'warning' => 'Перевірте alt-текст перед застосуванням.',
            ]);
        }

        if ($options['image_description'] ?? false) {
            $this->createTextSuggestion($run, $product, 'image_description', null, $outputPayload['image_description'] ?? null, [
                'source' => 'safe_image_assistant',
                'warning' => 'Опис зображення є підказкою для менеджера.',
            ]);
        }

        if ($options['image_search_queries'] ?? true) {
            $this->createPayloadSuggestion($run, $product, 'image_search_queries', [
                'queries' => array_values(array_filter($outputPayload['image_search_queries'] ?? [])),
                'warning' => 'Пошукові запити призначені тільки для ручного пошуку. Автоматичний scraping вимкнено.',
            ]);
        }

        if ($options['branded_placeholder_prompt'] ?? false) {
            $this->createTextSuggestion($run, $product, 'branded_placeholder_prompt', null, $outputPayload['branded_placeholder_prompt'] ?? null, [
                'source' => 'safe_image_assistant',
                'warning' => 'Prompt не створює і не завантажує файл автоматично.',
            ]);
        }

        $candidates = array_values(array_filter([
            $outputPayload['manual_url_candidate'] ?? null,
            $outputPayload['local_candidate'] ?? null,
        ]));

        if ($candidates !== []) {
            $this->createPayloadSuggestion($run, $product, 'image_candidates', [
                'candidates' => $candidates,
                'warning' => 'Remote URL не застосовується автоматично. Для Apply потрібен локальний файл.',
            ]);
        }

        $localCandidate = $outputPayload['local_candidate'] ?? null;

        if (is_array($localCandidate) && filled($localCandidate['local_path'] ?? null)) {
            $this->createPayloadSuggestion($run, $product, 'main_image_candidate', $localCandidate);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createTextSuggestion(AiRun $run, Product $product, string $field, ?string $oldValue, ?string $suggestedValue, array $payload): void
    {
        if (blank($suggestedValue)) {
            return;
        }

        AiSuggestion::create([
            'ai_run_id' => $run->id,
            'entity_type' => Product::class,
            'entity_id' => $product->id,
            'field' => $field,
            'old_value' => $oldValue,
            'suggested_value' => $suggestedValue,
            'suggested_payload' => $payload,
            'status' => AiSuggestion::STATUS_PENDING,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
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

    /**
     * @return array<string, mixed>|null
     */
    private function manualUrlCandidate(?string $url): ?array
    {
        if (blank($url)) {
            return null;
        }

        return [
            'url' => $url,
            'source' => 'manual_input',
            'license_note' => null,
            'confidence' => null,
            'warning' => 'URL додано вручну. Не завантажуйте і не застосовуйте фото без перевірки прав.',
            'local_path' => null,
            'can_apply' => false,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function localCandidate(?string $path): ?array
    {
        if (blank($path)) {
            return null;
        }

        $path = ltrim((string) $path, '/');
        $exists = $this->localPathExists($path);

        return [
            'url' => null,
            'source' => 'manual_local_path',
            'license_note' => 'Локальний файл має бути завантажений або створений користувачем.',
            'confidence' => $exists ? 1 : 0,
            'warning' => $exists
                ? 'Локальний файл існує. Після ручної перевірки його можна застосувати як main_image.'
                : 'Локальний файл не знайдено, Apply буде недоступний.',
            'local_path' => $path,
            'can_apply' => $exists,
        ];
    }

    private function localPathExists(string $path): bool
    {
        if (preg_match('/^https?:\/\//i', $path) === 1 || str_contains($path, "\0")) {
            return false;
        }

        if (str_starts_with($path, 'images/')) {
            return is_file(public_path($path));
        }

        if (str_starts_with($path, 'storage/')) {
            $storagePath = substr($path, strlen('storage/'));

            return is_file(public_path($path))
                || ($storagePath !== '' && Storage::disk('public')->exists($storagePath));
        }

        return Storage::disk('public')->exists($path);
    }
}
