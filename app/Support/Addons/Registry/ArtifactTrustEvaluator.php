<?php

namespace App\Support\Addons\Registry;

/**
 * Combines checksum, signature, and manifest inspection into a single trust
 * verdict for a quarantined artifact.
 *
 * Rules:
 * - checksum mismatch → rejected
 * - manifest missing/invalid/identity mismatch → rejected
 * - signature invalid → rejected
 * - signature cannot be verified (error) → untrusted
 * - signature required but missing → untrusted (future install blocked)
 * - signature uses unknown key → untrusted
 * - valid checksum + valid signature + valid manifest → trusted
 * - signature not required + valid checksum + valid manifest → partially_trusted
 */
final class ArtifactTrustEvaluator
{
    public const TRUST_UNTRUSTED = 'untrusted';

    public const TRUST_PARTIALLY_TRUSTED = 'partially_trusted';

    public const TRUST_TRUSTED = 'trusted';

    public const TRUST_REJECTED = 'rejected';

    /**
     * @var array<string, string>
     */
    public const LABELS = [
        self::TRUST_UNTRUSTED => 'Недовірений',
        self::TRUST_PARTIALLY_TRUSTED => 'Частково довірений',
        self::TRUST_TRUSTED => 'Довірений',
        self::TRUST_REJECTED => 'Відхилений',
    ];

    public function evaluate(
        bool $checksumValid,
        string $signatureStatus,
        string $manifestStatus,
        bool $requireSignature,
    ): ArtifactTrustResult {
        if (! $checksumValid) {
            return new ArtifactTrustResult(self::TRUST_REJECTED, ['Checksum mismatch; artifact integrity cannot be confirmed.']);
        }

        $manifestValid = $manifestStatus === QuarantinedArtifactInspector::STATUS_VALID;

        if (! $manifestValid) {
            return new ArtifactTrustResult(self::TRUST_REJECTED, [
                'Manifest is not valid (status: '.$manifestStatus.'); artifact rejected.',
            ]);
        }

        if ($signatureStatus === ArtifactSignatureVerifier::STATUS_INVALID) {
            return new ArtifactTrustResult(self::TRUST_REJECTED, ['Signature is invalid; artifact rejected.']);
        }

        if ($signatureStatus === ArtifactSignatureVerifier::STATUS_ERROR) {
            return new ArtifactTrustResult(self::TRUST_UNTRUSTED, ['Signature could not be verified; verification unavailable.']);
        }

        if ($signatureStatus === ArtifactSignatureVerifier::STATUS_UNKNOWN_KEY) {
            return new ArtifactTrustResult(self::TRUST_UNTRUSTED, ['Signature uses an unknown trusted key.']);
        }

        if ($signatureStatus === ArtifactSignatureVerifier::STATUS_MISSING) {
            return new ArtifactTrustResult(self::TRUST_UNTRUSTED, ['Signature is required but missing; install blocked.']);
        }

        if ($signatureStatus === ArtifactSignatureVerifier::STATUS_UNSUPPORTED_TYPE) {
            return new ArtifactTrustResult(self::TRUST_UNTRUSTED, ['Signature uses an unsupported type.']);
        }

        if ($signatureStatus === ArtifactSignatureVerifier::STATUS_VALID) {
            return new ArtifactTrustResult(self::TRUST_TRUSTED, ['Valid signature and manifest; artifact trusted.']);
        }

        if (! $requireSignature) {
            return new ArtifactTrustResult(self::TRUST_PARTIALLY_TRUSTED, [
                'Signature not required; checksum and manifest are valid.',
            ]);
        }

        return new ArtifactTrustResult(self::TRUST_PARTIALLY_TRUSTED, ['Artifact is partially trusted.']);
    }
}
