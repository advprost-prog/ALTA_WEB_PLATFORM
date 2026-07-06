<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\OrderNotificationEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    protected $fillable = [
        'code',
        'event',
        'channel',
        'name',
        'subject',
        'body',
        'is_active',
        'is_system',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn (self $template): bool => ! $template->is_system);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForEventChannel(Builder $query, OrderNotificationEvent $event, NotificationChannel $channel): Builder
    {
        return $query
            ->where('event', $event->value)
            ->where('channel', $channel->value);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function defaults(): array
    {
        return [
            self::emailDefault(
                OrderNotificationEvent::OrderCreated,
                'Замовлення створено',
                "Вітаємо, {{ order.customer_name }}!\n\nМи отримали ваше замовлення {{ order.number }} на суму {{ order.total }} {{ order.currency }}.\nСпосіб оплати: {{ order.payment_method }}.\nСпосіб доставки: {{ order.delivery_method }}.\n\nМи повідомимо вас про наступні зміни статусу.",
                10,
            ),
            self::emailDefault(
                OrderNotificationEvent::OrderConfirmed,
                'Замовлення підтверджено',
                "Вітаємо, {{ order.customer_name }}!\n\nВаше замовлення {{ order.number }} підтверджено менеджером.\nСума: {{ order.total }} {{ order.currency }}.\nСтатус оплати: {{ order.payment_status }}.",
                20,
            ),
            self::emailDefault(
                OrderNotificationEvent::OrderProcessing,
                'Замовлення в обробці',
                "Ваше замовлення {{ order.number }} передано в обробку.\n\nМи готуємо позиції до відправки або видачі.",
                30,
            ),
            self::emailDefault(
                OrderNotificationEvent::OrderReadyToShip,
                'Замовлення готове до відправки',
                "Замовлення {{ order.number }} готове до відправки.\n\nСпосіб доставки: {{ order.delivery_method }}.",
                40,
            ),
            self::emailDefault(
                OrderNotificationEvent::OrderShipped,
                'Замовлення відправлено',
                "Замовлення {{ order.number }} відправлено.\n\nСтатус доставки: {{ order.delivery_status }}.",
                50,
            ),
            self::emailDefault(
                OrderNotificationEvent::OrderCompleted,
                'Замовлення завершено',
                "Дякуємо!\n\nЗамовлення {{ order.number }} завершено.",
                60,
            ),
            self::emailDefault(
                OrderNotificationEvent::OrderCancelled,
                'Замовлення скасовано',
                "Замовлення {{ order.number }} скасовано.\n\nПричина: {{ cancel_reason }}",
                70,
            ),
            self::emailDefault(
                OrderNotificationEvent::PaymentPaid,
                'Оплату отримано',
                "Оплату за замовлення {{ order.number }} отримано.\n\nСума замовлення: {{ order.total }} {{ order.currency }}.",
                80,
            ),
        ];
    }

    public static function ensureDefaults(): void
    {
        foreach (self::defaults() as $template) {
            $existing = self::query()
                ->where('code', $template['code'])
                ->first();

            if (! $existing) {
                self::query()->create($template);

                continue;
            }

            $existing->forceFill([
                'event' => $template['event'],
                'channel' => $template['channel'],
                'is_system' => true,
            ])->save();
        }
    }

    public static function variablesText(): string
    {
        return '{{ order.number }}, {{ order.total }}, {{ order.currency }}, {{ order.status }}, {{ order.payment_status }}, {{ order.delivery_status }}, {{ order.customer_name }}, {{ order.customer_phone }}, {{ order.customer_email }}, {{ order.payment_method }}, {{ order.delivery_method }}, {{ order.created_at }}, {{ cancel_reason }}';
    }

    /**
     * @return array<string, mixed>
     */
    private static function emailDefault(OrderNotificationEvent $event, string $name, string $body, int $sortOrder): array
    {
        $channel = NotificationChannel::Email;

        return [
            'code' => $event->templateCode($channel),
            'event' => $event->value,
            'channel' => $channel->value,
            'name' => $name,
            'subject' => $event->defaultSubject(),
            'body' => $body,
            'is_active' => $event->defaultEnabled(),
            'is_system' => true,
            'sort_order' => $sortOrder,
        ];
    }
}
