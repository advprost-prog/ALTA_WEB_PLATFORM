<?php

namespace Tests\Feature;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\OrderNotificationEvent;
use App\Enums\UserRole;
use App\Filament\Pages\NotificationMailSettingsPage;
use App\Mail\OrderNotificationMail;
use App\Models\NotificationMailSetting;
use App\Models\NotificationOutbox;
use App\Models\Order;
use App\Services\Commerce\NotificationMailManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Feature\Concerns\CreatesCommerceData;
use Tests\TestCase;

class NotificationMailSettingsTest extends TestCase
{
    use CreatesCommerceData;
    use RefreshDatabase;

    public function test_current_creates_singleton_and_does_not_create_duplicates(): void
    {
        NotificationMailSetting::query()->delete();

        $settings = NotificationMailSetting::current();
        $again = NotificationMailSetting::current();

        $this->assertTrue($settings->is($again));
        $this->assertSame(1, NotificationMailSetting::count());
        $this->assertFalse($settings->is_enabled);
    }

    public function test_password_is_encrypted_and_hidden_from_arrays_json_and_summary(): void
    {
        $settings = NotificationMailSetting::current();
        $settings->setPassword('smtp-secret-password');
        $settings->save();

        $settings = $settings->fresh();

        $this->assertNotSame('smtp-secret-password', $settings->password_encrypted);
        $this->assertStringNotContainsString('smtp-secret-password', (string) $settings->password_encrypted);
        $this->assertSame('smtp-secret-password', $settings->getDecryptedPassword());
        $this->assertArrayNotHasKey('password_encrypted', $settings->toArray());
        $this->assertStringNotContainsString('smtp-secret-password', $settings->toJson());
        $this->assertArrayNotHasKey('password', $settings->safeSummary());
        $this->assertTrue($settings->safeSummary()['has_password']);
    }

    public function test_empty_password_update_keeps_existing_password_and_clear_password_removes_it(): void
    {
        $settings = NotificationMailSetting::current();
        $settings->setPassword('smtp-secret-password');
        $settings->save();

        $this->actingAs($this->createUserWithRole(UserRole::Admin));

        Livewire::test(NotificationMailSettingsPage::class)
            ->set('data.mailer', 'smtp')
            ->set('data.host', 'smtp.example.test')
            ->set('data.port', 587)
            ->set('data.encryption', 'tls')
            ->set('data.from_address', 'shop@example.test')
            ->set('data.password', '')
            ->call('save');

        $this->assertSame('smtp-secret-password', $settings->fresh()->getDecryptedPassword());

        Livewire::test(NotificationMailSettingsPage::class)
            ->call('clearPassword');

        $this->assertFalse($settings->fresh()->hasPassword());
    }

    public function test_admin_can_open_and_save_mail_settings_without_showing_password(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Admin));

        $this->get('/admin/notification-mail-settings')
            ->assertOk()
            ->assertSee('Сервер повідомлень');

        Livewire::test(NotificationMailSettingsPage::class)
            ->set('data.is_enabled', true)
            ->set('data.mailer', 'smtp')
            ->set('data.host', 'smtp.example.test')
            ->set('data.port', 587)
            ->set('data.encryption', 'tls')
            ->set('data.username', 'smtp-user@example.test')
            ->set('data.password', 'smtp-secret-password')
            ->set('data.from_address', 'shop@example.test')
            ->set('data.from_name', 'ALTA Shop')
            ->call('save')
            ->assertSet('data.password', null);

        $settings = NotificationMailSetting::current()->fresh();

        $this->assertTrue($settings->is_enabled);
        $this->assertSame('smtp-secret-password', $settings->getDecryptedPassword());

        $this->get('/admin/notification-mail-settings')
            ->assertOk()
            ->assertSee('Збережено encrypted')
            ->assertDontSee('smtp-secret-password')
            ->assertDontSee((string) $settings->password_encrypted);
    }

    public function test_non_admin_cannot_open_mail_settings_page(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Manager))
            ->get('/admin/notification-mail-settings')
            ->assertForbidden();
    }

    public function test_page_test_email_updates_last_test_status_without_exposing_password(): void
    {
        Mail::fake();
        $this->actingAs($this->createUserWithRole(UserRole::Admin));

        $settings = NotificationMailSetting::current();
        $settings->setPassword('smtp-secret-password');
        $settings->forceFill([
            'is_enabled' => true,
            'mailer' => 'array',
            'from_address' => 'shop@example.test',
        ])->save();

        Livewire::test(NotificationMailSettingsPage::class)
            ->set('data.is_enabled', true)
            ->set('data.mailer', 'array')
            ->set('data.from_address', 'shop@example.test')
            ->call('sendTestEmail', 'buyer@example.test');

        $settings = $settings->fresh();

        $this->assertSame(NotificationMailSetting::TEST_STATUS_SUCCESS, $settings->last_test_status);
        $this->assertNotNull($settings->last_tested_at);
        $this->assertNull($settings->last_test_error);
        $this->assertSame('smtp-secret-password', $settings->getDecryptedPassword());
        Mail::assertSent(OrderNotificationMail::class, fn (OrderNotificationMail $mail): bool => $mail->usesMailer(NotificationMailManager::DB_MAILER_KEY));
    }

    public function test_manager_falls_back_to_env_when_db_settings_are_disabled(): void
    {
        config(['mail.default' => 'array']);
        Mail::fake();

        NotificationMailSetting::current()->forceFill([
            'is_enabled' => false,
            'mailer' => 'smtp',
        ])->save();

        $summary = app(NotificationMailManager::class)->send(
            'buyer@example.test',
            new OrderNotificationMail('Subject', 'Body'),
        );

        $this->assertSame(NotificationMailManager::SOURCE_ENV, $summary['source']);
        $this->assertSame('array', $summary['mailer']);
        Mail::assertSent(OrderNotificationMail::class, fn (OrderNotificationMail $mail): bool => $mail->usesMailer('array'));
    }

    public function test_manager_uses_db_settings_when_enabled_and_configured(): void
    {
        Mail::fake();
        NotificationMailSetting::current()->forceFill([
            'is_enabled' => true,
            'mailer' => 'array',
            'from_address' => 'shop@example.test',
        ])->save();

        $summary = app(NotificationMailManager::class)->send(
            'buyer@example.test',
            new OrderNotificationMail('Subject', 'Body'),
        );

        $this->assertSame(NotificationMailManager::SOURCE_DB, $summary['source']);
        $this->assertSame('array', $summary['mailer']);
        Mail::assertSent(OrderNotificationMail::class, fn (OrderNotificationMail $mail): bool => $mail->usesMailer(NotificationMailManager::DB_MAILER_KEY));
    }

    public function test_manager_builds_isolated_smtp_runtime_config(): void
    {
        Mail::fake();
        $settings = NotificationMailSetting::current();
        $settings->setPassword('smtp-secret-password');
        $settings->forceFill([
            'is_enabled' => true,
            'mailer' => 'smtp',
            'host' => 'smtp.example.test',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'smtp-user@example.test',
            'from_address' => 'shop@example.test',
            'timeout' => 30,
            'verify_peer' => false,
        ])->save();

        $summary = app(NotificationMailManager::class)->send(
            'buyer@example.test',
            new OrderNotificationMail('Subject', 'Body'),
        );
        $config = config('mail.mailers.'.NotificationMailManager::DB_MAILER_KEY);

        $this->assertSame(NotificationMailManager::SOURCE_DB, $summary['source']);
        $this->assertSame('smtp', $summary['mailer']);
        $this->assertSame('smtp', $config['transport']);
        $this->assertSame('smtp', $config['scheme']);
        $this->assertTrue($config['require_tls']);
        $this->assertFalse($config['verify_peer']);
        $this->assertSame('smtp.example.test', $config['host']);
        $this->assertSame(587, $config['port']);
        $this->assertSame('smtp-user@example.test', $config['username']);
        $this->assertSame('smtp-secret-password', $config['password']);
        $this->assertSame(30, $config['timeout']);
        Mail::assertSent(OrderNotificationMail::class, fn (OrderNotificationMail $mail): bool => $mail->usesMailer(NotificationMailManager::DB_MAILER_KEY));
    }

    public function test_manager_reports_db_only_incomplete_settings_safely(): void
    {
        $settings = NotificationMailSetting::current();
        $settings->setPassword('smtp-secret-password');
        $settings->forceFill([
            'is_enabled' => true,
            'mailer' => 'smtp',
            'username' => 'smtp-user@example.test',
            'from_address' => 'shop@example.test',
        ])->save();

        try {
            app(NotificationMailManager::class)->send(
                'buyer@example.test',
                new OrderNotificationMail('Subject', 'Body'),
                NotificationMailManager::SOURCE_DB,
            );
            $this->fail('Incomplete DB SMTP settings should fail.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('incomplete', $exception->getMessage());
            $this->assertStringNotContainsString('smtp-secret-password', $exception->getMessage());
            $this->assertStringNotContainsString('smtp-user@example.test', $exception->getMessage());
        }
    }

    public function test_send_pending_uses_current_db_settings_and_records_safe_mail_metadata(): void
    {
        Mail::fake();
        NotificationMailSetting::current()->forceFill([
            'is_enabled' => true,
            'mailer' => 'array',
            'from_address' => 'shop@example.test',
        ])->save();
        $notification = $this->createPendingNotification($this->createOrder(), 'buyer@example.test');

        $this->artisan('notifications:send-pending')
            ->expectsOutputToContain('delivery_source: db')
            ->expectsOutputToContain('delivery_mailer: array')
            ->expectsOutputToContain('sent: 1')
            ->assertExitCode(0);

        $notification = $notification->fresh();

        $this->assertSame(NotificationStatus::Sent->value, $notification->status);
        $this->assertSame('db', data_get($notification->payload, 'mail.source'));
        $this->assertSame('array', data_get($notification->payload, 'mail.mailer'));
        $this->assertArrayNotHasKey('username', data_get($notification->payload, 'mail', []));
        $this->assertArrayNotHasKey('password', data_get($notification->payload, 'mail', []));
    }

    private function createOrder(array $attributes = []): Order
    {
        return Order::create($attributes + [
            'customer_name' => 'Settings Buyer',
            'phone' => '+380501112233',
            'email' => $attributes['email'] ?? 'buyer@example.test',
            'total_amount' => 1000,
        ]);
    }

    private function createPendingNotification(Order $order, ?string $recipient): NotificationOutbox
    {
        return NotificationOutbox::create([
            'order_id' => $order->id,
            'event' => OrderNotificationEvent::OrderCreated->value,
            'channel' => NotificationChannel::Email->value,
            'recipient' => $recipient,
            'subject' => 'Pending order',
            'body' => 'Pending body',
            'status' => NotificationStatus::Pending->value,
        ]);
    }
}
