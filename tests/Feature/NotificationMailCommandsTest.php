<?php

namespace Tests\Feature;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\OrderNotificationEvent;
use App\Mail\OrderNotificationMail;
use App\Models\NotificationOutbox;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class NotificationMailCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_test_email_command_validates_email_argument(): void
    {
        $this->artisan('notifications:test-email not-an-email')
            ->expectsOutputToContain('Invalid email address.')
            ->assertExitCode(1);
    }

    public function test_test_email_command_sends_with_fake_mailer(): void
    {
        Mail::fake();

        $this->artisan('notifications:test-email buyer@example.test')
            ->expectsOutputToContain('current_mailer:')
            ->expectsOutputToContain('Email delivery succeeded.')
            ->assertExitCode(0);

        Mail::assertSent(OrderNotificationMail::class);
    }

    public function test_test_email_command_returns_failure_without_exposing_mail_secret(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.username' => 'smtp-user@example.test',
            'mail.mailers.smtp.password' => 'smtp-secret-password',
        ]);

        Mail::shouldReceive('to')
            ->once()
            ->with('buyer@example.test')
            ->andReturn(new class
            {
                public function send(OrderNotificationMail $mail): void
                {
                    throw new RuntimeException('SMTP auth failed for smtp-user@example.test with smtp-secret-password');
                }
            });

        $this->artisan('notifications:test-email buyer@example.test')
            ->expectsOutputToContain('Email delivery failed:')
            ->doesntExpectOutputToContain('smtp-user@example.test')
            ->doesntExpectOutputToContain('smtp-secret-password')
            ->assertExitCode(1);
    }

    public function test_send_pending_command_sends_pending_notifications(): void
    {
        Mail::fake();
        $notification = $this->createPendingNotification($this->createOrder(), 'buyer@example.test');

        $this->artisan('notifications:send-pending')
            ->expectsOutputToContain('processed: 1')
            ->expectsOutputToContain('sent: 1')
            ->assertExitCode(0);

        $notification = $notification->fresh();

        $this->assertSame(NotificationStatus::Sent->value, $notification->status);
        $this->assertNotNull($notification->sent_at);
        Mail::assertSent(OrderNotificationMail::class);
    }

    public function test_send_pending_command_respects_limit(): void
    {
        Mail::fake();
        $first = $this->createPendingNotification($this->createOrder(['email' => 'first@example.test']), 'first@example.test');
        $second = $this->createPendingNotification($this->createOrder(['email' => 'second@example.test']), 'second@example.test');

        $this->artisan('notifications:send-pending --limit=1')
            ->expectsOutputToContain('matched: 1')
            ->expectsOutputToContain('processed: 1')
            ->assertExitCode(0);

        $this->assertSame(NotificationStatus::Sent->value, $first->fresh()->status);
        $this->assertSame(NotificationStatus::Pending->value, $second->fresh()->status);
    }

    public function test_send_pending_command_dry_run_does_not_mutate_database(): void
    {
        Mail::fake();
        $notification = $this->createPendingNotification($this->createOrder(), 'buyer@example.test');

        $this->artisan('notifications:send-pending --dry-run')
            ->expectsOutputToContain('dry_run: yes')
            ->expectsOutputToContain('would_process: notification#'.$notification->id)
            ->expectsOutputToContain('processed: 1')
            ->expectsOutputToContain('sent: 0')
            ->assertExitCode(0);

        $this->assertSame(NotificationStatus::Pending->value, $notification->fresh()->status);
        $this->assertNull($notification->fresh()->sent_at);
        Mail::assertNothingSent();
    }

    public function test_send_pending_command_marks_failed_on_mailer_exception_without_exposing_secret(): void
    {
        config([
            'mail.mailers.smtp.password' => 'smtp-secret-password',
        ]);

        $notification = $this->createPendingNotification($this->createOrder(['email' => 'fail@example.test']), 'fail@example.test');

        Mail::shouldReceive('to')
            ->once()
            ->with('fail@example.test')
            ->andThrow(new RuntimeException('SMTP transport down: smtp-secret-password'));

        $this->artisan('notifications:send-pending')
            ->expectsOutputToContain('processed: 1')
            ->expectsOutputToContain('failed: 1')
            ->assertExitCode(0);

        $notification = $notification->fresh();

        $this->assertSame(NotificationStatus::Failed->value, $notification->status);
        $this->assertStringContainsString('[redacted]', (string) $notification->error_message);
        $this->assertStringNotContainsString('smtp-secret-password', (string) $notification->error_message);
    }

    public function test_send_pending_command_does_not_touch_sent_notifications(): void
    {
        Mail::fake();
        $sentAt = now()->subDay()->startOfSecond();
        $notification = NotificationOutbox::create([
            'order_id' => $this->createOrder()->id,
            'event' => OrderNotificationEvent::OrderCreated->value,
            'channel' => NotificationChannel::Email->value,
            'recipient' => 'buyer@example.test',
            'subject' => 'Already sent',
            'body' => 'Already sent body',
            'status' => NotificationStatus::Sent->value,
            'sent_at' => $sentAt,
        ]);

        $this->artisan('notifications:send-pending')
            ->expectsOutputToContain('matched: 0')
            ->expectsOutputToContain('processed: 0')
            ->assertExitCode(0);

        $notification = $notification->fresh();

        $this->assertSame(NotificationStatus::Sent->value, $notification->status);
        $this->assertTrue($notification->sent_at?->equalTo($sentAt));
        Mail::assertNothingSent();
    }

    public function test_send_pending_command_filters_by_order_event_and_channel(): void
    {
        Mail::fake();
        $matchingOrder = $this->createOrder(['email' => 'matching@example.test']);
        $otherOrder = $this->createOrder(['email' => 'other@example.test']);
        $matching = $this->createPendingNotification($matchingOrder, 'matching@example.test');
        $otherOrderNotification = $this->createPendingNotification($otherOrder, 'other@example.test');
        $otherEvent = $this->createPendingNotification($matchingOrder, 'matching@example.test', OrderNotificationEvent::OrderConfirmed);

        $this->artisan('notifications:send-pending', [
            '--order-id' => (string) $matchingOrder->id,
            '--event' => OrderNotificationEvent::OrderCreated->value,
            '--channel' => NotificationChannel::Email->value,
        ])
            ->expectsOutputToContain('matched: 1')
            ->expectsOutputToContain('sent: 1')
            ->assertExitCode(0);

        $this->assertSame(NotificationStatus::Sent->value, $matching->fresh()->status);
        $this->assertSame(NotificationStatus::Pending->value, $otherOrderNotification->fresh()->status);
        $this->assertSame(NotificationStatus::Pending->value, $otherEvent->fresh()->status);
    }

    public function test_one_failed_pending_notification_does_not_stop_other_records(): void
    {
        Mail::fake();
        $failed = $this->createPendingNotification($this->createOrder(['email' => 'failed@example.test']), 'failed@example.test');
        $failed->forceFill(['body' => ''])->save();
        $sent = $this->createPendingNotification($this->createOrder(['email' => 'sent@example.test']), 'sent@example.test');

        $this->artisan('notifications:send-pending')
            ->expectsOutputToContain('processed: 2')
            ->expectsOutputToContain('sent: 1')
            ->expectsOutputToContain('failed: 1')
            ->assertExitCode(0);

        $this->assertSame(NotificationStatus::Failed->value, $failed->fresh()->status);
        $this->assertSame(NotificationStatus::Sent->value, $sent->fresh()->status);
    }

    private function createOrder(array $attributes = []): Order
    {
        return Order::create($attributes + [
            'customer_name' => 'Command Buyer',
            'phone' => '+380501112233',
            'email' => $attributes['email'] ?? 'buyer@example.test',
            'total_amount' => 1000,
        ]);
    }

    private function createPendingNotification(
        Order $order,
        ?string $recipient,
        OrderNotificationEvent $event = OrderNotificationEvent::OrderCreated,
        NotificationChannel $channel = NotificationChannel::Email,
    ): NotificationOutbox {
        return NotificationOutbox::create([
            'order_id' => $order->id,
            'event' => $event->value,
            'channel' => $channel->value,
            'recipient' => $recipient,
            'subject' => 'Pending '.$event->value,
            'body' => 'Pending body '.$event->value,
            'status' => NotificationStatus::Pending->value,
        ]);
    }
}
