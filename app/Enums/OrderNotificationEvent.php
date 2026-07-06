<?php

namespace App\Enums;

enum OrderNotificationEvent: string
{
    case OrderCreated = 'order_created';
    case OrderConfirmed = 'order_confirmed';
    case OrderProcessing = 'order_processing';
    case OrderReadyToShip = 'order_ready_to_ship';
    case OrderShipped = 'order_shipped';
    case OrderCompleted = 'order_completed';
    case OrderCancelled = 'order_cancelled';
    case PaymentPaid = 'payment_paid';
    case PaymentFailed = 'payment_failed';
    case DeliveryFailed = 'delivery_failed';

    public function label(): string
    {
        return match ($this) {
            self::OrderCreated => 'Замовлення створено',
            self::OrderConfirmed => 'Замовлення підтверджено',
            self::OrderProcessing => 'Замовлення в обробці',
            self::OrderReadyToShip => 'Замовлення готове до відправки',
            self::OrderShipped => 'Замовлення відправлено',
            self::OrderCompleted => 'Замовлення завершено',
            self::OrderCancelled => 'Замовлення скасовано',
            self::PaymentPaid => 'Оплату отримано',
            self::PaymentFailed => 'Оплата не пройшла',
            self::DeliveryFailed => 'Доставка не вдалася',
        };
    }

    public function defaultSubject(): string
    {
        return match ($this) {
            self::OrderCreated => 'Замовлення {{ order.number }} прийнято',
            self::OrderConfirmed => 'Замовлення {{ order.number }} підтверджено',
            self::OrderProcessing => 'Замовлення {{ order.number }} в обробці',
            self::OrderReadyToShip => 'Замовлення {{ order.number }} готове до відправки',
            self::OrderShipped => 'Замовлення {{ order.number }} відправлено',
            self::OrderCompleted => 'Замовлення {{ order.number }} завершено',
            self::OrderCancelled => 'Замовлення {{ order.number }} скасовано',
            self::PaymentPaid => 'Оплату за замовлення {{ order.number }} отримано',
            self::PaymentFailed => 'Оплату за замовлення {{ order.number }} не вдалося провести',
            self::DeliveryFailed => 'Доставка замовлення {{ order.number }} потребує уваги',
        };
    }

    public function defaultEnabled(): bool
    {
        return ! in_array($this, [self::PaymentFailed, self::DeliveryFailed], true);
    }

    public function recommendedChannel(): NotificationChannel
    {
        return match ($this) {
            self::PaymentFailed, self::DeliveryFailed => NotificationChannel::AdminPanel,
            default => NotificationChannel::Email,
        };
    }

    public function templateCode(NotificationChannel $channel): string
    {
        return $this->value.'.'.$channel->value;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $event): array => [$event->value => $event->label()])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function requiredEmailTemplateEvents(): array
    {
        return [
            self::OrderCreated->value,
            self::OrderConfirmed->value,
            self::OrderProcessing->value,
            self::OrderReadyToShip->value,
            self::OrderShipped->value,
            self::OrderCompleted->value,
            self::OrderCancelled->value,
            self::PaymentPaid->value,
        ];
    }

    public static function labelFor(?string $value): string
    {
        return self::tryFrom((string) $value)?->label() ?? (string) $value;
    }
}
