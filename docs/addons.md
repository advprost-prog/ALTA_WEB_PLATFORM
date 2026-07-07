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

Service providers, when used, must live under the addon directory and match the local namespace:

- modules: `Modules\Vendor\ModuleCode\...`
- extensions: `Extensions\Vendor\ExtensionCode\...`

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

## Core Services

- `App\Support\Addons\AddonDiscovery`
- `App\Support\Addons\AddonManifestValidator`
- `App\Support\Addons\AddonRegistry`
- `App\Support\Addons\AddonLifecycle`
- `App\Support\Addons\AddonHookRegistry`
- `App\Support\Addons\AddonHealthCheck`
- `App\Support\Addons\AddonManager`

`App\Providers\AddonServiceProvider` boots enabled addons only. Disabled or failed addons do not register routes, views, service providers, or hooks.

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
- Service providers must match the addon namespace/path whitelist.
- Missing service provider classes are reported, not allowed to crash the app.
- Disabled addons do not register routes, hooks, menu entries, widgets, or providers.
- Phase 2 should add checksums/signatures and a separate reviewed marketplace install process.

## Roadmap

- Phase 2: marketplace as package source, signatures/checksums, purchase/download flow.
- Phase 3: two real test modules and two real extensions.
- Phase 4: full install, enable, disable, uninstall, remove testing with real addon payloads.

## Release Note

Addon Foundation adds local module/extension manifests, discovery, validation, registry tables, soft lifecycle operations, hook registry, safe provider boot, Filament management UI, CLI commands, diagnostics, and Phase 1 documentation.
