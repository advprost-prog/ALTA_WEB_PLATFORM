<?php

namespace Tests\Unit\Support\Addons\Registry;

use App\Support\Addons\Registry\ManagedTreeEvidence;
use PHPUnit\Framework\TestCase;

final class ManagedTreeEvidenceTest extends TestCase
{
    public function test_serialization_is_normalized_and_contains_no_path(): void
    {
        $evidence = new ManagedTreeEvidence('live', 'present', 'verified', 'managed', 'alta.demo', '2.0.0', 'module', 'Alta',
            manifestDigest: str_repeat('a', 64), inventoryDigest: str_repeat('b', 64), fileCount: 2, totalBytes: 12,
            diagnosticCode: 'tree_verified', diagnosticMessage: 'Managed tree is verified.');

        self::assertSame($evidence->toArray(), $evidence->toArray());
        self::assertArrayNotHasKey('path', $evidence->toArray());
        self::assertStringNotContainsString('/home/', json_encode($evidence->toArray()));
    }
}
