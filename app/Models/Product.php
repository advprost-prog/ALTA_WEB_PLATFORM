<?php

namespace App\Models;

use App\Models\Concerns\ResolvesImageUrls;
use App\Services\Catalog\ProductCompletenessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;
    use ResolvesImageUrls;

    public const STOCK_STATUSES = [
        'in_stock' => 'В наявності',
        'low_stock' => 'Мало',
        'preorder' => 'Під замовлення',
        'out_of_stock' => 'Немає в наявності',
    ];

    protected $fillable = [
        'brand_id',
        'category_id',
        'name',
        'slug',
        'sku',
        'short_description',
        'description',
        'price',
        'old_price',
        'purchase_price',
        'stock',
        'stock_status',
        'is_active',
        'is_new',
        'is_hit',
        'is_sale',
        'seo_title',
        'seo_description',
        'main_image',
        'image_alt_text',
    ];

    protected $appends = [
        'discount_percent',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'old_price' => 'decimal:2',
            'purchase_price' => 'decimal:2',
            'stock' => 'integer',
            'is_active' => 'boolean',
            'is_new' => 'boolean',
            'is_hit' => 'boolean',
            'is_sale' => 'boolean',
        ];
    }

    public function isPurchasable(): bool
    {
        return $this->is_active
            && $this->stock > 0
            && in_array($this->stock_status, ['in_stock', 'low_stock', 'preorder'], true);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)
            ->orderByDesc('is_main')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function imageCandidates(): HasMany
    {
        return $this->hasMany(ProductImageCandidate::class)->latest();
    }

    public function specifications(): HasMany
    {
        return $this->hasMany(ProductSpecification::class)->orderBy('sort_order');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function aiSuggestions(): HasMany
    {
        return $this->hasMany(AiSuggestion::class, 'entity_id')
            ->where('entity_type', self::class)
            ->latest();
    }

    public function getDiscountPercentAttribute(): ?int
    {
        if (! $this->old_price || (float) $this->old_price <= (float) $this->price) {
            return null;
        }

        return (int) round((1 - ((float) $this->price / (float) $this->old_price)) * 100);
    }

    public function getImageUrlAttribute(): string
    {
        if ($this->main_image && $this->isLocalImagePath($this->main_image)) {
            return $this->resolveImageUrl($this->main_image, 'images/placeholders/product-placeholder.svg');
        }

        $mainGalleryImage = $this->relationLoaded('images')
            ? $this->images->firstWhere('is_main', true)?->image
            : $this->images()->where('is_main', true)->value('image');

        if ($mainGalleryImage) {
            return $this->resolveImageUrl($mainGalleryImage, 'images/placeholders/product-placeholder.svg');
        }

        $galleryImage = $this->relationLoaded('images')
            ? $this->images->first()?->image
            : $this->images()->value('image');

        return $this->resolveImageUrl($galleryImage, 'images/placeholders/product-placeholder.svg');
    }

    private function isLocalImagePath(string $path): bool
    {
        return preg_match('/^https?:\/\//i', $path) !== 1 && ! str_starts_with($path, '//');
    }

    /**
     * @return array<string, array{label: string, passed: bool, weight: int}>
     */
    public function completenessChecks(): array
    {
        return app(ProductCompletenessService::class)->checks($this);
    }

    public function completenessScore(): int
    {
        return app(ProductCompletenessService::class)->score($this);
    }

    public function completenessStatus(): string
    {
        return app(ProductCompletenessService::class)->status($this);
    }

    public function completenessStatusLabel(): string
    {
        return app(ProductCompletenessService::class)->statusLabel($this);
    }

    public function completenessMissingSummary(): string
    {
        return app(ProductCompletenessService::class)->missingSummary($this);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePurchasable(Builder $query): Builder
    {
        return $query
            ->active()
            ->where('stock', '>', 0)
            ->whereIn('stock_status', ['in_stock', 'low_stock', 'preorder']);
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where(fn (Builder $query): Builder => $query
            ->where('is_hit', true)
            ->orWhere('is_new', true)
            ->orWhere('is_sale', true));
    }
}
