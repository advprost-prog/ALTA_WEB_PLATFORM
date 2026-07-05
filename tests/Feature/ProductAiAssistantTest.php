<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\AiSuggestions\Pages\EditAiSuggestion;
use App\Filament\Resources\AiSuggestions\Pages\ListAiSuggestions;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\AiRun;
use App\Models\AiSuggestion;
use App\Models\Product;
use App\Services\Ai\AiClient;
use App\Services\Ai\ProductEnrichmentService;
use App\Services\Ai\ProductImageAssistantService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use LogicException;
use Tests\Feature\Concerns\CreatesCommerceData;
use Tests\TestCase;

class ProductAiAssistantTest extends TestCase
{
    use CreatesCommerceData;
    use RefreshDatabase;

    public function test_disabled_ai_creates_failed_run_without_http_request(): void
    {
        config([
            'ai.enabled' => false,
            'ai.openai.api_key' => 'test-key',
        ]);

        $sentRequests = [];
        Http::fake(function ($request) use (&$sentRequests) {
            $sentRequests[] = $request;

            return Http::response([]);
        });

        $run = app(ProductEnrichmentService::class)->generateForProduct(
            $this->createProduct(),
            $this->createUserWithRole(UserRole::Manager),
        );

        $this->assertSame(AiRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('AI модуль вимкнено', (string) $run->error);
        $this->assertCount(0, $sentRequests);
    }

    public function test_product_enrichment_service_creates_failed_run_without_api_key(): void
    {
        config([
            'ai.enabled' => true,
            'ai.openai.api_key' => null,
        ]);

        $sentRequests = [];
        Http::fake(function ($request) use (&$sentRequests) {
            $sentRequests[] = $request;

            return Http::response([]);
        });

        $run = app(ProductEnrichmentService::class)->generateForProduct(
            $this->createProduct(),
            $this->createUserWithRole(UserRole::Manager),
        );

        $this->assertSame(AiRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('API key', (string) $run->error);
        $this->assertCount(0, $sentRequests);
    }

    public function test_fake_ai_client_creates_completed_run_and_text_suggestions(): void
    {
        $this->bindFakeAiClient($this->sampleAiPayload());

        $product = $this->createProduct();
        $run = app(ProductEnrichmentService::class)->generateForProduct(
            $product,
            $this->createUserWithRole(UserRole::Manager),
        );

        $this->assertSame(AiRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(123, $run->tokens_input);
        $this->assertSame(456, $run->tokens_output);

        foreach (['short_description', 'full_description', 'seo_title', 'seo_description'] as $field) {
            $this->assertDatabaseHas('ai_suggestions', [
                'ai_run_id' => $run->id,
                'entity_type' => Product::class,
                'entity_id' => $product->id,
                'field' => $field,
                'status' => AiSuggestion::STATUS_PENDING,
            ]);
        }
    }

    public function test_apply_suggestion_updates_product_short_description(): void
    {
        $user = $this->createUserWithRole(UserRole::Manager);
        $product = $this->createProduct(['short_description' => 'Старий короткий опис']);
        $suggestion = $this->createSuggestion($product, [
            'field' => 'short_description',
            'old_value' => 'Старий короткий опис',
            'suggested_value' => 'Новий короткий опис від AI',
        ]);

        app(ProductEnrichmentService::class)->applySuggestion($suggestion, $user);

        $this->assertSame('Новий короткий опис від AI', $product->fresh()->short_description);
        $this->assertSame(AiSuggestion::STATUS_APPLIED, $suggestion->fresh()->status);
        $this->assertSame($user->id, $suggestion->fresh()->applied_by);
        $this->assertNotNull($suggestion->fresh()->applied_at);
    }

    public function test_apply_suggestion_updates_only_product_seo_title(): void
    {
        $user = $this->createUserWithRole(UserRole::Manager);
        $product = $this->createProduct([
            'short_description' => 'Старий короткий опис',
            'seo_title' => 'Старий SEO',
            'seo_description' => 'Старий meta description',
        ]);
        $suggestion = $this->createSuggestion($product, [
            'field' => 'seo_title',
            'old_value' => 'Старий SEO',
            'suggested_value' => 'Новий SEO title від AI',
        ]);

        app(ProductEnrichmentService::class)->applySuggestion($suggestion, $user);

        $product->refresh();

        $this->assertSame('Старий короткий опис', $product->short_description);
        $this->assertSame('Новий SEO title від AI', $product->seo_title);
        $this->assertSame('Старий meta description', $product->seo_description);
        $this->assertSame($user->id, $suggestion->fresh()->applied_by);
        $this->assertNotNull($suggestion->fresh()->applied_at);
    }

    public function test_pending_suggestion_can_be_edited_before_apply(): void
    {
        $user = $this->createUserWithRole(UserRole::Manager);
        $product = $this->createProduct();
        $suggestion = $this->createSuggestion($product, [
            'suggested_value' => 'Початковий текст',
            'suggested_payload' => ['confidence' => 0.4],
        ]);

        $this->actingAs($user);

        Livewire::test(EditAiSuggestion::class, ['record' => $suggestion->getKey()])
            ->fillForm([
                'suggested_value' => 'Відредагований текст перед Apply',
                'suggested_payload_json' => json_encode(['confidence' => 0.9], JSON_THROW_ON_ERROR),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $suggestion->refresh();

        $this->assertSame('Відредагований текст перед Apply', $suggestion->suggested_value);
        $this->assertSame(['confidence' => 0.9], $suggestion->suggested_payload);
        $this->assertSame($user->id, $suggestion->edited_by);
        $this->assertNotNull($suggestion->edited_at);
    }

    public function test_applied_and_rejected_suggestions_cannot_be_edited(): void
    {
        $user = $this->createUserWithRole(UserRole::Admin);
        $product = $this->createProduct();
        $applied = $this->createSuggestion($product, [
            'status' => AiSuggestion::STATUS_APPLIED,
            'applied_by' => $user->id,
            'applied_at' => now(),
        ]);
        $rejected = $this->createSuggestion($product, [
            'status' => AiSuggestion::STATUS_REJECTED,
        ]);

        $this->assertFalse($user->can('update', $applied));
        $this->assertFalse($user->can('update', $rejected));
    }

    public function test_applied_suggestion_is_hidden_from_default_active_list(): void
    {
        $user = $this->createUserWithRole(UserRole::Manager);
        $product = $this->createProduct();
        $pending = $this->createSuggestion($product, [
            'suggested_value' => 'Активна пропозиція',
        ]);
        $applied = $this->createSuggestion($product, [
            'status' => AiSuggestion::STATUS_APPLIED,
            'applied_by' => $user->id,
            'applied_at' => now(),
            'suggested_value' => 'Історична пропозиція',
        ]);

        $this->actingAs($user);

        Livewire::test(ListAiSuggestions::class)
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$applied])
            ->filterTable('workflow', 'history')
            ->assertCanSeeTableRecords([$applied])
            ->assertCanNotSeeTableRecords([$pending]);
    }

    public function test_apply_image_alt_text_updates_product_alt_text(): void
    {
        $user = $this->createUserWithRole(UserRole::Manager);
        $product = $this->createProduct(['image_alt_text' => null]);
        $suggestion = $this->createSuggestion($product, [
            'field' => 'image_alt_text',
            'suggested_value' => 'Alt-текст від AI для основного фото',
        ]);

        app(ProductEnrichmentService::class)->applySuggestion($suggestion, $user);

        $this->assertSame('Alt-текст від AI для основного фото', $product->fresh()->image_alt_text);
        $this->assertSame(AiSuggestion::STATUS_APPLIED, $suggestion->fresh()->status);
    }

    public function test_rejected_suggestion_cannot_be_applied(): void
    {
        $user = $this->createUserWithRole(UserRole::Manager);
        $product = $this->createProduct(['short_description' => 'Старий короткий опис']);
        $suggestion = $this->createSuggestion($product, [
            'status' => AiSuggestion::STATUS_REJECTED,
            'suggested_value' => 'Не має застосуватися',
        ]);

        $this->expectException(LogicException::class);

        try {
            app(ProductEnrichmentService::class)->applySuggestion($suggestion, $user);
        } finally {
            $this->assertSame('Старий короткий опис', $product->fresh()->short_description);
            $this->assertSame(AiSuggestion::STATUS_REJECTED, $suggestion->fresh()->status);
        }
    }

    public function test_reject_suggestion_marks_it_rejected(): void
    {
        $product = $this->createProduct();
        $suggestion = $this->createSuggestion($product);

        app(ProductEnrichmentService::class)->rejectSuggestion($suggestion);

        $this->assertSame(AiSuggestion::STATUS_REJECTED, $suggestion->fresh()->status);
    }

    public function test_manager_has_access_to_ai_suggestion_resource(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Manager))
            ->get('/admin/ai-suggestions')
            ->assertOk();
    }

    public function test_guest_cannot_access_ai_suggestion_resource(): void
    {
        $this->get('/admin/ai-suggestions')
            ->assertRedirect('/admin/login');
    }

    public function test_ai_response_with_attributes_does_not_break_suggestion_creation(): void
    {
        $payload = $this->sampleAiPayload();
        $payload['attributes'] = [
            ['name' => 'Вʼязкість', 'value' => '5W-30', 'unit' => null, 'sort_order' => 1],
            ['name' => 'Обʼєм', 'value' => '4', 'unit' => 'л', 'sort_order' => 2],
        ];

        $this->bindFakeAiClient($payload);

        $product = $this->createProduct();
        $run = app(ProductEnrichmentService::class)->generateForProduct(
            $product,
            $this->createUserWithRole(UserRole::Manager),
        );

        $suggestion = AiSuggestion::query()
            ->where('ai_run_id', $run->id)
            ->where('field', 'attributes')
            ->firstOrFail();

        $this->assertSame(AiRun::STATUS_COMPLETED, $run->status);
        $this->assertCount(2, $suggestion->suggested_payload);
    }

    public function test_gtin_candidates_are_not_applied_automatically(): void
    {
        $this->bindFakeAiClient($this->sampleAiPayload([
            'gtin_candidates' => [
                ['gtin' => '1234567890123', 'source' => null, 'confidence' => 0.2, 'note' => 'Неперевірена гіпотеза'],
            ],
        ]));

        $user = $this->createUserWithRole(UserRole::Manager);
        $product = $this->createProduct(['seo_title' => 'Старий SEO']);
        $run = app(ProductEnrichmentService::class)->generateForProduct(
            $product,
            $user,
        );

        $gtinSuggestion = AiSuggestion::query()
            ->where('ai_run_id', $run->id)
            ->where('field', 'gtin_candidates')
            ->firstOrFail();

        $this->assertSame(AiSuggestion::STATUS_PENDING, $gtinSuggestion->status);
        $this->assertSame('Старий SEO', $product->fresh()->seo_title);

        $this->expectException(LogicException::class);

        app(ProductEnrichmentService::class)->applySuggestion(
            $gtinSuggestion,
            $user,
        );
    }

    public function test_unsupported_field_has_clear_reason_and_does_not_change_product(): void
    {
        $user = $this->createUserWithRole(UserRole::Manager);
        $product = $this->createProduct(['short_description' => 'Старий опис']);
        $suggestion = $this->createSuggestion($product, [
            'field' => 'unknown_field',
            'suggested_value' => 'Не має застосуватися',
        ]);

        $this->assertSame(AiSuggestion::APPLY_STATUS_UNSUPPORTED, $suggestion->applyStatus());
        $this->assertStringContainsString('поки не застосовується автоматично', (string) $suggestion->applyUnavailableReason());

        $this->expectException(LogicException::class);

        try {
            app(ProductEnrichmentService::class)->applySuggestion($suggestion, $user);
        } finally {
            $this->assertSame('Старий опис', $product->fresh()->short_description);
            $this->assertSame(AiSuggestion::STATUS_PENDING, $suggestion->fresh()->status);
        }
    }

    public function test_deleted_product_suggestion_reports_missing_target_without_status_change(): void
    {
        $user = $this->createUserWithRole(UserRole::Manager);
        $product = $this->createProduct();
        $suggestion = $this->createSuggestion($product, [
            'suggested_value' => 'Не має застосуватися',
        ]);

        $product->delete();

        $this->assertSame(AiSuggestion::APPLY_STATUS_MISSING_TARGET, $suggestion->fresh()->applyStatus());

        $this->expectException(LogicException::class);

        try {
            app(ProductEnrichmentService::class)->applySuggestion($suggestion->fresh(), $user);
        } finally {
            $this->assertSame(AiSuggestion::STATUS_PENDING, $suggestion->fresh()->status);
        }
    }

    public function test_attributes_are_not_applied_automatically(): void
    {
        $user = $this->createUserWithRole(UserRole::Manager);
        $product = $this->createProduct(['short_description' => 'Старий короткий опис']);
        $suggestion = $this->createSuggestion($product, [
            'field' => 'attributes',
            'suggested_value' => null,
            'suggested_payload' => [
                ['name' => 'Обʼєм', 'value' => '4 л'],
            ],
        ]);

        $this->expectException(LogicException::class);

        try {
            app(ProductEnrichmentService::class)->applySuggestion($suggestion, $user);
        } finally {
            $this->assertSame('Старий короткий опис', $product->fresh()->short_description);
            $this->assertSame(AiSuggestion::STATUS_PENDING, $suggestion->fresh()->status);
        }
    }

    public function test_ai_suggestions_table_apply_action_is_visible_and_applies_pending_suggestion(): void
    {
        $user = $this->createUserWithRole(UserRole::Manager);
        $product = $this->createProduct(['short_description' => 'Старий короткий опис']);
        $suggestion = $this->createSuggestion($product, [
            'suggested_value' => 'Застосовано з table action',
        ]);

        $this->actingAs($user);

        Livewire::test(ListAiSuggestions::class)
            ->assertTableActionVisible('apply', $suggestion)
            ->assertTableActionVisible('reject', $suggestion)
            ->callAction(TestAction::make('apply')->table($suggestion))
            ->assertNotified('AI-пропозицію застосовано і прибрано з активного списку');

        $this->assertSame('Застосовано з table action', $product->fresh()->short_description);
        $this->assertSame(AiSuggestion::STATUS_APPLIED, $suggestion->fresh()->status);
        $this->assertSame($user->id, $suggestion->fresh()->applied_by);
        $this->assertNotNull($suggestion->fresh()->applied_at);
    }

    public function test_content_manager_can_apply_content_field_but_not_review_only_field(): void
    {
        $contentManager = $this->createUserWithRole(UserRole::ContentManager);
        $product = $this->createProduct();
        $contentSuggestion = $this->createSuggestion($product, [
            'field' => 'seo_description',
            'suggested_value' => 'Новий meta description',
        ]);
        $attributesSuggestion = $this->createSuggestion($product, [
            'field' => 'attributes',
            'suggested_value' => null,
            'suggested_payload' => [
                ['name' => 'Обʼєм', 'value' => '4 л'],
            ],
        ]);

        $this->assertTrue($contentManager->can('apply', $contentSuggestion));
        $this->assertFalse($contentManager->can('apply', $attributesSuggestion));
    }

    public function test_applied_suggestion_has_no_apply_action(): void
    {
        $user = $this->createUserWithRole(UserRole::Manager);
        $product = $this->createProduct();
        $suggestion = $this->createSuggestion($product, [
            'status' => AiSuggestion::STATUS_APPLIED,
            'applied_by' => $user->id,
            'applied_at' => now(),
        ]);

        $this->actingAs($user);

        Livewire::test(ListAiSuggestions::class)
            ->filterTable('workflow', 'history')
            ->assertTableActionHidden('apply', $suggestion);
    }

    public function test_ai_suggestions_can_be_filtered_by_product(): void
    {
        $user = $this->createUserWithRole(UserRole::Manager);
        $brand = $this->createBrand(['name' => 'Bosch', 'slug' => 'bosch-filter']);
        $category = $this->createCategory(['name' => 'Автотовари', 'slug' => 'avtotovary-filter']);
        $firstProduct = $this->createProduct([
            'brand' => $brand,
            'category' => $category,
            'name' => 'Bosch S5 AGM 70Ah',
            'slug' => 'bosch-s5-agm-70ah',
            'sku' => 'AT-BAT-S5-70',
        ]);
        $secondProduct = $this->createProduct([
            'brand' => $brand,
            'category' => $category,
            'name' => 'Castrol EDGE 5W-30 LL 4L',
            'slug' => 'castrol-edge-ai-filter',
            'sku' => 'AT-OIL-530-4L',
        ]);
        $firstSuggestion = $this->createSuggestion($firstProduct, [
            'field' => 'seo_description',
            'suggested_value' => 'AI для Bosch',
        ]);
        $secondSuggestion = $this->createSuggestion($secondProduct, [
            'field' => 'short_description',
            'suggested_value' => 'AI для Castrol',
        ]);

        $this->actingAs($user);

        Livewire::test(ListAiSuggestions::class)
            ->assertTableFilterExists('product_id')
            ->filterTable('product_id', $firstProduct->id)
            ->assertCanSeeTableRecords([$firstSuggestion])
            ->assertCanNotSeeTableRecords([$secondSuggestion]);
    }

    public function test_ai_image_assistant_creates_alt_text_and_search_query_suggestions(): void
    {
        $user = $this->createUserWithRole(UserRole::Manager);
        $product = $this->createProduct();

        $run = app(ProductImageAssistantService::class)->generateForProduct($product, $user, [
            'image_alt_text' => true,
            'image_search_queries' => true,
        ]);

        $this->assertSame(AiRun::STATUS_COMPLETED, $run->status);
        $this->assertDatabaseHas('ai_suggestions', [
            'ai_run_id' => $run->id,
            'field' => 'image_alt_text',
            'status' => AiSuggestion::STATUS_PENDING,
        ]);
        $this->assertDatabaseHas('ai_suggestions', [
            'ai_run_id' => $run->id,
            'field' => 'image_search_queries',
            'status' => AiSuggestion::STATUS_PENDING,
        ]);
    }

    public function test_remote_image_candidate_is_review_only_and_not_auto_applied(): void
    {
        $user = $this->createUserWithRole(UserRole::Manager);
        $product = $this->createProduct(['main_image' => null]);

        $run = app(ProductImageAssistantService::class)->generateForProduct($product, $user, [
            'image_alt_text' => false,
            'image_search_queries' => false,
            'manual_url' => 'https://example.test/photo.jpg',
        ]);

        $suggestion = AiSuggestion::query()
            ->where('ai_run_id', $run->id)
            ->where('field', 'image_candidates')
            ->firstOrFail();

        $this->assertSame(AiSuggestion::APPLY_STATUS_REVIEW_ONLY, $suggestion->applyStatus());
        $this->assertFalse((bool) $suggestion->suggested_payload['candidates'][0]['can_apply']);
        $this->assertSame(null, $product->fresh()->main_image);
    }

    public function test_local_image_candidate_can_apply_only_when_file_exists(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/local-photo.webp', 'fake image');

        $user = $this->createUserWithRole(UserRole::Manager);
        $product = $this->createProduct(['main_image' => null]);

        $run = app(ProductImageAssistantService::class)->generateForProduct($product, $user, [
            'image_alt_text' => false,
            'image_search_queries' => false,
            'local_path' => 'storage/products/local-photo.webp',
        ]);

        $suggestion = AiSuggestion::query()
            ->where('ai_run_id', $run->id)
            ->where('field', 'main_image_candidate')
            ->firstOrFail();

        $this->assertTrue($suggestion->canBeAppliedAutomatically());

        app(ProductEnrichmentService::class)->applySuggestion($suggestion, $user);

        $this->assertSame('storage/products/local-photo.webp', $product->fresh()->main_image);
        $this->assertSame(AiSuggestion::STATUS_APPLIED, $suggestion->fresh()->status);
    }

    public function test_main_image_field_alias_can_apply_local_public_disk_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/main-photo.webp', 'fake image');

        $user = $this->createUserWithRole(UserRole::Manager);
        $product = $this->createProduct(['main_image' => null]);
        $suggestion = $this->createSuggestion($product, [
            'field' => 'main_image',
            'suggested_value' => null,
            'suggested_payload' => [
                'local_path' => 'products/main-photo.webp',
            ],
        ]);

        $this->assertTrue($suggestion->canBeAppliedAutomatically());

        app(ProductEnrichmentService::class)->applySuggestion($suggestion, $user);

        $this->assertSame('products/main-photo.webp', $product->fresh()->main_image);
    }

    public function test_content_manager_image_assistant_ignores_risky_candidate_inputs(): void
    {
        $user = $this->createUserWithRole(UserRole::ContentManager);
        $product = $this->createProduct();

        $this->actingAs($user);

        Livewire::test(ListProducts::class)
            ->assertTableActionVisible('productImagePicker', $product);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function bindFakeAiClient(array $payload): void
    {
        config([
            'ai.enabled' => true,
            'ai.openai.api_key' => 'test-key',
        ]);

        app()->instance(AiClient::class, new class($payload) extends AiClient
        {
            /**
             * @param  array<string, mixed>  $payload
             */
            public function __construct(private readonly array $payload)
            {
                //
            }

            public function isEnabled(): bool
            {
                return true;
            }

            public function generateStructured(string $systemPrompt, string $userPrompt, array $schema): array
            {
                return $this->payload;
            }

            public function lastUsage(): array
            {
                return [
                    'input_tokens' => 123,
                    'output_tokens' => 456,
                ];
            }
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function sampleAiPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Castrol EDGE 5W-30 LL 4L',
            'short_description' => 'Синтетична моторна олива для щоденного обслуговування авто.',
            'full_description' => 'Опис від AI без перебільшень і неперевірених технічних тверджень.',
            'seo_title' => 'Castrol EDGE 5W-30 LL 4L купити',
            'seo_description' => 'Castrol EDGE 5W-30 LL 4L для сервісного обслуговування авто.',
            'attributes' => [
                ['name' => 'Вʼязкість', 'value' => '5W-30', 'unit' => null, 'sort_order' => 1],
            ],
            'gtin_candidates' => [],
            'image_alt_text' => 'Каністра моторної оливи Castrol EDGE 5W-30 LL 4L',
            'confidence' => 0.82,
            'warnings' => ['Перевірте відповідність товару конкретному авто.'],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createSuggestion(Product $product, array $attributes = []): AiSuggestion
    {
        $run = AiRun::create([
            'entity_type' => Product::class,
            'entity_id' => $product->id,
            'task_type' => 'product_enrichment',
            'provider' => 'openai',
            'model' => 'test-model',
            'status' => AiRun::STATUS_COMPLETED,
        ]);

        return AiSuggestion::create($attributes + [
            'ai_run_id' => $run->id,
            'entity_type' => Product::class,
            'entity_id' => $product->id,
            'field' => 'short_description',
            'old_value' => $product->short_description,
            'suggested_value' => 'AI-пропозиція',
            'status' => AiSuggestion::STATUS_PENDING,
        ]);
    }
}
