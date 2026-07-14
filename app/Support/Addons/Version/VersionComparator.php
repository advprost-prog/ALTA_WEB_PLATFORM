<?php

namespace App\Support\Addons\Version;

/**
 * Minimal, dependency-free semantic version helper for the local Marketplace.
 *
 * composer/semver is only a transitive lock entry and is not guaranteed to be
 * installed, so we implement just the constraint forms the Marketplace needs:
 *   >=1.0.0  >1.0.0  <=1.0.0  <1.0.0  =1.0.0  1.0.0 (exact)  ^1.0  ^1.0.0  *
 *
 * Pre-release/build suffixes are ignored; comparison is numeric on the
 * major.minor.patch parts.
 */
final class VersionComparator
{
    public function isSupported(string $version): bool
    {
        return preg_match('/^v?\d+(?:\.\d+){0,2}(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D', trim($version)) === 1;
    }

    /**
     * Strip a leading "v" and keep only the leading numeric X.Y.Z prefix.
     */
    public function normalize(string $version): string
    {
        $version = strtolower(trim($version));
        $version = ltrim($version, 'v');
        $version = preg_replace('/[^0-9.].*$/', '', $version) ?: '0.0.0';

        return $version;
    }

    /**
     * @return array{int, int, int}
     */
    private function parts(string $version): array
    {
        $segments = explode('.', $this->normalize($version));

        return [
            (int) ($segments[0] ?? 0),
            (int) ($segments[1] ?? 0),
            (int) ($segments[2] ?? 0),
        ];
    }

    public function compare(string $a, string $b): int
    {
        [$am, $ai, $ap] = $this->parts($a);
        [$bm, $bi, $bp] = $this->parts($b);

        return ($am <=> $bm) ?: ($ai <=> $bi) ?: ($ap <=> $bp);
    }

    public function greaterThan(string $a, string $b): bool
    {
        return $this->compare($a, $b) > 0;
    }

    public function lessThan(string $a, string $b): bool
    {
        return $this->compare($a, $b) < 0;
    }

    public function equalTo(string $a, string $b): bool
    {
        return $this->compare($a, $b) === 0;
    }

    /**
     * Does $version satisfy a (possibly comma-separated AND) $constraint?
     */
    public function satisfies(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);

        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        foreach (explode(',', $constraint) as $part) {
            if (! $this->satisfiesSingle($version, trim($part))) {
                return false;
            }
        }

        return true;
    }

    private function satisfiesSingle(string $version, string $constraint): bool
    {
        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        if (str_starts_with($constraint, '^')) {
            return $this->satisfiesCaret($version, substr($constraint, 1));
        }

        foreach (['>=', '<=', '>', '<', '='] as $operator) {
            if (str_starts_with($constraint, $operator)) {
                $c = $this->compare($version, substr($constraint, strlen($operator)));

                return match ($operator) {
                    '>=' => $c >= 0,
                    '<=' => $c <= 0,
                    '>' => $c > 0,
                    '<' => $c < 0,
                    '=' => $c === 0,
                };
            }
        }

        // No operator => exact match.
        return $this->equalTo($version, $constraint);
    }

    private function satisfiesCaret(string $version, string $base): bool
    {
        $base = $this->normalize($base);
        [$major, $minor] = $this->parts($base);

        if ($major > 0) {
            return $this->greaterThanOrEqual($version, $base)
                && $this->lessThan($version, ($major + 1).'.0.0');
        }

        if ($minor > 0) {
            return $this->greaterThanOrEqual($version, $base)
                && $this->lessThan($version, '0.'.($minor + 1).'.0');
        }

        return $this->greaterThanOrEqual($version, $base)
            && $this->lessThan($version, '0.0.1');
    }

    private function greaterThanOrEqual(string $a, string $b): bool
    {
        return $this->compare($a, $b) >= 0;
    }

    /**
     * Compare an installed version against an available (catalog) version.
     *
     * @return string One of: up_to_date, update_available, installed_newer, unknown
     */
    public function compareInstalled(string $installed, string $available): string
    {
        if (trim($installed) === '' || trim($available) === '') {
            return 'unknown';
        }

        $result = $this->compare($installed, $available);

        return match ($result) {
            0 => 'up_to_date',
            1 => 'installed_newer',
            -1 => 'update_available',
            default => 'unknown',
        };
    }
}
