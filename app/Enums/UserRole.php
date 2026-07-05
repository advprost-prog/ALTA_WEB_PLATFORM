<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case ContentManager = 'content_manager';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Адміністратор',
            self::Manager => 'Менеджер',
            self::ContentManager => 'Контент-менеджер',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $role): array => [$role->value => $role->label()])
            ->all();
    }

    public function canAccess(string $area): bool
    {
        if ($this === self::Admin) {
            return true;
        }

        return match ($area) {
            'catalog' => in_array($this, [self::Manager, self::ContentManager], true),
            'marketing' => $this === self::ContentManager,
            'sales' => $this === self::Manager,
            'customers' => $this === self::Manager,
            'settings' => false,
            default => false,
        };
    }

    public function canDeleteRecords(): bool
    {
        return $this === self::Admin;
    }
}
