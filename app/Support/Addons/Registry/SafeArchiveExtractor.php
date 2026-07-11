<?php

namespace App\Support\Addons\Registry;

use RuntimeException;
use ZipArchive;

final class SafeArchiveExtractor
{
    public function extract(string $archivePath, string $payloadRoot, array $inventory, array $config = []): array
    {
        $maxFile = (int) ($config['max_single_file_size'] ?? 20971520);
        $maxTotal = (int) ($config['max_uncompressed_size'] ?? 104857600);
        $zip = new ZipArchive;
        if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Не вдалося відкрити ZIP.');
        }
        if (! is_dir($payloadRoot) && ! mkdir($payloadRoot, 0755, true)) {
            throw new RuntimeException('Не вдалося створити payload directory.');
        }
        $root = realpath($payloadRoot);
        $total = 0;
        $result = [];
        foreach ($inventory as $entry) {
            $destination = $payloadRoot.'/'.$entry['path'];
            $parent = dirname($destination);
            if (! is_dir($parent) && ! mkdir($parent, 0755, true)) {
                throw new RuntimeException('Не вдалося створити directory.');
            }
            if (! str_starts_with(realpath($parent).DIRECTORY_SEPARATOR, $root.DIRECTORY_SEPARATOR) && realpath($parent) !== $root) {
                throw new RuntimeException('Extraction path вийшов за staging root.');
            }
            if ($entry['type'] === 'directory') {
                if (! is_dir($destination)) {
                    mkdir($destination, 0755, true);
                }
                $result[] = array_merge($entry, ['sha256' => null]);

                continue;
            }
            $input = $zip->getStream($zip->getNameIndex($entry['index']));
            $output = fopen($destination, 'wb');
            if (! is_resource($input) || ! is_resource($output)) {
                throw new RuntimeException('Не вдалося відкрити ZIP entry stream.');
            }
            $hash = hash_init('sha256');
            $written = 0;
            while (! feof($input)) {
                $chunk = fread($input, 8192);
                if ($chunk === false) {
                    throw new RuntimeException('Помилка читання ZIP entry.');
                }
                $written += strlen($chunk);
                $total += strlen($chunk);
                if ($written > $maxFile || $total > $maxTotal) {
                    throw new RuntimeException('Фактичний extracted size перевищує limit.');
                }
                hash_update($hash, $chunk);
                if (fwrite($output, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Помилка запису payload file.');
                }
            }
            fclose($input);
            fclose($output);
            chmod($destination, 0644);
            $result[] = array_merge($entry, ['size' => $written, 'sha256' => hash_final($hash)]);
        }
        $zip->close();

        return ['inventory' => $result, 'total_size' => $total, 'file_count' => count(array_filter($result, fn ($e) => $e['type'] === 'file'))];
    }
}
