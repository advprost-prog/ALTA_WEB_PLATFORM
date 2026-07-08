# Local Addon Marketplace (Phase 2)

Локальний Marketplace модулів і розширень — це внутрішній каталог addon-ів, доступний
адміністратору в адмінпанелі. Він дозволяє бачити доступні модулі/розширення з
локального каталогу, порівнювати їх зі станом у `system_addons` і виконувати
lifecycle-дії (discover / install / enable / disable / uninstall) через уже
існуючий Phase 1 pipeline.

Marketplace НЕ є зовнішнім магазином: він не завантажує файли з інтернету, не
виконує shell-команд, не робить `composer require`/`npm install`, не працює з
зовнішнім API і не обробляє оплату. Уся робота — тільки з локальним каталогом
`config/addons-marketplace.php` і фізичними модулями/розширеннями у `modules/` та
`extensions/`.

## Де відкрити

Адмінпанель → **Система → Marketplace** (`/admin/marketplace`).

Сторінка показує картки кожного catalog item із: назвою, типом (Модуль /
Розширення), vendor, code, версією, категорією, описом, тегами, поточним
обчисленим статусом, залежностями та попередженнями/діагностикою.

## Чим module відрізняється від extension

- **module** — повноцінний функціональний блок (каталог, замовлення, інтеграції).
  Маніфест `module.json` містить `permissions`, `menu`, `migrations`, `seeders`,
  `routes`.
- **extension** — розширює поведінку через hooks (наприклад, admin-віджети,
  SEO-хуки). Маніфест `extension.json` містить `hooks`.

Обидва типи проходять один і той самий lifecycle у Phase 1.

## Структура config/addons-marketplace.php

```php
return [
    'items' => [
        [
            'code' => 'core.products',        // stable slug-like id, напр. core.products
            'type' => 'module',               // "module" | "extension"
            'vendor' => 'Core',               // vendor/author namespace
            'name' => 'Каталог товарів',       // назва (укр. допускається)
            'description' => '...',           // короткий опис
            'version' => '1.0.0',             // semantic version
            'category' => 'Каталог',          // група
            'icon' => 'heroicon-o-cube',      // опціонально
            'path' => 'modules/Core/Products/module.json', // опціонально, відносно base_path()
            'platform_version' => '>=1.0.0',  // опціонально, мінімальна версія платформи
            'dependencies' => [],             // опціонально, список addon codes
            'tags' => ['catalog', 'core'],    // опціонально
            'is_featured' => true,            // рекомендований
            'sort_order' => 10,               // порядок (зростання)
        ],
    ],
];
```

Обов’язкові поля: `code`, `type`, `name`, `version`, `vendor`. `code` має
відповідати slug-патерну `^[a-z0-9]+([._-][a-z0-9]+)*$` і збігатися з `code` у
маніфесті фізичного модуля/розширення.

## Обчислений статус (computed status)

Кожен catalog item зіставляється з записом `system_addons` по `code`:

| Статус | Значення |
| --- | --- |
| `available` | є в каталозі, але ще не discovered/installed |
| `discovered` | є запис у `system_addons` зі статусом `discovered` |
| `installed` | встановлений |
| `enabled` | увімкнений |
| `disabled` | вимкнений |
| `missing_files` | запис є, але файл маніфесту відсутній (або catalog item без файлів) |
| `invalid` | item каталогу некоректний (пропущені поля, поганий `code` тощо) |
| `failed` | addon перейшов у статус помилки при boot/lifecycle |
| `removed` | soft-removed |

## Lifecycle і дії

Дії у Marketplace делегують виконання існуючому `AddonManager` /
`AddonLifecycle` (Phase 1) — логіка не дублюється:

1. **Discover** — якщо item є в каталозі, але ще не має запису в `system_addons`,
   запускає `addons:discover` (сканує `modules/` і `extensions/`).
2. **Install** — переводить discovered у `installed`.
3. **Enable** — переводить у `enabled` (boot сервіс-провайдера, маршрутів, hooks).
4. **Disable** — вимикає addon (він більше не boot-иться).
5. **Uninstall** — soft-uninstall: повертає у `discovered`, **файли не видаляються**.

Кожна дія пише подію у `system_addon_events` і, у разі помилки, оновлює
`last_error` у `system_addons`. Помилки показуються як Filament notification.

## Залежності

Якщо item має `dependencies`, Marketplace показує їх у картці. Якщо залежність
не встановлена або вимкнена — **Enable блокується** (кнопка неактивна) і
показується чітке попередження. Автозавантаження залежностей на цьому етапі НЕ
реалізовано (відкладено на Phase 3).

## Безпечні обмеження

- Ніякого shell execution, `eval`, `composer`/`npm` з UI.
- Ніяких remote install / download / оплати / license server.
- Некоректний catalog item НЕ валить сторінку — він показується як `invalid` із
  переліком помилок.
- Дублікат `code` показується як diagnostic.
- Сторінка працює при порожньому каталозі та при битому item.

## Як додати новий item у каталог

1. Створіть фізичний модуль/розширення у `modules/<Vendor>/<Code>/` або
   `extensions/<Vendor>/<Code>/` з відповідним маніфестом (`code` має збігатися).
2. Додайте запис у `config/addons-marketplace.php` (`config/addons-marketplace.php`).
3. В адмінці **Система → Marketplace** натисніть **Discover / rescan**.
4. Для item з’явиться дія **Install**, потім **Enable**.

## Як адміну встановити / увімкнути / вимкнути модуль

- Відкрийте **Система → Marketplace**.
- Скористайтеся фільтрами (тип, статус, категорія, vendor, рекомендовані).
- Натисніть потрібну дію на картці (`Install` / `Enable` / `Disable` /
  `Uninstall`). Помилки відображаються як спливаюче повідомлення.
- Кнопка **Деталі** розкриває діагностику: шлях маніфесту, computed status,
  запис `system_addons`, `last_error`, помилки валідації.

## CLI

```bash
php artisan addons:marketplace            # таблиця catalog items + статуси
php artisan addons:marketplace --json     # machine-readable JSON
php artisan addons:discover               # синхронізувати modules/extensions із system_addons
php artisan addons:list                   # поточний стан system_addons
php artisan addons:doctor                 # діагностика manifest/dependencies/compatibility
```

## Компоненти (код)

- `config/addons-marketplace.php` — локальний каталог.
- `app/Support/Addons/Marketplace/MarketplaceItem.php` — value object catalog item.
- `app/Support/Addons/Marketplace/MarketplaceCatalog.php` — читання/валідація каталогу.
- `app/Support/Addons/Marketplace/MarketplaceManager.php` — reconcile з `system_addons`,
  обчислення статусу, делегування lifecycle.
- `app/Support/Addons/Marketplace/MarketplaceStatus.php` — константи/лейбли статусів.
- `app/Filament/Pages/Marketplace.php` + `resources/views/filament/pages/marketplace.blade.php`.
- `app/Console/Commands/Addons/MarketplaceCommand.php`.

## Відкладено на Phase 3

- Remote registry / registry server.
- Завантаження (download) артефактів.
- Оплата / комерційні пакети.
- License server / активація ліцензій.
- Автоматичне встановлення залежностей.
- Версіонування та оновлення (update) catalog items.
