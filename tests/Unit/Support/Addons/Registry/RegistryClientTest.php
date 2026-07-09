<?php

namespace Tests\Unit\Support\Addons\Registry;

use App\Support\Addons\Registry\RegistryClient;
use App\Support\Addons\Registry\RegistryException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RegistryClientTest extends TestCase
{
    public function test_disabled_client_throws(): void
    {
        $client = new RegistryClient(['enabled' => false, 'url' => 'http://example.test']);

        $this->expectException(RegistryException::class);
        $this->expectExceptionMessage('Registry is disabled.');

        $client->fetch();
    }

    public function test_missing_url_throws(): void
    {
        $client = new RegistryClient(['enabled' => true, 'url' => '']);

        $this->expectException(RegistryException::class);
        $this->expectExceptionMessage('Registry URL is not configured.');

        $client->fetch();
    }

    public function test_invalid_scheme_throws(): void
    {
        $client = new RegistryClient(['enabled' => true, 'url' => 'file:///etc/passwd']);

        $this->expectException(RegistryException::class);
        $this->expectExceptionMessage('Registry URL is not configured.');

        $client->fetch();
    }

    public function test_empty_allowed_hosts_blocks_external_host(): void
    {
        $client = new RegistryClient([
            'enabled' => true,
            'url' => 'http://evil.example.test',
            'allowed_hosts' => [],
            'allow_localhost' => true,
        ]);

        $this->expectException(RegistryException::class);
        $this->expectExceptionMessage('Registry host [evil.example.test] is not allowed.');

        $client->fetch();
    }

    public function test_allowed_host_passes(): void
    {
        Http::fake([
            'http://registry.example.test' => Http::response([
                'registry' => ['name' => 'Test', 'version' => '1.0.0'],
                'items' => [],
            ], 200),
        ]);

        $client = new RegistryClient([
            'enabled' => true,
            'url' => 'http://registry.example.test',
            'allowed_hosts' => ['registry.example.test'],
            'allow_localhost' => true,
        ]);

        $result = $client->fetch();

        $this->assertSame(['name' => 'Test', 'version' => '1.0.0'], $result['registry']);
        $this->assertEmpty($result['items']);
    }

    public function test_localhost_allowed_in_testing_environment(): void
    {
        Http::fake([
            'http://localhost' => Http::response([
                'registry' => ['name' => 'Local', 'version' => '1.0.0'],
                'items' => [],
            ], 200),
        ]);

        $client = new RegistryClient([
            'enabled' => true,
            'url' => 'http://localhost',
            'allowed_hosts' => [],
            'allow_localhost' => true,
        ]);

        $result = $client->fetch();

        $this->assertSame(['name' => 'Local', 'version' => '1.0.0'], $result['registry']);
    }

    public function test_localhost_blocked_when_allow_localhost_disabled(): void
    {
        $client = new RegistryClient([
            'enabled' => true,
            'url' => 'http://localhost',
            'allowed_hosts' => [],
            'allow_localhost' => false,
        ]);

        $this->expectException(RegistryException::class);
        $this->expectExceptionMessage('Registry host [localhost] is not allowed.');

        $client->fetch();
    }

    public function test_timeout_returns_diagnostic(): void
    {
        Http::fake([
            'http://timeout.example.test' => Http::timeout(1),
        ]);

        $client = new RegistryClient([
            'enabled' => true,
            'url' => 'http://timeout.example.test',
            'allowed_hosts' => ['timeout.example.test'],
            'timeout' => 1,
        ]);

        $this->expectException(RegistryException::class);
        $this->expectExceptionMessage('Registry request failed:');

        $client->fetch();
    }

    public function test_http_error_returns_diagnostic(): void
    {
        Http::fake([
            'http://error.example.test' => Http::response([], 500),
        ]);

        $client = new RegistryClient([
            'enabled' => true,
            'url' => 'http://error.example.test',
            'allowed_hosts' => ['error.example.test'],
        ]);

        $this->expectException(RegistryException::class);
        $this->expectExceptionMessage('Registry request failed: HTTP status 500');

        $client->fetch();
    }

    public function test_invalid_json_returns_diagnostic(): void
    {
        Http::fake([
            'http://invalid.example.test' => Http::response('not-json', 200),
        ]);

        $client = new RegistryClient([
            'enabled' => true,
            'url' => 'http://invalid.example.test',
            'allowed_hosts' => ['invalid.example.test'],
        ]);

        $this->expectException(RegistryException::class);
        $this->expectExceptionMessage('Invalid registry JSON: Response is not a JSON object.');

        $client->fetch();
    }
}
