<?php

namespace App\Support\Addons\Registry;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class RegistryClient
{
    public function __construct(private readonly array $config) {}

    public function fetch(?string $etag = null, ?string $lastModified = null): RegistryHttpResult
    {
        $requestedAt = now()->toIso8601String();
        try {
            [$url, $host] = $this->validatedEndpoint();
        } catch (RegistryException $e) {
            return $this->error(0, '', '', $requestedAt, 'policy', $e->getMessage());
        }

        $headers = [];
        if ($etag !== null && $etag !== '') {
            $headers['If-None-Match'] = $etag;
        }
        if ($lastModified !== null && $lastModified !== '') {
            $headers['If-Modified-Since'] = $lastModified;
        }

        try {
            $response = Http::connectTimeout(max(1, (int) ($this->config['connect_timeout'] ?? 3)))
                ->timeout(max(1, (int) ($this->config['timeout'] ?? 5)))
                ->withOptions([
                    'verify' => (bool) ($this->config['verify_ssl'] ?? true),
                    'allow_redirects' => (bool) ($this->config['allow_redirects'] ?? false),
                ])->withHeaders($headers)->acceptJson()->get($url);
        } catch (ConnectionException $e) {
            return $this->error(0, $url, $host, $requestedAt, 'network', 'Registry connection failed.', ['exception' => $e::class]);
        } catch (\Throwable $e) {
            return $this->error(0, $url, $host, $requestedAt, 'network', 'Registry request failed.', ['exception' => $e::class]);
        }

        $status = $response->status();
        $base = [
            $response->header('ETag'), $response->header('Last-Modified'), $response->header('Content-Type'),
            $response->header('Cache-Control'), $response->header('Retry-After'),
        ];
        if ($status === 304) {
            return $this->response($status, null, $base, $url, $host, $requestedAt);
        }
        if ($status === 429) {
            return $this->response($status, null, $base, $url, $host, $requestedAt, 'rate_limited', 'Registry rate limit reached.');
        }
        if ($status < 200 || $status >= 300) {
            return $this->response($status, null, $base, $url, $host, $requestedAt, 'http', "Registry returned HTTP {$status}.");
        }

        $contentType = strtolower((string) $response->header('Content-Type'));
        if ($contentType !== '' && ! str_contains($contentType, 'application/json') && ! str_contains($contentType, '+json')) {
            return $this->response($status, null, $base, $url, $host, $requestedAt, 'content_type', 'Registry returned an unsupported Content-Type.');
        }
        if (strlen($response->body()) > (int) ($this->config['max_response_size'] ?? 1048576)) {
            return $this->response($status, null, $base, $url, $host, $requestedAt, 'oversized', 'Registry response exceeds the configured size limit.');
        }
        $payload = $response->json();
        if (! is_array($payload)) {
            return $this->response($status, null, $base, $url, $host, $requestedAt, 'invalid_json', 'Registry returned invalid JSON.');
        }

        return $this->response($status, $payload, $base, $url, $host, $requestedAt);
    }

    public function isHostAllowed(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return (bool) ($this->config['allow_localhost'] ?? false) && app()->environment(['local', 'testing']);
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isPublicIp($host) && in_array($host, $this->allowedHosts(), true);
        }

        return in_array($host, $this->allowedHosts(), true);
    }

    public function isUrlAllowed(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }
        if (! app()->environment(['local', 'testing']) && strtolower($parts['scheme']) !== 'https') {
            return false;
        }

        return $this->isHostAllowed((string) $parts['host']);
    }

    private function validatedEndpoint(): array
    {
        if (! (bool) ($this->config['enabled'] ?? false)) {
            throw RegistryException::disabled();
        }
        $url = (string) ($this->config['url'] ?? '');
        $parts = parse_url($url);
        if ($url === '' || ! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw RegistryException::urlMissing();
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RegistryException('Registry URL credentials are not allowed.');
        }
        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw RegistryException::urlMissing();
        }
        if (! app()->environment(['local', 'testing']) && ($scheme !== 'https' || ! (bool) ($this->config['verify_ssl'] ?? true))) {
            throw new RegistryException('Registry requires HTTPS with certificate verification.');
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        if (! $this->isHostAllowed($host)) {
            throw RegistryException::hostNotAllowed($host);
        }

        return [$url, $host];
    }

    private function allowedHosts(): array
    {
        return array_map(fn ($h) => strtolower(rtrim(trim((string) $h), '.')), array_values(array_filter((array) ($this->config['allowed_hosts'] ?? []))));
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function error(int $status, string $url, string $host, string $requestedAt, string $category, string $diagnostic, ?array $context = null): RegistryHttpResult
    {
        return new RegistryHttpResult($status, null, null, null, null, null, null, $url, $host, $requestedAt, now()->toIso8601String(), $category, $diagnostic, $context);
    }

    private function response(int $status, ?array $payload, array $headers, string $url, string $host, string $requestedAt, ?string $category = null, ?string $diagnostic = null): RegistryHttpResult
    {
        return new RegistryHttpResult($status, $payload, $headers[0], $headers[1], $headers[2], $headers[3], $headers[4], $url, $host, $requestedAt, now()->toIso8601String(), $category, $diagnostic);
    }
}
