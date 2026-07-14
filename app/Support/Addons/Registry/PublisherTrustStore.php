<?php

namespace App\Support\Addons\Registry;

final class PublisherTrustStore
{
    public function __construct(private readonly array $config) {}

    public function find(string $publisherId, string $keyId): array
    {
        $entries = (array) ($this->config['keys'] ?? []);
        $legacyKey = $this->config['trusted_keys'][$keyId] ?? null;
        $legacyPublisher = $this->config['legacy_publishers'][$keyId] ?? null;
        if ($entries === [] && is_string($legacyKey) && $legacyPublisher === $publisherId) {
            $entries[] = [
                'publisher_id' => $publisherId, 'key_id' => $keyId, 'algorithm' => 'ed25519',
                'public_key' => $legacyKey, 'status' => 'active',
            ];
        }
        foreach ($entries as $entry) {
            if (! is_array($entry) || ($entry['publisher_id'] ?? null) !== $publisherId || ($entry['key_id'] ?? null) !== $keyId) {
                continue;
            }
            $status = (string) ($entry['status'] ?? 'disabled');
            if (! in_array($status, ['active', 'retiring'], true)) {
                return ['allowed' => false, 'code' => 'signing_key_disabled', 'status' => $status];
            }
            if (($entry['algorithm'] ?? null) !== 'ed25519') {
                return ['allowed' => false, 'code' => 'unsupported_signature_algorithm', 'status' => $status];
            }
            $raw = is_string($entry['public_key'] ?? null) ? base64_decode($entry['public_key'], true) : false;
            if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                return ['allowed' => false, 'code' => 'malformed_public_key', 'status' => $status];
            }

            return ['allowed' => true, 'code' => null, 'status' => $status, 'public_key' => $raw, 'fingerprint' => hash('sha256', $raw)];
        }

        return ['allowed' => false, 'code' => 'unknown_signing_key', 'status' => 'unknown'];
    }
}
