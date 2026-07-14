<?php

namespace Tests\Unit\Support\Addons\Registry;

use App\Support\Addons\Registry\ArtifactSignatureVerifier;
use PHPUnit\Framework\TestCase;

final class ArtifactSignatureVerifierFileTest extends TestCase
{
    public function test_exact_raw_file_signature_is_verified_and_mutation_fails(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $public = sodium_crypto_sign_publickey($pair);
        $bytes = random_bytes(257);
        $path = tempnam(sys_get_temp_dir(), 'alta-signature-');
        file_put_contents($path, $bytes);
        $signature = ['type' => 'ed25519', 'payload_version' => 'raw-zip-v1', 'key_id' => 'key-1', 'value' => base64_encode(sodium_crypto_sign_detached($bytes, $secret))];
        $verifier = new ArtifactSignatureVerifier;

        self::assertSame(ArtifactSignatureVerifier::STATUS_VALID, $verifier->verifyFile($signature, $path, $public, 1024)->status);
        file_put_contents($path, substr($bytes, 0, -1).(chr(ord($bytes[-1]) ^ 1)));
        self::assertSame(ArtifactSignatureVerifier::STATUS_INVALID, $verifier->verifyFile($signature, $path, $public, 1024)->status);
        self::assertSame(ArtifactSignatureVerifier::STATUS_ERROR, $verifier->verifyFile($signature, $path, $public, 10)->status);
        @unlink($path);
    }

    public function test_payload_and_signature_encoding_are_strict(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'alta-signature-');
        file_put_contents($path, 'message');
        $verifier = new ArtifactSignatureVerifier;
        $public = random_bytes(32);

        self::assertSame(ArtifactSignatureVerifier::STATUS_INVALID, $verifier->verifyFile(['type' => 'ed25519', 'payload_version' => 'ed25519ph', 'value' => 'x'], $path, $public, 100)->status);
        self::assertSame(ArtifactSignatureVerifier::STATUS_INVALID, $verifier->verifyFile(['type' => 'ed25519', 'payload_version' => 'raw-zip-v1', 'value' => '%%%'], $path, $public, 100)->status);
        @unlink($path);
    }
}
