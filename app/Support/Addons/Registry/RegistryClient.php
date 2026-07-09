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

        $host = parse_url($url, PHP_URL_HOST) ?: $url;

        if ($host !== null && filter_var($host, FILTER_VALIDATE_IP) === false && ! in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            $allowedHosts = array_values(array_filter((array) ($this->config['allowed_hosts'] ?? []), static fn ($host) => $host !== ''));

            if ($allowedHosts !== [] && ! in_array($host, $allowedHosts, true)) {
                throw RegistryException::hostNotAllowed($host);
            }
        }

        if (preg_match('/^https?:\/\//', $url) !== 1) {
            throw RegistryException::urlMissing();
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
}
