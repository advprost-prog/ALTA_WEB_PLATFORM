<?php

namespace App\Services\Images\Providers;

use App\Models\AiSetting;
use App\Models\Product;
use App\Services\Images\Contracts\ImageSearchProvider;
use App\Services\Images\ProductImageQueryBuilder;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class SerpApiImageSearchProvider implements ImageSearchProvider
{
    public function __construct(private readonly ProductImageQueryBuilder $queryBuilder)
    {
        //
    }

    public function search(Product $product, int $limit = 5): array
    {
        return $this->searchWithDiagnostics($product, $this->queryBuilder->buildQueries($product), $limit)['candidates'];
    }

    /**
     * @param  array<int, string>  $queries
     * @return array{queries: array<int, string>, candidates: array<int, array<string, mixed>>, diagnostics: array<int, array<string, mixed>>, provider_error: ?string}
     */
    public function searchWithDiagnostics(Product $product, array $queries, int $limit = 5): array
    {
        $settings = AiSetting::getActive();
        $queries = collect($queries)
            ->map(fn (string $query): string => trim($query))
            ->filter()
            ->unique(fn (string $query): string => Str::lower($query))
            ->values()
            ->all();

        if ($queries === []) {
            $queries = $this->queryBuilder->buildQueries($product);
        }

        if (! $settings->hasImageSearchApiKey()) {
            return [
                'queries' => $queries,
                'candidates' => [],
                'diagnostics' => [[
                    'query' => null,
                    'http_status' => null,
                    'image_results_count' => 0,
                    'provider_error' => 'SerpAPI key is not configured.',
                ]],
                'provider_error' => 'SerpAPI key is not configured.',
            ];
        }

        $candidates = [];
        $diagnostics = [];

        foreach ($queries as $query) {
            $attempt = $this->requestQuery($settings, $query, withTbs: true);
            $diagnostics[] = $attempt['diagnostic'];

            if (($attempt['diagnostic']['image_results_count'] ?? 0) === 0 && blank($attempt['diagnostic']['response_error'] ?? null)) {
                $fallback = $this->requestQuery($settings, $query, withTbs: false);
                $diagnostics[] = $fallback['diagnostic'];

                if (($fallback['diagnostic']['image_results_count'] ?? 0) > 0 || filled($fallback['diagnostic']['response_error'] ?? null)) {
                    $attempt = $fallback;
                }
            }

            foreach ($attempt['results'] as $position => $result) {
                $candidate = $this->candidateFromResult($product, $query, $result, $position + 1);

                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }
        }

        $candidates = collect($candidates)
            ->unique(fn (array $candidate): string => $this->candidateKey($candidate))
            ->sortByDesc(fn (array $candidate): int => (int) ($candidate['score'] ?? 0))
            ->take(max(1, min(50, $limit)))
            ->values()
            ->all();

        return [
            'queries' => $queries,
            'candidates' => $candidates,
            'diagnostics' => $diagnostics,
            'provider_error' => collect($diagnostics)->pluck('provider_error')->filter()->first(),
        ];
    }

    /**
     * @return array{results: array<int, array<string, mixed>>, diagnostic: array<string, mixed>}
     */
    private function requestQuery(AiSetting $settings, string $query, bool $withTbs): array
    {
        $params = [
            'engine' => 'google_images',
            'q' => $query,
            'api_key' => (string) $settings->image_search_api_key,
            'ijn' => 0,
            'hl' => 'uk',
            'gl' => 'ua',
            'safe' => $settings->image_search_safe_mode ? 'active' : 'off',
        ];

        if ($withTbs) {
            $params['tbs'] = 'itp:photos,isz:l';
        }

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->get($this->endpoint(), $params);

            $json = $response->json();
            $json = is_array($json) ? $json : [];
            [$resultKey, $results] = $this->imageResults($json);

            return [
                'results' => $results,
                'diagnostic' => $this->diagnostic($query, $withTbs, $response, $json, $results, $resultKey),
            ];
        } catch (Throwable $exception) {
            return [
                'results' => [],
                'diagnostic' => [
                    'query' => $query,
                    'with_tbs' => $withTbs,
                    'endpoint' => $this->endpoint(),
                    'http_status' => null,
                    'response_error' => null,
                    'search_metadata_status' => null,
                    'top_level_keys' => [],
                    'selected_result_key' => null,
                    'alternate_result_counts' => [],
                    'image_results_count' => 0,
                    'first_result_keys' => [],
                    'candidate_preview' => [],
                    'provider_error' => $this->redactSecrets($exception->getMessage()),
                ],
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $json
     * @param  array<int, array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    private function diagnostic(string $query, bool $withTbs, Response $response, array $json, array $results, string $resultKey): array
    {
        return [
            'query' => $query,
            'with_tbs' => $withTbs,
            'endpoint' => $this->endpoint(),
            'http_status' => $response->status(),
            'response_error' => is_scalar($json['error'] ?? null) ? (string) $json['error'] : null,
            'search_metadata_status' => Arr::get($json, 'search_metadata.status'),
            'top_level_keys' => array_slice(array_keys($json), 0, 20),
            'selected_result_key' => $resultKey,
            'alternate_result_counts' => [
                'image_results' => is_countable($json['image_results'] ?? null) ? count($json['image_results']) : null,
                'images_results' => is_countable($json['images_results'] ?? null) ? count($json['images_results']) : null,
                'images' => is_countable($json['images'] ?? null) ? count($json['images']) : null,
                'items' => is_countable($json['items'] ?? null) ? count($json['items']) : null,
                'results' => is_countable($json['results'] ?? null) ? count($json['results']) : null,
                'organic_results' => is_countable($json['organic_results'] ?? null) ? count($json['organic_results']) : null,
                'shopping_results' => is_countable($json['shopping_results'] ?? null) ? count($json['shopping_results']) : null,
            ],
            'image_results_count' => count($results),
            'first_result_keys' => array_slice(array_keys($results[0] ?? []), 0, 12),
            'candidate_preview' => collect($results)
                ->take(3)
                ->map(fn (array $result): array => [
                    'title' => $result['title'] ?? null,
                    'source' => $result['source'] ?? ($result['source_name'] ?? null),
                    'original' => $result['original'] ?? null,
                    'thumbnail' => $result['thumbnail'] ?? null,
                    'link' => $result['link'] ?? null,
                ])
                ->values()
                ->all(),
            'provider_error' => $response->successful() ? null : 'SerpAPI HTTP '.$response->status(),
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>|null
     */
    private function candidateFromResult(Product $product, string $query, array $result, int $position): ?array
    {
        $original = $this->stringValue($result['original'] ?? null);
        $thumbnail = $this->stringValue($result['thumbnail'] ?? null);
        $link = $this->stringValue($result['link'] ?? null);
        $imageUrl = $original ?: $thumbnail;

        if ($imageUrl === '') {
            return null;
        }

        $sourceUrl = $link ?: $imageUrl;
        $host = parse_url($sourceUrl, PHP_URL_HOST) ?: parse_url($imageUrl, PHP_URL_HOST);
        $sourceName = $this->stringValue($result['source_name'] ?? null) ?: $this->stringValue($result['source'] ?? null);
        $title = $this->stringValue($result['title'] ?? null);
        $warnings = [];

        if ($original === '' && $thumbnail !== '') {
            $warnings[] = 'SerpAPI не повернув original URL; thumbnail використано як preview/import fallback.';
        }

        return [
            'provider' => 'serpapi',
            'query' => $query,
            'source_url' => $sourceUrl,
            'thumbnail_url' => $thumbnail ?: $imageUrl,
            'image_url' => $imageUrl,
            'title' => $title ?: null,
            'source_domain' => is_string($host) ? strtolower($host) : null,
            'width' => $this->intValue($result['original_width'] ?? ($result['width'] ?? null)),
            'height' => $this->intValue($result['original_height'] ?? ($result['height'] ?? null)),
            'mime_type' => null,
            'score' => $this->score($product, $title.' '.$sourceUrl.' '.$imageUrl, $original !== ''),
            'warnings' => $warnings,
            'license_note' => 'Фото знайдено через SerpAPI Google Images; права має підтвердити оператор.',
            'can_import' => false,
            'rejection_reason' => null,
            'metadata' => [
                'mode' => 'serpapi',
                'query' => $query,
                'serpapi_position' => $this->intValue($result['position'] ?? null) ?: $position,
                'serpapi_source' => $sourceName ?: null,
                'has_original' => $original !== '',
                'result_keys' => array_slice(array_keys($result), 0, 20),
            ],
        ];
    }

    private function score(Product $product, string $haystack, bool $hasOriginal): int
    {
        $haystack = Str::lower($haystack);
        $score = $hasOriginal ? 78 : 58;

        foreach ([$product->brand?->name, $product->sku, $product->name] as $needle) {
            $needle = Str::lower(trim((string) $needle));

            if ($needle !== '' && str_contains($haystack, $needle)) {
                $score += $needle === Str::lower((string) $product->name) ? 8 : 6;
            }
        }

        foreach (['watermark', 'logo', 'icon', 'banner', 'placeholder', 'sprite'] as $needle) {
            if (str_contains($haystack, $needle)) {
                $score -= 18;
            }
        }

        return max(1, min(100, $score));
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function intValue(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function imageResults(array $json): array
    {
        $key = is_array($json['image_results'] ?? null)
            ? 'image_results'
            : (is_array($json['images_results'] ?? null) ? 'images_results' : 'image_results');

        return [
            $key,
            array_values(array_filter((array) ($json[$key] ?? []), 'is_array')),
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function candidateKey(array $candidate): string
    {
        return sha1(Str::lower((string) ($candidate['image_url'] ?? '')).'|'.Str::lower((string) ($candidate['source_url'] ?? '')));
    }

    private function endpoint(): string
    {
        return (string) config('ai.image_search.serpapi_endpoint', 'https://serpapi.com/search');
    }

    private function redactSecrets(string $value): string
    {
        return preg_replace('/([?&]api_key=)[^&\s]+/i', '$1[redacted]', $value) ?? $value;
    }
}
