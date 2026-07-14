<?php

namespace App\Support\Addons\Registry;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class ManagedTreeInventory
{
    private const EXCLUDED = ['backup.json', '.candidate-evidence.json'];

    public function build(string $root): array
    {
        if (! is_dir($root) || is_link($root)) {
            throw new RuntimeException('tree_unmanaged_or_symlink');
        }
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        $files = [];
        $total = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry->isLink() || ! $entry->isFile()) {
                throw new RuntimeException('tree_special_entry');
            }
            $path = $entry->getPathname();
            $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
            if ($relative === '' || preg_match('/[\x00-\x1F\x7F]/', $relative) || in_array('..', explode('/', $relative), true)) {
                throw new RuntimeException('tree_path_invalid');
            }
            if (in_array($relative, self::EXCLUDED, true)) {
                continue;
            }
            $size = filesize($path);
            $hash = hash_file('sha256', $path);
            if ($size === false || $hash === false) {
                throw new RuntimeException('tree_read_failed');
            }
            $files[] = ['path' => $relative, 'size' => $size, 'sha256' => $hash];
            $total += $size;
        }
        usort($files, fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
        $digest = hash('sha256', json_encode($files, JSON_UNESCAPED_SLASHES));

        return ['files' => $files, 'file_count' => count($files), 'total_bytes' => $total, 'inventory_digest' => $digest];
    }
}
