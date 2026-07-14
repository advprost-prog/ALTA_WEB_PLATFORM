<?php

namespace App\Support\Addons\Registry;

final class MarketplaceHttpPolicy
{
    public function __construct(private readonly array $config) {}

    public function allows(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        if (! app()->environment(['local', 'testing']) && ($scheme !== 'https' || ! (bool) ($this->config['verify_ssl'] ?? true))) {
            return false;
        }

        return $this->allowsHost((string) $parts['host']);
    }

    public function allowsHost(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return (bool) ($this->config['allow_localhost'] ?? false) && app()->environment(['local', 'testing']);
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
                && in_array($host, $this->allowedHosts(), true);
        }

        return in_array($host, $this->allowedHosts(), true);
    }

    private function allowedHosts(): array
    {
        return array_map(fn ($host) => strtolower(rtrim(trim((string) $host), '.')), array_values(array_filter((array) ($this->config['allowed_hosts'] ?? []))));
    }
}
