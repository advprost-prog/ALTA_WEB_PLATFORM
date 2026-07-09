<?php

namespace App\Support\Addons\Registry;

class ArtifactValidator
{
    /**
     * @param  array<string, mixed>  $artifact
     * @return array<int, string>
     */
    public function validateMetadata(array $artifact): array
    {
        $issues = [];

        if (empty($artifact['url']) || ! is_string($artifact['url'])) {
            $issues[] = 'Missing artifact URL.';
        }

        if (empty($artifact['type']) || ! is_string($artifact['type'])) {
            $issues[] = 'Missing artifact type.';
        } elseif ($artifact['type'] !== 'zip') {
            $issues[] = 'Unsupported artifact type: '.$artifact['type'];
        }

        if (! isset($artifact['sha256']) || ! is_string($artifact['sha256']) || strlen($artifact['sha256']) !== 64) {
            $issues[] = 'Missing or invalid artifact sha256.';
        }

        if (! isset($artifact['size']) || ! is_int($artifact['size']) || $artifact['size'] <= 0) {
            $issues[] = 'Missing or invalid artifact size.';
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public function normalizedArtifact(array $artifact): array
    {
        return [
            'url' => (string) ($artifact['url'] ?? ''),
            'type' => (string) ($artifact['type'] ?? ''),
            'sha256' => (string) ($artifact['sha256'] ?? ''),
            'size' => isset($artifact['size']) ? (int) $artifact['size'] : 0,
            'signature' => isset($artifact['signature']) && is_string($artifact['signature']) ? $artifact['signature'] : null,
        ];
    }
}
