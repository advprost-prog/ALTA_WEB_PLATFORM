<?php

namespace Tests\Unit\Support\Addons\Registry;

use App\Support\Addons\Registry\RegistryClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegistryClientTest extends TestCase
{
    private function client(array $overrides = []): RegistryClient
    {
        return new RegistryClient(array_merge(['enabled' => true, 'url' => 'https://registry.example.test/api/v1/registry', 'allowed_hosts' => ['registry.example.test'], 'verify_ssl' => true, 'max_response_size' => 4096], $overrides));
    }

    public function test_valid_200_returns_transport_metadata_and_sends_validators(): void
    {
        Http::fake(['*' => Http::response(['registry' => [], 'items' => []], 200, ['Content-Type' => 'application/json', 'ETag' => '"abc"', 'Last-Modified' => 'Tue, 14 Jul 2026 00:00:00 GMT'])]);
        $result = $this->client()->fetch('"old"', 'Mon, 13 Jul 2026 00:00:00 GMT');
        $this->assertSame(200, $result->status);
        $this->assertSame('"abc"', $result->etag);
        $this->assertSame('registry.example.test', $result->sourceHost);
        Http::assertSent(fn ($request) => $request->hasHeader('If-None-Match', '"old"') && $request->hasHeader('If-Modified-Since', 'Mon, 13 Jul 2026 00:00:00 GMT'));
    }

    public function test_304_and_429_are_structured_results(): void
    {
        Http::fakeSequence()->push('', 304, ['ETag' => '"abc"'])->push('', 429, ['Retry-After' => '30']);
        $this->assertSame(304, $this->client()->fetch()->status);
        $limited = $this->client()->fetch();
        $this->assertSame('rate_limited', $limited->errorCategory);
        $this->assertSame('30', $limited->retryAfter);
    }

    public function test_policy_rejects_disabled_missing_credentials_and_unlisted_host(): void
    {
        $this->assertSame('registry_disabled', $this->client(['enabled' => false])->fetch()->errorCategory);
        $this->assertSame('policy_rejected', $this->client(['url' => 'https://user:secret@registry.example.test'])->fetch()->errorCategory);
        $result = $this->client(['url' => 'https://evil.example.test', 'allowed_hosts' => []])->fetch();
        $this->assertSame('host_rejected', $result->errorCategory);
        $this->assertStringNotContainsString('secret', (string) $result->diagnostic);
    }

    public function test_localhost_requires_testing_and_explicit_flag(): void
    {
        Http::fake(['*' => Http::response([], 200, ['Content-Type' => 'application/json'])]);
        $this->assertSame(200, $this->client(['url' => 'http://localhost/registry', 'allowed_hosts' => [], 'allow_localhost' => true])->fetch()->status);
        $this->assertSame('host_rejected', $this->client(['url' => 'http://localhost/registry', 'allow_localhost' => false])->fetch()->errorCategory);
    }

    public function test_network_failure_is_classified(): void
    {
        Http::fake(['*' => Http::timeout(1)]);
        $this->assertSame('connect_failure', $this->client()->fetch()->errorCategory);
    }

    #[DataProvider('connectionFailures')]
    public function test_connection_failures_are_safely_classified(string $message, string $expected): void
    {
        $method = new \ReflectionMethod(RegistryClient::class, 'connectionFailure');
        $this->assertSame($expected, $method->invoke($this->client(), new ConnectionException($message)));
    }

    public static function connectionFailures(): array
    {
        return [
            ['cURL error 6: Could not resolve host', 'dns_failure'],
            ['cURL error 7: Failed to connect', 'connect_failure'],
            ['cURL error 60: SSL certificate problem', 'tls_failure'],
            ['cURL error 28: Operation timed out', 'timeout'],
        ];
    }

    public function test_wrong_content_type_is_classified(): void
    {
        Http::fake(['*' => Http::response('{}', 200, ['Content-Type' => 'text/html'])]);
        $this->assertSame('html_challenge_response', $this->client()->fetch()->errorCategory);
    }

    public function test_non_html_unsupported_content_type_is_distinct(): void
    {
        Http::fake(['*' => Http::response('plain response', 200, ['Content-Type' => 'text/plain'])]);
        $this->assertSame('invalid_content_type', $this->client()->fetch()->errorCategory);
    }

    public function test_mislabeled_html_challenge_never_reaches_json_pipeline(): void
    {
        Http::fake(['*' => Http::response('<!DOCTYPE html><title>Browser verification</title>', 200, ['Content-Type' => 'application/json'])]);
        $result = $this->client()->fetch();
        $this->assertSame('html_challenge_response', $result->errorCategory);
        $this->assertNull($result->payload);
        $this->assertStringNotContainsString('Browser verification', (string) $result->diagnostic);
    }

    public function test_oversized_response_is_classified(): void
    {
        Http::fake(['*' => Http::response(str_repeat('x', 20), 200, ['Content-Type' => 'application/json'])]);
        $this->assertSame('oversized', $this->client(['max_response_size' => 10])->fetch()->errorCategory);
    }

    public function test_redirect_is_not_followed(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://evil.test'])]);
        $this->assertSame('redirect_rejected', $this->client()->fetch()->errorCategory);
    }

    public function test_production_requires_https_tls_and_rejects_private_literal_ip(): void
    {
        $previous = $this->app['env'];
        $this->app['env'] = 'production';
        try {
            $this->assertSame('policy_rejected', $this->client(['url' => 'http://registry.example.test'])->fetch()->errorCategory);
            $this->assertSame('policy_rejected', $this->client(['verify_ssl' => false])->fetch()->errorCategory);
            $this->assertSame('host_rejected', $this->client(['url' => 'https://10.0.0.1/registry', 'allowed_hosts' => ['10.0.0.1']])->fetch()->errorCategory);
        } finally {
            $this->app['env'] = $previous;
        }
    }
}
