<?php

namespace Tests\Feature;

use App\Support\Addons\Registry\ManagedTreeEvidenceBuilder;
use App\Support\Addons\Registry\ManagedTreeInventory;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class ManagedTreeEvidenceBuilderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/managed-evidence-'.bin2hex(random_bytes(5)));
        File::makeDirectory($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_live_tree_is_verified_and_content_changes_change_evidence_without_mutation(): void
    {
        $live = $this->root.'/live';
        File::makeDirectory($live);
        File::put($live.'/module.json', json_encode(['code' => 'alta.demo', 'version' => '1.0.0', 'type' => 'module', 'vendor' => 'Alta']));
        File::put($live.'/payload.php', '<?php return true;');
        $before = $this->digest($live);

        $evidence = app(ManagedTreeEvidenceBuilder::class)->inspect('live', $live, $this->root, ['code' => 'alta.demo']);

        self::assertSame('verified', $evidence->integrity);
        self::assertSame('1.0.0', $evidence->version);
        self::assertSame($before, $this->digest($live));

        File::put($live.'/payload.php', '<?php return false;');
        $changed = app(ManagedTreeEvidenceBuilder::class)->inspect('live', $live, $this->root, ['code' => 'alta.demo', 'inventory_digest' => $evidence->inventoryDigest]);
        self::assertSame('integrity_failed', $changed->integrity);
    }

    public function test_candidate_requires_exact_atomic_ownership_metadata(): void
    {
        $candidate = $this->root.'/.Demo.promote-tx-1';
        File::makeDirectory($candidate);
        File::put($candidate.'/module.json', json_encode(['code' => 'alta.demo', 'version' => '2.0.0', 'type' => 'module', 'vendor' => 'Alta']));

        $missing = app(ManagedTreeEvidenceBuilder::class)->inspect('candidate', $candidate, $this->root, ['code' => 'alta.demo', 'operation_id' => 'tx-1']);
        self::assertSame('ownership_mismatch', $missing->ownership);

        $inventory = app(ManagedTreeInventory::class)->build($candidate);
        File::put($candidate.'/.candidate-evidence.json', json_encode(['operation_id' => 'tx-1', 'code' => 'alta.demo', 'version' => '2.0.0', 'inventory_digest' => $inventory['inventory_digest']]));
        $owned = app(ManagedTreeEvidenceBuilder::class)->inspect('candidate', $candidate, $this->root, ['code' => 'alta.demo', 'operation_id' => 'tx-1']);
        self::assertSame('managed', $owned->ownership);
        self::assertSame('verified', $owned->integrity);

        $wrong = app(ManagedTreeEvidenceBuilder::class)->inspect('candidate', $candidate, $this->root, ['code' => 'alta.demo', 'operation_id' => 'tx-2']);
        self::assertSame('ownership_mismatch', $wrong->ownership);
    }

    public function test_unmanaged_and_symlink_paths_fail_closed(): void
    {
        $outside = storage_path('framework/testing/outside-'.bin2hex(random_bytes(5)));
        File::makeDirectory($outside);
        File::put($outside.'/module.json', json_encode(['code' => 'alta.demo', 'version' => '1.0.0']));
        self::assertSame('unmanaged', app(ManagedTreeEvidenceBuilder::class)->inspect('live', $outside, $this->root)->ownership);

        $link = $this->root.'/linked';
        symlink($outside, $link);
        self::assertSame('symlink_conflict', app(ManagedTreeEvidenceBuilder::class)->inspect('live', $link, $this->root)->ownership);
        File::deleteDirectory($outside);
    }

    private function digest(string $path): string
    {
        $files = collect(File::allFiles($path))->mapWithKeys(fn ($file) => [$file->getRelativePathname() => hash_file('sha256', $file->getPathname())])->sortKeys()->all();

        return hash('sha256', json_encode($files));
    }
}
