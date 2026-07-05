<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImageCandidate extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_IMPORTED = 'imported';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_PENDING => 'Очікує',
        self::STATUS_APPROVED => 'Схвалено',
        self::STATUS_IMPORTED => 'Імпортовано',
        self::STATUS_REJECTED => 'Відхилено',
        self::STATUS_FAILED => 'Помилка',
    ];

    protected $fillable = [
        'product_id',
        'imported_product_image_id',
        'provider',
        'query',
        'source_url',
        'thumbnail_url',
        'image_url',
        'source_domain',
        'title',
        'width',
        'height',
        'mime_type',
        'quality_score',
        'status',
        'can_import',
        'warnings',
        'license_note',
        'rejection_reason',
        'metadata',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'quality_score' => 'integer',
            'can_import' => 'boolean',
            'warnings' => 'array',
            'metadata' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function importedProductImage(): BelongsTo
    {
        return $this->belongsTo(ProductImage::class, 'imported_product_image_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::STATUS_IMPORTED, self::STATUS_APPROVED => 'success',
            self::STATUS_PENDING => $this->can_import ? 'info' : 'warning',
            self::STATUS_REJECTED, self::STATUS_FAILED => 'danger',
            default => 'gray',
        };
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeImportable(Builder $query, int $minQualityScore = 50): Builder
    {
        return $query
            ->where('can_import', true)
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_APPROVED])
            ->whereNull('rejection_reason')
            ->where('quality_score', '>=', $minQualityScore);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRejected(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->whereIn('status', [self::STATUS_REJECTED, self::STATUS_FAILED])
                ->orWhere('can_import', false)
                ->orWhereNotNull('rejection_reason');
        });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeReview(Builder $query): Builder
    {
        return $query
            ->importable()
            ->whereNotNull('warnings');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForProduct(Builder $query, Product $product): Builder
    {
        return $query->where('product_id', $product->id);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->latest();
    }

    public function isImportable(): bool
    {
        return $this->can_import
            && in_array($this->status, [self::STATUS_PENDING, self::STATUS_APPROVED], true)
            && blank($this->rejection_reason)
            && (int) $this->quality_score >= 50;
    }

    public function isRejectedForImport(): bool
    {
        return ! $this->isImportable();
    }
}
