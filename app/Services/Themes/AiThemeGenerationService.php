<?php

namespace App\Services\Themes;

use App\Models\StorefrontTheme;
use App\Models\ThemeGenerationRun;
use App\Models\User;
use App\Services\Ai\AiClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AiThemeGenerationService
{
    public function __construct(
        private readonly WebsiteStyleCaptureService $captureService,
        private readonly WebsiteStyleAnalysisService $analysisService,
        private readonly WebsiteStyleClassifier $styleClassifier,
        private readonly StyleFingerprintExtractor $styleFingerprintExtractor,
        private readonly ThemePresetMapper $presetMapper,
        private readonly ThemeGuardrailService $guardrailService,
        private readonly ThemePayloadValidator $validator,
        private readonly AiClient $aiClient,
    ) {
        //
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function generateFromUrl(string $sourceUrl, User $user, array $options): ThemeGenerationRun
    {
        $run = ThemeGenerationRun::create([
            'user_id' => $user->id,
            'source_url' => $sourceUrl,
            'status' => ThemeGenerationRun::STATUS_RUNNING,
            'input_payload' => $options,
            'started_at' => now(),
        ]);

        try {
            $result = $this->buildThemePayloadForSource($sourceUrl, $options);
            $payload = $result['payload'];
            $analysis = $result['analysis'];
            $styleProfile = $result['style_profile'];

            DB::transaction(function () use ($run, $user, $sourceUrl, $analysis, $styleProfile, $payload): void {
                $theme = StorefrontTheme::create([
                    'name' => (string) $payload['name'],
                    'slug' => $this->uniqueSlug((string) $payload['name']),
                    'description' => (string) ($payload['description'] ?? 'AI generated storefront theme draft.'),
                    'type' => StorefrontTheme::TYPE_AI_GENERATED,
                    'status' => StorefrontTheme::STATUS_DRAFT,
                    'is_active' => false,
                    'source' => 'ai_theme_studio',
                    'source_url' => $sourceUrl,
                    'style_family' => (string) ($payload['style_family'] ?? $analysis['style_family'] ?? 'commerce catalog'),
                    'style_profile' => $styleProfile,
                    'selected_preset' => $payload['selected_preset'] ?? null,
                    'guardrails_applied' => $payload['guardrails_applied'] ?? [],
                    'generation_warnings' => $payload['generation_warnings'] ?? [],
                    'tokens' => $payload['tokens'],
                    'layout_config' => $payload['layout_config'],
                    'component_config' => $payload['component_config'],
                    'css_variables' => $payload['css_variables'],
                    'custom_css' => $payload['custom_css'] ?? null,
                    'created_by' => $user->id,
                    'generated_by_ai' => true,
                    'ai_run_id' => $run->id,
                ]);

                $theme->createVersion('Initial AI Theme Studio payload.');

                $run->forceFill([
                    'status' => ThemeGenerationRun::STATUS_COMPLETED,
                    'analysis_payload' => array_merge($analysis, [
                        'style_profile' => $styleProfile,
                        'selected_preset' => $payload['selected_preset'] ?? null,
                        'guardrails_applied' => $payload['guardrails_applied'] ?? [],
                        'generation_warnings' => $payload['generation_warnings'] ?? [],
                    ]),
                    'style_profile' => $styleProfile,
                    'selected_preset' => $payload['selected_preset'] ?? null,
                    'guardrails_applied' => $payload['guardrails_applied'] ?? [],
                    'generation_warnings' => $payload['generation_warnings'] ?? [],
                    'generated_theme_payload' => array_merge($payload, [
                        'theme_id' => $theme->id,
                        'theme_slug' => $theme->slug,
                    ]),
                    'finished_at' => now(),
                ])->save();
            });
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => ThemeGenerationRun::STATUS_FAILED,
                'error' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();
        }

        return $run->refresh();
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function regenerateTheme(StorefrontTheme $theme, User $user, array $options = []): ThemeGenerationRun
    {
        if (! $theme->generated_by_ai || blank($theme->source_url)) {
            throw new RuntimeException('Only AI generated themes with source URL can be regenerated.');
        }

        if ($theme->is_active) {
            throw new RuntimeException('Active theme cannot be regenerated in place. Duplicate it first or activate another draft.');
        }

        $sourceUrl = (string) $theme->source_url;
        $run = ThemeGenerationRun::create([
            'user_id' => $user->id,
            'source_url' => $sourceUrl,
            'status' => ThemeGenerationRun::STATUS_RUNNING,
            'input_payload' => array_merge([
                'regenerated_theme_id' => $theme->id,
                'theme_name' => $theme->name,
                'base_layout' => $theme->layout_config['productCardVariant'] ?? 'marketplace',
            ], $options),
            'started_at' => now(),
        ]);

        try {
            $result = $this->buildThemePayloadForSource($sourceUrl, $run->input_payload ?? []);
            $payload = $result['payload'];
            $analysis = $result['analysis'];
            $styleProfile = $result['style_profile'];

            DB::transaction(function () use ($theme, $run, $sourceUrl, $analysis, $styleProfile, $payload): void {
                $theme->forceFill([
                    'name' => (string) $payload['name'],
                    'description' => (string) ($payload['description'] ?? 'AI regenerated storefront theme draft.'),
                    'status' => StorefrontTheme::STATUS_DRAFT,
                    'is_active' => false,
                    'source' => 'ai_theme_studio',
                    'source_url' => $sourceUrl,
                    'style_family' => (string) ($payload['style_family'] ?? $analysis['style_family'] ?? 'commerce catalog'),
                    'style_profile' => $styleProfile,
                    'selected_preset' => $payload['selected_preset'] ?? null,
                    'guardrails_applied' => $payload['guardrails_applied'] ?? [],
                    'generation_warnings' => $payload['generation_warnings'] ?? [],
                    'tokens' => $payload['tokens'],
                    'layout_config' => $payload['layout_config'],
                    'component_config' => $payload['component_config'],
                    'css_variables' => $payload['css_variables'],
                    'custom_css' => $payload['custom_css'] ?? null,
                    'ai_run_id' => $run->id,
                ])->save();

                $theme->createVersion('Regenerated from source by AI Theme Studio.');

                $run->forceFill([
                    'status' => ThemeGenerationRun::STATUS_COMPLETED,
                    'analysis_payload' => array_merge($analysis, [
                        'style_profile' => $styleProfile,
                        'selected_preset' => $payload['selected_preset'] ?? null,
                        'guardrails_applied' => $payload['guardrails_applied'] ?? [],
                        'generation_warnings' => $payload['generation_warnings'] ?? [],
                    ]),
                    'style_profile' => $styleProfile,
                    'selected_preset' => $payload['selected_preset'] ?? null,
                    'guardrails_applied' => $payload['guardrails_applied'] ?? [],
                    'generation_warnings' => $payload['generation_warnings'] ?? [],
                    'generated_theme_payload' => array_merge($payload, [
                        'theme_id' => $theme->id,
                        'theme_slug' => $theme->slug,
                        'regenerated' => true,
                    ]),
                    'finished_at' => now(),
                ])->save();
            });
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => ThemeGenerationRun::STATUS_FAILED,
                'error' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();
        }

        return $run->refresh();
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{capture: array<string, mixed>, analysis: array<string, mixed>, style_profile: array<string, mixed>, base_preset: array<string, mixed>, payload: array<string, mixed>}
     */
    private function buildThemePayloadForSource(string $sourceUrl, array $options): array
    {
        $capture = $this->captureService->capture($sourceUrl);
        $analysis = $this->analysisService->analyze($capture);
        $fingerprint = $this->styleFingerprintExtractor->extract($capture, $analysis);
        $styleProfile = $this->styleClassifier->classify($capture, $analysis);
        $styleProfile['business_profile'] = $fingerprint['business_profile'];
        $styleProfile['style_fingerprint'] = array_replace($fingerprint['style_fingerprint'], $styleProfile['style_fingerprint'] ?? []);
        $styleProfile['style_lock'] = $fingerprint['style_lock'];
        $basePreset = $this->presetMapper->mapStyleProfileToThemeDefaults($styleProfile);
        $payload = $this->generateThemePayload($sourceUrl, $analysis, $styleProfile, $basePreset, $options);
        $payload = $this->sanitizeGeneratedNameForSource($payload, $sourceUrl);

        $payload = $this->validator->validate($payload, $sourceUrl);
        $payload = $this->guardrailService->apply($payload, $styleProfile);
        $payload = $this->validator->validate($payload, $sourceUrl);

        return [
            'capture' => $capture,
            'analysis' => $analysis,
            'style_profile' => $styleProfile,
            'base_preset' => $basePreset,
            'payload' => $payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $styleProfile
     * @param  array<string, mixed>  $basePreset
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function generateThemePayload(string $sourceUrl, array $analysis, array $styleProfile, array $basePreset, array $options): array
    {
        $payload = ! $this->aiClient->isEnabled()
            ? $this->heuristicThemePayload($basePreset, $options)
            : $this->aiClient->generateStructuredWithSchema(
                $this->systemPrompt(),
                $this->userPrompt($sourceUrl, $analysis, $styleProfile, $basePreset, $options),
                $this->themePayloadSchema(),
                'ai_theme_generation_result',
            );

        $payload = array_replace_recursive($basePreset, $payload);
        $payload['name'] = trim((string) ($options['theme_name'] ?? '')) ?: (string) ($payload['name'] ?? 'AI Commerce Style Draft');
        $payload['description'] = (string) ($payload['description'] ?? 'Оригінальна token-based тема за стилістичним профілем source storefront без копіювання assets.');
        $payload['style_family'] = (string) ($basePreset['style_family'] ?? $payload['style_family'] ?? 'commerce catalog');
        $payload['style_profile'] = $styleProfile;
        $payload['business_profile'] = $styleProfile['business_profile'] ?? [];
        $payload['style_fingerprint'] = $styleProfile['style_fingerprint'] ?? [];
        $payload['style_lock'] = $styleProfile['style_lock'] ?? [];
        $payload['anti_clone_constraints'] = $styleProfile['style_fingerprint']['anti_clone_constraints'] ?? [
            'Do not copy the source HTML/CSS/JS.',
            'Do not reuse source logos, banners, texts, product photos or brand names.',
        ];
        $payload['selected_preset'] = $basePreset['selected_preset'] ?? null;
        $payload['css_variables'] = ThemeSchema::cssVariables((array) ($payload['tokens'] ?? []), (array) ($payload['css_variables'] ?? []));
        $payload['notes'] = (string) ($payload['notes'] ?? ($this->aiClient->isEnabled() ? 'Generated with AI Theme Studio.' : 'Generated with heuristic fallback because AI is disabled or unavailable.'));
        $payload['similarity_strategy'] = (string) ($payload['similarity_strategy'] ?? 'Uses generic style profile signals such as visual mode, density, CTA style, product-card pattern and homepage structure without copying source HTML/CSS/assets.');
        $payload['legal_safety_notes'] = (string) ($payload['legal_safety_notes'] ?? 'No source logos, texts, product photos, banners, remote images, brand names or CSS are included.');

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $basePreset
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function heuristicThemePayload(array $basePreset, array $options): array
    {
        return array_replace($basePreset, [
            'name' => trim((string) ($options['theme_name'] ?? '')) ?: 'AI Commerce Style Draft',
            'description' => 'Оригінальна token-based тема за універсальним style profile без копіювання assets.',
            'notes' => 'Generated with heuristic fallback because AI is disabled or unavailable.',
            'similarity_strategy' => 'Uses the classifier profile and selected generic preset as the source of truth; no source HTML, CSS, assets or brand text is reused.',
            'legal_safety_notes' => 'No source logos, texts, product photos, banners, remote images, brand names or CSS are included.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizeGeneratedNameForSource(array $payload, string $sourceUrl): array
    {
        $name = (string) ($payload['name'] ?? 'AI Commerce Style Draft');
        $host = parse_url($sourceUrl, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return $payload;
        }

        $fragments = collect(preg_split('/[.\-]+/', Str::lower($host)) ?: [])
            ->reject(fn (string $part): bool => in_array($part, ['www', 'shop', 'store', 'com', 'net', 'org', 'ua', 'co'], true))
            ->filter(fn (string $part): bool => mb_strlen($part) >= 4)
            ->values();

        foreach ($fragments as $fragment) {
            $name = preg_replace('/\b'.preg_quote($fragment, '/').'\b/i', 'Source', $name) ?? $name;
        }

        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        $payload['name'] = $name !== '' ? $name : 'AI Commerce Style Draft';

        return $payload;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'ai-theme';
        $slug = $base;
        $suffix = 2;

        while (StorefrontTheme::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You generate original ecommerce storefront themes for Alta-Trade Theme Engine.
Hard rules:
- Business domain is not the same as visual style. Do not infer dark automotive style just because the business sells auto goods.
- Extract a visual style fingerprint first, then generate a theme from that fingerprint.
- Do not clone the source website.
- Do not copy HTML, CSS, JavaScript, layout pixel-perfect structure, logos, images, banners, product photos, text, brand names, trademarks, or remote assets.
- Generate only structured ThemeSchema JSON: tokens, allowed layout presets, component variants, CSS variables.
- The result must be commercially similar in mood only, not a replica.
- Use safe hex colors, no remote URLs, no CSS imports, no scripts.
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $styleProfile
     * @param  array<string, mixed>  $basePreset
     * @param  array<string, mixed>  $options
     */
    private function userPrompt(string $sourceUrl, array $analysis, array $styleProfile, array $basePreset, array $options): string
    {
        return 'Source URL for safety context only: '.$sourceUrl."\n"
            .'Options: '.json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"
            .'Style analysis: '.json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"
            .'Business profile: '.json_encode($styleProfile['business_profile'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"
            .'Style fingerprint: '.json_encode($styleProfile['style_fingerprint'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"
            .'Style lock: '.json_encode($styleProfile['style_lock'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"
            .'Anti-clone constraints: '.json_encode($styleProfile['style_fingerprint']['anti_clone_constraints'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"
            .'Selected generic base preset: '.json_encode([
                'selected_preset' => $basePreset['selected_preset'] ?? null,
                'style_family' => $basePreset['style_family'] ?? null,
                'layout_config' => $basePreset['layout_config'] ?? null,
                'component_config' => $basePreset['component_config'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"
            .'Create an original Alta-Trade theme that refines the base preset while preserving the classifier visual mode, density, CTA style, product-card pattern and homepage structure. Avoid source brand/domain names, exact source text, logos, images, HTML and CSS.';
    }

    /**
     * @return array<string, mixed>
     */
    private function themePayloadSchema(): array
    {
        $colorProperties = collect(array_keys(ThemeSchema::defaultTokens()['colors']))
            ->mapWithKeys(fn (string $key): array => [$key => ['type' => 'string']])
            ->all();
        $cssVariableProperties = collect(array_keys(ThemeSchema::cssVariables(ThemeSchema::defaultTokens())))
            ->mapWithKeys(fn (string $key): array => [$key => ['type' => 'string']])
            ->all();

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'name' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'style_family' => ['type' => 'string'],
                'tokens' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'colors' => ['type' => 'object', 'additionalProperties' => false, 'properties' => $colorProperties, 'required' => array_keys($colorProperties)],
                        'typography' => $this->objectSchema([
                            'fontFamily' => ['type' => 'string'],
                            'headingFamily' => ['type' => 'string'],
                            'baseSize' => ['type' => 'string'],
                            'scale' => ['type' => 'number'],
                            'headingWeight' => ['type' => 'integer'],
                            'bodyWeight' => ['type' => 'integer'],
                            'letterSpacing' => ['type' => 'string'],
                        ]),
                        'radius' => $this->objectSchema([
                            'sm' => ['type' => 'string'],
                            'md' => ['type' => 'string'],
                            'lg' => ['type' => 'string'],
                            'xl' => ['type' => 'string'],
                            'full' => ['type' => 'string'],
                        ]),
                        'shadows' => $this->objectSchema([
                            'card' => ['type' => 'string'],
                            'dropdown' => ['type' => 'string'],
                            'hero' => ['type' => 'string'],
                        ]),
                        'spacing' => $this->objectSchema([
                            'sectionY' => ['type' => 'string'],
                            'containerX' => ['type' => 'string'],
                            'cardPadding' => ['type' => 'string'],
                            'gridGap' => ['type' => 'string'],
                        ]),
                        'buttons' => $this->objectSchema([
                            'radius' => ['type' => 'string'],
                            'weight' => ['type' => 'integer'],
                            'uppercase' => ['type' => 'boolean'],
                            'shadow' => ['type' => 'string'],
                        ]),
                        'badges' => $this->objectSchema([
                            'radius' => ['type' => 'string'],
                            'uppercase' => ['type' => 'boolean'],
                        ]),
                    ],
                    'required' => ['colors', 'typography', 'radius', 'shadows', 'spacing', 'buttons', 'badges'],
                ],
                'layout_config' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'headerVariant' => ['type' => 'string', 'enum' => ThemeSchema::HEADER_VARIANTS],
                        'topBarVariant' => ['type' => 'string', 'enum' => ThemeSchema::TOP_BAR_VARIANTS],
                        'heroVariant' => ['type' => 'string', 'enum' => ThemeSchema::HERO_VARIANTS],
                        'categoryGridVariant' => ['type' => 'string', 'enum' => ThemeSchema::CATEGORY_GRID_VARIANTS],
                        'productCardVariant' => ['type' => 'string', 'enum' => ThemeSchema::PRODUCT_CARD_VARIANTS],
                        'productPageVariant' => ['type' => 'string', 'enum' => ThemeSchema::PRODUCT_PAGE_VARIANTS],
                        'footerVariant' => ['type' => 'string', 'enum' => ThemeSchema::FOOTER_VARIANTS],
                        'containerWidth' => ['type' => 'string', 'enum' => ThemeSchema::CONTAINER_WIDTHS],
                        'density' => ['type' => 'string', 'enum' => ThemeSchema::DENSITIES],
                        'mobileNavVariant' => ['type' => 'string', 'enum' => ThemeSchema::MOBILE_NAV_VARIANTS],
                    ],
                    'required' => ['headerVariant', 'topBarVariant', 'heroVariant', 'categoryGridVariant', 'productCardVariant', 'productPageVariant', 'footerVariant', 'containerWidth', 'density', 'mobileNavVariant'],
                ],
                'component_config' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'showTopBar' => ['type' => 'boolean'],
                        'showSearch' => ['type' => 'boolean'],
                        'stickyHeader' => ['type' => 'boolean'],
                        'showCategoryMenu' => ['type' => 'boolean'],
                        'showBadges' => ['type' => 'boolean'],
                        'showBrandInCard' => ['type' => 'boolean'],
                        'showSkuInCard' => ['type' => 'boolean'],
                        'showQuickBuy' => ['type' => 'boolean'],
                        'showProductShortSpecs' => ['type' => 'boolean'],
                        'heroOverlay' => ['type' => 'boolean'],
                        'cardImageRatio' => ['type' => 'string', 'enum' => ThemeSchema::CARD_IMAGE_RATIOS],
                    ],
                    'required' => ['showTopBar', 'showSearch', 'stickyHeader', 'showCategoryMenu', 'showBadges', 'showBrandInCard', 'showSkuInCard', 'showQuickBuy', 'showProductShortSpecs', 'heroOverlay', 'cardImageRatio'],
                ],
                'css_variables' => ['type' => 'object', 'additionalProperties' => false, 'properties' => $cssVariableProperties, 'required' => array_keys($cssVariableProperties)],
                'custom_css' => ['type' => ['string', 'null']],
                'notes' => ['type' => 'string'],
                'similarity_strategy' => ['type' => 'string'],
                'legal_safety_notes' => ['type' => 'string'],
            ],
            'required' => ['name', 'description', 'style_family', 'tokens', 'layout_config', 'component_config', 'css_variables', 'custom_css', 'notes', 'similarity_strategy', 'legal_safety_notes'],
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function objectSchema(array $properties): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => $properties,
            'required' => array_keys($properties),
        ];
    }
}
