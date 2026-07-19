<?php

namespace Tests\Unit\Support\Addons\Providers;

use App\Models\SystemAddon;
use App\Support\Addons\Providers\AddonProviderException;
use App\Support\Addons\Providers\AddonProviderResolver;
use App\Support\Addons\Providers\PackageScopedAutoloadRegistry;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AddonProviderResolverTest extends TestCase
{
    private string $root;

    private PackageScopedAutoloadRegistry $loaders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = base_path('modules/PackageContract');
        File::deleteDirectory($this->root);
        File::ensureDirectoryExists($this->root);
        $this->loaders = app(PackageScopedAutoloadRegistry::class);
    }

    protected function tearDown(): void
    {
        foreach (['valid', 'collision-a', 'collision-b', 'invalid'] as $code) {
            $this->loaders->unregister('tests.'.$code);
        }
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_valid_package_provider_resolves_loads_and_registers_once(): void
    {
        $addon = $this->package('Valid', 'tests.valid', 'Vendor\\Valid\\', 'ValidProvider');
        $resolver = app(AddonProviderResolver::class);
        $resolved = $resolver->resolve($addon);

        $this->assertSame('package', $resolved->mode);
        $this->assertStringEndsWith('/src/ValidProvider.php', str_replace('\\', '/', $resolved->providerFile));
        $this->assertTrue($resolver->load($addon));
        $this->assertTrue($resolver->load($addon));
        $this->assertTrue($this->loaders->isRegistered('tests.valid'));
        $this->assertSame(1, $this->loaders->count());
    }

    public function test_loaded_provider_reregisters_package_loader_after_unregister(): void
    {
        $addon = $this->package('Reloaded', 'tests.valid', 'Vendor\\Reloaded\\', 'ReloadedProvider');
        $root = dirname(base_path($addon->manifest_path));
        file_put_contents($root.'/src/AuxiliaryService.php', "<?php\nnamespace Vendor\\Reloaded;\nfinal class AuxiliaryService {}\n");
        $resolver = app(AddonProviderResolver::class);

        $this->assertTrue($resolver->load($addon));
        $resolver->unregister($addon->code);
        $this->assertFalse($this->loaders->isRegistered($addon->code));
        $this->assertTrue($resolver->load($addon));
        $this->assertTrue($this->loaders->isRegistered($addon->code));
        $this->assertTrue(class_exists('Vendor\\Reloaded\\AuxiliaryService'));
    }

    public function test_existing_bundled_module_convention_remains_supported(): void
    {
        $addon = new SystemAddon([
            'code' => 'core.products', 'type' => 'module',
            'manifest_path' => 'modules/Core/Products/module.json',
            'service_provider' => 'Modules\\Core\\Products\\ProductsServiceProvider',
        ]);
        $addon->metadata = ['manifest' => json_decode((string) file_get_contents(base_path($addon->manifest_path)), true)];

        $resolved = app(AddonProviderResolver::class)->resolve($addon);
        $this->assertSame('bundled', $resolved->mode);
        $this->assertTrue(app(AddonProviderResolver::class)->load($addon));
    }

    #[DataProvider('invalidMetadataProvider')]
    public function test_invalid_package_metadata_fails_closed(string $mutation, string $expectedCode): void
    {
        $addon = $this->package('Invalid', 'tests.invalid', 'Vendor\\Invalid\\', 'InvalidProvider');
        $root = dirname(base_path($addon->manifest_path));
        $composer = json_decode((string) file_get_contents($root.'/composer.json'), true);

        match ($mutation) {
            'missing' => unlink($root.'/composer.json'),
            'malformed' => file_put_contents($root.'/composer.json', '{bad'),
            'missing_psr4' => file_put_contents($root.'/composer.json', json_encode(['name' => 'vendor/invalid', 'autoload' => []])),
            'absolute' => $this->writeComposer($root, ['Vendor\\Invalid\\' => '/tmp']),
            'traversal' => $this->writeComposer($root, ['Vendor\\Invalid\\' => '../outside']),
            'reserved' => $this->replaceProvider($addon, $root, 'App\\Invalid\\InvalidProvider', ['App\\Invalid\\' => 'src/']),
            'modules' => $this->replaceProvider($addon, $root, 'Modules\\Foreign\\InvalidProvider', ['Modules\\Foreign\\' => 'src/']),
            'host_dependency' => $this->replaceProvider($addon, $root, 'GuzzleHttp\\InvalidProvider', ['GuzzleHttp\\' => 'src/']),
            'prefix' => $this->replaceProvider($addon, $root, 'Other\\InvalidProvider', $composer['autoload']['psr-4']),
            'files' => $this->writeComposer($root, $composer['autoload']['psr-4'], ['files' => ['bootstrap.php']]),
            'classmap' => $this->writeComposer($root, [], ['classmap' => ['src/']]),
        };

        try {
            app(AddonProviderResolver::class)->resolve($addon);
            $this->fail('Unsafe package metadata was accepted.');
        } catch (AddonProviderException $exception) {
            $this->assertSame($expectedCode, $exception->diagnosticCode);
            $this->assertFalse($this->loaders->isRegistered('tests.invalid'));
        }
    }

    public static function invalidMetadataProvider(): array
    {
        return [
            'missing composer' => ['missing', 'package_metadata_missing'],
            'malformed composer' => ['malformed', 'package_metadata_invalid'],
            'missing psr4' => ['missing_psr4', 'psr4_missing'],
            'absolute root' => ['absolute', 'psr4_path_invalid'],
            'traversal root' => ['traversal', 'psr4_path_invalid'],
            'host namespace' => ['reserved', 'namespace_reserved'],
            'bundled namespace claim' => ['modules', 'namespace_reserved'],
            'host dependency namespace' => ['host_dependency', 'namespace_reserved'],
            'provider prefix mismatch' => ['prefix', 'provider_prefix_mismatch'],
            'autoload files' => ['files', 'package_autoload_unsupported'],
            'classmap only' => ['classmap', 'package_autoload_unsupported'],
        ];
    }

    public function test_symlink_source_escape_and_provider_escape_are_rejected(): void
    {
        $addon = $this->package('Invalid', 'tests.invalid', 'Vendor\\Invalid\\', 'InvalidProvider');
        $root = dirname(base_path($addon->manifest_path));
        File::deleteDirectory($root.'/src');
        symlink(sys_get_temp_dir(), $root.'/src');

        $this->assertDiagnostic($addon, 'psr4_path_escape');
        unlink($root.'/src');
        File::ensureDirectoryExists($root.'/src');
        $outside = tempnam(sys_get_temp_dir(), 'external-provider-');
        file_put_contents($outside, '<?php');
        symlink($outside, $root.'/src/InvalidProvider.php');
        $this->assertDiagnostic($addon, 'provider_file_escape');
        unlink($outside);
    }

    public function test_missing_ambiguous_invalid_and_reflection_mismatch_providers_fail_closed(): void
    {
        $missing = $this->package('Missing', 'tests.invalid', 'Vendor\\Missing\\', 'MissingProvider');
        unlink(dirname(base_path($missing->manifest_path)).'/src/MissingProvider.php');
        $this->assertDiagnostic($missing, 'provider_file_missing');

        $ambiguous = $this->package('Ambiguous', 'tests.invalid', 'Vendor\\Ambiguous\\', 'Provider', ['src/', 'lib/']);
        $this->providerFile(dirname(base_path($ambiguous->manifest_path)).'/lib/Provider.php', 'Vendor\\Ambiguous', 'Provider');
        $this->assertDiagnostic($ambiguous, 'provider_file_ambiguous');

        $invalid = $this->package('Plain', 'tests.invalid', 'Vendor\\Plain\\', 'PlainProvider', providerBase: null);
        $this->assertLoadDiagnostic($invalid, 'provider_class_invalid');

        $mismatch = $this->package('Mismatch', 'tests.invalid', 'Vendor\\Mismatch\\', 'ExpectedProvider', declaredClass: 'DifferentProvider');
        $this->assertLoadDiagnostic($mismatch, 'provider_class_invalid');
    }

    public function test_namespace_collision_fails_closed_and_unregister_releases_claim(): void
    {
        $first = $this->package('CollisionA', 'tests.collision-a', 'Vendor\\Shared\\', 'FirstProvider');
        $second = $this->package('CollisionB', 'tests.collision-b', 'Vendor\\Shared\\Child\\', 'SecondProvider');
        $resolver = app(AddonProviderResolver::class);
        $this->assertTrue($resolver->load($first));
        $this->assertLoadDiagnostic($second, 'namespace_collision');
        $resolver->unregister('tests.collision-a');
        $this->assertTrue($resolver->load($second));
    }

    public function test_preloaded_provider_from_an_unapproved_file_is_rejected(): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'preloaded-provider-');
        file_put_contents($outside, '<?php namespace Vendor\\Preloaded; class Provider extends \\Illuminate\\Support\\ServiceProvider {}');
        require $outside;
        $addon = $this->package('Preloaded', 'tests.invalid', 'Vendor\\Preloaded\\', 'Provider');

        $this->assertLoadDiagnostic($addon, 'provider_reflection_mismatch');
        unlink($outside);
    }

    private function package(string $directory, string $code, string $prefix, string $provider, array|string $roots = 'src/', ?string $providerBase = 'service', ?string $declaredClass = null): SystemAddon
    {
        $root = $this->root.'/'.$directory;
        $roots = (array) $roots;
        File::ensureDirectoryExists($root.'/src');
        foreach ($roots as $source) {
            File::ensureDirectoryExists($root.'/'.trim($source, '/'));
        }
        $class = $declaredClass ?? $provider;
        $namespace = rtrim($prefix, '\\');
        $base = $providerBase === 'service' ? ' extends \\Illuminate\\Support\\ServiceProvider' : '';
        $this->providerFile($root.'/'.trim($roots[0], '/').'/'.$provider.'.php', $namespace, $class, $base);
        $this->writeComposer($root, [$prefix => $roots]);
        file_put_contents($root.'/module.json', json_encode(['code' => $code, 'type' => 'module']));

        $addon = new SystemAddon([
            'code' => $code, 'type' => 'module', 'manifest_path' => 'modules/PackageContract/'.$directory.'/module.json',
            'service_provider' => $prefix.$provider,
        ]);
        $addon->metadata = ['manifest' => ['code' => $code, 'type' => 'module', 'service_provider' => $prefix.$provider]];

        return $addon;
    }

    private function writeComposer(string $root, array $psr4, array $additionalAutoload = []): void
    {
        file_put_contents($root.'/composer.json', json_encode([
            'name' => 'vendor/package-contract',
            'autoload' => array_merge(['psr-4' => $psr4], $additionalAutoload),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function providerFile(string $path, string $namespace, string $class, string $base = ' extends \\Illuminate\\Support\\ServiceProvider'): void
    {
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, "<?php\nnamespace {$namespace};\nclass {$class}{$base} {}\n");
    }

    private function replaceProvider(SystemAddon $addon, string $root, string $provider, array $psr4): void
    {
        $addon->service_provider = $provider;
        $this->writeComposer($root, $psr4);
    }

    private function assertDiagnostic(SystemAddon $addon, string $code): void
    {
        try {
            app(AddonProviderResolver::class)->resolve($addon);
            $this->fail('Expected provider diagnostic was not raised.');
        } catch (AddonProviderException $exception) {
            $this->assertSame($code, $exception->diagnosticCode);
        }
    }

    private function assertLoadDiagnostic(SystemAddon $addon, string $code): void
    {
        try {
            app(AddonProviderResolver::class)->load($addon);
            $this->fail('Expected provider load diagnostic was not raised.');
        } catch (AddonProviderException $exception) {
            $this->assertSame($code, $exception->diagnosticCode);
            $this->assertFalse($this->loaders->isRegistered($addon->code));
        }
    }
}
