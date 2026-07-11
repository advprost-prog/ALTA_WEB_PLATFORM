<?php

namespace App\Support\Addons\Registry;

use ZipArchive;

final class ArchiveSafetyValidator
{
    public function validate(string $archivePath, array $config = []): array
    {
        $zip = new ZipArchive;
        if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) {
            return $this->failed('Archive ZIP пошкоджений або не підтримується.');
        }

        $limits = array_merge([
            'max_entries' => 2000, 'max_uncompressed_size' => 104857600,
            'max_single_file_size' => 20971520, 'max_compression_ratio' => 100, 'max_path_length' => 240,
        ], $config);
        $inventory = [];
        $seen = [];
        $total = 0;
        $manifests = [];
        $diagnostics = [];

        if ($zip->numFiles > $limits['max_entries']) {
            $diagnostics[] = 'Archive перевищує максимальну кількість entries.';
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i, ZipArchive::FL_UNCHANGED);
            if (! is_array($stat) || ! isset($stat['name'])) {
                $diagnostics[] = 'ZIP entry має некоректні metadata.';

                continue;
            }
            $raw = (string) $stat['name'];
            $path = $this->normalize($raw);
            if ($path === null) {
                $diagnostics[] = "Небезпечний archive path [{$raw}].";

                continue;
            }
            if (strlen($path) > $limits['max_path_length']) {
                $diagnostics[] = "Archive path задовгий [{$path}].";
            }
            $key = strtolower(rtrim($path, '/'));
            if (isset($seen[$key])) {
                $diagnostics[] = "Duplicate/case-collision archive path [{$path}].";
            }
            $seen[$key] = true;

            $isDirectory = str_ends_with($raw, '/');
            $mode = 0;
            $opsys = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attributes)) {
                $mode = ($attributes >> 16) & 0xFFFF;
                $type = $mode & 0170000;
                if ($type !== 0 && $type !== 0100000 && $type !== 0040000) {
                    $diagnostics[] = "Symlink або special file заборонений [{$path}].";
                }
            }
            $size = (int) ($stat['size'] ?? 0);
            $compressed = (int) ($stat['comp_size'] ?? 0);
            if (! $isDirectory && $size > $limits['max_single_file_size']) {
                $diagnostics[] = "Файл перевищує staging limit [{$path}].";
            }
            if (! $isDirectory && $size / max($compressed, 1) > $limits['max_compression_ratio']) {
                $diagnostics[] = "Підозрілий compression ratio [{$path}].";
            }
            $total += $size;
            if (in_array($path, ['module.json', 'extension.json', 'manifest.json'], true)) {
                $manifests[] = $path;
            }
            $inventory[] = ['index' => $i, 'path' => rtrim($path, '/'), 'type' => $isDirectory ? 'directory' : 'file', 'size' => $size, 'sha256' => null];
        }
        $zip->close();

        if ($total > $limits['max_uncompressed_size']) {
            $diagnostics[] = 'Archive перевищує сумарний uncompressed size limit.';
        }
        if (count($manifests) !== 1) {
            $diagnostics[] = count($manifests) === 0 ? 'Root manifest відсутній.' : 'Archive містить декілька root manifest files.';
        }
        foreach ($inventory as $entry) {
            if ($entry['type'] === 'file' && preg_match('~/(?:module|extension|manifest)\.json$~i', $entry['path'])) {
                $diagnostics[] = 'Nested manifest заборонений: '.$entry['path'];
            }
        }
        usort($inventory, fn ($a, $b) => strcmp($a['path'], $b['path']));

        return ['success' => $diagnostics === [], 'inventory' => $inventory, 'manifest_path' => $manifests[0] ?? null, 'total_size' => $total, 'diagnostics' => array_values(array_unique($diagnostics))];
    }

    private function normalize(string $path): ?string
    {
        if ($path === '' || str_contains($path, "\0") || preg_match('/[\x00-\x1F\x7F]/', $path)
            || str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('~^[A-Za-z]:[\\\\/]~', $path)) {
            return null;
        }
        $path = preg_replace('~/+~', '/', str_replace('\\', '/', $path));
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                return null;
            }
            $parts[] = $part;
        }
        if ($parts === []) {
            return null;
        }

        return implode('/', $parts).(str_ends_with($path, '/') ? '/' : '');
    }

    private function failed(string $message): array
    {
        return ['success' => false, 'inventory' => [], 'manifest_path' => null, 'total_size' => 0, 'diagnostics' => [$message]];
    }
}
