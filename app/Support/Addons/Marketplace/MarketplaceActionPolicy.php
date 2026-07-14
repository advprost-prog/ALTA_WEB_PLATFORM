<?php

namespace App\Support\Addons\Marketplace;

final class MarketplaceActionPolicy
{
    public function assess(array $context): array
    {
        $remote = (bool) ($context['has_remote'] ?? false);
        $fresh = ($context['registry_state'] ?? 'unavailable') === 'fresh';
        $identityOk = (bool) ($context['identity_ok'] ?? true);
        $compatible = ($context['compatibility'] ?? 'unknown') === 'compatible';
        $dependenciesOk = ! (bool) ($context['dependencies_blocked'] ?? false);
        $installed = (bool) ($context['installed'] ?? false);
        $versionState = $context['version_state'] ?? 'unknown';
        $downloadsEnabled = (bool) ($context['downloads_enabled'] ?? false);

        $download = $this->decision($remote && $fresh && $identityOk && $compatible && $dependenciesOk && $downloadsEnabled,
            ! $remote ? 'remote_candidate_missing' : (! $fresh ? 'registry_not_fresh' : (! $identityOk ? 'identity_conflict' : (! $compatible ? 'platform_incompatible' : (! $dependenciesOk ? 'dependencies_blocked' : (! $downloadsEnabled ? 'downloads_disabled' : 'allowed'))))));
        $update = $this->decision($installed && $versionState === 'update_available' && $fresh && $identityOk && $compatible && $dependenciesOk,
            ! $installed ? 'not_installed' : ($versionState !== 'update_available' ? ($versionState === 'local_newer' ? 'downgrade_blocked' : 'remote_update_unavailable') : (! $fresh ? 'registry_not_fresh' : (! $identityOk ? 'identity_conflict' : (! $compatible ? 'platform_incompatible' : (! $dependenciesOk ? 'dependencies_blocked' : 'allowed'))))));

        return ['download' => $download, 'update_remote' => $update];
    }

    private function decision(bool $allowed, string $code): array
    {
        $messages = [
            'allowed' => 'Дію дозволено.', 'remote_candidate_missing' => 'Remote candidate відсутній.',
            'registry_not_fresh' => 'Registry snapshot не є fresh.', 'identity_conflict' => 'Local і remote identity конфліктують.',
            'platform_incompatible' => 'Remote candidate несумісний з ALTA platform.', 'dependencies_blocked' => 'Dependency preflight має блокуючі проблеми.',
            'downloads_disabled' => 'Remote downloads вимкнено.', 'not_installed' => 'Addon не встановлено локально.',
            'downgrade_blocked' => 'Встановлена версія новіша; downgrade заборонено.', 'remote_update_unavailable' => 'Новіша remote version недоступна.',
        ];

        return ['allowed' => $allowed, 'reason_code' => $allowed ? 'allowed' : $code, 'reason' => $messages[$allowed ? 'allowed' : $code] ?? 'Дію заблоковано.'];
    }
}
