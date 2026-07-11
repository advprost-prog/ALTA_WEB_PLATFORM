<?php

namespace Tests\Feature;

use App\Support\Addons\Registry\ArchiveSafetyValidator;
use App\Support\Addons\Registry\ArtifactStagingManager;
use App\Support\Addons\Registry\ArtifactStagingResult;
use App\Support\Addons\Registry\ArtifactStagingStatus;
use App\Support\Addons\Registry\SafeArchiveExtractor;
use Tests\TestCase;
use ZipArchive;

class AddonArtifactStagingTest extends TestCase
{
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_dir($path)) {
                foreach (array_reverse(glob($path.'/**', GLOB_NOSORT) ?: []) as $entry) {
                    is_dir($entry) ? @rmdir($entry) : @unlink($entry);
                }
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_valid_archive_is_validated_and_streamed_without_executable_permissions(): void
    {
        $archive = $this->zip(['manifest.json' => '{"code":"demo"}', 'src/Provider.php' => '<?php return true;']);
        $validation = app(ArchiveSafetyValidator::class)->validate($archive);

        $this->assertTrue($validation['success'], implode(' ', $validation['diagnostics']));
        $this->assertSame('manifest.json', $validation['manifest_path']);

        $payload = $this->temporaryDirectory();
        $extracted = app(SafeArchiveExtractor::class)->extract($archive, $payload, $validation['inventory']);

        $this->assertSame(2, $extracted['file_count']);
        $this->assertFileExists($payload.'/src/Provider.php');
        $this->assertSame(0644, fileperms($payload.'/src/Provider.php') & 0777);
        $this->assertSame(hash_file('sha256', $payload.'/src/Provider.php'), collect($extracted['inventory'])->firstWhere('path', 'src/Provider.php')['sha256']);
    }

    public function test_path_traversal_absolute_paths_and_case_collisions_are_rejected(): void
    {
        foreach ([
            ['../evil.php' => 'x', 'manifest.json' => '{}'],
            ['..\\evil.php' => 'x', 'manifest.json' => '{}'],
            ['/etc/passwd' => 'x', 'manifest.json' => '{}'],
            ['C:\\evil.php' => 'x', 'manifest.json' => '{}'],
            ['Module.php' => 'a', 'module.php' => 'b', 'manifest.json' => '{}'],
        ] as $entries) {
            $result = app(ArchiveSafetyValidator::class)->validate($this->zip($entries));
            $this->assertFalse($result['success']);
            $this->assertNotEmpty($result['diagnostics']);
        }
    }

    public function test_manifest_policy_and_zip_bomb_limits_are_enforced(): void
    {
        $missing = app(ArchiveSafetyValidator::class)->validate($this->zip(['README.md' => 'x']));
        $duplicate = app(ArchiveSafetyValidator::class)->validate($this->zip(['module.json' => '{}', 'manifest.json' => '{}']));
        $nested = app(ArchiveSafetyValidator::class)->validate($this->zip(['package/manifest.json' => '{}']));
        $limited = app(ArchiveSafetyValidator::class)->validate($this->zip(['manifest.json' => '{}', 'large.txt' => str_repeat('a', 100)]), ['max_single_file_size' => 10]);

        $this->assertFalse($missing['success']);
        $this->assertFalse($duplicate['success']);
        $this->assertFalse($nested['success']);
        $this->assertFalse($limited['success']);
    }

    public function test_staging_services_result_and_status_contract_resolve(): void
    {
        $this->assertInstanceOf(ArchiveSafetyValidator::class, app(ArchiveSafetyValidator::class));
        $this->assertInstanceOf(SafeArchiveExtractor::class, app(SafeArchiveExtractor::class));
        $this->assertInstanceOf(ArtifactStagingManager::class, app(ArtifactStagingManager::class));

        $result = ArtifactStagingResult::failure('demo', null, ArtifactStagingStatus::BLOCKED, 'blocked', ['reason']);
        $this->assertFalse($result->success);
        $this->assertSame('blocked', $result->status);
        $this->assertSame(['reason'], $result->blockedReasons);
    }

    private function zip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'staging-').'.zip';
        $this->paths[] = $path;
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }

    private function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir().'/addon-staging-'.str()->uuid();
        mkdir($path, 0755, true);
        $this->paths[] = $path;

        return $path;
    }
}
