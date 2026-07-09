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

    public function test_valid_payload_returns_decoded_json(): void
    {
        Http::fake([
            'http://example.test' => Http::response([
                'registry' => ['name' => 'Test', 'version' => '1.0.0'],
                'items' => [],
            ], 200),
        ]);

        $client = new RegistryClient([
            'enabled' => true,
            'url' => 'http://example.test',
            'timeout' => 5,
            'verify_ssl' => true,
        ]);

        $result = $client->fetch();

        $this->assertSame(['name' => 'Test', 'version' => '1.0.0'], $result['registry']);
        $this->assertEmpty($result['items']);
    }
}
