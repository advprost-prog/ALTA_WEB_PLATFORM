<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Local Addon Marketplace Catalog
    |--------------------------------------------------------------------------
    |
    | This is a STATIC, LOCAL catalog of modules and extensions that can be
    | managed from the admin panel (System -> Marketplace). It is NOT a remote
    | store, does not download anything from the internet, and never executes
    | shell commands or composer/npm.
    |
    | Each item describes a local module/extension that may (or may not) have
    | its physical files under modules/<Vendor>/<Code>/ or extensions/<Vendor>/<Code>/.
    | The Marketplace only reads this catalog and reconciles it against the
    | system_addons table using the existing Phase 1 lifecycle pipeline.
    |
    | Field reference for a single item:
    |   code               (string)  stable slug-like identifier, e.g. core.products
    |   type               (string)  "module" or "extension"
    |   vendor             (string)  vendor/author namespace, e.g. Core
    |   name               (string)  human-readable name (Ukrainian is fine)
    |   description        (string)  short description
    |   version            (string)  semantic version
    |   category           (string)  grouping label, e.g. Catalog
    |   icon               (string)  optional Filament/Heroicon icon name
    |   path               (string)  optional manifest path relative to base_path()
    |   platform_version   (string)  optional minimum platform version constraint
    |   dependencies       (array)   optional list of addon codes this item needs
    |   tags               (array)   optional list of tags
    |   is_featured       (bool)    show as featured
    |   sort_order         (int)     ordering weight (ascending)
    |
    */

    'items' => [

        [
            'code' => 'core.products',
            'type' => 'module',
            'vendor' => 'Core',
            'name' => 'Каталог товарів',
            'description' => 'Базовий модуль каталогу товарів, категорій та брендів.',
            'version' => '1.0.0',
            'category' => 'Каталог',
            'icon' => 'heroicon-o-cube',
            'path' => 'modules/Core/Products/module.json',
            'platform_version' => '>=1.0.0',
            'dependencies' => [],
            'tags' => ['catalog', 'products', 'core'],
            'is_featured' => true,
            'sort_order' => 10,
        ],

        [
            'code' => 'core.promotions',
            'type' => 'module',
            'vendor' => 'Core',
            'name' => 'Промобанери та акції',
            'description' => 'Модуль промо-банерів, акцій та знижок на головній сторінці.',
            'version' => '1.0.0',
            'category' => 'Маркетинг',
            'icon' => 'heroicon-o-megaphone',
            'path' => 'modules/Core/Promotions/module.json',
            'platform_version' => '>=1.0.0',
            'dependencies' => ['core.products'],
            'tags' => ['marketing', 'promotions', 'core'],
            'is_featured' => true,
            'sort_order' => 20,
        ],

        [
            'code' => 'core.integrations',
            'type' => 'module',
            'vendor' => 'Core',
            'name' => 'Інтеграції',
            'description' => 'Модуль зовнішніх інтеграцій (пошта, платежі, аналітика).',
            'version' => '0.9.0',
            'category' => 'Інтеграції',
            'icon' => 'heroicon-o-arrow-path',
            'path' => 'modules/Core/Integrations/module.json',
            'platform_version' => '>=1.0.0',
            'dependencies' => [],
            'tags' => ['integrations', 'core'],
            'is_featured' => false,
            'sort_order' => 30,
        ],

        [
            'code' => 'core.theme-maker',
            'type' => 'extension',
            'vendor' => 'Core',
            'name' => 'Theme Maker',
            'description' => 'Demo extension for validating the local addon marketplace lifecycle.',
            'version' => '0.2.0',
            'category' => 'Дизайн',
            'icon' => 'heroicon-o-paint-brush',
            'path' => 'extensions/Core/ThemeMaker/extension.json',
            'platform_version' => '>=1.0.0',
            'dependencies' => [],
            'tags' => ['theme', 'design', 'demo'],
            'is_featured' => true,
            'sort_order' => 40,
        ],

        [
            'code' => 'core.seo',
            'type' => 'extension',
            'vendor' => 'Core',
            'name' => 'SEO інструменти',
            'description' => 'Розширення SEO: метатеги, sitemap, мікророзмітка.',
            'version' => '1.0.0',
            'category' => 'Маркетинг',
            'icon' => 'heroicon-o-magnifying-glass',
            'path' => 'extensions/Core/Seo/extension.json',
            'platform_version' => '>=1.0.0',
            'dependencies' => [],
            'tags' => ['seo', 'marketing', 'core'],
            'is_featured' => false,
            'sort_order' => 50,
        ],

    ],

];
