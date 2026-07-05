<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Storage;

trait ResolvesImageUrls
{
    protected function resolveImageUrl(?string $path, string $placeholder): string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return asset($placeholder);
        }

        if (preg_match('/^https?:\/\//i', $path) === 1) {
            return $path;
        }

        if (str_starts_with($path, '//') || str_contains($path, "\0")) {
            return asset($placeholder);
        }

        $normalizedPath = ltrim($path, '/');

        if ($normalizedPath === '') {
            return asset($placeholder);
        }

        if (str_starts_with($normalizedPath, 'images/')) {
            return asset($normalizedPath);
        }

        if (str_starts_with($normalizedPath, 'storage/')) {
            return $this->publicStoragePathExists($normalizedPath) ? asset($normalizedPath) : asset($placeholder);
        }

        if ($this->publicStoragePathExists($normalizedPath)) {
            return asset('storage/'.$normalizedPath);
        }

        return asset($placeholder);
    }

    private function publicStoragePathExists(string $path): bool
    {
        if (Storage::disk('public')->exists($path) || is_file(public_path($path))) {
            return true;
        }

        if (str_starts_with($path, 'storage/')) {
            $storagePath = substr($path, strlen('storage/'));

            return $storagePath !== '' && Storage::disk('public')->exists($storagePath);
        }

        return false;
    }
}
