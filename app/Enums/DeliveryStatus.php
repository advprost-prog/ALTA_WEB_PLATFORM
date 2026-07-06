<?php

namespace App\Enums;

enum DeliveryStatus: string
{
    case NotRequired = 'not_required';
    case Pending = 'pending';
    case Preparing = 'preparing';
    case ReadyToShip = 'ready_to_ship';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::NotRequired => 'Не потребує доставки',
            self::Pending => 'Очікує обробки',
            self::Preparing => 'Готується',
            self::ReadyToShip => 'Готове до відправки',
            self::Shipped => 'Відправлено',
            self::Delivered => 'Доставлено',
            self::Failed => 'Проблема доставки',
            self::Returned => 'Повернено',
            self::Cancelled => 'Скасовано',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NotRequired => 'gray',
            self::Pending => 'info',
            self::Preparing, self::ReadyToShip => 'warning',
            self::Shipped => 'primary',
            self::Delivered => 'success',
            self::Failed, self::Cancelled => 'danger',
            self::Returned => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }

    public static function labelFor(?string $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return self::tryFrom($value)?->label() ?? $value;
    }

    public static function colorFor(?string $value): string
    {
        return self::tryFrom((string) $value)?->color() ?? 'gray';
    }
}
