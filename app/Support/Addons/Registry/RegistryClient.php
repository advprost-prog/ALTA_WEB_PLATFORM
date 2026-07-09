<?php

namespace App\Support\Addons\Registry;

use Illuminate\Support\Facades\Http;

class RegistryClient
{
    public function __construct(
        private readonly array $config,
    ) {}

    /**
     * @return array{registry: array<string, mixed>, items: array<int, RegistryItem>}
     */
    public function fetch(): array
    {
        $enabled = (bool) ($this->config['enabled'] ?? false);
        $url = (string) ($this->config['url'] ?? '');

        if (! $enabled) {
            throw RegistryException::disabled();
        }

        if ($url === '') {
            throw RegistryException::urlMissing();
        }

        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'http';

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw RegistryException::urlMissing();
        }

        $host = parse_url($url, PHP_URL_HOST) ?: $url;

        if (! $this->isHostAllowed($host)) {
            throw RegistryException::hostNotAllowed($host);
        }

        $timeout = (int) ($this->config['timeout'] ?? 5);
        $verify = (bool) ($this->config['verify_ssl'] ?? true);

        try {
            $response = Http::timeout($timeout)
                ->withOptions(['verify' => $verify])
                ->acceptJson()
                ->get($url);
        } catch (\Throwable $exception) {
            throw RegistryException::requestFailed($exception->getMessage());
        }

        if (! $response->successful()) {
            throw RegistryException::requestFailed('HTTP status '.$response->status());
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw RegistryException::invalidJson('Response is not a JSON object.');
        }

        return $payload;
    }

    private function isHostAllowed(string $host): bool
    {
        $allowLocalhost = (bool) ($this->config['allow_localhost'] ?? false);
        $environment = app()->environment();

        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return $allowLocalhost && in_array($environment, ['local', 'testing'], true);
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        $allowedHosts = array_values(array_filter((array) ($this->config['allowed_hosts'] ?? []), static fn ($host) => $host !== ''));

        if ($allowedHosts === []) {
            return false;
        }

        return in_array($host, $allowedHosts, true);
    }
}
