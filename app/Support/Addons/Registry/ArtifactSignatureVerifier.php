<?php

namespace App\Support\Addons\Registry;

/**
 * Verifies the cryptographic signature of a remote artifact.
 *
 * Only ed25519 is supported. When the PHP sodium extension is available the
 * signature is verified with sodium_crypto_sign_verify_detached. When sodium
 * is missing the verifier does NOT attempt hand-rolled cryptography — it
 * returns a controlled {@see self::STATUS_ERROR} result with a diagnostic so
 * the caller can surface "signature verification unavailable".
 */
class ArtifactSignatureVerifier
{
    public const TYPE_ED25519 = 'ed25519';

    public const STATUS_NOT_REQUIRED = 'not_required';

    public const STATUS_MISSING = 'missing';

    public const STATUS_UNKNOWN_KEY = 'unknown_key';

    public const STATUS_UNSUPPORTED_TYPE = 'unsupported_type';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_VALID = 'valid';

    public const STATUS_ERROR = 'error';

    /**
     * @var array<string, string>
     */
    public const LABELS = [
        self::STATUS_NOT_REQUIRED => 'Не вимагається',
        self::STATUS_MISSING => 'Підпис відсутній',
        self::STATUS_UNKNOWN_KEY => 'Невідомий ключ',
        self::STATUS_UNSUPPORTED_TYPE => 'Непідтримуваний тип підпису',
        self::STATUS_INVALID => 'Підпис недійсний',
        self::STATUS_VALID => 'Підпис дійсний',
        self::STATUS_ERROR => 'Помилка перевірки',
    ];

    /**
     * Verify an artifact signature.
     *
     * @param  array<string, mixed>|null  $signature  Registry artifact.signature metadata.
     * @param  string  $artifactBytes  Raw artifact bytes (kept in memory; never unpacked).
     * @param  bool  $requireSignature  Trust policy: whether a signature is mandatory.
     * @param  array<string, string>  $trustedKeys  key_id => base64 ed25519 public key.
     */
    public function verify(?array $signature, string $artifactBytes, bool $requireSignature, array $trustedKeys): ArtifactSignatureResult
    {
        if ($signature === null || $signature === [] || empty($signature['type'])) {
            if ($requireSignature) {
                return new ArtifactSignatureResult(self::STATUS_MISSING, null, ['Signature is required but missing.']);
            }

            return new ArtifactSignatureResult(self::STATUS_NOT_REQUIRED, null, ['Signature is not required.']);
        }

        $type = (string) ($signature['type'] ?? '');
        $keyId = isset($signature['key_id']) && is_string($signature['key_id']) ? $signature['key_id'] : null;
        $value = isset($signature['value']) && is_string($signature['value']) ? $signature['value'] : null;

        if ($type !== self::TYPE_ED25519) {
            return new ArtifactSignatureResult(
                self::STATUS_UNSUPPORTED_TYPE,
                $keyId,
                ['Unsupported signature type ['.$type.']. Only ed25519 is supported.'],
            );
        }

        if ($keyId === null || ! isset($trustedKeys[$keyId])) {
            return new ArtifactSignatureResult(
                self::STATUS_UNKNOWN_KEY,
                $keyId,
                $keyId === null ? ['Signature has no key_id.'] : ['Unknown trusted key ['.$keyId.'].'],
            );
        }

        if (! $this->sodiumAvailable()) {
            return new ArtifactSignatureResult(
                self::STATUS_ERROR,
                $keyId,
                ['sodium extension not available', 'Ed25519 signature verification requires the PHP sodium extension.'],
            );
        }

        if ($value === null || $value === '') {
            return new ArtifactSignatureResult(self::STATUS_INVALID, $keyId, ['Signature value is empty.']);
        }

        $publicKey = base64_decode($trustedKeys[$keyId], true);

        if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return new ArtifactSignatureResult(
                self::STATUS_ERROR,
                $keyId,
                ['Trusted key ['.$keyId.'] is not a valid base64 ed25519 public key.'],
            );
        }

        $rawSignature = base64_decode($value, true);

        if ($rawSignature === false || strlen($rawSignature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return new ArtifactSignatureResult(self::STATUS_INVALID, $keyId, ['Signature value is not valid base64 ed25519 signature.']);
        }

        try {
            $valid = sodium_crypto_sign_verify_detached($rawSignature, $artifactBytes, $publicKey);
        } catch (\Throwable $exception) {
            return new ArtifactSignatureResult(self::STATUS_ERROR, $keyId, ['Signature verification failed: '.$exception->getMessage()]);
        }

        if (! $valid) {
            return new ArtifactSignatureResult(self::STATUS_INVALID, $keyId, ['Signature does not match artifact bytes.']);
        }

        return new ArtifactSignatureResult(self::STATUS_VALID, $keyId, ['Signature verified with key ['.$keyId.'].']);
    }

    public function sodiumAvailable(): bool
    {
        return extension_loaded('sodium')
            && function_exists('sodium_crypto_sign_verify_detached');
    }

    public function verifyFile(array $signature, string $path, string $publicKey, int $maximumBytes): ArtifactSignatureResult
    {
        $keyId = is_string($signature['key_id'] ?? null) ? $signature['key_id'] : null;
        if (($signature['type'] ?? null) !== self::TYPE_ED25519) {
            return new ArtifactSignatureResult(self::STATUS_UNSUPPORTED_TYPE, $keyId, ['Unsupported signature algorithm.']);
        }
        if (($signature['payload_version'] ?? null) !== 'raw-zip-v1') {
            return new ArtifactSignatureResult(self::STATUS_INVALID, $keyId, ['Unsupported signature payload version.']);
        }
        $value = is_string($signature['value'] ?? null) ? base64_decode($signature['value'], true) : false;
        if ($value === false || strlen($value) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return new ArtifactSignatureResult(self::STATUS_INVALID, $keyId, ['Malformed detached signature.']);
        }
        $size = is_file($path) ? filesize($path) : false;
        if ($size === false || $size > $maximumBytes) {
            return new ArtifactSignatureResult(self::STATUS_ERROR, $keyId, ['Artifact exceeds signature verification memory limit.']);
        }
        $bytes = file_get_contents($path, false, null, 0, $maximumBytes + 1);
        if (! is_string($bytes) || strlen($bytes) !== $size) {
            return new ArtifactSignatureResult(self::STATUS_ERROR, $keyId, ['Artifact could not be read for signature verification.']);
        }

        return sodium_crypto_sign_verify_detached($value, $bytes, $publicKey)
            ? new ArtifactSignatureResult(self::STATUS_VALID, $keyId, ['Detached signature verified.'])
            : new ArtifactSignatureResult(self::STATUS_INVALID, $keyId, ['Detached signature is invalid.']);
    }
}
