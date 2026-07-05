<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\ProductImageCandidatesRelationManager;
use App\Models\AiSetting;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductImageCandidate;
use App\Services\Images\ImageConversionService;
use App\Services\Images\ImageDownloadService;
use App\Services\Images\ProductImageQueryBuilder;
use App\Services\Images\ProductImageImportService;
use App\Services\Images\ProductImageSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\Feature\Concerns\CreatesCommerceData;
use Tests\TestCase;

class ProductImagePickerTest extends TestCase
{
    use CreatesCommerceData;
    use RefreshDatabase;

    public function test_image_download_rejects_unsafe_urls(): void
    {
        $service = app(ImageDownloadService::class);

        foreach ([
            'file:///etc/passwd',
            'ftp://example.test/photo.jpg',
            'http://localhost/photo.jpg',
            'http://127.0.0.1/photo.jpg',
            'http://10.0.0.5/photo.jpg',
            'http://internal/photo.jpg',
        ] as $url) {
            try {
                $service->assertSafeUrl($url);
                $this->fail($url.' should have been rejected.');
            } catch (RuntimeException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_manual_url_provider_creates_pending_candidate_for_valid_image(): void
    {
        $this->configureImageSearch();
        $image = $this->fakePng();

        Http::fake([
            'cdn.example.test/*' => Http::response($image, 200, [
                'Content-Type' => 'image/png',
                'Content-Length' => strlen($image),
            ]),
        ]);

        $product = $this->createProduct();
        $user = $this->createUserWithRole(UserRole::Manager);

        $candidates = app(ProductImageSearchService::class)->search($product, $user, [
            'provider' => 'manual_url',
            'manual_urls' => 'https://cdn.example.test/product.png',
            'limit' => 5,
        ]);

        $candidate = $candidates->first();

        $this->assertInstanceOf(ProductImageCandidate::class, $candidate);
        $this->assertSame(ProductImageCandidate::STATUS_PENDING, $candidate->status);
        $this->assertTrue($candidate->can_import);
        $this->assertSame(800, $candidate->width);
        $this->assertSame(800, $candidate->height);
        $this->assertSame('image/png', $candidate->mime_type);
    }

    public function test_query_builder_generates_multiple_clean_product_image_queries(): void
    {
        $product = $this->createProduct([
            'name' => 'Castrol EDGE 5W-30 LL 4L купити Alta-Trade',
            'sku' => '15A568',
        ]);

        $queries = app(ProductImageQueryBuilder::class)->buildQueries($product);

        $this->assertGreaterThanOrEqual(3, count($queries));
        $this->assertContains('Bosch 15A568', $queries);
        $this->assertFalse(collect($queries)->contains(fn (string $query): bool => str_contains($query, 'купити') || str_contains($query, 'Alta-Trade')));
    }

    public function test_serpapi_provider_reads_image_results_and_creates_candidate(): void
    {
        $this->configureImageSearch(provider: 'serpapi', key: 'serp_test_key');
        $image = $this->fakePng();

        Http::fake(function ($request) use ($image) {
            if (str_contains($request->url(), 'serpapi.com/search')) {
                return Http::response([
                    'search_metadata' => ['status' => 'Success'],
                    'image_results' => [[
                        'position' => 1,
                        'title' => 'Bosch Castrol EDGE 5W-30 LL 4L',
                        'original' => 'https://images.example.test/castrol.png',
                        'thumbnail' => 'https://images.example.test/castrol-thumb.png',
                        'source' => 'Example',
                        'source_name' => 'Example Shop',
                        'link' => 'https://shop.example.test/castrol-edge',
                        'original_width' => 800,
                        'original_height' => 800,
                    ]],
                ], 200);
            }

            return Http::response($image, 200, [
                'Content-Type' => 'image/png',
                'Content-Length' => strlen($image),
            ]);
        });

        $product = $this->createProduct();
        $user = $this->createUserWithRole(UserRole::Manager);

        $candidates = app(ProductImageSearchService::class)->search($product, $user, [
            'provider' => 'serpapi',
            'limit' => 5,
        ]);

        $candidate = $candidates->first();

        $this->assertInstanceOf(ProductImageCandidate::class, $candidate);
        $this->assertSame('serpapi', $candidate->provider);
        $this->assertNotNull($candidate->query);
        $this->assertSame('https://images.example.test/castrol.png', $candidate->image_url);
        $this->assertTrue($candidate->can_import);
    }

    public function test_serpapi_provider_retries_without_tbs_when_first_attempt_has_zero_results(): void
    {
        $this->configureImageSearch(provider: 'serpapi', key: 'serp_test_key');
        $image = $this->fakePng();
        $serpApiCalls = 0;

        Http::fake(function ($request) use ($image, &$serpApiCalls) {
            if (str_contains($request->url(), 'serpapi.com/search')) {
                $serpApiCalls++;
                $query = method_exists($request, 'data') ? $request->data() : [];

                if ($query === []) {
                    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                }

                if (isset($query['tbs'])) {
                    return Http::response([
                        'search_metadata' => ['status' => 'Success'],
                        'image_results' => [],
                    ], 200);
                }

                return Http::response([
                    'search_metadata' => ['status' => 'Success'],
                    'images_results' => [[
                        'title' => 'Bosch fallback photo',
                        'original' => 'https://images.example.test/fallback.png',
                        'thumbnail' => 'https://images.example.test/fallback-thumb.png',
                        'link' => 'https://shop.example.test/fallback',
                    ]],
                ], 200);
            }

            return Http::response($image, 200, [
                'Content-Type' => 'image/png',
                'Content-Length' => strlen($image),
            ]);
        });

        $product = $this->createProduct();
        $user = $this->createUserWithRole(UserRole::Manager);

        $candidates = app(ProductImageSearchService::class)->search($product, $user, [
            'provider' => 'serpapi',
            'limit' => 1,
        ]);

        $this->assertGreaterThanOrEqual(2, $serpApiCalls);
        $this->assertSame('https://images.example.test/fallback.png', $candidates->first()?->image_url);
    }

    public function test_serpapi_http_468_candidate_is_rejected_and_not_importable(): void
    {
        $this->configureImageSearch(provider: 'serpapi', key: 'serp_test_key');

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'serpapi.com/search')) {
                return Http::response([
                    'search_metadata' => ['status' => 'Success'],
                    'image_results' => [[
                        'title' => 'Blocked Castrol photo',
                        'original' => 'https://blocked.example.test/castrol.png',
                        'thumbnail' => 'https://blocked.example.test/castrol-thumb.png',
                    ]],
                ], 200);
            }

            return Http::response('', 468, ['Content-Type' => 'text/plain']);
        });

        $product = $this->createProduct();
        $user = $this->createUserWithRole(UserRole::Manager);

        $candidates = app(ProductImageSearchService::class)->search($product, $user, [
            'provider' => 'serpapi',
            'limit' => 5,
        ]);

        $candidate = $candidates->first();

        $this->assertInstanceOf(ProductImageCandidate::class, $candidate);
        $this->assertSame(ProductImageCandidate::STATUS_REJECTED, $candidate->status);
        $this->assertFalse($candidate->can_import);
        $this->assertSame(0, ProductImageCandidate::query()->importable()->count());
        $this->assertSame(1, ProductImageCandidate::query()->rejected()->count());
        $this->assertSame('HTTP 468', data_get($candidate->metadata, 'rejection_category'));
    }

    public function test_image_url_connection_timeout_becomes_rejected_candidate(): void
    {
        $this->configureImageSearch();

        Http::fake(function () {
            throw new ConnectionException('Timeout was reached');
        });

        $product = $this->createProduct();
        $user = $this->createUserWithRole(UserRole::Manager);

        $candidate = app(ProductImageSearchService::class)->search($product, $user, [
            'provider' => 'direct_image_url',
            'direct_image_urls' => 'https://cdn.example.test/timeout.png',
            'limit' => 1,
        ])->first();

        $this->assertInstanceOf(ProductImageCandidate::class, $candidate);
        $this->assertSame(ProductImageCandidate::STATUS_REJECTED, $candidate->status);
        $this->assertFalse($candidate->can_import);
        $this->assertSame('connection_timeout', data_get($candidate->metadata, 'rejection_category'));
    }

    public function test_source_page_timeout_does_not_stop_serpapi_search(): void
    {
        $this->configureImageSearch(provider: 'serpapi', key: 'serp_test_key');
        $image = $this->fakePng();

        Http::fake(function ($request) use ($image) {
            if (str_contains($request->url(), 'serpapi.com/search')) {
                return Http::response([
                    'search_metadata' => ['status' => 'Success'],
                    'image_results' => [
                        [
                            'title' => 'Blocked original timeout page',
                            'original' => 'https://blocked.example.test/first.png',
                            'thumbnail' => 'https://blocked.example.test/first-thumb.png',
                            'link' => 'https://timeout.example.test/product',
                        ],
                        [
                            'title' => 'Valid second photo',
                            'original' => 'https://images.example.test/valid.png',
                            'thumbnail' => 'https://images.example.test/valid-thumb.png',
                            'link' => 'https://shop.example.test/product',
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'https://blocked.example.test/first.png') {
                return Http::response('', 468, ['Content-Type' => 'text/plain']);
            }

            if ($request->url() === 'https://timeout.example.test/product') {
                throw new ConnectionException('Timeout was reached');
            }

            return Http::response($image, 200, [
                'Content-Type' => 'image/png',
                'Content-Length' => strlen($image),
            ]);
        });

        $product = $this->createProduct();
        $user = $this->createUserWithRole(UserRole::Manager);

        $candidates = app(ProductImageSearchService::class)->search($product, $user, [
            'provider' => 'serpapi',
            'limit' => 1,
        ]);

        $this->assertTrue($candidates->contains(fn (ProductImageCandidate $candidate): bool => $candidate->isImportable() && $candidate->image_url === 'https://images.example.test/valid.png'));
        $this->assertTrue($candidates->contains(fn (ProductImageCandidate $candidate): bool => data_get($candidate->metadata, 'rejection_category') === 'connection_timeout'));
    }

    public function test_serpapi_keeps_scanning_raw_pool_after_first_rejected_results(): void
    {
        $this->configureImageSearch(provider: 'serpapi', key: 'serp_test_key');
        $image = $this->fakePng();

        Http::fake(function ($request) use ($image) {
            if (str_contains($request->url(), 'serpapi.com/search')) {
                $blocked = collect(range(1, 10))
                    ->map(fn (int $index): array => [
                        'position' => $index,
                        'title' => 'Bosch blocked photo '.$index,
                        'original' => 'https://blocked-'.$index.'.example.test/photo.png',
                        'thumbnail' => 'https://blocked-'.$index.'.example.test/thumb.png',
                    ])
                    ->all();

                return Http::response([
                    'search_metadata' => ['status' => 'Success'],
                    'image_results' => [
                        ...$blocked,
                        [
                            'position' => 11,
                            'title' => 'Bosch valid photo',
                            'original' => 'https://images.example.test/valid-after-blocked.png',
                            'thumbnail' => 'https://images.example.test/valid-after-blocked-thumb.png',
                        ],
                    ],
                ], 200);
            }

            if (str_contains($request->url(), 'blocked-')) {
                return Http::response('', 468, ['Content-Type' => 'text/plain']);
            }

            return Http::response($image, 200, [
                'Content-Type' => 'image/png',
                'Content-Length' => strlen($image),
            ]);
        });

        $product = $this->createProduct();
        $user = $this->createUserWithRole(UserRole::Manager);

        $candidates = app(ProductImageSearchService::class)->search($product, $user, [
            'provider' => 'serpapi',
            'limit' => 1,
        ]);

        $this->assertTrue($candidates->contains(fn (ProductImageCandidate $candidate): bool => $candidate->isImportable() && $candidate->image_url === 'https://images.example.test/valid-after-blocked.png'));
        $this->assertSame(1, ProductImageCandidate::query()->importable()->count());
        $this->assertGreaterThanOrEqual(10, ProductImageCandidate::query()->rejected()->count());
    }

    public function test_blocked_domains_are_rejected_before_http_request(): void
    {
        $this->configureImageSearch();
        $requests = 0;

        Http::fake(function () use (&$requests) {
            $requests++;

            return Http::response('', 200);
        });

        $product = $this->createProduct();
        $user = $this->createUserWithRole(UserRole::Manager);

        $candidates = app(ProductImageSearchService::class)->search($product, $user, [
            'provider' => 'direct_image_url',
            'direct_image_urls' => "https://market.yandex.ru/product.jpg\nhttps://cdn.example.ru/product.jpg\nhttps://cdn.example.by/product.jpg",
            'limit' => 3,
        ]);

        $this->assertSame(0, $requests);
        $this->assertCount(3, $candidates);
        $this->assertTrue($candidates->every(fn (ProductImageCandidate $candidate): bool => $candidate->status === ProductImageCandidate::STATUS_REJECTED));
        $this->assertTrue($candidates->every(fn (ProductImageCandidate $candidate): bool => data_get($candidate->metadata, 'rejection_category') === 'blocked_domain'));
    }

    public function test_blocked_serpapi_source_domain_is_rejected_before_image_download(): void
    {
        $this->configureImageSearch(provider: 'serpapi', key: 'serp_test_key');
        $imageDownloads = 0;

        Http::fake(function ($request) use (&$imageDownloads) {
            if (str_contains($request->url(), 'serpapi.com/search')) {
                return Http::response([
                    'search_metadata' => ['status' => 'Success'],
                    'image_results' => [[
                        'title' => 'Bosch market image',
                        'original' => 'https://avatars.mds.yandex.net/product.png',
                        'thumbnail' => 'https://avatars.mds.yandex.net/thumb.png',
                        'link' => 'https://market.yandex.ru/product/bosch',
                    ]],
                ], 200);
            }

            if (str_contains($request->url(), 'avatars.mds.yandex.net')) {
                $imageDownloads++;
            }

            return Http::response($this->fakePng(), 200, [
                'Content-Type' => 'image/png',
                'Content-Length' => strlen($this->fakePng()),
            ]);
        });

        $product = $this->createProduct();
        $user = $this->createUserWithRole(UserRole::Manager);

        $candidates = app(ProductImageSearchService::class)->search($product, $user, [
            'provider' => 'serpapi',
            'limit' => 1,
        ]);

        $this->assertSame(0, $imageDownloads);
        $this->assertTrue($candidates->contains(fn (ProductImageCandidate $candidate): bool => data_get($candidate->metadata, 'rejection_category') === 'blocked_domain'));
        $this->assertSame(0, ProductImageCandidate::query()->importable()->count());
    }

    public function test_serpapi_blocked_original_falls_back_to_source_page_image(): void
    {
        $this->configureImageSearch(provider: 'serpapi', key: 'serp_test_key');
        $image = $this->fakePng();
        $html = '<html><head><meta property="og:image" content="/images/source-product.png"></head><body></body></html>';

        Http::fake(function ($request) use ($image, $html) {
            if (str_contains($request->url(), 'serpapi.com/search')) {
                return Http::response([
                    'search_metadata' => ['status' => 'Success'],
                    'image_results' => [[
                        'title' => 'Blocked original with useful page',
                        'original' => 'https://blocked.example.test/castrol.png',
                        'thumbnail' => 'https://blocked.example.test/thumb.png',
                        'link' => 'https://shop.example.test/product',
                    ]],
                ], 200);
            }

            if ($request->url() === 'https://blocked.example.test/castrol.png') {
                return Http::response('', 468, ['Content-Type' => 'text/plain']);
            }

            if ($request->url() === 'https://shop.example.test/product') {
                return Http::response($html, 200, ['Content-Type' => 'text/html']);
            }

            if ($request->url() === 'https://shop.example.test/images/source-product.png') {
                return Http::response($image, 200, [
                    'Content-Type' => 'image/png',
                    'Content-Length' => strlen($image),
                ]);
            }

            return Http::response('', 404);
        });

        $product = $this->createProduct();
        $user = $this->createUserWithRole(UserRole::Manager);

        $candidates = app(ProductImageSearchService::class)->search($product, $user, [
            'provider' => 'serpapi',
            'limit' => 5,
        ]);

        $this->assertTrue($candidates->contains(fn (ProductImageCandidate $candidate): bool => $candidate->isRejectedForImport() && $candidate->image_url === 'https://blocked.example.test/castrol.png'));
        $importable = $candidates->first(fn (ProductImageCandidate $candidate): bool => $candidate->isImportable());

        $this->assertInstanceOf(ProductImageCandidate::class, $importable);
        $this->assertSame('serpapi_source_page', $importable->provider);
        $this->assertSame('https://shop.example.test/images/source-product.png', $importable->image_url);
        $this->assertTrue((bool) data_get($importable->metadata, 'source_page_fallback_used'));
    }

    public function test_product_page_url_mode_extracts_html_images(): void
    {
        $this->configureImageSearch();
        $image = $this->fakePng();
        $html = <<<'HTML'
            <html>
                <head>
                    <title>Castrol EDGE 5W-30 LL 4L</title>
                    <meta property="og:image" content="/images/og-product.png">
                    <meta name="twitter:image" content="https://shop.example.test/images/twitter-product.png">
                    <script type="application/ld+json">{"@type":"Product","image":["https://shop.example.test/images/jsonld-product.png"]}</script>
                </head>
                <body>
                    <img src="/images/logo.svg" width="100" height="100">
                    <img data-src="/images/data-product.png" width="800" height="800" alt="Castrol product">
                    <picture><source srcset="/images/small.png 200w, /images/srcset-product.png 900w"></picture>
                </body>
            </html>
        HTML;

        Http::fake([
            'shop.example.test/product' => Http::response($html, 200, ['Content-Type' => 'text/html']),
            'shop.example.test/images/*' => Http::response($image, 200, [
                'Content-Type' => 'image/png',
                'Content-Length' => strlen($image),
            ]),
        ]);

        $product = $this->createProduct();
        $user = $this->createUserWithRole(UserRole::Manager);

        $candidates = app(ProductImageSearchService::class)->search($product, $user, [
            'provider' => 'page_url',
            'page_urls' => 'https://shop.example.test/product',
            'limit' => 10,
        ]);

        $this->assertGreaterThanOrEqual(4, $candidates->where('can_import', true)->count());
        $this->assertTrue($candidates->contains(fn (ProductImageCandidate $candidate): bool => $candidate->image_url === 'https://shop.example.test/images/og-product.png'));
        $this->assertFalse($candidates->contains(fn (ProductImageCandidate $candidate): bool => str_contains($candidate->image_url, 'logo.svg')));
    }

    public function test_text_html_in_direct_image_mode_gets_operator_friendly_message(): void
    {
        $this->configureImageSearch();

        Http::fake([
            'shop.example.test/product' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $product = $this->createProduct();
        $user = $this->createUserWithRole(UserRole::Manager);

        $candidate = app(ProductImageSearchService::class)->search($product, $user, [
            'provider' => 'direct_image_url',
            'direct_image_urls' => 'https://shop.example.test/product',
            'limit' => 1,
        ])->first();

        $this->assertInstanceOf(ProductImageCandidate::class, $candidate);
        $this->assertSame(ProductImageCandidate::STATUS_REJECTED, $candidate->status);
        $this->assertStringContainsString('HTML-сторінка', (string) $candidate->rejection_reason);
    }

    public function test_candidate_validation_rejects_non_image_and_too_large_file(): void
    {
        $this->configureImageSearch(maxMb: 1);

        Http::fake([
            'cdn.example.test/not-image' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html']),
            'cdn.example.test/too-large.png' => Http::response('', 200, [
                'Content-Type' => 'image/png',
                'Content-Length' => (2 * 1024 * 1024),
            ]),
        ]);

        $product = $this->createProduct();
        $user = $this->createUserWithRole(UserRole::Manager);

        $candidates = app(ProductImageSearchService::class)->search($product, $user, [
            'provider' => 'manual_url',
            'manual_urls' => "https://cdn.example.test/not-image\nhttps://cdn.example.test/too-large.png",
            'limit' => 5,
        ]);

        $this->assertCount(2, $candidates);
        $this->assertTrue($candidates->every(fn (ProductImageCandidate $candidate): bool => $candidate->status === ProductImageCandidate::STATUS_REJECTED));
        $this->assertTrue($candidates->every(fn (ProductImageCandidate $candidate): bool => $candidate->can_import === false));
    }

    public function test_small_image_is_rejected_and_excluded_from_importable_scope(): void
    {
        $this->configureImageSearch();
        $small = $this->fakePng(265, 265);

        Http::fake([
            'cdn.example.test/small.png' => Http::response($small, 200, [
                'Content-Type' => 'image/png',
                'Content-Length' => strlen($small),
            ]),
        ]);

        $product = $this->createProduct();
        $user = $this->createUserWithRole(UserRole::Manager);

        $candidate = app(ProductImageSearchService::class)->search($product, $user, [
            'provider' => 'direct_image_url',
            'direct_image_urls' => 'https://cdn.example.test/small.png',
            'limit' => 1,
        ])->first();

        $this->assertInstanceOf(ProductImageCandidate::class, $candidate);
        $this->assertSame(ProductImageCandidate::STATUS_REJECTED, $candidate->status);
        $this->assertSame(0, ProductImageCandidate::query()->importable()->count());
        $this->assertSame(1, ProductImageCandidate::query()->rejected()->count());
        $this->assertSame('too small', data_get($candidate->metadata, 'rejection_category'));
    }

    public function test_image_search_diagnostics_groups_rejected_reasons(): void
    {
        $this->configureImageSearch(provider: 'serpapi', key: 'serp_test_key');

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'serpapi.com/search')) {
                return Http::response([
                    'search_metadata' => ['status' => 'Success'],
                    'image_results' => [[
                        'title' => 'Blocked photo',
                        'original' => 'https://blocked.example.test/photo.png',
                        'thumbnail' => 'https://blocked.example.test/thumb.png',
                    ]],
                ], 200);
            }

            return Http::response('', 468, ['Content-Type' => 'text/plain']);
        });

        $product = $this->createProduct();

        $this->artisan('alta:image-search-test', [
            'productSlug' => $product->slug,
            '--limit' => 1,
        ])
            ->expectsOutputToContain('valid_importable_count: 0')
            ->expectsOutputToContain('rejected_count: 1')
            ->expectsOutputToContain('HTTP 468: 1')
            ->assertSuccessful();
    }

    public function test_image_download_rejects_redirect_to_private_ip(): void
    {
        Http::fake([
            'safe.example.test/photo.jpg' => Http::response('', 302, ['Location' => 'http://127.0.0.1/private.jpg']),
        ]);

        $this->expectException(RuntimeException::class);

        app(ImageDownloadService::class)->download('https://safe.example.test/photo.jpg');
    }

    public function test_import_converts_candidate_to_local_gallery_image_without_setting_remote_main_image(): void
    {
        Storage::fake('public');
        $this->configureImageSearch();
        $this->bindFakeConversionService();
        $image = $this->fakePng();

        Http::fake([
            'cdn.example.test/*' => Http::response($image, 200, [
                'Content-Type' => 'image/png',
                'Content-Length' => strlen($image),
            ]),
        ]);

        $product = $this->createProduct(['main_image' => null]);
        $user = $this->createUserWithRole(UserRole::Manager);
        $candidate = app(ProductImageSearchService::class)->search($product, $user, [
            'provider' => 'manual_url',
            'manual_urls' => 'https://cdn.example.test/product.png',
        ])->first();

        $this->assertInstanceOf(ProductImageCandidate::class, $candidate);

        $result = app(ProductImageImportService::class)
            ->importCandidates($product, [$candidate->id], $user, false);

        $imageRecord = ProductImage::firstOrFail();

        $this->assertSame(1, $result['imported']);
        $this->assertStringEndsWith('.webp', $imageRecord->image);
        $this->assertSame('https://cdn.example.test/product.png', $imageRecord->source_url);
        $this->assertSame('cdn.example.test', $imageRecord->source_domain);
        $this->assertSame($user->id, $imageRecord->imported_by);
        $this->assertNotNull($imageRecord->imported_at);
        $this->assertNotNull($imageRecord->file_hash);
        $this->assertNull($product->fresh()->main_image);
        $this->assertFalse(str_starts_with((string) $imageRecord->image, 'http'));
    }

    public function test_duplicate_source_url_and_file_hash_are_not_imported_twice(): void
    {
        Storage::fake('public');
        $this->configureImageSearch();
        $this->bindFakeConversionService();
        $image = $this->fakePng();

        Http::fake([
            'cdn.example.test/*' => Http::response($image, 200, [
                'Content-Type' => 'image/png',
                'Content-Length' => strlen($image),
            ]),
        ]);

        $product = $this->createProduct();
        $user = $this->createUserWithRole(UserRole::Manager);
        $candidates = app(ProductImageSearchService::class)->search($product, $user, [
            'provider' => 'manual_url',
            'manual_urls' => "https://cdn.example.test/product-a.png\nhttps://cdn.example.test/product-b.png",
        ]);

        $import = app(ProductImageImportService::class);
        $first = $import->importCandidates($product, [$candidates[0]->id], $user);
        $duplicateHash = $import->importCandidates($product, [$candidates[1]->id], $user);
        $duplicateSource = $import->importCandidates($product, [$candidates[0]->fresh()->id], $user);

        $this->assertSame(1, $first['imported']);
        $this->assertSame(0, $duplicateHash['imported']);
        $this->assertSame(0, $duplicateSource['imported']);
        $this->assertSame('duplicate_file_hash', $duplicateHash['results'][0]['reason']);
        $this->assertSame('rejected_candidate', $duplicateSource['results'][0]['reason']);
        $this->assertSame(1, ProductImage::count());
    }

    public function test_import_conversion_failure_returns_detailed_result(): void
    {
        Storage::fake('public');
        $this->configureImageSearch();
        $this->bindFailingConversionService('GD WebP support unavailable');
        $image = $this->fakePng();

        Http::fake([
            'cdn.example.test/*' => Http::response($image, 200, [
                'Content-Type' => 'image/png',
                'Content-Length' => strlen($image),
            ]),
        ]);

        $product = $this->createProduct();
        $user = $this->createUserWithRole(UserRole::Manager);
        $candidate = app(ProductImageSearchService::class)->search($product, $user, [
            'provider' => 'manual_url',
            'manual_urls' => 'https://cdn.example.test/product.png',
        ])->first();

        $result = app(ProductImageImportService::class)
            ->importCandidates($product, [$candidate->id], $user);

        $candidate->refresh();

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame('conversion_failed', $result['results'][0]['reason']);
        $this->assertStringContainsString('GD WebP support unavailable', $result['results'][0]['message']);
        $this->assertSame(ProductImageCandidate::STATUS_FAILED, $candidate->status);
        $this->assertSame('conversion_failed', data_get($candidate->metadata, 'import_result.reason'));
        $this->assertSame(0, ProductImage::count());
    }

    public function test_import_download_failure_returns_detailed_result(): void
    {
        Storage::fake('public');
        $this->configureImageSearch();
        $this->bindFakeConversionService();

        $product = $this->createProduct();
        $user = $this->createUserWithRole(UserRole::Manager);
        $candidate = $this->createImportableCandidate($product, [
            'source_url' => 'https://cdn.example.test/product.png',
            'image_url' => 'https://cdn.example.test/product.png',
        ]);

        Http::fake([
            'cdn.example.test/*' => Http::response('', 403, ['Content-Type' => 'text/plain']),
        ]);

        $result = app(ProductImageImportService::class)
            ->importCandidates($product, [$candidate->id], $user);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame('download_failed', $result['results'][0]['reason']);
        $this->assertStringContainsString('HTTP 403', $result['results'][0]['message']);
        $this->assertSame(ProductImageCandidate::STATUS_FAILED, $candidate->fresh()->status);
    }

    public function test_duplicate_source_url_returns_explicit_reason(): void
    {
        Storage::fake('public');
        $this->configureImageSearch();
        $this->bindFakeConversionService();
        $image = $this->fakePng();

        Http::fake([
            'cdn.example.test/*' => Http::response($image, 200, [
                'Content-Type' => 'image/png',
                'Content-Length' => strlen($image),
            ]),
        ]);

        $product = $this->createProduct();
        $user = $this->createUserWithRole(UserRole::Manager);
        $candidate = $this->createImportableCandidate($product, [
            'source_url' => 'https://cdn.example.test/product.png',
            'image_url' => 'https://cdn.example.test/product.png',
        ]);

        app(ProductImageImportService::class)->importCandidates($product, [$candidate->id], $user);

        $duplicate = $this->createImportableCandidate($product, [
            'source_url' => 'https://cdn.example.test/product.png',
            'image_url' => 'https://cdn.example.test/product.png',
        ]);

        $result = app(ProductImageImportService::class)
            ->importCandidates($product, [$duplicate->id], $user);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame('duplicate_source_url', $result['results'][0]['reason']);
        $this->assertSame(ProductImageCandidate::STATUS_REJECTED, $duplicate->fresh()->status);
    }

    public function test_image_import_test_command_dry_run_does_not_create_product_image(): void
    {
        Storage::fake('public');
        $this->configureImageSearch();
        $this->bindFakeConversionService();
        $image = $this->fakePng();

        Http::fake([
            'cdn.example.test/*' => Http::response($image, 200, [
                'Content-Type' => 'image/png',
                'Content-Length' => strlen($image),
            ]),
        ]);

        $product = $this->createProduct();
        $candidate = $this->createImportableCandidate($product, [
            'source_url' => 'https://cdn.example.test/product.png',
            'image_url' => 'https://cdn.example.test/product.png',
        ]);

        $this->artisan('alta:image-import-test', [
            'candidateId' => $candidate->id,
        ])
            ->expectsOutputToContain('candidate_id: '.$candidate->id)
            ->expectsOutputToContain('dry_run: ProductImage was not created.')
            ->assertSuccessful();

        $this->assertSame(0, ProductImage::count());
    }

    public function test_image_import_test_command_import_creates_product_image(): void
    {
        Storage::fake('public');
        $this->configureImageSearch();
        $this->bindFakeConversionService();
        $image = $this->fakePng();

        Http::fake([
            'cdn.example.test/*' => Http::response($image, 200, [
                'Content-Type' => 'image/png',
                'Content-Length' => strlen($image),
            ]),
        ]);

        $product = $this->createProduct();
        $this->createUserWithRole(UserRole::Admin);
        $candidate = $this->createImportableCandidate($product, [
            'source_url' => 'https://cdn.example.test/product.png',
            'image_url' => 'https://cdn.example.test/product.png',
        ]);

        $this->artisan('alta:image-import-test', [
            'candidateId' => $candidate->id,
            '--import' => true,
        ])
            ->expectsOutputToContain('import_result:')
            ->expectsOutputToContain(' - imported: 1')
            ->assertSuccessful();

        $this->assertSame(1, ProductImage::count());
        $this->assertSame(ProductImageCandidate::STATUS_IMPORTED, $candidate->fresh()->status);
    }

    public function test_candidate_relation_manager_exposes_preview_single_and_batch_import_actions(): void
    {
        $product = $this->createProduct();
        $user = $this->createUserWithRole(UserRole::Manager);
        $candidate = $this->createImportableCandidate($product);

        Livewire::actingAs($user)
            ->test(ProductImageCandidatesRelationManager::class, [
                'ownerRecord' => $product,
                'pageClass' => EditProduct::class,
            ])
            ->assertTableActionExists('preview', null, $candidate)
            ->assertTableActionVisible('preview', $candidate)
            ->assertTableActionExists('import', null, $candidate)
            ->assertTableActionVisible('import', $candidate)
            ->assertTableBulkActionExists('importSelected');
    }

    public function test_rejected_candidate_can_be_restored_to_approved_from_relation_manager(): void
    {
        $product = $this->createProduct();
        $user = $this->createUserWithRole(UserRole::Manager);
        $candidate = $this->createImportableCandidate($product, [
            'status' => ProductImageCandidate::STATUS_REJECTED,
            'can_import' => false,
            'quality_score' => 0,
            'rejection_reason' => 'Відхилено оператором.',
        ]);

        Livewire::actingAs($user)
            ->test(ProductImageCandidatesRelationManager::class, [
                'ownerRecord' => $product,
                'pageClass' => EditProduct::class,
            ])
            ->set('activeTab', 'rejected')
            ->assertTableActionExists('restoreRejected', null, $candidate)
            ->assertTableActionVisible('restoreRejected', $candidate)
            ->callTableAction('restoreRejected', $candidate);

        $candidate->refresh();

        $this->assertSame(ProductImageCandidate::STATUS_APPROVED, $candidate->status);
        $this->assertTrue($candidate->can_import);
        $this->assertNull($candidate->rejection_reason);
        $this->assertGreaterThanOrEqual(50, $candidate->quality_score);
        $this->assertNotNull(data_get($candidate->metadata, 'operator_restore.restored_at'));
        $this->assertTrue($candidate->isImportable());
    }

    public function test_candidates_can_be_bulk_rejected_without_touching_imported_or_already_rejected(): void
    {
        $product = $this->createProduct();
        $user = $this->createUserWithRole(UserRole::Manager);
        $first = $this->createImportableCandidate($product);
        $second = $this->createImportableCandidate($product, [
            'image_url' => 'https://cdn.example.test/product-2.png',
            'source_url' => 'https://cdn.example.test/product-2.png',
            'status' => ProductImageCandidate::STATUS_APPROVED,
        ]);
        $imported = $this->createImportableCandidate($product, [
            'image_url' => 'https://cdn.example.test/imported.png',
            'source_url' => 'https://cdn.example.test/imported.png',
            'status' => ProductImageCandidate::STATUS_IMPORTED,
            'can_import' => false,
        ]);
        $alreadyRejected = $this->createImportableCandidate($product, [
            'image_url' => 'https://cdn.example.test/rejected.png',
            'source_url' => 'https://cdn.example.test/rejected.png',
            'status' => ProductImageCandidate::STATUS_REJECTED,
            'can_import' => false,
            'rejection_reason' => 'Відхилено раніше.',
        ]);

        Livewire::actingAs($user)
            ->test(ProductImageCandidatesRelationManager::class, [
                'ownerRecord' => $product,
                'pageClass' => EditProduct::class,
            ])
            ->set('activeTab', 'all')
            ->assertTableBulkActionExists('rejectSelected')
            ->callTableBulkAction('rejectSelected', collect([$first, $second, $imported, $alreadyRejected]));

        foreach ([$first, $second] as $candidate) {
            $candidate->refresh();

            $this->assertSame(ProductImageCandidate::STATUS_REJECTED, $candidate->status);
            $this->assertFalse($candidate->can_import);
            $this->assertSame('Відхилено оператором.', $candidate->rejection_reason);
            $this->assertNotNull(data_get($candidate->metadata, 'operator_reject.rejected_at'));
        }

        $this->assertSame(ProductImageCandidate::STATUS_IMPORTED, $imported->fresh()->status);
        $this->assertSame('Відхилено раніше.', $alreadyRejected->fresh()->rejection_reason);
    }

    public function test_rejected_candidate_can_be_deleted_from_relation_manager(): void
    {
        $product = $this->createProduct();
        $user = $this->createUserWithRole(UserRole::Manager);
        $candidate = $this->createImportableCandidate($product, [
            'status' => ProductImageCandidate::STATUS_REJECTED,
            'can_import' => false,
            'rejection_reason' => 'Відхилено оператором.',
        ]);

        Livewire::actingAs($user)
            ->test(ProductImageCandidatesRelationManager::class, [
                'ownerRecord' => $product,
                'pageClass' => EditProduct::class,
            ])
            ->set('activeTab', 'rejected')
            ->assertTableActionExists('deleteRejected', null, $candidate)
            ->assertTableActionVisible('deleteRejected', $candidate)
            ->callTableAction('deleteRejected', $candidate);

        $this->assertDatabaseMissing('product_image_candidates', [
            'id' => $candidate->id,
        ]);
    }

    public function test_rejected_candidates_can_be_bulk_deleted_without_touching_importable_candidates(): void
    {
        $product = $this->createProduct();
        $user = $this->createUserWithRole(UserRole::Manager);
        $firstRejected = $this->createImportableCandidate($product, [
            'status' => ProductImageCandidate::STATUS_REJECTED,
            'can_import' => false,
            'rejection_reason' => 'Відхилено оператором.',
        ]);
        $secondRejected = $this->createImportableCandidate($product, [
            'status' => ProductImageCandidate::STATUS_FAILED,
            'can_import' => false,
            'rejection_reason' => 'download_failed: HTTP 403',
        ]);
        $importable = $this->createImportableCandidate($product, [
            'image_url' => 'https://cdn.example.test/keep.png',
            'source_url' => 'https://cdn.example.test/keep.png',
        ]);

        Livewire::actingAs($user)
            ->test(ProductImageCandidatesRelationManager::class, [
                'ownerRecord' => $product,
                'pageClass' => EditProduct::class,
            ])
            ->set('activeTab', 'all')
            ->assertTableBulkActionExists('deleteRejectedSelected')
            ->callTableBulkAction('deleteRejectedSelected', collect([$firstRejected, $secondRejected, $importable]));

        $this->assertDatabaseMissing('product_image_candidates', [
            'id' => $firstRejected->id,
        ]);
        $this->assertDatabaseMissing('product_image_candidates', [
            'id' => $secondRejected->id,
        ]);
        $this->assertDatabaseHas('product_image_candidates', [
            'id' => $importable->id,
            'status' => ProductImageCandidate::STATUS_PENDING,
            'can_import' => true,
        ]);
    }

    public function test_imported_gallery_image_can_be_set_as_main_and_storefront_uses_it(): void
    {
        Storage::fake('public');
        $this->configureImageSearch();
        $this->bindFakeConversionService();
        $image = $this->fakePng();

        Http::fake([
            'cdn.example.test/*' => Http::response($image, 200, [
                'Content-Type' => 'image/png',
                'Content-Length' => strlen($image),
            ]),
        ]);

        $product = $this->createProduct(['main_image' => null]);
        $user = $this->createUserWithRole(UserRole::Manager);
        $candidate = app(ProductImageSearchService::class)->search($product, $user, [
            'provider' => 'manual_url',
            'manual_urls' => 'https://cdn.example.test/product.png',
        ])->first();

        $this->assertInstanceOf(ProductImageCandidate::class, $candidate);

        app(ProductImageImportService::class)
            ->importCandidates($product, [$candidate->id], $user, true);

        $imageRecord = ProductImage::firstOrFail();
        $product->refresh();

        $this->assertTrue($imageRecord->fresh()->is_main);
        $this->assertSame($imageRecord->image, $product->main_image);
        $this->assertStringContainsString('/storage/'.$imageRecord->image, $product->image_url);
        $this->assertFalse(str_starts_with((string) $product->main_image, 'http'));

        $second = $product->images()->create([
            'image' => 'product-gallery/'.$product->id.'/second.webp',
            'alt' => 'Second',
            'sort_order' => 2,
        ]);

        $second->setAsMain();

        $this->assertTrue($second->fresh()->is_main);
        $this->assertFalse($imageRecord->fresh()->is_main);
        $this->assertSame(1, ProductImage::where('product_id', $product->id)->where('is_main', true)->count());
        $this->assertSame($second->image, $product->fresh()->main_image);
    }

    public function test_product_image_url_falls_back_to_gallery_main_image(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('product-gallery/1/main.webp', 'webp');

        $product = $this->createProduct(['main_image' => null]);
        $galleryImage = $product->images()->create([
            'image' => 'product-gallery/1/main.webp',
            'alt' => 'Main',
            'sort_order' => 1,
            'is_main' => true,
        ]);

        $this->assertStringContainsString('/storage/'.$galleryImage->image, $product->fresh()->image_url);
    }

    private function configureImageSearch(int $maxMb = 5, string $provider = 'manual_url', ?string $key = null): void
    {
        $settings = AiSetting::getActive();
        $settings->forceFill([
            'image_search_enabled' => true,
            'image_search_provider' => $provider,
            'image_search_safe_mode' => true,
            'image_search_max_candidates' => 5,
            'image_search_min_width' => 600,
            'image_search_min_height' => 600,
            'image_search_max_download_size_mb' => $maxMb,
            'allow_manual_url_candidates' => true,
        ]);

        if ($key !== null) {
            $settings->image_search_api_key = $key;
        }

        $settings->save();
    }

    private function bindFakeConversionService(): void
    {
        app()->instance(ImageConversionService::class, new class extends ImageConversionService
        {
            public function capabilities(): array
            {
                return [
                    'gd_loaded' => true,
                    'imagecreatefromstring' => true,
                    'imagewebp' => true,
                    'imagetypes_webp' => true,
                    'webp_supported' => true,
                ];
            }

            public function canConvertToWebp(): bool
            {
                return true;
            }

            public function probeWebpConversion(string $bytes): array
            {
                return [
                    'width' => 800,
                    'height' => 800,
                    'mime_type' => 'image/webp',
                    'size' => strlen($bytes),
                ];
            }

            public function convertToWebp(Product $product, string $bytes, string $directory = 'product-gallery'): array
            {
                $path = trim($directory, '/').'/'.$product->id.'/'.$product->slug.'-test.webp';
                Storage::disk('public')->put($path, 'webp');

                return [
                    'path' => $path,
                    'width' => 800,
                    'height' => 800,
                    'mime_type' => 'image/webp',
                    'size' => 4,
                ];
            }
        });
    }

    private function bindFailingConversionService(string $message): void
    {
        app()->instance(ImageConversionService::class, new class($message) extends ImageConversionService
        {
            public function __construct(private readonly string $message)
            {
                //
            }

            public function convertToWebp(Product $product, string $bytes, string $directory = 'product-gallery'): array
            {
                throw new RuntimeException($this->message);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createImportableCandidate(Product $product, array $attributes = []): ProductImageCandidate
    {
        return ProductImageCandidate::create(array_merge([
            'product_id' => $product->id,
            'provider' => 'direct_image_url',
            'source_url' => 'https://cdn.example.test/product.png',
            'thumbnail_url' => 'https://cdn.example.test/product.png',
            'image_url' => 'https://cdn.example.test/product.png',
            'source_domain' => 'cdn.example.test',
            'title' => $product->name,
            'width' => 800,
            'height' => 800,
            'mime_type' => 'image/png',
            'quality_score' => 80,
            'status' => ProductImageCandidate::STATUS_PENDING,
            'can_import' => true,
            'warnings' => [],
            'license_note' => null,
            'rejection_reason' => null,
            'metadata' => [],
            'created_by' => null,
        ], $attributes));
    }

    private function fakePng(int $width = 800, int $height = 800): string
    {
        $ihdr = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
        $scanline = "\0".str_repeat("\0", $width * 3);
        $idat = gzcompress(str_repeat($scanline, $height));

        return "\x89PNG\r\n\x1a\n"
            .$this->pngChunk('IHDR', $ihdr)
            .$this->pngChunk('IDAT', $idat)
            .$this->pngChunk('IEND', '');
    }

    private function pngChunk(string $type, string $data): string
    {
        $payload = $type.$data;

        return pack('N', strlen($data)).$payload.pack('N', crc32($payload));
    }
}
