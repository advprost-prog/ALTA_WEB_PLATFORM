<?php

namespace App\Console\Commands;

use App\Models\AiSetting;
use App\Models\Product;
use App\Services\Images\ImageCandidateValidator;
use App\Services\Images\ProductImageQueryBuilder;
use App\Services\Images\Providers\SerpApiImageSearchProvider;
use Illuminate\Console\Command;

class TestProductImageSearch extends Command
{
    protected $signature = 'alta:image-search-test {productSlug} {--raw} {--query=} {--limit=5} {--show-rejected}';

    protected $description = 'Diagnose SerpAPI Google Images product photo search without exposing secrets.';

    public function handle(
        ProductImageQueryBuilder $queryBuilder,
        SerpApiImageSearchProvider $provider,
        ImageCandidateValidator $validator,
    ): int {
        $slug = (string) $this->argument('productSlug');
        $product = Product::query()
            ->with(['brand', 'category'])
            ->where('slug', $slug)
            ->first();

        if (! $product) {
            $this->error('Product not found: '.$slug);

            return self::FAILURE;
        }

        $settings = AiSetting::getActive();
        $limit = max(1, min(10, (int) $this->option('limit')));
        $query = trim((string) $this->option('query'));
        $queries = $query !== '' ? [$query] : $queryBuilder->buildQueries($product);

        $this->line('product_slug: '.$product->slug);
        $this->line('product_name: '.$product->name);
        $this->line('brand: '.($product->brand?->name ?? '-'));
        $this->line('provider: serpapi');
        $this->line('serpapi_key_set: '.($settings->hasImageSearchApiKey() ? 'yes' : 'no'));
        $this->line('generated_queries:');

        foreach ($queries as $item) {
            $this->line(' - '.$item);
        }

        $rawCandidateLimit = max(20, min(40, $limit * 8));
        $result = $provider->searchWithDiagnostics($product, $queries, $rawCandidateLimit);

        $this->newLine();
        $this->line('serpapi_diagnostics:');

        foreach ($result['diagnostics'] as $diagnostic) {
            $this->line(' - query: '.($diagnostic['query'] ?? '-'));
            $this->line('   with_tbs: '.(($diagnostic['with_tbs'] ?? false) ? 'yes' : 'no'));
            $this->line('   http_status: '.($diagnostic['http_status'] ?? '-'));
            $this->line('   response_error: '.($diagnostic['response_error'] ?? '-'));
            $this->line('   search_metadata.status: '.($diagnostic['search_metadata_status'] ?? '-'));
            $this->line('   top_level_keys: '.implode(', ', (array) ($diagnostic['top_level_keys'] ?? [])));
            $this->line('   selected_result_key: '.($diagnostic['selected_result_key'] ?? '-'));
            $this->line('   alternate_result_counts: '.json_encode($diagnostic['alternate_result_counts'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->line('   image_results_count: '.($diagnostic['image_results_count'] ?? 0));
            $this->line('   first_result_keys: '.implode(', ', (array) ($diagnostic['first_result_keys'] ?? [])));

            foreach (array_slice((array) ($diagnostic['candidate_preview'] ?? []), 0, 3) as $index => $preview) {
                $this->line('   candidate_preview_'.($index + 1).': '.json_encode($preview, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }

            if (filled($diagnostic['provider_error'] ?? null)) {
                $this->line('   provider_error: '.$diagnostic['provider_error']);
            }
        }

        $validated = collect($result['candidates'])
            ->map(fn (array $candidate): array => $validator->validate($product, $candidate))
            ->values();
        $valid = $validated->filter(fn (array $candidate): bool => (bool) ($candidate['can_import'] ?? false))->values();
        $review = $valid->filter(fn (array $candidate): bool => filled($candidate['warnings'] ?? []))->values();
        $rejected = $validated->reject(fn (array $candidate): bool => (bool) ($candidate['can_import'] ?? false))->values();
        $rejectedReasons = $rejected
            ->groupBy(fn (array $candidate): string => (string) data_get($candidate, 'metadata.rejection_category', $candidate['rejection_reason'] ?? 'unknown'))
            ->map->count()
            ->sortDesc();

        $this->newLine();
        $this->line('raw_results_count: '.collect($result['diagnostics'])->sum(fn (array $diagnostic): int => (int) ($diagnostic['image_results_count'] ?? 0)));
        $this->line('candidates_created: '.count($result['candidates']));
        $this->line('valid_importable_count: '.$valid->count());
        $this->line('review_count: '.$review->count());
        $this->line('rejected_count: '.$rejected->count());

        if ($rejectedReasons->isNotEmpty()) {
            $this->line('top_rejected_reasons:');

            foreach ($rejectedReasons->take(8) as $reason => $count) {
                $this->line(' - '.$reason.': '.$count);
            }
        }

        if ($valid->isEmpty()) {
            $this->warn('valid_importable_count=0: SerpAPI знайшов зображення, але жодне не пройшло перевірку імпорту. Перевірте rejected reasons або спробуйте URL сторінки товару.');
        }

        foreach ($valid->take(5) as $index => $candidate) {
            $this->line('valid_candidate_'.($index + 1).':');
            $this->line('  title: '.($candidate['title'] ?? '-'));
            $this->line('  source: '.($candidate['source_domain'] ?? '-'));
            $this->line('  original: '.($candidate['image_url'] ?? '-'));
            $this->line('  thumbnail: '.($candidate['thumbnail_url'] ?? '-'));
            $this->line('  source_url: '.($candidate['source_url'] ?? '-'));
            $this->line('  dimensions: '.(($candidate['width'] ?? '-') .'x'. ($candidate['height'] ?? '-')));
            $this->line('  can_import: '.(($candidate['can_import'] ?? false) ? 'yes' : 'no'));
            $this->line('  rejection_reason: '.($candidate['rejection_reason'] ?? '-'));
        }

        if ($this->option('show-rejected')) {
            $this->newLine();
            $this->line('rejected_preview:');

            foreach ($rejected->take(10) as $index => $candidate) {
                $sourceDomain = $candidate['source_domain'] ?? parse_url((string) ($candidate['source_url'] ?? ''), PHP_URL_HOST) ?: '-';

                $this->line('rejected_'.($index + 1).':');
                $this->line('  source: '.$sourceDomain);
                $this->line('  image_url: '.($candidate['image_url'] ?? '-'));
                $this->line('  source_url: '.($candidate['source_url'] ?? '-'));
                $this->line('  http_status: '.(data_get($candidate, 'metadata.http_status') ?? '-'));
                $this->line('  reason: '.(data_get($candidate, 'metadata.rejection_category') ?? ($candidate['rejection_reason'] ?? '-')));
                $this->line('  message: '.($candidate['rejection_reason'] ?? '-'));
            }
        }

        if ($this->option('raw')) {
            $this->newLine();
            $this->line('safe_raw_summary:');
            $this->line(json_encode([
                'product' => [
                    'slug' => $product->slug,
                    'name' => $product->name,
                    'brand' => $product->brand?->name,
                ],
                'provider' => 'serpapi',
                'key_set' => $settings->hasImageSearchApiKey(),
                    'queries' => $queries,
                    'diagnostics' => $result['diagnostics'],
                    'candidate_count' => count($result['candidates']),
                    'valid_importable_count' => $valid->count(),
                    'review_count' => $review->count(),
                    'rejected_count' => $rejected->count(),
                    'rejected_reasons' => $rejectedReasons->all(),
                    'validated_preview' => $valid->take(3)->map(fn (array $candidate): array => [
                        'title' => $candidate['title'] ?? null,
                        'source_domain' => $candidate['source_domain'] ?? null,
                        'image_url' => $candidate['image_url'] ?? null,
                    'thumbnail_url' => $candidate['thumbnail_url'] ?? null,
                    'can_import' => $candidate['can_import'] ?? false,
                    'rejection_reason' => $candidate['rejection_reason'] ?? null,
                ])->values()->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
