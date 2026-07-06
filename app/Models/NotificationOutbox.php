<?php

namespace App\Models;

use App\Enums\NotificationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationOutbox extends Model
{
    protected $table = 'notification_outbox';

    protected $fillable = [
        'order_id',
        'event',
        'channel',
        'recipient',
        'subject',
        'body',
        'payload',
        'status',
        'error_message',
        'sent_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $notification): void {
            $notification->status ??= NotificationStatus::Pending->value;
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeResendable(Builder $query): Builder
    {
        return $query->whereIn('status', [
            NotificationStatus::Pending->value,
            NotificationStatus::Failed->value,
            NotificationStatus::Skipped->value,
        ]);
    }

    public function canResend(): bool
    {
        return NotificationStatus::tryFrom((string) $this->status)?->canResend() ?? false;
    }
}
