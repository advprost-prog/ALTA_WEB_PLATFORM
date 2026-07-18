# Alta-Trade Commerce Engine

MVP e-commerce платформи для автомагазину Alta-Trade: публічний каталог, кошик, оформлення замовлення і Filament-адмінка для керування товарами, замовленнями, банерами та налаштуваннями.

## Технології

- PHP 8.3+
- Laravel 12
- Filament 4
- Livewire 3
- Blade
- Alpine.js
- Tailwind CSS 4
- SQLite для локального старту, MySQL/PostgreSQL для production

## Модулі

- Ролі користувачів: `admin`, `manager`, `content_manager`
- Категорії, бренди, товари
- Галерея і характеристики товарів
- SEO-поля для товарів і категорій
- Акції, банери головної сторінки
- Клієнти, замовлення, позиції замовлення
- Налаштування сайту
- Product AI Assistant для чернеток описів, SEO та характеристик товарів
- Theme Engine для storefront: token-based теми, preview, activation, regenerate from source і AI Theme Studio
- Session-based кошик і checkout

## Product AI Assistant

AI-модуль не застосовує зміни до товарів без ручної дії. Він створює `AiRun` і `AiSuggestion`, після чого користувач з правом `apply` вручну застосовує дозволені текстові поля.

## Theme Engine

Storefront використовує активну тему з `storefront_themes`: colors, typography, radius, shadows, spacing, layout presets, component variants і generic `style_profile` конвертуються в CSS variables та body data attributes. Тема не змінює routes, cart, checkout, order creation або product data.

Керування доступне в `/admin`:

- `Дизайн -> Теми storefront`: admin створює, редагує, preview-ить і активує теми; manager має view/preview; content_manager не має доступу.
- Preview відкриває storefront як `/?theme={slug}` і показує banner `Preview theme: ...`; тема не активується і не впливає на інших користувачів.
- Activation вручну публікує тему, вимикає інші `is_active` і очищає cache `active_storefront_theme`.
- `Regenerate from source` для inactive AI-generated themes повторює capture/analyze/classify/map/generate, створює нову version і не активує тему автоматично.
- Seed додає 4 системні теми: `Alta Trade Dark Automotive`, `Clean Marketplace`, `Premium Parts`, `Discount Auto`.

`Дизайн -> AI Theme Studio` доступний тільки admin. Сторінка приймає URL інтернет-магазину, аналізує стилістичні ознаки, класифікує generic style profile, мапить його у безпечний base preset і створює draft theme. Система не копіює чужий HTML/CSS/JS, логотипи, тексти, фото, банери, trademarks або remote assets; AI/heuristic generation працює тільки через контрольовані tokens/layout/component presets і generic guardrails. Деталі: [`docs/theme-engine.md`](docs/theme-engine.md).

### AI setup from admin panel

1. Зайдіть в `/admin` як `admin`.
2. Відкрийте `AI -> AI налаштування`.
3. Увімкніть AI, вставте OpenAI API key, виберіть model і timeout.
4. Опційно вставте OpenAI Admin API key для синхронізації фактичних costs.
5. Задайте monthly internal budget і hard limit.
6. Натисніть `Test connection`.
7. Після success використовуйте `AI-заповнення` у ProductResource.

`.env` лишається fallback/dev bootstrap (`OPENAI_API_KEY`, `OPENAI_MODEL`, `OPENAI_TIMEOUT`), але production users не повинні редагувати `.env` для бізнес-налаштування AI. Не комітьте `OPENAI_API_KEY`. Фактичні OpenAI prepaid credits дивіться в OpenAI Billing; Alta-Trade показує internal budget і estimated spend, а фактичні costs можна синхронізувати через Admin API key.

AI може створювати пропозиції для короткого опису, повного опису, SEO title, SEO description, alt-тексту фото, характеристик товару та майбутніх `gtin_candidates`.

Запуск доступний у Filament на сторінці товару через кнопку `AI-заповнення`. Результати зберігаються в таблицях `ai_runs` і `ai_suggestions`; налаштування зберігаються encrypted у `ai_settings`, usage snapshots - у `ai_usage_snapshots`.

### AI suggestions workflow

AI-пропозиції переглядаються в `AI -> AI-пропозиції`. Pending/accepted пропозицію можна відредагувати перед Apply; збереження не застосовує її автоматично. Після успішного `Застосувати` запис отримує `status=applied`, `applied_by`, `applied_at` і зникає з активного робочого списку. Applied/rejected записи лишаються в БД як історія і доступні через фільтр `Історія applied/rejected`.

Автоматично застосовуються тільки `short_description`, `full_description` (у поточній схемі це `products.description`), `description`, `seo_title`, `seo_description`, `image_alt_text`, а також `main_image`/`main_image_candidate` тільки для локального файлу. `attributes`, `gtin_candidates`, search queries і remote image candidates є review-only; у таблиці показується причина, чому Apply недоступний.

### Product Image Picker

Кнопка `Підібрати фото` у ProductResource створює реальні `product_image_candidates`. Робочий provider у цій фазі - `manual_url`: оператор вставляє 1-10 URL фото, система перевіряє SSRF-ризики, MIME, розмір файлу, dimensions і очевидні placeholder/banner/watermark ознаки, після чого показує candidates у вкладці товару `Кандидати фото`.

Remote URL ніколи не записується напряму в `Product.main_image`. Оператор імпортує вибрані candidates у галерею; система повторно завантажує фото, прибирає metadata через перекодування, конвертує у WebP, записує локальний файл у `storage/app/public/product-gallery/{product_id}` і створює `ProductImage` із source metadata. Для WebP потрібен PHP GD з `imagewebp`; якщо його немає, UI отримує зрозумілу помилку без падіння.

У вкладці `Галерея фото` можна встановити будь-яке фото головним. Це оновлює `Product.main_image`, ставить `product_images.is_main=true` для вибраного фото і скидає `is_main` для інших. External provider зараз є stub: його можна підключити через provider abstraction без Google Images scraping або HTML scraping.

### Product completeness

У списку товарів є колонка `Заповненість`: badge з відсотком, статусом і коротким переліком відсутніх реквізитів. Score рахується live за назвою, slug, артикулом, брендом, категорією, ціною, залишком, фото, alt-текстом, описами, SEO, галереєю і характеристиками. Фільтри допомагають знайти товари з `Заповненість < 50`, без фото, без SEO, без опису або без характеристик.

## Admin governance

Адміністративні системні розділи:

- `AI -> AI налаштування`: доступ тільки для `admin`; ключі показуються лише masked, порожнє поле ключа не стирає збережене значення.
- `Система -> Користувачі`: доступ тільки для `admin`; дозволяє створювати користувачів, змінювати роль і пароль.
- `AI -> AI-пропозиції`: `admin` має повний доступ, `manager` може переглядати/створювати/застосовувати дозволені пропозиції, `content_manager` може переглядати/створювати та застосовувати тільки content/SEO поля.

Seeder і команда governance підтримують таких користувачів:

```text
o.lykhobaba@alta-trade.com.ua / admin
office@alta-trade.com.ua / admin
admin@alta-trade.test / admin / password
manager@alta-trade.test / manager / password
content@alta-trade.test / content_manager / password
```

Для `o.lykhobaba@alta-trade.com.ua` і `office@alta-trade.com.ua` існуючий пароль не перезаписується. Demo-користувачі `*.test` при seed отримують пароль `password`. Перевірка і repair:

```bash
php artisan alta:admin-governance-check
```

User Management захищає останнього admin: його не можна видалити або понизити до `manager`/`content_manager`.

## Встановлення

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
npm run build
php artisan test
```

`composer install` створює `database/database.sqlite`, якщо його немає і поточний `DB_CONNECTION` не вибирає інший provider. PostgreSQL setup не створює і не використовує SQLite-файл. Для повторного створення storage symlink можна використати:

```bash
php artisan storage:link --force
```

## Запуск

```bash
php artisan serve
npm run dev
```

Якщо використовується production build, достатньо:

```bash
npm run build
php artisan serve
```

## Адмінка

URL:

```text
/admin
```

Демо-користувачі після `php artisan migrate --seed`:

```text
o.lykhobaba@alta-trade.com.ua / password, якщо користувач створений seed вперше
office@alta-trade.com.ua / password, якщо користувач створений seed вперше
admin@alta-trade.test / password
manager@alta-trade.test / password
content@alta-trade.test / password
```

Права:

- `admin`: повний доступ до всіх ресурсів.
- `manager`: продажі, клієнти, замовлення, доступ до каталогу без системних налаштувань.
- `content_manager`: каталог і маркетинг, без доступу до замовлень і клієнтів.

## Публічні сторінки

- `/`
- `/catalog`
- `/category/{slug}`
- `/product/{slug}`
- `/cart`
- `/checkout`
- `/delivery-payment`
- `/contacts`
- `/about`

## Тести

Перед тестами, які використовують `RefreshDatabase`, очистити config cache:

```bash
php artisan config:clear
php artisan test
```

### PostgreSQL 18.4 development matrix

SQLite залишається default development provider. PG-H2 не переносить normal data та не перемикає `.env` застосунку.

Для disposable PostgreSQL скопіюйте лише credential template; реальний local-файл ігнорується Git:

```bash
cp .env.postgresql.example .env.postgresql.local
# Замініть placeholder PG_H2_PASSWORD випадковим локальним секретом.
docker compose --env-file .env.postgresql.local -f compose.postgresql.yml up -d
```

Сервіс використовує офіційний `postgres:18.4`, UTC, UTF-8, named disposable volume і слухає тільки `127.0.0.1:55432`. Перед provider matrix експортуйте значення з local-файлу без виведення секрету в logs:

```bash
set -a
. ./.env.postgresql.local
set +a
export DB_HOST=127.0.0.1 DB_PORT="${PG_H2_HOST_PORT:-55432}"
export DB_DATABASE="${PG_H2_DATABASE:-alta_pg_h2_dev}"
export DB_USERNAME="${PG_H2_USERNAME:-alta_pg_h2}"
php scripts/run-postgresql-tests.php
```

Runner fail-closed перевіряє `alta_pg_h2_` prefix, local host, очищає config cache, виконує `migrate:fresh` тільки на disposable PostgreSQL і запускає повну suite разом із `tests/PostgreSQL`. Він не приймає production/external host і не має SQLite fallback.

Зупинка зі збереженням disposable volume та повний reset:

```bash
docker compose --env-file .env.postgresql.local -f compose.postgresql.yml down
docker compose --env-file .env.postgresql.local -f compose.postgresql.yml down --volumes
```

Не запускайте reset проти normal SQLite або production database. PostgreSQL 18.4 CI job є окремим обов'язковим provider gate; локальні PostgreSQL 16 clients не є acceptance substitute.

Database policy у PG-H2:

- application timestamps і PostgreSQL session timezone — UTC;
- user-facing catalog search — Unicode case-insensitive з literal `%`/`_`;
- SKU, addon codes, hashes, checksums і public IDs лишаються case-sensitive;
- Ukrainian ICU display collation перевіряється на official image, але лишається provisional до підтвердження hosting;
- `citext`, Redis, normal-data import і default connection switch не входять у PG-H2.

Поточне покриття:

- storefront pages;
- category/product route model binding;
- inactive category/product 404;
- cart add/update/cleanup;
- checkout і створення замовлення;
- списання залишків;
- Filament guest/admin/manager/content_manager access;
- Product AI Assistant disabled/fake-client/apply/reject/access behavior;
- AI Settings encrypted keys, budget hard stop, health command, cost estimate.
- Theme Engine seed/resolver/activation/preview/CSS variables/style classifier/preset mapper/guardrails/regeneration/AI draft behavior.
- Admin governance command, User Management access, create/edit/password/last-admin guards.

## Типові проблеми

### SQLite file does not exist

Виконайте:

```bash
touch database/database.sqlite
php artisan migrate --seed
```

### PostgreSQL profile refuses to start

Перевірте, що всі `PG_H2_PASSWORD`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` задані, database має disposable prefix `alta_pg_h2_`, а host дорівнює `127.0.0.1`, `localhost` або Compose service `postgresql`. Не друкуйте local env або password у діагностичний output.

### Storage link already exists

```bash
php artisan storage:link --force
```

### Тести працюють не з in-memory DB

Очистіть кеш конфігурації перед тестами:

```bash
php artisan config:clear
php artisan test
```

### Frontend assets не оновились

```bash
npm install
npm run build
```

## Production notes

- Production PostgreSQL switch не авторизований PG-H2. Перед майбутнім switch окремо підтвердьте PostgreSQL 18.4+, SSL, UTC, locale/collation, backup tools і hosting privileges.
- Не використовуйте демо-паролі в production.
- Для імпорту товарів Excel/CSV краще додавати окремий модуль після стабілізації MVP.
