<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\Ai\AiBudgetExceededException;
use App\Exceptions\Ai\AiSettingsMissingException;
use App\Filament\Pages\AiSettingsPage;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Services\Ai\AiClient;
use App\Services\Ai\OpenAiUsageService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Tests\Feature\Concerns\CreatesCommerceData;
use Tests\TestCase;

class AiSettingsTest extends TestCase
{
    use CreatesCommerceData;
    use RefreshDatabase;

    public function test_admin_can_open_ai_settings_page(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Admin))
            ->get('/admin/ai-settings')
            ->assertOk()
            ->assertSee('AI налаштування');
    }

    public function test_manager_cannot_open_ai_settings_page(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Manager))
            ->get('/admin/ai-settings')
            ->assertForbidden();
    }

    public function test_content_manager_cannot_open_ai_settings_page(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::ContentManager))
            ->get('/admin/ai-settings')
            ->assertForbidden();
    }

    public function test_api_key_is_encrypted_and_hidden_from_arrays_and_json(): void
    {
        $settings = AiSetting::getActive();
        $settings->api_key = 'sk-test-secret-1234';
        $settings->admin_api_key = 'sk-admin-secret-5678';
        $settings->save();

        $settings = $settings->fresh();

        $this->assertNotSame('sk-test-secret-1234', $settings->encrypted_api_key);
        $this->assertNotSame('sk-admin-secret-5678', $settings->encrypted_admin_api_key);
        $this->assertStringNotContainsString('sk-test-secret-1234', (string) $settings->encrypted_api_key);
        $this->assertStringNotContainsString('sk-admin-secret-5678', (string) $settings->encrypted_admin_api_key);
        $this->assertArrayNotHasKey('encrypted_api_key', $settings->toArray());
        $this->assertArrayNotHasKey('encrypted_admin_api_key', $settings->toArray());
        $this->assertStringNotContainsString('sk-test-secret-1234', $settings->toJson());
        $this->assertStringNotContainsString('sk-admin-secret-5678', $settings->toJson());
        $this->assertSame('sk-...1234', $settings->maskedApiKey());
    }

    public function test_ai_settings_page_shows_only_masked_key(): void
    {
        $settings = $this->enabledSettings();
        $settings->api_key = 'sk-visible-secret-1234';
        $settings->save();

        $this->actingAs($this->createUserWithRole(UserRole::Admin))
            ->get('/admin/ai-settings')
            ->assertOk()
            ->assertSee('sk-...1234')
            ->assertDontSee('sk-visible-secret-1234');
    }

    public function test_empty_key_fields_do_not_clear_existing_keys_on_save(): void
    {
        $settings = $this->enabledSettings();
        $settings->api_key = 'sk-existing-1234';
        $settings->admin_api_key = 'sk-admin-existing-5678';
        $settings->save();

        $this->actingAs($this->createUserWithRole(UserRole::Admin));

        Livewire::test(AiSettingsPage::class)
            ->set('data.api_key', '')
            ->set('data.admin_api_key', '')
            ->call('save');

        $settings = $settings->fresh();

        $this->assertSame('sk-existing-1234', $settings->api_key);
        $this->assertSame('sk-admin-existing-5678', $settings->admin_api_key);
    }

    public function test_delete_api_key_actions_clear_keys(): void
    {
        $settings = $this->enabledSettings();
        $settings->api_key = 'sk-existing-1234';
        $settings->admin_api_key = 'sk-admin-existing-5678';
        $settings->save();

        $this->actingAs($this->createUserWithRole(UserRole::Admin));

        Livewire::test(AiSettingsPage::class)
            ->call('deleteApiKey')
            ->call('deleteAdminApiKey');

        $settings = $settings->fresh();

        $this->assertNull($settings->api_key);
        $this->assertNull($settings->admin_api_key);
    }

    public function test_ai_client_uses_db_key_before_env_fallback(): void
    {
        config(['ai.openai.api_key' => 'sk-env-fallback']);
        $settings = $this->enabledSettings();
        $settings->api_key = 'sk-db-key';
        $settings->save();

        $authorization = null;
        Http::fake(function ($request) use (&$authorization) {
            $authorization = $request->header('Authorization')[0] ?? null;

            return Http::response($this->structuredResponse(['status' => 'ok', 'message' => 'ok']));
        });

        app(AiClient::class)->testConnection();

        $this->assertSame('Bearer sk-db-key', $authorization);
    }

    public function test_ai_client_falls_back_to_env_key_when_db_key_is_missing(): void
    {
        config(['ai.openai.api_key' => 'sk-env-fallback']);
        $this->enabledSettings();

        $authorization = null;
        Http::fake(function ($request) use (&$authorization) {
            $authorization = $request->header('Authorization')[0] ?? null;

            return Http::response($this->structuredResponse(['status' => 'ok', 'message' => 'ok']));
        });

        app(AiClient::class)->testConnection();

        $this->assertSame('Bearer sk-env-fallback', $authorization);
    }

    public function test_ai_disabled_and_missing_key_block_requests_without_http_call(): void
    {
        $settings = AiSetting::getActive();
        $settings->api_key = 'sk-disabled-key';
        $settings->forceFill(['enabled' => false]);
        $settings->save();
        $this->assertFalse($settings->fresh()->enabled);

        $requests = 0;
        Http::fake(function () use (&$requests) {
            $requests++;

            return Http::response([]);
        });

        try {
            app(AiClient::class)->generateStructured('system', 'user', $this->basicSchema());
            $this->fail('AI disabled should throw.');
        } catch (AiSettingsMissingException $exception) {
            $this->assertStringContainsString('вимкнено', $exception->getMessage());
        }

        config(['ai.openai.api_key' => null]);
        $settings->forceFill(['enabled' => true, 'encrypted_api_key' => null])->save();

        try {
            app(AiClient::class)->generateStructured('system', 'user', $this->basicSchema());
            $this->fail('Missing key should throw.');
        } catch (AiSettingsMissingException $exception) {
            $this->assertStringContainsString('API key', $exception->getMessage());
        }

        $this->assertSame(0, $requests);
    }

    public function test_budget_exceeded_blocks_request_without_http_call(): void
    {
        $settings = $this->enabledSettings([
            'monthly_budget' => 0.01,
            'current_month_spend_estimate' => 0.01,
            'hard_limit_enabled' => true,
        ]);
        $settings->api_key = 'sk-budget-key';
        $settings->save();

        $requests = 0;
        Http::fake(function () use (&$requests) {
            $requests++;

            return Http::response([]);
        });

        $this->expectException(AiBudgetExceededException::class);

        try {
            app(AiClient::class)->generateStructured('system', 'user', $this->basicSchema());
        } finally {
            $this->assertSame(0, $requests);
        }
    }

    public function test_cost_estimate_is_recorded_on_ai_run_when_usage_exists(): void
    {
        $settings = $this->enabledSettings();
        $settings->api_key = 'sk-cost-key';
        $settings->save();

        Http::fake(fn () => Http::response($this->structuredResponse($this->productPayload(), [
            'input_tokens' => 1000,
            'output_tokens' => 500,
        ])));

        $run = app(\App\Services\Ai\ProductEnrichmentService::class)->generateForProduct(
            $this->createProduct(),
            $this->createUserWithRole(UserRole::Manager),
        );

        $this->assertSame(AiRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1000, $run->tokens_input);
        $this->assertSame(500, $run->tokens_output);
        $this->assertSame('0.001200', $run->cost_estimate);
        $this->assertGreaterThan(0, (float) $settings->fresh()->current_month_spend_estimate);
    }

    public function test_ai_health_command_does_not_show_secrets(): void
    {
        $settings = $this->enabledSettings();
        $settings->api_key = 'sk-health-secret-1234';
        $settings->admin_api_key = 'sk-admin-health-5678';
        $settings->save();

        $this->artisan('alta:ai-health')
            ->expectsOutputToContain('has_api_key: yes')
            ->expectsOutputToContain('has_admin_api_key: yes')
            ->doesntExpectOutputToContain('sk-health-secret-1234')
            ->doesntExpectOutputToContain('sk-admin-health-5678')
            ->assertExitCode(0);
    }

    public function test_insufficient_quota_creates_failed_run_without_secret(): void
    {
        $settings = $this->enabledSettings();
        $settings->api_key = 'sk-quota-secret-1234';
        $settings->save();

        Http::fake(fn () => Http::response([
            'error' => [
                'code' => 'insufficient_quota',
                'message' => 'quota exceeded',
            ],
        ], 429));

        $run = app(\App\Services\Ai\ProductEnrichmentService::class)->generateForProduct(
            $this->createProduct(),
            $this->createUserWithRole(UserRole::Manager),
        );

        $this->assertSame(AiRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('insufficient_quota', (string) $run->error);
        $this->assertStringNotContainsString('sk-quota-secret-1234', (string) $run->error);
    }

    public function test_openai_bad_request_reports_safe_message_without_secret(): void
    {
        $settings = $this->enabledSettings();
        $settings->api_key = 'sk-bad-request-secret-1234';
        $settings->save();

        Http::fake(fn () => Http::response([
            'error' => [
                'code' => 'invalid_json_schema',
                'message' => 'Invalid schema for response_format. Secret sk-bad-request-secret-1234 should not leak.',
            ],
        ], 400));

        try {
            app(AiClient::class)->generateStructured('system', 'user', $this->basicSchema());
            $this->fail('OpenAI HTTP 400 should throw.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Invalid schema for response_format', $exception->getMessage());
            $this->assertStringNotContainsString('sk-bad-request-secret-1234', $exception->getMessage());
        }
    }

    public function test_usage_sync_without_admin_key_returns_clear_error(): void
    {
        $this->enabledSettings();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Admin API key');

        app(OpenAiUsageService::class)->syncCostsForCurrentMonth();
    }

    public function test_ai_settings_test_connection_creates_connection_test_run(): void
    {
        $settings = $this->enabledSettings(['enabled' => false]);
        $settings->api_key = 'sk-test-connection';
        $settings->save();

        Http::fake(fn () => Http::response($this->structuredResponse(['status' => 'ok', 'message' => 'AI підключення працює.'])));

        $this->actingAs($this->createUserWithRole(UserRole::Admin));

        Livewire::test(AiSettingsPage::class)
            ->call('testConnection');

        $this->assertDatabaseHas('ai_runs', [
            'entity_type' => AiSetting::class,
            'entity_id' => $settings->id,
            'task_type' => 'connection_test',
            'status' => AiRun::STATUS_COMPLETED,
        ]);
    }

    public function test_product_resource_ai_action_notifies_when_ai_is_not_configured(): void
    {
        $settings = AiSetting::getActive();
        $settings->forceFill([
            'enabled' => false,
            'encrypted_api_key' => null,
        ])->save();
        config(['ai.openai.api_key' => null]);

        $product = $this->createProduct();

        $this->actingAs($this->createUserWithRole(UserRole::Manager));

        Livewire::test(ListProducts::class)
            ->callAction(TestAction::make('aiEnrichment')->table($product), [
                'short_description' => true,
                'full_description' => true,
                'seo' => true,
                'attributes' => true,
                'image_alt_text' => true,
            ])
            ->assertNotified('AI не налаштований');

        $this->assertDatabaseMissing('ai_runs', [
            'task_type' => 'product_enrichment',
            'entity_id' => $product->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function enabledSettings(array $attributes = []): AiSetting
    {
        $settings = AiSetting::getActive();
        $settings->forceFill($attributes + [
            'enabled' => true,
            'provider' => 'openai',
            'mode' => 'test',
            'model' => 'gpt-4.1-mini',
            'timeout' => 60,
            'max_input_chars' => 12000,
            'max_output_tokens' => 2000,
            'hard_limit_enabled' => true,
        ])->save();

        return $settings->fresh();
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, int>  $usage
     * @return array<string, mixed>
     */
    private function structuredResponse(array $content, array $usage = ['input_tokens' => 10, 'output_tokens' => 5]): array
    {
        return [
            'output' => [
                [
                    'content' => [
                        ['text' => json_encode($content, JSON_UNESCAPED_UNICODE)],
                    ],
                ],
            ],
            'usage' => $usage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function basicSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['status' => ['type' => 'string']],
            'required' => ['status'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayload(): array
    {
        return [
            'name' => 'Castrol EDGE 5W-30 LL 4L',
            'short_description' => 'Короткий опис AI',
            'full_description' => 'Повний опис AI',
            'seo_title' => 'SEO title AI',
            'seo_description' => 'SEO description AI',
            'attributes' => [
                ['name' => 'Вʼязкість', 'value' => '5W-30', 'unit' => null, 'sort_order' => 1],
            ],
            'gtin_candidates' => [],
            'image_alt_text' => 'Alt AI',
            'confidence' => 0.8,
            'warnings' => [],
        ];
    }
}
