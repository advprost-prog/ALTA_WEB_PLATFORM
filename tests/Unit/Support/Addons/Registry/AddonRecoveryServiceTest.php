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
            'first install db only' => [['journal_state' => 'registering', 'previous_version' => null, 'target_version' => '2.0.0', 'live_version' => null, 'db_version' => '2.0.0'], 'first_install_db_present_live_missing', true],
            'ambiguous' => [['journal_state' => 'promoting', 'previous_version' => '1.0.0', 'target_version' => '2.0.0', 'live_version' => '3.0.0', 'db_version' => '1.5.0'], 'ambiguous_conflict', false],
            'completed' => [['journal_state' => 'completed', 'previous_version' => null, 'target_version' => '2.0.0', 'live_version' => '2.0.0', 'db_version' => '2.0.0'], 'completed_consistent', false],
            'rolled back' => [['journal_state' => 'rolled_back', 'previous_version' => '1.0.0', 'target_version' => '2.0.0', 'live_version' => '1.0.0', 'db_version' => '1.0.0'], 'rolled_back_consistent', false],
            'unmanaged precedence' => [['journal_state' => 'completed', 'previous_version' => '1.0.0', 'target_version' => '2.0.0', 'live_version' => '2.0.0', 'db_version' => '2.0.0', 'live_status' => 'unmanaged'], 'unmanaged_path_conflict', false],
            'integrity precedence' => [['journal_state' => 'completed', 'previous_version' => '1.0.0', 'target_version' => '2.0.0', 'live_version' => '2.0.0', 'db_version' => '2.0.0', 'live_status' => 'integrity_failed'], 'integrity_failure', false],
            'ownership precedence' => [['journal_state' => 'staged', 'previous_version' => '1.0.0', 'target_version' => '2.0.0', 'live_version' => '1.0.0', 'db_version' => '1.0.0', 'staging_status' => 'ownership_mismatch'], 'unmanaged_path_conflict', false],
            'staged only' => [['journal_state' => 'staged', 'previous_version' => '1.0.0', 'target_version' => '2.0.0', 'live_version' => '1.0.0', 'db_version' => '1.0.0', 'staging_verified' => true], 'staged_only', true],
            'candidate only' => [['journal_state' => 'promoting', 'previous_version' => '1.0.0', 'target_version' => '2.0.0', 'live_version' => '1.0.0', 'db_version' => '1.0.0', 'candidate_verified' => true], 'candidate_only', true],
            'backup live missing' => [['journal_state' => 'promoting', 'previous_version' => '1.0.0', 'target_version' => '2.0.0', 'live_version' => null, 'db_version' => '1.0.0', 'backup_valid' => true], 'backup_created_live_missing', true],
            'first install live only' => [['journal_state' => 'promoted', 'previous_version' => null, 'target_version' => '2.0.0', 'live_version' => '2.0.0', 'db_version' => null], 'first_install_live_present_db_absent', true],
            'old live db new' => [['journal_state' => 'recovering', 'previous_version' => '1.0.0', 'target_version' => '2.0.0', 'live_version' => '1.0.0', 'db_version' => '2.0.0'], 'rollback_incomplete', true],
            'rollback ambiguous' => [['journal_state' => 'recovering', 'previous_version' => '1.0.0', 'target_version' => '2.0.0', 'live_version' => '2.0.0', 'db_version' => '2.0.0', 'backup_valid' => true], 'rollback_incomplete', false],
        ];
    }
}
