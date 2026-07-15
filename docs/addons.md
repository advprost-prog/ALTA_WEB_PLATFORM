# Addon Foundation

Phase 1 adds a local addon core for modules and extensions. Marketplace purchase, remote package download, signatures, and full external install flows are intentionally out of scope.

## Modules vs Extensions

Module:

- larger functional unit with its own domain;
- may provide routes, views, migrations, seeders, Filament pages/resources/widgets, permissions, settings, and menu entries;
- examples for later phases: suppliers, warehouse, fiscal/RRO, delivery, supplier integration.

Extension:

- smaller enhancement over existing functionality;
- may add hooks, widgets, custom fields, export/import, storefront blocks, banner effects, or admin actions;
- examples for later phases: CSV product export, extra product badge, banner SEO extension.

Marketplace should later be only a package source. The local core must already discover, validate, install, enable, disable, and diagnose trusted local addons.

## Directory Layout

Local modules:

```text
modules/
  Vendor/
    ModuleCode/
      module.json
      src/
      database/migrations/
      database/seeders/
      resources/views/
      routes/web.php
      routes/admin.php
```

Local extensions:

```text
extensions/
  Vendor/
    ExtensionCode/
      extension.json
      src/
      resources/views/
```

Bundled/internal service providers must live under the addon directory and match the host-derived namespace:

- modules: `Modules\Vendor\ModuleCode\...`
- extensions: `Extensions\Vendor\ExtensionCode\...`

Trusted standalone Marketplace packages may instead own a stable vendor namespace. They use the same manifest `service_provider` field plus package-local `composer.json` `autoload.psr-4`; no second namespace manifest field is required. See “Standalone package providers” below.

## Manifest Format

Minimal `module.json`:

```json
{
  "code": "demo.hello-module",
  "type": "module",
  "name": "Demo Hello Module",
  "description": "Local module",
  "version": "0.1.0",
  "vendor": "Demo",
  "author": "Alta Trade",
  "enabled_by_default": false,
  "service_provider": null,
  "dependencies": [],
  "permissions": [],
  "menu": [],
  "settings_schema": [],
  "migrations": [],
  "seeders": [],
  "routes": {
    "web": "routes/web.php",
    "admin": "routes/admin.php"
  },
  "compatibility": {
    "app_min_version": null,
    "app_max_version": null,
    "laravel_version": ">=12.0",
    "php_version": ">=8.3"
  }
}
```

Minimal `extension.json`:

```json
{
  "code": "demo.admin-widget",
  "type": "extension",
  "name": "Demo Admin Widget Extension",
  "description": "Local extension",
  "version": "0.1.0",
  "vendor": "Demo",
  "author": "Alta Trade",
  "enabled_by_default": false,
  "service_provider": null,
  "dependencies": [],
  "hooks": [],
  "settings_schema": [],
  "compatibility": {
    "app_min_version": null,
    "app_max_version": null,
    "laravel_version": ">=12.0",
    "php_version": ">=8.3"
  }
}
```

`code` must be a stable slug-like identifier such as `alta.suppliers` or `demo.admin-widget`. Invalid manifests are logged and reported by diagnostics, but do not crash the application.

## Database

Phase 1 creates:

- `system_addons` - registry and lifecycle state;
- `system_addon_settings` - JSON settings by addon code and key;
- `system_addon_events` - lifecycle, discovery, and diagnostics events.

Statuses:

- `discovered`
- `installed`
- `enabled`
- `disabled`
- `failed`
- `removed`

`uninstall` and `remove` are soft operations in Phase 1. They do not delete physical files.

`last_error` keeps a short admin-readable reason for runtime/lifecycle failures. Full diagnostic details remain in `system_addon_events.context`.

## Core Services

- `App\Support\Addons\AddonDiscovery`
- `App\Support\Addons\AddonManifestValidator`
- `App\Support\Addons\AddonRegistry`
- `App\Support\Addons\AddonLifecycle`
- `App\Support\Addons\AddonHookRegistry`
- `App\Support\Addons\AddonHealthCheck`
- `App\Support\Addons\AddonManager`

`App\Providers\AddonServiceProvider` boots enabled addons only. Disabled or failed addons do not register routes, views, service providers, or hooks.

Runtime safe-failure policy:

- invalid manifest during discovery is reported, but does not crash the app;
- missing manifest for an enabled addon marks the addon as `failed`, updates `last_error`, and keeps app boot alive;
- missing service provider class marks the addon as `failed`, updates `last_error`, and keeps app boot alive;
- service provider exceptions mark the addon as `failed`, update `last_error`, and keep app boot alive;
- failed addons are automatically deactivated for boot (`is_enabled=false`) and do not register routes/hooks/providers.

## CLI

```bash
php artisan addons:discover
php artisan addons:list
php artisan addons:install demo.hello-module
php artisan addons:enable demo.hello-module
php artisan addons:disable demo.hello-module
php artisan addons:uninstall demo.hello-module
php artisan addons:doctor
```

`addons:doctor` reports invalid manifests, duplicate codes, missing service providers, dependency issues, compatibility issues, enabled addons with missing manifests, and failed statuses.

`addons:list` includes the current status and a shortened `last_error` summary for quick operator triage.

## Admin UI

Filament menu:

- `Система -> Модулі та розширення`

The resource lists code, name, type, version, vendor, source, status, enabled flag, and last error. It includes local lifecycle actions and a `Discover / rescan` header action. The view page shows manifest JSON and recent addon logs.

## Hooks

`AddonHookRegistry` supports:

- `register(hookName, handler, priority, addonCode)`
- `get(hookName)`
- `run(hookName, payload)`
- `filter(hookName, payload)`

Known future hook names:

- `admin.dashboard.widgets`
- `admin.navigation.items`
- `storefront.home.blocks`
- `storefront.product.card.badges`
- `storefront.product.detail.sections`
- `catalog.product.saved`
- `order.created`
- `banner.render.before`
- `banner.render.after`

Phase 1 registers manifest-declared hooks for enabled addons. Broad integration into every storefront/admin surface is reserved for later phases.

## Permissions

Module manifests may declare `permissions`. Phase 1 stores and exposes enabled addon permissions through `AddonRegistry::permissions()`. It does not replace the current user role model.

## Security Boundaries

- Phase 1 works only with trusted local addons in `modules/` and `extensions/`.
- No marketplace downloads, payments, remote install, or package execution are implemented.
- Manifests cannot contain arbitrary PHP code.
- No `eval` is used.
- Service providers must pass either the bundled namespace/path contract or the standalone package contract.
- Missing service provider classes are reported, not allowed to crash the app.
- Runtime boot failures are isolated per addon; they do not crash the application kernel.
- Disabled addons do not register routes, hooks, menu entries, widgets, or providers.
- Phase 2 should add checksums/signatures and a separate reviewed marketplace install process.

## Standalone package providers

Standalone packages are active only after the existing verified Marketplace flow promotes them into an approved module or extension live root. Quarantine, staging, backup, recovery and arbitrary filesystem locations are never provider roots. The package stays disabled after install until an explicit enable operation.

Required production metadata is the existing `module.json`/`extension.json` plus `composer.json`. The manifest declares one exact provider FQCN. Composer metadata must contain a syntactically valid package name and a package-owned PSR-4 mapping, for example:

```json
{
  "name": "neutral-vendor/audit-tools",
  "autoload": {
    "psr-4": {
      "NeutralVendor\\AuditTools\\": "src/"
    }
  }
}
```

The host does not run Composer and does not install package dependencies. It ignores `autoload-dev`, scripts and repositories and never executes them. `autoload.files`, classmap and include-path loading are rejected with `package_autoload_unsupported`; package-bundled `vendor/` is not searched or registered.

Resolution is fail-closed:

1. Canonicalize the manifest parent and prove it is below the bundled root or configured active Marketplace root.
2. Parse `composer.json`, validate the package name and PSR-4 mapping, and reject absolute, drive/UNC, NUL and traversal paths.
3. Canonicalize every source directory and prove it stays within the package root, including through symlinks.
4. Reject protected prefixes: `App`, `Modules`, `Extensions`, `Database`, `Tests`, `Illuminate`, `Laravel`, `Filament`, `Livewire`, `Symfony`, `Composer`, `PHPUnit`, `Mockery`, and host dependency roots `Psr`, `GuzzleHttp`, `Monolog`, `League`, `Carbon`, and `Doctrine`.
5. Select the longest matching PSR-4 prefix, resolve the provider suffix, require exactly one readable regular candidate, and prove it remains below both source and package roots.
6. Register one package-scoped SPL loader, load only the approved provider, compare its reflection file to the approved canonical file, and require the exact manifest class to be an instantiable Laravel `ServiceProvider`.

Overlapping namespace ownership by active addon loaders fails with `namespace_collision`. Loader registration is keyed by addon code and canonical package root, repeated bootstrap is idempotent, and failed validation/load unregisters its loader. Disable, uninstall and remove unregister the mapping. PHP classes cannot be unloaded: workers and other long-running processes must restart after install, update, enable, disable, uninstall or package replacement before the new runtime state is authoritative. HTTP request processes naturally pick up state on their next bootstrap.

Stable package diagnostics include `package_metadata_missing`, `package_metadata_invalid`, `psr4_missing`, `psr4_path_invalid`, `psr4_path_escape`, `namespace_reserved`, `namespace_collision`, `provider_prefix_mismatch`, `provider_file_missing`, `provider_file_escape`, `provider_file_ambiguous`, `provider_class_invalid`, `provider_reflection_mismatch`, `provider_not_allowed`, and `package_autoload_unsupported`. Operator messages expose the code and package-relative context only; stack traces and unrelated absolute paths are not UI diagnostics.

External repositories must produce a root manifest, package-local source/config/migrations/resources and Composer PSR-4 metadata. They must not rely on fixture namespaces, host `vendor/`, Composer scripts/plugins, absolute host paths or private credentials. Marketplace signature, checksum, review, staging, promotion, rollback and recovery policy remains unchanged by provider loading.

## Demo Seeding Safety

- Demo content seeding is separated into `DemoContentSeeder`.
- `DatabaseSeeder` always provisions core infrastructure and the primary admin user.
- Demo content is seeded automatically only in `local`/`testing`, or when `ALLOW_DEMO_SEEDING=true`.
- Outside those conditions demo seeding is skipped with an explicit console warning.
- Demo skeleton addon files in `modules/Demo/...` and `extensions/Demo/...` stay in repository for discovery/tests.

Operational note:

- Marketplace and remote package install are not implemented in Phase 1.
- Phase 2 marketplace flow must include package checksum/signature validation.
- Demo content seed is not intended for production-like data refreshes.

## Roadmap

- Phase 2: marketplace as package source, signatures/checksums, purchase/download flow.
- Phase 3: two real test modules and two real extensions.
- Phase 4: full install, enable, disable, uninstall, remove testing with real addon payloads.

## Release Note

Addon Foundation adds local module/extension manifests, discovery, validation, registry tables, soft lifecycle operations, hook registry, safe provider boot, Filament management UI, CLI commands, diagnostics, and Phase 1 documentation.
