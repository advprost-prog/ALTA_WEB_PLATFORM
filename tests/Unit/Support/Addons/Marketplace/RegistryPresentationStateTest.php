<?php

namespace Tests\Unit\Support\Addons\Marketplace;

use App\Support\Addons\Marketplace\RegistryPresentationState;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RegistryPresentationStateTest extends TestCase
{
    #[DataProvider('states')]
    public function test_transport_results_are_mapped_to_operator_states(array $config, array $catalog, string $expected): void
    {
        config(['addons-registry' => array_replace(config('addons-registry'), $config)]);

        $this->assertSame($expected, app(RegistryPresentationState::class)->resolve($catalog));
    }

    public static function states(): array
    {
        return [
            'connected empty' => [['enabled' => true, 'url' => 'https://registry.test'], ['state' => 'fresh', 'items' => []], 'connected_empty'],
            'connected with items' => [['enabled' => true, 'url' => 'https://registry.test'], ['state' => 'fresh', 'items' => [['code' => 'one']]], 'connected_with_items'],
            'stale cache' => [['enabled' => true, 'url' => 'https://registry.test'], ['state' => 'stale', 'items' => [['code' => 'one']]], 'stale_cache'],
            'unavailable with cache' => [['enabled' => true, 'url' => 'https://registry.test'], ['state' => 'offline', 'items' => [['code' => 'one']]], 'unavailable_with_cache'],
            'unavailable without cache' => [['enabled' => true, 'url' => 'https://registry.test'], ['state' => 'unavailable', 'items' => []], 'unavailable_without_cache'],
            'disabled' => [['enabled' => false, 'url' => 'https://registry.test'], ['state' => 'disabled', 'items' => []], 'disabled'],
            'not configured' => [['enabled' => false, 'url' => ''], ['state' => 'disabled', 'items' => []], 'not_configured'],
            'invalid response' => [['enabled' => true, 'url' => 'https://registry.test'], ['state' => 'unavailable', 'items' => [], 'meta' => ['last_error_category' => 'invalid_json']], 'invalid_response'],
            'html challenge' => [['enabled' => true, 'url' => 'https://registry.test'], ['state' => 'unavailable', 'items' => [], 'meta' => ['last_error_category' => 'html_challenge_response']], 'html_challenge_response'],
            'host rejected' => [['enabled' => true, 'url' => 'https://registry.test'], ['state' => 'unavailable', 'items' => [], 'meta' => ['last_error_category' => 'host_rejected']], 'host_rejected'],
            'timeout' => [['enabled' => true, 'url' => 'https://registry.test'], ['state' => 'unavailable', 'items' => [], 'meta' => ['last_error_category' => 'timeout']], 'timeout'],
        ];
    }
}
