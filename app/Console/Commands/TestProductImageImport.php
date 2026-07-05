<?php

namespace App\Console\Commands;

use App\Models\ProductImage;
use App\Models\ProductImageCandidate;
use App\Services\Images\ImageConversionService;
use App\Services\Images\ImageDownloadService;
use App\Services\Images\ProductImageImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class TestProductImageImport extends Command
{
    protected $signature = 'alta:image-import-test {candidateId} {--import} {--set-main}';

    protected $description = 'Diagnose one product image candidate import without exposing secrets.';

    public function handle(
        ImageDownloadService $downloadService,
        ImageConversionService $conversionService,
        ProductImageImportService $importService,
    ): int {
        $candidate = ProductImageCandidate::query()
            ->with(['product.brand', 'product.category'])
            ->find((int) $this->argument('candidateId'));

        if (! $candidate) {
            $this->error('Candidate not found: '.$this->argument('candidateId'));

            return self::FAILURE;
        }

        $product = $candidate->product;

        $this->line('candidate_id: '.$candidate->id);
        $this->line('product_id: '.$product?->id);
        $this->line('product_slug: '.($product?->slug ?? '-'));
        $this->line('product_name: '.($product?->name ?? '-'));
        $this->line('source_domain: '.($candidate->source_domain ?? '-'));
        $this->line('source_url: '.($candidate->source_url ?? '-'));
        $this->line('image_url: '.($candidate->image_url ?? '-'));
        $this->line('status: '.($candidate->status ?? '-'));
        $this->line('can_import: '.($candidate->can_import ? 'yes' : 'no'));
        $this->line('quality_score: '.($candidate->quality_score ?? '-'));

        $this->newLine();
        $this->line('webp_capabilities:');

        foreach ($conversionService->capabilities() as $key => $value) {
            $this->line(' - '.$key.': '.($value ? 'yes' : 'no'));
        }

        $download = null;

        try {
            $downloadService->assertSafeUrl($candidate->source_url ?: $candidate->image_url);
            $downloadService->assertSafeUrl($candidate->image_url);
            $this->line('url_safety: ok');
        } catch (RuntimeException $exception) {
            $this->line('url_safety: failed');
            $this->line('url_safety_reason: '.$exception->getMessage());
        }

        try {
            $download = $downloadService->download($candidate->image_url);
            $this->line('download_status: '.$download['http_status']);
            $this->line('download_mime_type: '.($download['mime_type'] ?? '-'));
            $this->line('download_dimensions: '.(($download['width'] ?? '-').'x'.($download['height'] ?? '-')));
            $this->line('download_size: '.$download['size']);
        } catch (RuntimeException $exception) {
            $this->line('download_status: failed');
            $this->line('download_reason: '.$exception->getMessage());
        } catch (Throwable) {
            $this->line('download_status: failed');
            $this->line('download_reason: unexpected download failure');
        }

        if ($product) {
            $sourceDuplicate = ProductImage::query()
                ->where('product_id', $product->id)
                ->where('source_url', $candidate->source_url)
                ->exists();

            $this->line('duplicate_source_url: '.($sourceDuplicate ? 'yes' : 'no'));
        }

        if ($download && $product) {
            $fileHash = hash('sha256', $download['body']);
            $hashDuplicate = ProductImage::query()
                ->where('product_id', $product->id)
                ->where('file_hash', $fileHash)
                ->exists();

            $this->line('duplicate_file_hash: '.($hashDuplicate ? 'yes' : 'no'));

            try {
                $probe = $conversionService->probeWebpConversion($download['body']);
                $this->line('webp_probe: ok');
                $this->line('webp_probe_dimensions: '.$probe['width'].'x'.$probe['height']);
                $this->line('webp_probe_size: '.$probe['size']);
            } catch (RuntimeException $exception) {
                $this->line('webp_probe: failed');
                $this->line('webp_probe_reason: '.$exception->getMessage());
            }
        }

        if (! $this->option('import')) {
            $this->warn('dry_run: ProductImage was not created. Pass --import to import this candidate.');

            return self::SUCCESS;
        }

        if (! $product) {
            $this->error('Cannot import: candidate product is missing.');

            return self::FAILURE;
        }

        $user = auth()->user();

        if (! $user) {
            $user = \App\Models\User::query()->where('role', 'admin')->first()
                ?? \App\Models\User::query()->first();
        }

        if (! $user) {
            $this->error('Cannot import: no user found for imported_by.');

            return self::FAILURE;
        }

        $result = $importService->importCandidates($product, [$candidate->id], $user, (bool) $this->option('set-main'));
        $first = $result['results'][0] ?? [];

        $this->newLine();
        $this->line('import_result:');
        $this->line(' - imported: '.$result['imported']);
        $this->line(' - skipped: '.$result['skipped']);
        $this->line(' - failed: '.$result['failed']);
        $this->line(' - candidate_status: '.($first['status'] ?? '-'));
        $this->line(' - reason: '.($first['reason'] ?? '-'));
        $this->line(' - message: '.($first['message'] ?? '-'));
        $this->line(' - product_image_id: '.($first['product_image_id'] ?? '-'));
        $this->line(' - storage_path: '.($first['storage_path'] ?? '-'));

        if (filled($first['storage_path'] ?? null)) {
            $this->line(' - public_url: '.Storage::disk('public')->url((string) $first['storage_path']));
        }

        return $result['imported'] > 0 ? self::SUCCESS : self::FAILURE;
    }
}
