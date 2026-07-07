<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Customer extends Model
{
    use HasFactory;

    public const TYPE_INDIVIDUAL = 'individual';

    public const TYPE_COMPANY = 'company';

    protected $fillable = [
        'type',
        'name',
        'first_name',
        'last_name',
        'middle_name',
        'full_name',
        'company_name',
        'phone',
        'email',
        'normalized_phone',
        'normalized_email',
        'tax_id',
        'edrpou',
        'city',
        'address',
        'notes',
        'is_active',
        'marketing_consent',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'marketing_consent' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $customer): void {
            $customer->type ??= self::TYPE_INDIVIDUAL;

            $email = trim((string) $customer->email);
            $customer->normalized_email = $email === '' ? null : mb_strtolower($email);

            $phone = trim((string) $customer->phone);
            $digits = $phone === '' ? null : (preg_replace('/\D+/', '', $phone) ?: null);

            if ($digits && str_starts_with($digits, '00')) {
                $digits = substr($digits, 2);
            }

            if ($digits && strlen($digits) === 10 && str_starts_with($digits, '0')) {
                $digits = '38'.$digits;
            }

            $customer->normalized_phone = $digits;

            $displayName = $customer->display_name;

            if (! $customer->full_name && $customer->type === self::TYPE_INDIVIDUAL && $displayName) {
                $customer->full_name = $displayName;
            }

            if (! $customer->name && $displayName) {
                $customer->name = $displayName;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            self::TYPE_INDIVIDUAL => 'Фізична особа',
            self::TYPE_COMPANY => 'Компанія',
        ];
    }

    public function getDisplayNameAttribute(): string
    {
        $partsName = trim(collect([
            $this->last_name,
            $this->first_name,
            $this->middle_name,
        ])->filter()->implode(' '));

        return (string) collect([
            $this->type === self::TYPE_COMPANY ? $this->company_name : null,
            $this->full_name,
            $partsName !== '' ? $partsName : null,
            $this->company_name,
            $this->attributes['name'] ?? null,
            $this->email,
            $this->phone,
        ])->first(fn ($value): bool => filled($value));
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function defaultDeliveryAddress(): HasOne
    {
        return $this->hasOne(CustomerAddress::class)
            ->where('type', CustomerAddress::TYPE_DELIVERY)
            ->where('is_default', true);
    }

    public function notifications(): HasManyThrough
    {
        return $this->hasManyThrough(NotificationOutbox::class, Order::class);
    }
}
