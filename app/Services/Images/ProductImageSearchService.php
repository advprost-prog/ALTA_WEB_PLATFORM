<?php

namespace App\Services\Images;

use App\Models\AiSetting;
use App\Models\Product;
use App\Models\ProductImageCandidate;
use App\Models\User;
use App\Services\Images\Providers\ConfigurableExternalSearchProvider;
use App\Services\Images\Providers\ManualUrlImageSearchProvider;
use App\Services\Images\Providers\SerpApiImageSearchProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProductImageSearchService
{
    public function __construct(
        private readonly ImageCandidateValidator $validator,
        private readonly ProductPageImageExtractor $pageExtractor,
    ) {
        //
    }

    /**
     * @param  array<string, mixed>  $options
     * @return Collection<int, ProductImageCandidate>
     */
    public function search(Product $product, User $user, array $options): Collection
    {
        $settings = AiSetting::getActive();
        $provider = $this->mode($options, $settings);
        $limit = max(1, min(10, (int) ($options['limit'] ?? $settings->image_search_max_candidates ?: 5)));

        $rawCandidates = match ($provider) {
            'auto_serpapi', 'serpapi' => app(SerpApiImageSearchProvider::class)->search($product, $this->rawCandidateLimit($provider, $limit)),
            'product_page_url', 'page_url' => $this->pageUrlCandidates($product, $options, $this->rawCandidateLimit($provider, $limit)),
            'direct_image_url', 'manual_url' => $this->manualProvider($options, $provider)->search($product, $limit),
            'external_stub' => app(ConfigurableExternalSearchProvider::class)->search($product, $limit),
            default => app(ConfigurableExternalSearchProvider::class)->search($product, $limit),
        };

        if ($rawCandidates === [] && in_array($provider, ['auto_serpapi', 'serpapi'], true)) {
            Log::info('Product image search returned no SerpAPI candidates.', [
                'product_id' => $product->id,
                'product_slug' => $product->slug,
                'provider' => $provider,
            ]);
        }

        $validatedCandidates = collect($rawCandidates)
            ->filter(fn (array $candidate): bool => filled($candidate['source_url'] ?? null) || filled($candidate['image_url'] ?? null))
            ->unique(fn (array $candidate): string => $this->candidateKey($candidate))
            ->sortByDesc(fn (array $candidate): int => (int) ($candidate['score'] ?? $candidate['quality_score'] ?? 0))
            ->take($this->rawCandidateLimit($provider, $limit))
            ->flatMap(function (array $candidate) use ($product, $provider): array {
                try {
                    return $this->validateCandidateWithFallback($product, $candidate, $provider);
                } catch (\Throwable $exception) {
                    return [$this->failedCandidate($candidate, $exception->getMessage())];
                }
            })
            ->unique(fn (array $candidate): string => $this->candidateKey($candidate))
            ->values();

        return $this->selectCandidatesForStorage($validatedCandidates, $provider, $limit)
            ->map(function (array $candidate) use ($product, $user): ProductImageCandidate {
                $sourceUrl = (string) ($candidate['source_url'] ?: $candidate['image_url']);
                $imageUrl = (string) ($candidate['image_url'] ?: $sourceUrl);
                $host = parse_url($sourceUrl, PHP_URL_HOST);

                return ProductImageCandidate::create([
                    'product_id' => $product->id,
                    'provider' => (string) ($candidate['provider'] ?? 'manual_url'),
                    'query' => $candidate['query'] ?? ($candidate['metadata']['query'] ?? null),
                    'source_url' => $sourceUrl,
                    'thumbnail_url' => $candidate['thumbnail_url'] ?? $imageUrl,
                    'image_url' => $imageUrl,
                    'source_domain' => $candidate['source_domain'] ?? (is_string($host) ? strtolower($host) : null),
                    'title' => $candidate['title'] ?? null,
                    'width' => $candidate['width'] ?? null,
                    'height' => $candidate['height'] ?? null,
                    'mime_type' => $candidate['mime_type'] ?? null,
                    'quality_score' => $candidate['quality_score'] ?? null,
                    'status' => $candidate['status'] ?? ProductImageCandidate::STATUS_PENDING,
                    'can_import' => (bool) ($candidate['can_import'] ?? false),
                    'warnings' => $candidate['warnings'] ?? [],
                    'license_note' => $candidate['license_note'] ?? null,
                    'rejection_reason' => $candidate['rejection_reason'] ?? null,
                    'metadata' => $candidate['metadata'] ?? [],
                    'created_by' => $user->id,
                ]);
            })
            ->values();
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function manualProvider(array $options, string $provider): ManualUrlImageSearchProvider
    {
        $urls = $options['direct_image_urls'] ?? ($options['manual_urls'] ?? []);

        if (is_string($urls)) {
            $urls = preg_split('/\R+/', $urls) ?: [];
        }

        return new ManualUrlImageSearchProvider(array_values(array_filter((array) $urls)), $provider === 'manual_url' ? 'manual_url' : 'direct_image_url');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<int, array<string, mixed>>
     */
    private function pageUrlCandidates(Product $product, array $options, int $limit): array
    {
        $urls = $options['page_urls'] ?? [];

        if (is_string($urls)) {
            $urls = preg_split('/\R+/', $urls) ?: [];
        }

        return collect((array) $urls)
            ->map(fn (string $url): string => trim($url))
            ->filter()
            ->unique()
            ->take(5)
            ->flatMap(function (string $url) use ($product): array {
                try {
                    return $this->pageExtractor->extract($url, $product);
                } catch (\Throwable $exception) {
                    $host = parse_url($url, PHP_URL_HOST);

                    return [[
                        'provider' => 'page_url',
                        'query' => null,
                        'source_url' => $url,
                        'thumbnail_url' => null,
                        'image_url' => $url,
                        'source_domain' => is_string($host) ? strtolower($host) : null,
                        'title' => null,
                        'width' => null,
                        'height' => null,
                        'mime_type' => null,
                        'score' => 0,
                        'warnings' => [$exception->getMessage()],
                        'license_note' => null,
                        'can_import' => false,
                        'rejection_reason' => $exception->getMessage(),
                        'metadata' => [
                            'mode' => 'page_url',
                            'page_url' => $url,
                            'extraction_failed' => true,
                        ],
                    ]];
                }
            })
            ->unique(fn (array $candidate): string => $this->candidateKey($candidate))
            ->sortByDesc(fn (array $candidate): int => (int) ($candidate['score'] ?? 0))
            ->take(max(1, min(10, $limit)))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<int, array<string, mixed>>
     */
    private function validateCandidateWithFallback(Product $product, array $candidate, string $provider): array
    {
        if (($candidate['status'] ?? null) === ProductImageCandidate::STATUS_REJECTED && ! (bool) ($candidate['can_import'] ?? false) && filled($candidate['rejection_reason'] ?? null)) {
            return [$candidate];
        }

        $validated = $this->validator->validate($product, $candidate);

        if (! in_array($provider, ['serpapi', 'auto_serpapi'], true) || ($validated['can_import'] ?? false)) {
            return [$validated];
        }

        $sourceUrl = (string) ($candidate['source_url'] ?? '');
        $imageUrl = (string) ($candidate['image_url'] ?? '');

        if ($sourceUrl === '' || $sourceUrl === $imageUrl || preg_match('/^https?:\/\//i', $sourceUrl) !== 1) {
            return [$validated];
        }

        if ((int) ($candidate['metadata']['serpapi_position'] ?? 999) > 15) {
            return [$validated];
        }

        try {
            $fallbacks = collect($this->pageExtractor->extract($sourceUrl, $product))
                ->map(function (array $fallback) use ($candidate, $imageUrl): array {
                    $fallback['provider'] = 'serpapi_source_page';
                    $fallback['query'] = $candidate['query'] ?? null;
                    $fallback['metadata'] = array_merge((array) ($fallback['metadata'] ?? []), [
                        'mode' => 'serpapi_source_page',
                        'fallback_from_image_url' => $imageUrl,
                        'fallback_from_rejection_reason' => $candidate['rejection_reason'] ?? null,
                    ]);

                    return $fallback;
                })
                ->map(fn (array $fallback): array => ($fallback['status'] ?? null) === ProductImageCandidate::STATUS_REJECTED && ! (bool) ($fallback['can_import'] ?? false) && filled($fallback['rejection_reason'] ?? null)
                    ? $fallback
                    : $this->validator->validate($product, $fallback))
                ->sortByDesc(fn (array $fallback): int => (int) ($fallback['quality_score'] ?? $fallback['score'] ?? 0))
                ->values();
        } catch (\Throwable $exception) {
            $validated['metadata'] = array_merge((array) ($validated['metadata'] ?? []), [
                'source_page_fallback_failed' => $exception->getMessage(),
            ]);

            return [$validated];
        }

        $bestImportable = $fallbacks->first(fn (array $fallback): bool => (bool) ($fallback['can_import'] ?? false));

        if (! is_array($bestImportable)) {
            return [$validated, ...$fallbacks->take(3)->all()];
        }

        $bestImportable['metadata'] = array_merge((array) ($bestImportable['metadata'] ?? []), [
            'source_page_fallback_used' => true,
        ]);

        return [$validated, $bestImportable];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @return Collection<int, array<string, mixed>>
     */
    private function selectCandidatesForStorage(Collection $candidates, string $provider, int $limit): Collection
    {
        $importable = $candidates
            ->filter(fn (array $candidate): bool => (bool) ($candidate['can_import'] ?? false))
            ->sortByDesc(fn (array $candidate): int => (int) ($candidate['quality_score'] ?? 0))
            ->take($limit)
            ->values();

        $debugLimit = in_array($provider, ['serpapi', 'auto_serpapi', 'page_url', 'product_page_url'], true) ? 25 : $limit;
        $debug = $candidates
            ->reject(fn (array $candidate): bool => (bool) ($candidate['can_import'] ?? false))
            ->take($debugLimit)
            ->values();

        return $importable
            ->merge($debug)
            ->unique(fn (array $candidate): string => $this->candidateKey($candidate))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function mode(array $options, AiSetting $settings): string
    {
        $mode = (string) ($options['mode'] ?? $options['provider'] ?? $settings->image_search_provider ?: 'manual_url');

        return match ($mode) {
            'auto', 'auto_serpapi', 'serpapi' => 'serpapi',
            'product_page_url', 'page_url' => 'page_url',
            'direct', 'direct_image_url', 'manual_url' => $mode,
            default => $mode,
        };
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function candidateKey(array $candidate): string
    {
        return sha1(Str::lower((string) ($candidate['image_url'] ?? '')).'|'.Str::lower((string) ($candidate['source_url'] ?? '')));
    }

    private function rawCandidateLimit(string $provider, int $limit): int
    {
        if (in_array($provider, ['serpapi', 'auto_serpapi'], true)) {
            return max(20, min(40, $limit * 8));
        }

        if (in_array($provider, ['page_url', 'product_page_url'], true)) {
            return max(10, min(30, $limit * 4));
        }

        return $limit;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function failedCandidate(array $candidate, string $message): array
    {
        $sourceUrl = (string) ($candidate['source_url'] ?? $candidate['image_url'] ?? '');
        $host = parse_url($sourceUrl, PHP_URL_HOST);

        return array_merge($candidate, [
            'source_url' => $sourceUrl,
            'image_url' => (string) ($candidate['image_url'] ?? $sourceUrl),
            'source_domain' => $candidate['source_domain'] ?? (is_string($host) ? strtolower($host) : null),
            'status' => ProductImageCandidate::STATUS_REJECTED,
            'can_import' => false,
            'quality_score' => 0,
            'rejection_reason' => 'connection_failed: '.$message,
            'warnings' => array_values(array_filter(array_merge((array) ($candidate['warnings'] ?? []), ['connection_failed: '.$message]))),
            'metadata' => array_merge((array) ($candidate['metadata'] ?? []), [
                'technical_validation' => 'failed',
                'rejection_category' => 'connection_failed',
            ]),
        ]);
    }
}
