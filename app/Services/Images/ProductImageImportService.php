<?php

namespace App\Services\Images;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductImageCandidate;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ProductImageImportService
{
    public function __construct(
        private readonly ImageDownloadService $downloadService,
        private readonly ImageConversionService $conversionService,
    ) {
        //
    }

    /**
     * @param  array<int, int>|Collection<int, ProductImageCandidate>  $candidates
     * @return array{imported: int, skipped: int, failed: int, errors: array<int, string>, images: array<int, ProductImage>, results: array<int, array<string, mixed>>}
     */
    public function importCandidates(Product $product, array|Collection $candidates, User $user, bool $setFirstAsMain = false): array
    {
        $selectedIds = $this->candidateIds($candidates);
        $records = $this->candidateRecords($product, $candidates);
        $result = [
            'imported' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
            'images' => [],
            'results' => [],
        ];
        $firstImported = null;
        $seenIds = [];

        foreach ($records as $candidate) {
            $seenIds[] = (int) $candidate->id;
            $import = $this->importCandidate($product, $candidate, $user);
            $candidateResult = $import['result'];
            $image = $import['image'];

            $result['results'][] = $candidateResult;
            $result[$candidateResult['status']]++;

            if ($image instanceof ProductImage) {
                if (! $firstImported) {
                    $firstImported = $image;
                }

                $result['images'][] = $image;
            }

            if ($candidateResult['status'] !== 'imported') {
                $result['errors'][] = '#'.$candidateResult['candidate_id'].' '.$candidateResult['reason'].': '.$candidateResult['message'];
            }
        }

        foreach (array_diff($selectedIds, $seenIds) as $missingId) {
            $candidateResult = $this->baseResult((int) $missingId);
            $candidateResult['status'] = 'skipped';
            $candidateResult['reason'] = 'candidate_not_found';
            $candidateResult['message'] = 'Кандидат не знайдено або він не належить цьому товару.';

            $result['results'][] = $candidateResult;
            $result['skipped']++;
            $result['errors'][] = '#'.$missingId.' candidate_not_found: '.$candidateResult['message'];
        }

        if ($setFirstAsMain && $firstImported) {
            $firstImported->setAsMain();
        }

        return $result;
    }

    /**
     * @return array{result: array<string, mixed>, image: ?ProductImage}
     */
    private function importCandidate(Product $product, ProductImageCandidate $candidate, User $user): array
    {
        $result = $this->baseResult($candidate);

        if ($candidate->product_id !== $product->id) {
            return $this->skipped($candidate, $result, 'wrong_product', 'Кандидат належить іншому товару.');
        }

        if (! $candidate->isImportable()) {
            return $this->skipped($candidate, $result, 'rejected_candidate', $candidate->rejection_reason ?: 'Кандидат не є придатним до імпорту.');
        }

        if ($candidate->status === ProductImageCandidate::STATUS_IMPORTED) {
            $result['product_image_id'] = $candidate->imported_product_image_id;

            return $this->skipped($candidate, $result, 'already_imported', 'Цей кандидат уже імпортовано.');
        }

        if ($this->sourceAlreadyImported($product, $candidate)) {
            return $this->skipped($candidate, $result, 'duplicate_source_url', 'Фото з таким source URL уже є в галереї товару.', markRejected: true);
        }

        try {
            $download = $this->downloadService->download($candidate->image_url);
        } catch (RuntimeException $exception) {
            return $this->failed($candidate, $result, 'download_failed', $exception->getMessage());
        } catch (Throwable) {
            return $this->failed($candidate, $result, 'download_failed', 'Не вдалося завантажити фото.');
        }

        $result['download_status'] = $download['http_status'] ?? null;
        $result['mime_type'] = $download['mime_type'] ?? null;
        $result['width'] = $download['width'] ?? null;
        $result['height'] = $download['height'] ?? null;

        $fileHash = hash('sha256', $download['body']);

        if (ProductImage::query()->where('product_id', $product->id)->where('file_hash', $fileHash)->exists()) {
            return $this->skipped($candidate, $result, 'duplicate_file_hash', 'Фото з таким file hash уже є в галереї товару.', markRejected: true);
        }

        try {
            $converted = $this->conversionService->convertToWebp($product, $download['body']);

            if (! Storage::disk('public')->exists($converted['path'])) {
                throw new RuntimeException('WebP файл не знайдено після конвертації.');
            }
        } catch (RuntimeException $exception) {
            return $this->failed($candidate, $result, 'conversion_failed', $exception->getMessage());
        } catch (Throwable) {
            return $this->failed($candidate, $result, 'conversion_failed', 'Не вдалося конвертувати фото у WebP.');
        }

        try {
            $image = DB::transaction(function () use ($product, $candidate, $user, $download, $fileHash, $converted): ProductImage {
                $sortOrder = (int) ProductImage::query()
                    ->where('product_id', $product->id)
                    ->max('sort_order') + 1;

                $image = ProductImage::create([
                    'product_id' => $product->id,
                    'image' => $converted['path'],
                    'alt' => $product->image_alt_text ?: $product->name,
                    'sort_order' => $sortOrder,
                    'source_url' => $candidate->source_url,
                    'source_domain' => $candidate->source_domain,
                    'imported_by' => $user->id,
                    'imported_at' => now(),
                    'quality_score' => $candidate->quality_score,
                    'metadata' => [
                        'candidate_id' => $candidate->id,
                        'provider' => $candidate->provider,
                        'candidate_source_url' => $candidate->source_url,
                        'candidate_image_url' => $candidate->image_url,
                        'warnings' => $candidate->warnings ?? [],
                        'original_mime_type' => $download['mime_type'],
                        'original_width' => $download['width'],
                        'original_height' => $download['height'],
                        'converted_width' => $converted['width'],
                        'converted_height' => $converted['height'],
                        'converted_size' => $converted['size'],
                    ],
                    'is_main' => false,
                    'file_hash' => $fileHash,
                ]);

                $candidate->forceFill([
                    'status' => ProductImageCandidate::STATUS_IMPORTED,
                    'imported_product_image_id' => $image->id,
                    'metadata' => array_merge((array) ($candidate->metadata ?? []), [
                        'import_result' => [
                            'status' => 'imported',
                            'reason' => 'imported',
                            'message' => 'Фото імпортовано.',
                            'product_image_id' => $image->id,
                            'storage_path' => $converted['path'],
                            'imported_at' => now()->toIso8601String(),
                        ],
                    ]),
                ])->save();

                return $image;
            });
        } catch (RuntimeException $exception) {
            return $this->failed($candidate, $result, 'save_failed', $exception->getMessage());
        } catch (Throwable) {
            return $this->failed($candidate, $result, 'save_failed', 'Не вдалося створити запис ProductImage.');
        }

        $result['status'] = 'imported';
        $result['reason'] = 'imported';
        $result['message'] = 'Фото імпортовано.';
        $result['storage_path'] = $converted['path'];
        $result['product_image_id'] = $image->id;

        return ['result' => $result, 'image' => $image];
    }

    /**
     * @param  array<int, int>|Collection<int, ProductImageCandidate>  $candidates
     * @return Collection<int, ProductImageCandidate>
     */
    private function candidateRecords(Product $product, array|Collection $candidates): Collection
    {
        if ($candidates instanceof Collection && $candidates->first() instanceof ProductImageCandidate) {
            return $candidates->values();
        }

        return ProductImageCandidate::query()
            ->whereIn('id', collect($candidates)->map(fn ($candidate): int => (int) $candidate)->all())
            ->get();
    }

    private function sourceAlreadyImported(Product $product, ProductImageCandidate $candidate): bool
    {
        $matches = ProductImage::query()
            ->where('product_id', $product->id)
            ->where('source_url', $candidate->source_url)
            ->get();

        if ($matches->isEmpty()) {
            return false;
        }

        if ($candidate->source_url === $candidate->image_url) {
            return true;
        }

        return $matches->contains(function (ProductImage $image) use ($candidate): bool {
            return (string) data_get($image->metadata, 'candidate_image_url') === (string) $candidate->image_url;
        });
    }

    /**
     * @param  array<int, int>|Collection<int, ProductImageCandidate>  $candidates
     * @return array<int, int>
     */
    private function candidateIds(array|Collection $candidates): array
    {
        return collect($candidates)
            ->map(fn ($candidate): int => $candidate instanceof ProductImageCandidate ? (int) $candidate->id : (int) $candidate)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function baseResult(ProductImageCandidate|int $candidate): array
    {
        if (is_int($candidate)) {
            return [
                'candidate_id' => $candidate,
                'source_domain' => null,
                'image_url' => null,
                'status' => 'skipped',
                'reason' => null,
                'message' => null,
                'download_status' => null,
                'mime_type' => null,
                'width' => null,
                'height' => null,
                'storage_path' => null,
                'product_image_id' => null,
            ];
        }

        return [
            'candidate_id' => $candidate->id,
            'source_domain' => $candidate->source_domain,
            'image_url' => $candidate->image_url,
            'status' => 'skipped',
            'reason' => null,
            'message' => null,
            'download_status' => null,
            'mime_type' => $candidate->mime_type,
            'width' => $candidate->width,
            'height' => $candidate->height,
            'storage_path' => null,
            'product_image_id' => $candidate->imported_product_image_id,
        ];
    }

    /**
     * @return array{result: array<string, mixed>, image: null}
     */
    private function skipped(ProductImageCandidate $candidate, array $result, string $reason, string $message, bool $markRejected = false): array
    {
        $result['status'] = 'skipped';
        $result['reason'] = $reason;
        $result['message'] = $message;

        $this->storeImportResult($candidate, $result, $markRejected ? ProductImageCandidate::STATUS_REJECTED : null, $markRejected ? false : null, $markRejected ? $message : null);

        return ['result' => $result, 'image' => null];
    }

    /**
     * @return array{result: array<string, mixed>, image: null}
     */
    private function failed(ProductImageCandidate $candidate, array $result, string $reason, string $message): array
    {
        $result['status'] = 'failed';
        $result['reason'] = $reason;
        $result['message'] = $message;

        $this->storeImportResult($candidate, $result, ProductImageCandidate::STATUS_FAILED, false, $reason.': '.$message);

        return ['result' => $result, 'image' => null];
    }

    private function storeImportResult(ProductImageCandidate $candidate, array $result, ?string $status = null, ?bool $canImport = null, ?string $rejectionReason = null): void
    {
        $attributes = [
            'metadata' => array_merge((array) ($candidate->metadata ?? []), [
                'import_result' => array_merge($result, [
                    'recorded_at' => now()->toIso8601String(),
                ]),
            ]),
        ];

        if ($status !== null) {
            $attributes['status'] = $status;
        }

        if ($canImport !== null) {
            $attributes['can_import'] = $canImport;
        }

        if ($rejectionReason !== null) {
            $attributes['rejection_reason'] = $rejectionReason;
        }

        $candidate->forceFill($attributes)->save();
    }
}
