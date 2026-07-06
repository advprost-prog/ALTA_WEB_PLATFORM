<?php

namespace App\Enums;

enum OrderStatus: string
{
    case New = 'new';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case ReadyToShip = 'ready_to_ship';
    case Shipped = 'shipped';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
    case Returned = 'returned';
    case AwaitingPayment = 'awaiting_payment';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Нове',
            self::Confirmed => 'Підтверджене',
            self::Processing => 'В обробці',
            self::ReadyToShip => 'Готове до відправки',
            self::Shipped => 'Відправлене',
            self::Completed => 'Завершене',
            self::Cancelled => 'Скасоване',
            self::Failed => 'Проблемне',
            self::Returned => 'Повернене',
            self::AwaitingPayment => 'Очікує оплати',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'info',
            self::Confirmed, self::Processing, self::ReadyToShip => 'warning',
            self::Shipped => 'primary',
            self::Completed => 'success',
            self::Cancelled, self::Failed => 'danger',
            self::Returned => 'gray',
            self::AwaitingPayment => 'gray',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    /**
     * @return array<string, string>
     */
    public static function options(bool $includeLegacy = true): array
    {
        $options = [];

        foreach (self::cases() as $status) {
            if (! $includeLegacy && $status === self::AwaitingPayment) {
                continue;
            }

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
