<?php

namespace App\Enums;

enum NotificationChannel: string
{
    case Email = 'email';
    case AdminPanel = 'admin_panel';
    case Log = 'log';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::AdminPanel => 'Адмін-панель',
            self::Log => 'Log',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $channel): array => [$channel->value => $channel->label()])
            ->all();
    }

    public static function labelFor(?string $value): string
    {
        return self::tryFrom((string) $value)?->label() ?? (string) $value;
    }
}
