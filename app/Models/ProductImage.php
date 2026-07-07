<?php

namespace App\Models;

use App\Models\Concerns\ResolvesImageUrls;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    use HasFactory;
    use ResolvesImageUrls;

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'type',
        'image',
        'alt',
        'sort_order',
        'source_url',
        'source_domain',
        'imported_by',
        'imported_at',
        'quality_score',
        'metadata',
        'is_main',
        'file_hash',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'imported_at' => 'datetime',
            'quality_score' => 'integer',
            'metadata' => 'array',
            'is_main' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(function (ProductImage $image): void {
            $product = $image->product;

            if (! $product || ($product->main_image !== $image->image && ! $image->is_main)) {
                return;
            }

            $next = self::query()
                ->where('product_id', $image->product_id)
                ->orderByDesc('is_main')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->first();

            if ($next) {
                $next->setAsMain();

                return;
            }

            $product->forceFill(['main_image' => null])->save();
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function getImageUrlAttribute(): string
    {
        return $this->resolveImageUrl($this->image, 'images/placeholders/product-placeholder.svg');
    }

    public function setAsMain(): void
    {
        $product = $this->product()->firstOrFail();

        self::query()
            ->where('product_id', $this->product_id)
            ->whereKeyNot($this->getKey())
            ->update(['is_main' => false]);

        $this->forceFill(['is_main' => true])->save();
        $product->forceFill(['main_image' => $this->image])->save();
    }
}
