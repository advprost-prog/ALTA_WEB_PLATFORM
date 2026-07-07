<?php

namespace Database\Seeders;

use App\Models\CommerceSetting;
use App\Models\Currency;
use App\Models\DeliveryMethod;
use App\Models\NotificationTemplate;
use App\Models\PaymentMethod;
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
        app(AdminUserProvisioner::class)->provisionPrimaryAdmin();

        $currency = Currency::ensureDefault();
        $warehouse = Warehouse::ensureDefault();
        PaymentMethod::ensureDefaults();
        DeliveryMethod::ensureDefaults();
        NotificationTemplate::ensureDefaults();

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

        if ($this->shouldSeedDemoContent()) {
            $this->call(DemoContentSeeder::class);

            return;
        }

        $this->command?->warn('Demo content seeding skipped. Set ALLOW_DEMO_SEEDING=true to allow it outside local/testing.');
    }

    private function shouldSeedDemoContent(): bool
    {
        if ((bool) config('app.allow_demo_seeding', false)) {
            return true;
        }

        return in_array((string) config('app.env'), ['local', 'testing'], true);
    }
}
