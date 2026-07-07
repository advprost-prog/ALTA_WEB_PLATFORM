<?php

namespace App\Services\Commerce;

use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\OrderNotificationEvent;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Mail\OrderNotificationMail;
use App\Models\NotificationOutbox;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class OrderNotificationService
{
    public function __construct(
        private readonly NotificationMailManager $mailManager,
    ) {}

    /**
     * @param  array<string, mixed>  $extraPayload
     */
    public function queueOrderNotification(
        Order $order,
        OrderNotificationEvent $event,
        ?User $user = null,
        array $extraPayload = [],
    ): ?NotificationOutbox {
        try {
            $channel = $event->recommendedChannel();
            $existing = NotificationOutbox::query()
                ->where('order_id', $order->id)
                ->where('event', $event->value)
                ->where('channel', $channel->value)
                ->oldest()
                ->first();

            if ($existing) {
                return $existing;
            }

            return $this->createOutboxAttempt($order, $event, $channel, $user, $extraPayload);
        } catch (Throwable $exception) {
            Log::warning('Order notification failed before outbox write', [
                'order_id' => $order->id,
                'event' => $event->value,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    public function sendOutbox(NotificationOutbox $notification): NotificationOutbox
    {
        if ($notification->status === NotificationStatus::Sent->value) {
            return $notification;
        }

        try {
            $channel = NotificationChannel::tryFrom((string) $notification->channel);

            if (! $channel) {
                return $this->fail($notification, 'Невідомий канал повідомлення: '.(string) $notification->channel);
            }

            if ($notification->body === null || trim($notification->body) === '') {
                return $this->fail($notification, 'Порожнє тіло повідомлення.');
            }

            return match ($channel) {
                NotificationChannel::Email => $this->sendEmail($notification),
                NotificationChannel::AdminPanel => $this->markSent($notification),
                NotificationChannel::Log => $this->sendLog($notification),
            };
        } catch (Throwable $exception) {
            return $this->fail($notification, $exception->getMessage(), $this->mailManager->lastSummary());
        }
    }

    public function resend(NotificationOutbox $notification, ?User $user = null): NotificationOutbox
    {
        if (! $notification->canResend()) {
            throw new RuntimeException('Це повідомлення не можна надіслати повторно.');
        }

        $event = OrderNotificationEvent::tryFrom((string) $notification->event);
        $channel = NotificationChannel::tryFrom((string) $notification->channel);

        if ($event && $channel && $notification->order) {
            return $this->createOutboxAttempt($notification->order, $event, $channel, $user);
        }

        $copy = NotificationOutbox::query()->create([
            'order_id' => $notification->order_id,
            'event' => $notification->event,
            'channel' => $notification->channel,
            'recipient' => $notification->recipient,
            'subject' => $notification->subject,
            'body' => $notification->body,
            'payload' => $notification->payload,
            'status' => NotificationStatus::Pending->value,
            'created_by' => $user?->id,
        ]);

        return $this->sendOutbox($copy);
    }

    /**
     * @param  array<string, mixed>  $extraPayload
     * @return array{subject: string|null, body: string, payload: array<string, mixed>}
     */
    public function renderTemplate(NotificationTemplate $template, Order $order, array $extraPayload = []): array
    {
        $payload = $this->payloadFor($order, $extraPayload);

        return [
            'subject' => $this->renderString($template->subject, $payload),
            'body' => (string) $this->renderString($template->body, $payload),
            'payload' => $payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $extraPayload
     */
    private function createOutboxAttempt(
        Order $order,
        OrderNotificationEvent $event,
        NotificationChannel $channel,
        ?User $user = null,
        array $extraPayload = [],
    ): NotificationOutbox {
        $order->loadMissing(['currency', 'paymentMethod', 'deliveryMethod', 'customer']);
        $recipient = $this->recipientFor($order, $channel);

        $template = NotificationTemplate::query()
            ->forEventChannel($event, $channel)
            ->active()
            ->ordered()
            ->first();

        if (! $template) {
            return NotificationOutbox::query()->create([
                'order_id' => $order->id,
                'event' => $event->value,
                'channel' => $channel->value,
                'recipient' => $recipient,
                'payload' => $this->payloadFor($order, $extraPayload),
                'status' => NotificationStatus::Skipped->value,
                'error_message' => 'Активний шаблон для події та каналу не знайдено.',
                'created_by' => $user?->id,
            ]);
        }

        $rendered = $this->renderTemplate($template, $order, $extraPayload);

        $notification = NotificationOutbox::query()->create([
            'order_id' => $order->id,
            'event' => $event->value,
            'channel' => $channel->value,
            'recipient' => $recipient,
            'subject' => $rendered['subject'],
            'body' => $rendered['body'],
            'payload' => $rendered['payload'],
            'status' => NotificationStatus::Pending->value,
            'created_by' => $user?->id,
        ]);

        return $this->sendOutbox($notification);
    }

    /**
     * @param  array<string, mixed>  $extraPayload
     * @return array<string, mixed>
     */
    private function payloadFor(Order $order, array $extraPayload = []): array
    {
        $paymentMethod = $order->payment_method_name ?: $order->paymentMethod?->name ?: $order->payment_method;
        $deliveryMethod = $order->delivery_method_name ?: $order->deliveryMethod?->name ?: $order->delivery_method;
        $currency = $order->currency_code ?: $order->currency?->code;

        $payload = [
            'order' => [
                'number' => $order->number,
                'total' => number_format((float) $order->total_amount, 2, '.', ' '),
                'currency' => $currency,
                'status' => OrderStatus::labelFor($order->status),
                'payment_status' => PaymentStatus::labelFor($order->payment_status),
                'delivery_status' => DeliveryStatus::labelFor($order->delivery_status),
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->phone,
                'customer_email' => $order->email,
                'payment_method' => $paymentMethod,
                'delivery_method' => $deliveryMethod,
                'created_at' => $order->created_at?->format('d.m.Y H:i'),
            ],
            'cancel_reason' => $order->cancel_reason,
        ];

        return array_replace_recursive($payload, $extraPayload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderString(?string $template, array $payload): ?string
    {
        if ($template === null) {
            return null;
        }

        $flatPayload = $this->dot($payload);

        return preg_replace_callback('/{{\s*([A-Za-z0-9_.]+)\s*}}/', function (array $matches) use ($flatPayload): string {
            $key = $matches[1];

            if (! array_key_exists($key, $flatPayload)) {
                return $matches[0];
            }

            return (string) ($flatPayload[$key] ?? '');
        }, $template);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function dot(array $payload, string $prefix = ''): array
    {
        $result = [];

        foreach ($payload as $key => $value) {
            $fullKey = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $result += $this->dot($value, $fullKey);

                continue;
            }

            $result[$fullKey] = $value;
        }

        return $result;
    }

    private function recipientFor(Order $order, NotificationChannel $channel): ?string
    {
        return match ($channel) {
            NotificationChannel::Email => $order->email ?: $order->customer?->email,
            NotificationChannel::AdminPanel, NotificationChannel::Log => null,
        };
    }

    private function sendEmail(NotificationOutbox $notification): NotificationOutbox
    {
        if (! $notification->recipient) {
            return $this->skip($notification, 'У замовленні немає email клієнта.');
        }

        try {
            $summary = $this->mailManager->send(
                recipient: $notification->recipient,
                mailable: new OrderNotificationMail(
                    notificationSubject: $notification->subject ?: 'Повідомлення щодо замовлення',
                    notificationBody: (string) $notification->body,
                ),
            );
        } catch (Throwable $exception) {
            return $this->fail($notification, $exception->getMessage(), $this->mailManager->lastSummary());
        }

        return $this->markSent($notification, $summary);
    }

    private function sendLog(NotificationOutbox $notification): NotificationOutbox
    {
        Log::info('Order notification', [
            'notification_id' => $notification->id,
            'order_id' => $notification->order_id,
            'event' => $notification->event,
            'channel' => $notification->channel,
            'recipient' => $notification->recipient,
            'subject' => $notification->subject,
        ]);

        return $this->markSent($notification);
    }

    /**
     * @param  array<string, mixed>|null  $mailSummary
     */
    private function markSent(NotificationOutbox $notification, ?array $mailSummary = null): NotificationOutbox
    {
        $notification->forceFill([
            'status' => NotificationStatus::Sent->value,
            'error_message' => null,
            'sent_at' => now(),
            'payload' => $this->payloadWithMailSummary($notification, $mailSummary),
        ])->save();

        return $notification->refresh();
    }

    /**
     * @param  array<string, mixed>|null  $mailSummary
     */
    private function skip(NotificationOutbox $notification, string $reason, ?array $mailSummary = null): NotificationOutbox
    {
        $notification->forceFill([
            'status' => NotificationStatus::Skipped->value,
            'error_message' => $this->cleanError($reason),
            'sent_at' => null,
            'payload' => $this->payloadWithMailSummary($notification, $mailSummary),
        ])->save();

        return $notification->refresh();
    }

    /**
     * @param  array<string, mixed>|null  $mailSummary
     */
    private function fail(NotificationOutbox $notification, string $reason, ?array $mailSummary = null): NotificationOutbox
    {
        $notification->forceFill([
            'status' => NotificationStatus::Failed->value,
            'error_message' => $this->cleanError($reason),
            'sent_at' => null,
            'payload' => $this->payloadWithMailSummary($notification, $mailSummary),
        ])->save();

        return $notification->refresh();
    }

    private function cleanError(string $reason): string
    {
        return mb_substr($this->mailManager->redact(trim($reason)), 0, 2000);
    }

    /**
     * @param  array<string, mixed>|null  $mailSummary
     * @return array<string, mixed>|null
     */
    private function payloadWithMailSummary(NotificationOutbox $notification, ?array $mailSummary): ?array
    {
        $payload = $notification->payload ?? [];

        if ($mailSummary === null) {
            return $payload === [] ? null : $payload;
        }

        $payload['mail'] = [
            'source' => (string) ($mailSummary['source'] ?? 'env'),
            'mailer' => (string) ($mailSummary['mailer'] ?? config('mail.default')),
            'from_address' => $mailSummary['from_address'] ?? null,
        ];

        return $payload;
    }
}
