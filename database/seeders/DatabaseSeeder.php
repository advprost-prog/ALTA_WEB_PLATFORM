<?php

namespace Database\Seeders;

use App\Models\Banner;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CommerceSetting;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\DeliveryMethod;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Promotion;
use App\Models\SiteSetting;
use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Services\Admin\AdminUserProvisioner;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        app(AdminUserProvisioner::class)->provision();

        $currency = Currency::ensureDefault();
        $warehouse = Warehouse::ensureDefault();
        PaymentMethod::ensureDefaults();
        DeliveryMethod::ensureDefaults();

        $cashOnDelivery = PaymentMethod::query()->where('code', PaymentMethod::CASH_ON_DELIVERY)->first();
        $novaPoshta = DeliveryMethod::query()->where('code', DeliveryMethod::NOVA_POSHTA)->first();

        $commerceSettings = CommerceSetting::query()->first()
            ?? CommerceSetting::query()->create([
                'multi_currency_enabled' => false,
                'multi_warehouse_enabled' => false,
                'default_currency_id' => $currency->id,
                'default_warehouse_id' => $warehouse->id,
            ]);

        $commerceSettings->forceFill([
            'default_currency_id' => $currency->id,
            'default_warehouse_id' => $warehouse->id,
        ])->save();

        $productPlaceholder = '/images/placeholders/product-placeholder.svg';

        $categories = collect([
            ['name' => 'Моторні оливи', 'slug' => 'motorni-olyvy', 'image' => '/images/demo/categories/motorni-olyvy.svg'],
            ['name' => 'Акумулятори', 'slug' => 'akumuliatory', 'image' => '/images/demo/categories/akumuliatory.svg'],
            ['name' => 'Гальмівна система', 'slug' => 'halmivna-systema', 'image' => '/images/demo/categories/halmivna-systema.svg'],
            ['name' => 'Автохімія', 'slug' => 'avtokhimiia', 'image' => '/images/demo/categories/avtokhimiia.svg'],
            ['name' => 'Інструменти', 'slug' => 'instrumenty', 'image' => '/images/demo/categories/instrumenty.svg'],
            ['name' => 'Освітлення', 'slug' => 'osvitlennia', 'image' => '/images/demo/categories/osvitlennia.svg'],
            ['name' => 'Фільтри', 'slug' => 'filtry', 'image' => '/images/demo/categories/filtry.svg'],
            ['name' => 'Щітки та догляд', 'slug' => 'shitky-ta-dohliad', 'image' => '/images/demo/categories/shchitky-ta-dohliad.svg'],
        ])->mapWithKeys(fn (array $category, int $index): array => [
            $category['slug'] => Category::updateOrCreate(
                ['slug' => $category['slug']],
                [
                    'name' => $category['name'],
                    'description' => 'Підібрані позиції для швидкого обслуговування авто та регулярних закупівель СТО.',
                    'image' => $category['image'],
                    'is_active' => true,
                    'sort_order' => $index + 1,
                    'seo_title' => $category['name'].' | Alta-Trade',
                    'seo_description' => 'Купити '.mb_strtolower($category['name']).' в Alta-Trade з швидким оформленням замовлення.',
                ],
            ),
        ]);

        $brands = collect([
            ['name' => 'Bosch', 'slug' => 'bosch', 'website' => 'https://www.bosch.com'],
            ['name' => 'Castrol', 'slug' => 'castrol', 'website' => 'https://www.castrol.com'],
            ['name' => 'Mann-Filter', 'slug' => 'mann-filter', 'website' => 'https://www.mann-filter.com'],
            ['name' => 'Osram', 'slug' => 'osram', 'website' => 'https://www.osram.com'],
            ['name' => 'Liqui Moly', 'slug' => 'liqui-moly', 'website' => 'https://www.liqui-moly.com'],
            ['name' => 'Brembo', 'slug' => 'brembo', 'website' => 'https://www.brembo.com'],
            ['name' => 'Valeo', 'slug' => 'valeo', 'website' => 'https://www.valeo.com'],
            ['name' => 'Michelin', 'slug' => 'michelin', 'website' => 'https://www.michelin.com'],
        ])->mapWithKeys(fn (array $brand): array => [
            $brand['slug'] => Brand::updateOrCreate(
                ['slug' => $brand['slug']],
                [
                    'name' => $brand['name'],
                    'description' => 'Демо-бренд для каталогу Alta-Trade.',
                    'website' => $brand['website'],
                    'is_active' => true,
                ],
            ),
        ]);

        Promotion::updateOrCreate(
            ['slug' => 'spring-service-kit'],
            [
                'title' => 'Сервісний комплект зі знижкою',
                'description' => 'Знижка на оливу, фільтри та автохімію при купівлі комплектом.',
                'badge_label' => 'Комплект',
                'discount_type' => 'percent',
                'discount_value' => 12,
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addMonth(),
                'is_active' => true,
                'sort_order' => 1,
            ],
        );

        Promotion::updateOrCreate(
            ['slug' => 'battery-week'],
            [
                'title' => 'Тиждень акумуляторів',
                'description' => 'Підбір АКБ за ємністю, пусковим струмом і типом авто.',
                'badge_label' => 'АКБ',
                'discount_type' => 'fixed',
                'discount_value' => 300,
                'starts_at' => now()->subDays(2),
                'ends_at' => now()->addWeeks(2),
                'is_active' => true,
                'sort_order' => 2,
            ],
        );

        Promotion::updateOrCreate(
            ['slug' => 'night-drive-light'],
            [
                'title' => 'Світло для нічних поїздок',
                'description' => 'Лампи, LED-комплекти та аксесуари освітлення зі швидкою відправкою.',
                'badge_label' => 'Світло',
                'discount_type' => 'percent',
                'discount_value' => 10,
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addMonth(),
                'is_active' => true,
                'sort_order' => 3,
            ],
        );

        Banner::updateOrCreate(
            ['placement' => 'home_hero', 'sort_order' => 1],
            [
                'title' => 'Запчастини, які не гальмують продажі',
                'subtitle' => 'Яскравий каталог, швидкий кошик і Filament-адмінка для менеджерів автомагазину.',
                'button_text' => 'До каталогу',
                'button_url' => '/catalog',
                'image' => '/images/demo/banners/hero.svg',
                'accent_color' => '#ffb703',
                'is_active' => true,
            ],
        );

        Banner::updateOrCreate(
            ['placement' => 'home_promo', 'sort_order' => 1],
            [
                'title' => 'Оливи та фільтри для сезонного ТО',
                'subtitle' => 'Підбір комплектів для легкових авто, комерційного транспорту та СТО.',
                'button_text' => 'Обрати комплект',
                'button_url' => '/catalog?category=motorni-olyvy',
                'image' => '/images/demo/banners/service.svg',
                'accent_color' => '#22d3ee',
                'is_active' => true,
            ],
        );

        Banner::updateOrCreate(
            ['placement' => 'home_promo', 'sort_order' => 2],
            [
                'title' => 'Гальма, світло, щітки - готові комплекти',
                'subtitle' => 'Позиції, які часто купують перед сезоном і регулярним сервісом.',
                'button_text' => 'Дивитися акції',
                'button_url' => '/catalog?sale=1',
                'image' => '/images/demo/banners/sale.svg',
                'accent_color' => '#b9f23f',
                'is_active' => true,
            ],
        );

        Banner::updateOrCreate(
            ['placement' => 'catalog_top', 'sort_order' => 1],
            [
                'title' => 'Каталог для швидкого підбору',
                'subtitle' => 'Фільтруйте за категоріями, брендами й артикулами.',
                'button_text' => 'Переглянути',
                'button_url' => '/catalog',
                'image' => '/images/demo/banners/delivery.svg',
                'accent_color' => '#a3e635',
                'is_active' => true,
            ],
        );

        $productImages = [
            'motorni-olyvy' => '/images/demo/products/motor-oil.svg',
            'akumuliatory' => '/images/demo/products/battery.svg',
            'halmivna-systema' => '/images/demo/products/brake-disc.svg',
            'avtokhimiia' => '/images/demo/products/auto-chemistry.svg',
            'instrumenty' => '/images/demo/products/tools.svg',
            'osvitlennia' => '/images/demo/products/lighting.svg',
            'filtry' => '/images/demo/products/filter.svg',
            'shitky-ta-dohliad' => '/images/demo/products/wiper-care.svg',
        ];

        $products = [
            [
                'name' => 'Castrol EDGE 5W-30 LL 4L',
                'slug' => 'castrol-edge-5w-30-ll-4l',
                'sku' => 'AT-OIL-530-4L',
                'category' => 'motorni-olyvy',
                'brand' => 'castrol',
                'price' => 1890,
                'old_price' => 2190,
                'purchase_price' => 1510,
                'stock' => 24,
                'is_hit' => true,
                'is_sale' => true,
                'image' => $productPlaceholder,
                'specs' => ['Вʼязкість' => '5W-30', 'Обʼєм' => '4 л', 'Допуск' => 'LongLife'],
            ],
            [
                'name' => 'Bosch S5 AGM 70Ah',
                'slug' => 'bosch-s5-agm-70ah',
                'sku' => 'AT-BAT-S5-70',
                'category' => 'akumuliatory',
                'brand' => 'bosch',
                'price' => 5990,
                'old_price' => null,
                'purchase_price' => 4820,
                'stock' => 8,
                'is_new' => true,
                'image' => $productPlaceholder,
                'specs' => ['Ємність' => '70 Ah', 'Тип' => 'AGM', 'Пусковий струм' => '760 A'],
            ],
            [
                'name' => 'Mann-Filter C 35 154',
                'slug' => 'mann-filter-c-35-154',
                'sku' => 'AT-FIL-C35154',
                'category' => 'motorni-olyvy',
                'brand' => 'mann-filter',
                'price' => 520,
                'old_price' => 650,
                'purchase_price' => 360,
                'stock' => 41,
                'is_hit' => true,
                'image' => $productPlaceholder,
                'specs' => ['Тип' => 'Повітряний', 'Матеріал' => 'Целюлоза', 'Серія' => 'Premium'],
            ],
            [
                'name' => 'Osram Night Breaker LED H7',
                'slug' => 'osram-night-breaker-led-h7',
                'sku' => 'AT-LGT-H7LED',
                'category' => 'osvitlennia',
                'brand' => 'osram',
                'price' => 3290,
                'old_price' => 3690,
                'purchase_price' => 2600,
                'stock' => 14,
                'is_new' => true,
                'is_sale' => true,
                'image' => $productPlaceholder,
                'specs' => ['Тип' => 'H7 LED', 'Світло' => 'Холодне біле', 'Комплект' => '2 шт'],
            ],
            [
                'name' => 'Liqui Moly Brake Cleaner 500ml',
                'slug' => 'liqui-moly-brake-cleaner-500ml',
                'sku' => 'AT-CHM-BR500',
                'category' => 'avtokhimiia',
                'brand' => 'liqui-moly',
                'price' => 290,
                'old_price' => null,
                'purchase_price' => 190,
                'stock' => 76,
                'is_hit' => true,
                'image' => $productPlaceholder,
                'specs' => ['Обʼєм' => '500 мл', 'Призначення' => 'Гальма', 'Формат' => 'Аерозоль'],
            ],
            [
                'name' => 'Набір інструментів 108 предметів',
                'slug' => 'nabir-instrumentiv-108',
                'sku' => 'AT-TOOL-108',
                'category' => 'instrumenty',
                'brand' => 'bosch',
                'price' => 2490,
                'old_price' => 2990,
                'purchase_price' => 1850,
                'stock' => 11,
                'is_sale' => true,
                'image' => $productPlaceholder,
                'specs' => ['Кількість' => '108', 'Кейс' => 'Пластик', 'Гарантія' => '12 міс'],
            ],
            [
                'name' => 'Brembo P 85 020 передні колодки',
                'slug' => 'brembo-p-85-020-peredni-kolodky',
                'sku' => 'AT-BRK-P85020',
                'category' => 'halmivna-systema',
                'brand' => 'brembo',
                'price' => 1340,
                'old_price' => 1590,
                'purchase_price' => 980,
                'stock' => 18,
                'is_hit' => true,
                'is_sale' => true,
                'image' => $productPlaceholder,
                'specs' => ['Вісь' => 'Передня', 'Матеріал' => 'Low-metallic', 'Комплект' => '4 шт'],
            ],
            [
                'name' => 'Brembo DOT4 Brake Fluid 1L',
                'slug' => 'brembo-dot4-brake-fluid-1l',
                'sku' => 'AT-BRK-DOT4-1L',
                'category' => 'halmivna-systema',
                'brand' => 'brembo',
                'price' => 360,
                'old_price' => null,
                'purchase_price' => 240,
                'stock' => 33,
                'is_new' => true,
                'image' => $productPlaceholder,
                'specs' => ['Клас' => 'DOT4', 'Обʼєм' => '1 л', 'Температура' => '260°C'],
            ],
            [
                'name' => 'Valeo Silencio X-TRM 600/450',
                'slug' => 'valeo-silencio-x-trm-600-450',
                'sku' => 'AT-WPR-6045',
                'category' => 'shitky-ta-dohliad',
                'brand' => 'valeo',
                'price' => 780,
                'old_price' => 920,
                'purchase_price' => 560,
                'stock' => 22,
                'is_sale' => true,
                'image' => $productPlaceholder,
                'specs' => ['Довжина' => '600/450 мм', 'Тип' => 'Безкаркасні', 'Комплект' => '2 шт'],
            ],
            [
                'name' => 'Michelin Hybrid Wiper 650mm',
                'slug' => 'michelin-hybrid-wiper-650mm',
                'sku' => 'AT-WPR-MHY650',
                'category' => 'shitky-ta-dohliad',
                'brand' => 'michelin',
                'price' => 430,
                'old_price' => null,
                'purchase_price' => 300,
                'stock' => 0,
                'stock_status' => 'out_of_stock',
                'image' => $productPlaceholder,
                'specs' => ['Довжина' => '650 мм', 'Тип' => 'Hybrid', 'Кріплення' => 'Multi-clip'],
            ],
            [
                'name' => 'Mann-Filter HU 816 X',
                'slug' => 'mann-filter-hu-816-x',
                'sku' => 'AT-FIL-HU816X',
                'category' => 'filtry',
                'brand' => 'mann-filter',
                'price' => 410,
                'old_price' => null,
                'purchase_price' => 280,
                'stock' => 52,
                'is_hit' => true,
                'image' => $productPlaceholder,
                'specs' => ['Тип' => 'Масляний', 'Серія' => 'Eco', 'Висота' => '99 мм'],
            ],
            [
                'name' => 'Bosch Oil Filter P 3274',
                'slug' => 'bosch-oil-filter-p-3274',
                'sku' => 'AT-FIL-P3274',
                'category' => 'filtry',
                'brand' => 'bosch',
                'price' => 260,
                'old_price' => 320,
                'purchase_price' => 170,
                'stock' => 4,
                'stock_status' => 'low_stock',
                'is_sale' => true,
                'image' => $productPlaceholder,
                'specs' => ['Тип' => 'Масляний', 'Корпус' => 'Метал', 'Різьба' => 'M20x1.5'],
            ],
            [
                'name' => 'Castrol Magnatec 10W-40 4L',
                'slug' => 'castrol-magnatec-10w-40-4l',
                'sku' => 'AT-OIL-1040-4L',
                'category' => 'motorni-olyvy',
                'brand' => 'castrol',
                'price' => 1280,
                'old_price' => null,
                'purchase_price' => 940,
                'stock' => 27,
                'is_new' => true,
                'image' => $productPlaceholder,
                'specs' => ['Вʼязкість' => '10W-40', 'Обʼєм' => '4 л', 'Основа' => 'Напівсинтетика'],
            ],
            [
                'name' => 'Liqui Moly Top Tec 4200 5W-30 5L',
                'slug' => 'liqui-moly-top-tec-4200-5w-30-5l',
                'sku' => 'AT-OIL-TT4200-5L',
                'category' => 'motorni-olyvy',
                'brand' => 'liqui-moly',
                'price' => 2450,
                'old_price' => 2790,
                'purchase_price' => 1880,
                'stock' => 9,
                'is_hit' => true,
                'is_sale' => true,
                'image' => $productPlaceholder,
                'specs' => ['Вʼязкість' => '5W-30', 'Обʼєм' => '5 л', 'Допуск' => 'VW 504/507'],
            ],
            [
                'name' => 'Bosch Aerotwin AR604S',
                'slug' => 'bosch-aerotwin-ar604s',
                'sku' => 'AT-WPR-AR604S',
                'category' => 'shitky-ta-dohliad',
                'brand' => 'bosch',
                'price' => 690,
                'old_price' => null,
                'purchase_price' => 480,
                'stock' => 16,
                'is_hit' => true,
                'image' => $productPlaceholder,
                'specs' => ['Довжина' => '600/400 мм', 'Тип' => 'Aerotwin', 'Комплект' => '2 шт'],
            ],
            [
                'name' => 'Osram Cool Blue Intense H4',
                'slug' => 'osram-cool-blue-intense-h4',
                'sku' => 'AT-LGT-H4CBI',
                'category' => 'osvitlennia',
                'brand' => 'osram',
                'price' => 620,
                'old_price' => 740,
                'purchase_price' => 420,
                'stock' => 28,
                'is_sale' => true,
                'image' => $productPlaceholder,
                'specs' => ['Тип' => 'H4', 'Температура' => '5000K', 'Комплект' => '2 шт'],
            ],
            [
                'name' => 'Valeo Fog Light Kit Universal',
                'slug' => 'valeo-fog-light-kit-universal',
                'sku' => 'AT-LGT-FOGKIT',
                'category' => 'osvitlennia',
                'brand' => 'valeo',
                'price' => 1450,
                'old_price' => null,
                'purchase_price' => 1040,
                'stock' => 6,
                'stock_status' => 'preorder',
                'is_new' => true,
                'image' => $productPlaceholder,
                'specs' => ['Тип' => 'Протитуманні', 'Живлення' => '12V', 'Комплект' => '2 фари'],
            ],
            [
                'name' => 'Liqui Moly Engine Flush 300ml',
                'slug' => 'liqui-moly-engine-flush-300ml',
                'sku' => 'AT-CHM-FLUSH300',
                'category' => 'avtokhimiia',
                'brand' => 'liqui-moly',
                'price' => 340,
                'old_price' => null,
                'purchase_price' => 230,
                'stock' => 49,
                'is_hit' => true,
                'image' => $productPlaceholder,
                'specs' => ['Обʼєм' => '300 мл', 'Призначення' => 'Двигун', 'Дія' => 'Промивка'],
            ],
            [
                'name' => 'Michelin Digital Pressure Gauge',
                'slug' => 'michelin-digital-pressure-gauge',
                'sku' => 'AT-TOOL-PSI',
                'category' => 'instrumenty',
                'brand' => 'michelin',
                'price' => 890,
                'old_price' => null,
                'purchase_price' => 610,
                'stock' => 13,
                'is_new' => true,
                'image' => $productPlaceholder,
                'specs' => ['Діапазон' => '0-7 bar', 'Дисплей' => 'LCD', 'Живлення' => 'CR2032'],
            ],
            [
                'name' => 'Набір торцевих головок Bosch 46 предметів',
                'slug' => 'nabir-tortsevykh-holovok-bosch-46',
                'sku' => 'AT-TOOL-B46',
                'category' => 'instrumenty',
                'brand' => 'bosch',
                'price' => 1190,
                'old_price' => 1490,
                'purchase_price' => 820,
                'stock' => 7,
                'is_sale' => true,
                'image' => $productPlaceholder,
                'specs' => ['Кількість' => '46', 'Привід' => '1/4"', 'Кейс' => 'Пластик'],
            ],
            [
                'name' => 'Brembo Brake Disc Front 280mm',
                'slug' => 'brembo-brake-disc-front-280mm',
                'sku' => 'AT-BRK-DISC280',
                'category' => 'halmivna-systema',
                'brand' => 'brembo',
                'price' => 1780,
                'old_price' => null,
                'purchase_price' => 1320,
                'stock' => 20,
                'is_hit' => true,
                'image' => $productPlaceholder,
                'specs' => ['Діаметр' => '280 мм', 'Вентиляція' => 'Так', 'Вісь' => 'Передня'],
            ],
            [
                'name' => 'Bosch S4 60Ah',
                'slug' => 'bosch-s4-60ah',
                'sku' => 'AT-BAT-S4-60',
                'category' => 'akumuliatory',
                'brand' => 'bosch',
                'price' => 3890,
                'old_price' => 4290,
                'purchase_price' => 2980,
                'stock' => 12,
                'is_sale' => true,
                'image' => $productPlaceholder,
                'specs' => ['Ємність' => '60 Ah', 'Тип' => 'Кислотний', 'Пусковий струм' => '540 A'],
            ],
            [
                'name' => 'Valeo Start-Stop AGM 80Ah',
                'slug' => 'valeo-start-stop-agm-80ah',
                'sku' => 'AT-BAT-V80AGM',
                'category' => 'akumuliatory',
                'brand' => 'valeo',
                'price' => 6890,
                'old_price' => null,
                'purchase_price' => 5400,
                'stock' => 3,
                'stock_status' => 'preorder',
                'is_new' => true,
                'image' => $productPlaceholder,
                'specs' => ['Ємність' => '80 Ah', 'Тип' => 'AGM', 'Start-Stop' => 'Так'],
            ],
            [
                'name' => 'Liqui Moly Ceramic Paste 250g',
                'slug' => 'liqui-moly-ceramic-paste-250g',
                'sku' => 'AT-CHM-CP250',
                'category' => 'avtokhimiia',
                'brand' => 'liqui-moly',
                'price' => 510,
                'old_price' => null,
                'purchase_price' => 350,
                'stock' => 34,
                'image' => $productPlaceholder,
                'specs' => ['Вага' => '250 г', 'Температура' => 'до 1400°C', 'Призначення' => 'Гальма'],
            ],
        ];

        foreach ($products as $index => $data) {
            $productImage = $data['image'] ?? $productImages[$data['category']] ?? $productPlaceholder;

            if ($productImage === $productPlaceholder && isset($productImages[$data['category']])) {
                $productImage = $productImages[$data['category']];
            }

            $product = Product::firstOrNew(['slug' => $data['slug']]);
            $product->fill([
                'brand_id' => $brands[$data['brand']]->id,
                'category_id' => $categories[$data['category']]->id,
                'name' => $data['name'],
                'sku' => $data['sku'],
                'short_description' => 'Демо-позиція Alta-Trade для швидкого старту каталогу та візуальної перевірки карток.',
                'description' => 'Товар доданий як частина демонстраційного наповнення Alta-Trade Commerce Engine. Менеджер може змінити ціну, залишок, SEO та фото в Filament-адмінці.',
                'price' => $data['price'],
                'old_price' => $data['old_price'],
                'purchase_price' => $data['purchase_price'],
                'stock' => $data['stock'],
                'stock_status' => $data['stock_status'] ?? ($data['stock'] <= 0 ? 'out_of_stock' : ($data['stock'] > 10 ? 'in_stock' : 'low_stock')),
                'is_active' => true,
                'is_new' => $data['is_new'] ?? false,
                'is_hit' => $data['is_hit'] ?? false,
                'is_sale' => $data['is_sale'] ?? false,
                'seo_title' => $data['name'].' | Alta-Trade',
                'seo_description' => 'Купити '.$data['name'].' в інтернет-магазині Alta-Trade.',
            ]);

            if (! $product->exists || blank($product->main_image)) {
                $product->main_image = $productImage;
            }

            $product->save();

            ProductPrice::query()->updateOrCreate(
                [
                    'product_id' => $product->id,
                    'currency_id' => $commerceSettings->default_currency_id,
                ],
                [
                    'price' => $product->price,
                    'compare_at_price' => $product->old_price,
                    'is_active' => true,
                ],
            );

            StockBalance::query()->updateOrCreate(
                [
                    'product_id' => $product->id,
                    'warehouse_id' => $commerceSettings->default_warehouse_id,
                ],
                [
                    'quantity' => $product->stock,
                    'reserved_quantity' => 0,
                ],
            );

            $galleryImage = $product->images()->firstOrNew(['sort_order' => 1]);
            $galleryImage->alt = $data['name'];

            if (! $galleryImage->exists || blank($galleryImage->image)) {
                $galleryImage->image = $productImage;
            }

            $galleryImage->save();

            $specOrder = 1;

            foreach ($data['specs'] as $specIndex => $value) {
                $product->specifications()->updateOrCreate(
                    ['name' => (string) $specIndex],
                    ['value' => $value, 'unit' => null, 'sort_order' => $specOrder++],
                );
            }
        }

        foreach ([
            ['key' => 'store_phone', 'label' => 'Телефон магазину', 'value' => '+38 067 111 22 33', 'type' => 'phone', 'group' => 'contacts'],
            ['key' => 'store_email', 'label' => 'Email магазину', 'value' => 'sales@alta-trade.test', 'type' => 'email', 'group' => 'contacts'],
            ['key' => 'homepage_seo_title', 'label' => 'SEO title головної', 'value' => 'Alta-Trade - автомагазин запчастин', 'type' => 'text', 'group' => 'seo'],
        ] as $setting) {
            SiteSetting::updateOrCreate(['key' => $setting['key']], $setting + ['is_public' => true]);
        }

        $this->call(StorefrontThemeSeeder::class);

        $customer = Customer::updateOrCreate(
            ['phone' => '+380501234567'],
            [
                'name' => 'Демо Покупець',
                'email' => 'customer@example.test',
                'city' => 'Київ',
                'address' => 'Відділення Нової пошти 12',
            ],
        );

        $demoProduct = Product::where('slug', 'castrol-edge-5w-30-ll-4l')->first();
        $order = Order::updateOrCreate(
            ['number' => 'AT-DEMO-00001'],
            [
                'customer_id' => $customer->id,
                'currency_id' => $commerceSettings->default_currency_id,
                'currency_code' => $currency->code,
                'exchange_rate_to_base' => $currency->rate_to_base,
                'warehouse_id' => $commerceSettings->default_warehouse_id,
                'customer_name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'total_amount' => $demoProduct?->price ?? 0,
                'status' => 'new',
                'payment_status' => 'unpaid',
                'delivery_status' => 'pending',
                'delivery_method' => $novaPoshta?->code ?? 'nova_poshta',
                'delivery_method_id' => $novaPoshta?->id,
                'delivery_method_name' => $novaPoshta?->name ?? 'Нова пошта',
                'payment_method' => $cashOnDelivery?->code ?? 'cash_on_delivery',
                'payment_method_id' => $cashOnDelivery?->id,
                'payment_method_name' => $cashOnDelivery?->name ?? 'Післяплата',
                'customer_comment' => 'Демо-замовлення для перевірки адмінки.',
            ],
        );

        if ($demoProduct) {
            $order->items()->updateOrCreate(
                ['product_id' => $demoProduct->id],
                [
                    'product_name' => $demoProduct->name,
                    'sku' => $demoProduct->sku,
                    'quantity' => 1,
                    'warehouse_id' => $commerceSettings->default_warehouse_id,
                    'unit_price' => $demoProduct->price,
                    'price' => $demoProduct->price,
                    'total' => $demoProduct->price,
                ],
            );
        }
    }
}
