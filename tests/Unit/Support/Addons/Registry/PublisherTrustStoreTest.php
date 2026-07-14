<?php

namespace Tests\Unit\Support\Addons\Registry;

use App\Support\Addons\Registry\PublisherTrustStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublisherTrustStoreTest extends TestCase
{
    public function test_key_is_bound_to_publisher_and_has_deterministic_fingerprint(): void
    {
        $public = random_bytes(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        $store = new PublisherTrustStore(['keys' => [[
            'publisher_id' => 'publisher-a', 'key_id' => 'key-1', 'algorithm' => 'ed25519',
            'public_key' => base64_encode($public), 'status' => 'active',
        ]]]);

        $accepted = $store->find('publisher-a', 'key-1');

        self::assertTrue($accepted['allowed']);
        self::assertSame(hash('sha256', $public), $accepted['fingerprint']);
        self::assertSame('unknown_signing_key', $store->find('publisher-b', 'key-1')['code']);
    }

    #[DataProvider('blockedEntries')]
    public function test_invalid_or_disabled_entries_fail_closed(array $entry, string $code): void
    {
        $result = (new PublisherTrustStore(['keys' => [$entry]]))->find('publisher-a', 'key-1');

        self::assertFalse($result['allowed']);
        self::assertSame($code, $result['code']);
    }

    public static function blockedEntries(): array
    {
        $base = ['publisher_id' => 'publisher-a', 'key_id' => 'key-1', 'algorithm' => 'ed25519', 'public_key' => base64_encode(random_bytes(32)), 'status' => 'active'];

        return [
            'disabled' => [array_replace($base, ['status' => 'disabled']), 'signing_key_disabled'],
            'revoked' => [array_replace($base, ['status' => 'revoked']), 'signing_key_disabled'],
            'algorithm' => [array_replace($base, ['algorithm' => 'rsa']), 'unsupported_signature_algorithm'],
            'malformed' => [array_replace($base, ['public_key' => base64_encode('short')]), 'malformed_public_key'],
        ];
    }
}
