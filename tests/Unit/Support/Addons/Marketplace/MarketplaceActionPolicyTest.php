<?php

namespace Tests\Unit\Support\Addons\Marketplace;

use App\Support\Addons\Marketplace\MarketplaceActionPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MarketplaceActionPolicyTest extends TestCase
{
    #[DataProvider('blockedCases')]
    public function test_remote_download_is_fail_closed_with_stable_reason(array $override, string $reason): void
    {
        $context = array_merge($this->validContext(), $override);
        $decision = (new MarketplaceActionPolicy)->assess($context)['download'];

        $this->assertFalse($decision['allowed']);
        $this->assertSame($reason, $decision['reason_code']);
    }

    public static function blockedCases(): array
    {
        return [
            [['registry_state' => 'stale'], 'registry_not_fresh'],
            [['registry_state' => 'offline'], 'registry_not_fresh'],
            [['identity_ok' => false], 'identity_conflict'],
            [['compatibility' => 'incompatible'], 'platform_incompatible'],
            [['dependencies_blocked' => true], 'dependencies_blocked'],
            [['downloads_enabled' => false], 'downloads_disabled'],
        ];
    }

    public function test_update_requires_strictly_newer_remote_and_blocks_downgrade(): void
    {
        $policy = new MarketplaceActionPolicy;
        $this->assertTrue($policy->assess($this->validContext())['update_remote']['allowed']);
        $decision = $policy->assess(array_merge($this->validContext(), ['version_state' => 'local_newer']))['update_remote'];
        $this->assertFalse($decision['allowed']);
        $this->assertSame('downgrade_blocked', $decision['reason_code']);
    }

    private function validContext(): array
    {
        return ['has_remote' => true, 'registry_state' => 'fresh', 'identity_ok' => true, 'compatibility' => 'compatible', 'dependencies_blocked' => false, 'installed' => true, 'version_state' => 'update_available', 'downloads_enabled' => true];
    }
}
