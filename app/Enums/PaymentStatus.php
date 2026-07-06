<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Pending = 'pending';
    case Paid = 'paid';
    case PartiallyPaid = 'partially_paid';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Не оплачено',
            self::Pending => 'Очікує оплати',
            self::Paid => 'Оплачено',
            self::PartiallyPaid => 'Частково оплачено',
            self::Failed => 'Помилка оплати',
            self::Refunded => 'Повернено',
            self::Cancelled => 'Скасовано',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Unpaid => 'gray',
            self::Pending, self::PartiallyPaid => 'warning',
            self::Paid => 'success',
            self::Failed, self::Cancelled => 'danger',
            self::Refunded => 'info',
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
