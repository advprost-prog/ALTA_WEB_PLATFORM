<?php

namespace Tests\Unit\Support\Addons\Registry;

use App\Support\Addons\Registry\AddonRecoveryService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AddonRecoveryServiceTest extends TestCase
{
    #[DataProvider('classifications')]
    public function test_recovery_evidence_is_classified_deterministically(array $evidence, string $classification, bool $automatic): void
    {
        $service = (new ReflectionClass(AddonRecoveryService::class))->newInstanceWithoutConstructor();
        $result = $service->classifyEvidence($evidence);

        self::assertSame($classification, $result[0]);
        self::assertSame($automatic, $result[2]);
    }

    public static function classifications(): array
    {
        return [
            'prepared first install' => [['journal_state' => 'prepared', 'previous_version' => null, 'target_version' => '2.0.0', 'live_version' => null, 'db_version' => null], 'prepared_no_mutation', true],
            'target live old db' => [['journal_state' => 'promoted', 'previous_version' => '1.0.0', 'target_version' => '2.0.0', 'live_version' => '2.0.0', 'db_version' => '1.0.0', 'backup_valid' => true], 'new_live_promoted_db_old', true],
            'target complete missing marker' => [['journal_state' => 'registering', 'previous_version' => '1.0.0', 'target_version' => '2.0.0', 'live_version' => '2.0.0', 'db_version' => '2.0.0', 'backup_valid' => true], 'new_live_promoted_db_new', true],
            'first install db only' => [['journal_state' => 'registering', 'previous_version' => null, 'target_version' => '2.0.0', 'live_version' => null, 'db_version' => '2.0.0'], 'first_install_db_present_live_missing', false],
            'ambiguous' => [['journal_state' => 'promoting', 'previous_version' => '1.0.0', 'target_version' => '2.0.0', 'live_version' => '3.0.0', 'db_version' => '1.5.0'], 'ambiguous_conflict', false],
        ];
    }
}
