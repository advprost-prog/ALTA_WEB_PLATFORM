<?php

namespace App\Support\Addons\Registry;

/**
 * Inspects the manifest inside a quarantined artifact ZIP.
 *
 * Security guarantees (supply-chain hardening, Phase 3.2):
 * - The ZIP is opened read-only with ZipArchive and never extracted to disk.
 * - No file from the archive is included/required and no provider code runs.
 * - The provider path (if present) is treated only as a string and never executed.
 *
 * The manifest is read from the first matching entry: module.json,
 * extension.json, or manifest.json.
 */
final class QuarantinedArtifactInspector
{
    public const MANIFEST_CANDIDATES = ['module.json', 'extension.json', 'manifest.json'];

    public const STATUS_NOT_INSPECTED = 'not_inspected';

    public const STATUS_MANIFEST_MISSING = 'manifest_missing';

    public const STATUS_MANIFEST_INVALID = 'manifest_invalid';

    public const STATUS_IDENTITY_MISMATCH = 'identity_mismatch';

    public const STATUS_VALID = 'valid';

    public const STATUS_ERROR = 'error';

    /**
     * @var array<string, string>
     */
    public const LABELS = [
        self::STATUS_NOT_INSPECTED => 'Не перевірено',
        self::STATUS_MANIFEST_MISSING => 'Manifest відсутній',
        self::STATUS_MANIFEST_INVALID => 'Manifest некоректний',
        self::STATUS_IDENTITY_MISMATCH => 'Code/version не збігаються',
        self::STATUS_VALID => 'Manifest валідний',
        self::STATUS_ERROR => 'Помилка перевірки',
    ];

    /**
     * Inspect a ZIP archive stored at $path.
     *
     * @param  string  $code  Expected registry item code.
     * @param  string  $version  Expected registry item version.
     */
    public function inspect(string $path, string $code, string $version): ArtifactManifestInspectionResult
    {
        if (! extension_loaded('zip') || ! class_exists(\ZipArchive::class)) {
            return new ArtifactManifestInspectionResult(self::STATUS_ERROR, null, null, ['zip extension not available']);
        }

        if (! is_file($path) || ! is_readable($path)) {
            return new ArtifactManifestInspectionResult(self::STATUS_ERROR, null, null, ['Artifact file is missing or not readable: '.$path]);
        }

        $zip = new \ZipArchive;

        try {
            $opened = $zip->open($path, \ZipArchive::RDONLY);
        } catch (\Throwable $exception) {
            return new ArtifactManifestInspectionResult(self::STATUS_ERROR, null, null, ['Cannot open ZIP: '.$exception->getMessage()]);
        }

        if ($opened !== true) {
            return new ArtifactManifestInspectionResult(self::STATUS_ERROR, null, null, ['Cannot open ZIP archive (code '.$opened.').']);
        }

        try {
            $manifestPath = $this->locateManifest($zip);
        } catch (\Throwable $exception) {
            $zip->close();

            return new ArtifactManifestInspectionResult(self::STATUS_ERROR, null, null, ['Manifest inspection failed: '.$exception->getMessage()]);
        }

        if ($manifestPath === null) {
            $zip->close();

            return new ArtifactManifestInspectionResult(self::STATUS_MANIFEST_MISSING, null, null, ['No manifest (module.json/extension.json/manifest.json) found in artifact.']);
        }

        try {
            $manifest = $this->readManifest($zip, $manifestPath);
        } catch (\Throwable $exception) {
            $zip->close();

            return new ArtifactManifestInspectionResult(self::STATUS_ERROR, null, null, ['Manifest inspection failed: '.$exception->getMessage()]);
        }

        if ($manifest === null) {
            $zip->close();

            return new ArtifactManifestInspectionResult(self::STATUS_MANIFEST_INVALID, null, $manifestPath, ['Manifest ['.$manifestPath.'] is not valid JSON.']);
        }

        $codeMismatch = isset($manifest['code']) && $manifest['code'] !== $code;
        $versionMismatch = isset($manifest['version']) && $manifest['version'] !== $version;
        $typeInvalid = isset($manifest['type']) && ! in_array($manifest['type'], ['module', 'extension'], true);

        if ($codeMismatch || $versionMismatch || $typeInvalid) {
            $zip->close();

            return new ArtifactManifestInspectionResult(
                self::STATUS_IDENTITY_MISMATCH,
                $manifest,
                $manifestPath,
                array_filter([
                    $codeMismatch ? "Manifest code [{$manifest['code']}] does not match item code [{$code}]." : null,
                    $versionMismatch ? "Manifest version [{$manifest['version']}] does not match item version [{$version}]." : null,
                    $typeInvalid ? "Manifest type [{$manifest['type']}] is not module or extension." : null,
                ]),
            );
        }

        $zip->close();

        $diagnostics = ['Manifest validated: '.$manifestPath];

        if (isset($manifest['provider']) && is_string($manifest['provider']) && $manifest['provider'] !== '') {
            $diagnostics[] = 'Provider ['.$manifest['provider'].'] declared but not executed (quarantine inspection only).';
        }

        return new ArtifactManifestInspectionResult(self::STATUS_VALID, $manifest, $manifestPath, $diagnostics);
    }

    private function locateManifest(\ZipArchive $zip): ?string
    {
        foreach (self::MANIFEST_CANDIDATES as $candidate) {
            if ($zip->locateName($candidate) !== false) {
                return $candidate;
            }
        }

        // Also tolerate a single nested directory prefix (common in archives).
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if (! is_array($stat) || ! isset($stat['name'])) {
                continue;
            }

            $name = $stat['name'];
            $basename = basename($name);

            if (in_array($basename, self::MANIFEST_CANDIDATES, true) && str_ends_with($name, $basename)) {
                return $name;
            }
        }

        return null;
    }

    private function readManifest(\ZipArchive $zip, string $manifestPath): ?array
    {
        $content = $zip->getFromName($manifestPath);

        if ($content === false) {
            return null;
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            return null;
        }

        return $decoded;
    }
}
