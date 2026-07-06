<?php

namespace App\Console\Commands;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\OrderNotificationEvent;
use App\Models\NotificationOutbox;
use App\Services\Commerce\NotificationMailManager;
use App\Services\Commerce\OrderNotificationService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class SendPendingNotifications extends Command
{
    protected $signature = 'notifications:send-pending
        {--limit=50 : Maximum number of pending notifications to process}
        {--dry-run : Show matching pending notifications without sending}
        {--order-id= : Restrict processing to one order id}
        {--event= : Restrict processing to one notification event}
        {--channel=email : Restrict processing to one channel}';

    protected $description = 'Send pending notification outbox records without changing order lifecycle or stock.';

    public function handle(OrderNotificationService $notifications, NotificationMailManager $mail): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $dryRun = (bool) $this->option('dry-run');
        $event = $this->normalizedOption('event');
        $channel = $this->normalizedOption('channel') ?: NotificationChannel::Email->value;
        $orderId = $this->normalizedOption('order-id');

        if ($event !== null && ! OrderNotificationEvent::tryFrom($event)) {
            $this->error('Unknown notification event: '.$event);

            return self::FAILURE;
        }

        if (! NotificationChannel::tryFrom($channel)) {
            $this->error('Unknown notification channel: '.$channel);

            return self::FAILURE;
        }

        if ($orderId !== null && (! ctype_digit($orderId) || (int) $orderId < 1)) {
            $this->error('Invalid order id.');

            return self::FAILURE;
        }

        $query = NotificationOutbox::query()
            ->where('status', NotificationStatus::Pending->value)
            ->when($orderId !== null, fn (Builder $query): Builder => $query->where('order_id', (int) $orderId))
            ->when($event !== null, fn (Builder $query): Builder => $query->where('event', $event))
            ->where('channel', $channel)
            ->orderBy('id')
            ->limit($limit);

        $pending = $query->get();

        $this->line('dry_run: '.($dryRun ? 'yes' : 'no'));
        $this->line('limit: '.$limit);
        $this->line('matched: '.$pending->count());

        $delivery = $mail->summary();
        $this->line('delivery_source: '.(string) $delivery['source']);
        $this->line('delivery_mailer: '.(string) $delivery['mailer']);

        $summary = [
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        foreach ($pending as $notification) {
            if ($dryRun) {
                $summary['processed']++;
                $this->line('would_process: notification#'.$notification->id.' order#'.($notification->order_id ?? '-').' '.$notification->event.'/'.$notification->channel);

                continue;
            }

            $summary['processed']++;

            try {
                $result = $notifications->sendOutbox($notification);
            } catch (Throwable $exception) {
                $notification->forceFill([
                    'status' => NotificationStatus::Failed->value,
                    'error_message' => mb_substr($mail->redact($exception->getMessage()), 0, 2000),
                    'sent_at' => null,
                ])->save();

                $result = $notification->refresh();
            }

            match ($result->status) {
                NotificationStatus::Sent->value => $summary['sent']++,
                NotificationStatus::Failed->value => $summary['failed']++,
                NotificationStatus::Skipped->value => $summary['skipped']++,
                default => null,
            };
        }

        $this->newLine();
        $this->line('summary:');
        $this->line('processed: '.$summary['processed']);
        $this->line('sent: '.$summary['sent']);
        $this->line('failed: '.$summary['failed']);
        $this->line('skipped: '.$summary['skipped']);

        return self::SUCCESS;
    }

    private function normalizedOption(string $name): ?string
    {
        $value = $this->option($name);

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
