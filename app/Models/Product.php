<?php

namespace App\Models;

use App\Models\Concerns\ResolvesImageUrls;
use App\Services\Catalog\ProductCompletenessService;
use App\Services\Commerce\StockService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

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
        'status',
        'is_featured',
        'sort_order',
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
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'is_new' => 'boolean',
            'is_hit' => 'boolean',
            'is_sale' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $product): void {
            $product->price ??= 0;
            $product->stock ??= 0;
            $product->stock_status ??= 'in_stock';
            $product->status ??= $product->is_active ? 'active' : 'draft';
        });

        static::created(function (self $product): void {
            $product->syncDefaultVariantRecords();
            $product->syncDefaultCommerceRecords();
            $product->syncPrimaryCategoryPivot();
        });

        static::updated(function (self $product): void {
            if ($product->wasChanged(['price', 'old_price', 'stock', 'sku', 'is_active', 'status'])) {
                $product->syncDefaultVariantRecords();
                $product->syncDefaultCommerceRecords();
            }

            if ($product->wasChanged(['category_id'])) {
                $product->syncPrimaryCategoryPivot();
            }
        });
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

    public function media(): HasMany
    {
        return $this->hasMany(ProductImage::class)
            ->orderByDesc('is_main')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderByDesc('is_default')->orderBy('sort_order')->orderBy('id');
    }

    public function defaultVariant(): HasOne
    {
        return $this->hasOne(ProductVariant::class)->where('is_default', true);
    }

    public function additionalCategories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'product_category')
            ->withPivot(['is_primary', 'sort_order']);
    }

    public function attributeValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class)->orderBy('sort_order')->orderBy('id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function defaultPrice(): HasOne
    {
        $currencyId = CommerceSetting::query()->value('default_currency_id');

        return $this->hasOne(ProductPrice::class)
            ->where('currency_id', $currencyId)
            ->orderByDesc('product_variant_id');
    }

    public function defaultStockBalance(): HasOne
    {
        $warehouseId = CommerceSetting::query()->value('default_warehouse_id');

        return $this->hasOne(StockBalance::class)
            ->where('warehouse_id', $warehouseId)
            ->orderByDesc('product_variant_id');
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

    public function applyStockChange(
        float $quantityDelta,
        string $type = StockMovement::TYPE_ADJUSTMENT,
        ?EloquentModel $related = null,
        ?int $warehouseId = null,
        ?string $note = null,
    ): void {
        if (! $this->commerceTablesReady()) {
            $newStock = (int) $this->stock + (int) $quantityDelta;

            if ($newStock < 0) {
                throw new RuntimeException('Insufficient stock for this product.');
            }

            $this->forceFill([
                'stock' => $newStock,
            ])->save();

            return;
        }

        $settings = CommerceSetting::current();
        $warehouseId ??= $settings->default_warehouse_id;

        if (! $warehouseId) {
            return;
        }

        app(StockService::class)->applyDelta(
            subject: $this,
            warehouseId: $warehouseId,
            delta: $quantityDelta,
            type: $type,
            note: $note,
            createdBy: auth()->id(),
            related: $related,
        );
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
            ->orWhere('is_sale', true)
            ->orWhere('is_featured', true));
    }

    public function resolveDefaultVariant(): ?ProductVariant
    {
        if ($this->relationLoaded('defaultVariant') && $this->defaultVariant) {
            return $this->defaultVariant;
        }

        if ($this->relationLoaded('variants') && $this->variants->isNotEmpty()) {
            return $this->variants->firstWhere('is_default', true)
                ?? $this->variants->firstWhere('is_active', true)
                ?? $this->variants->first();
        }

        return $this->defaultVariant()->first()
            ?? $this->variants()->where('is_active', true)->first()
            ?? $this->variants()->first();
    }

    public function syncLegacyFieldsFromVariant(ProductVariant $variant): void
    {
        if (! $variant->is_default || $variant->product_id !== $this->getKey()) {
            return;
        }

        $this->forceFill([
            'sku' => $variant->sku ?: $this->sku,
            'is_active' => $variant->is_active,
        ])->saveQuietly();
    }

    private function syncDefaultCommerceRecords(): void
    {
        if (! $this->commerceTablesReady()) {
            return;
        }

        $settings = CommerceSetting::current();
        $defaultVariantId = $this->resolveDefaultVariant()?->getKey();

        if ($settings->default_currency_id && ! $settings->multi_currency_enabled) {
            ProductPrice::query()->updateOrCreate(
                [
                    'product_id' => $this->getKey(),
                    'product_variant_id' => $defaultVariantId,
                    'currency_id' => $settings->default_currency_id,
                ],
                [
                    'price' => $this->price,
                    'compare_at_price' => $this->old_price,
                    'is_active' => true,
                ],
            );
        }

        if (! $settings->default_warehouse_id || $settings->multi_warehouse_enabled) {
            return;
        }

        $balance = StockBalance::query()->firstOrNew([
            'product_id' => $this->getKey(),
            'product_variant_id' => $defaultVariantId,
            'warehouse_id' => $settings->default_warehouse_id,
        ]);

        $newQuantity = (float) $this->stock;

        $balance->forceFill([
            'quantity' => $newQuantity,
            'reserved_quantity' => $balance->reserved_quantity ?? 0,
        ]);

        if ($balance->exists) {
            $balance->save();

            return;
        }

        $balance->saveQuietly();
    }

    private function syncDefaultVariantRecords(): void
    {
        if (! $this->catalogTablesReady()) {
            return;
        }

        $defaultVariant = $this->defaultVariant()->first() ?? new ProductVariant([
            'product_id' => $this->getKey(),
            'is_default' => true,
        ]);

        $pieceUnit = Unit::ensurePiece();
        $defaultTaxProfile = TaxProfile::ensureDefault();

        $defaultVariant->forceFill([
            'sku' => $this->sku,
            'name' => $defaultVariant->name,
            'base_unit_id' => $defaultVariant->base_unit_id ?: $pieceUnit->getKey(),
            'sales_unit_id' => $defaultVariant->sales_unit_id ?: ($defaultVariant->base_unit_id ?: $pieceUnit->getKey()),
            'purchase_unit_id' => $defaultVariant->purchase_unit_id ?: ($defaultVariant->base_unit_id ?: $pieceUnit->getKey()),
            'tax_profile_id' => $defaultVariant->tax_profile_id ?: $defaultTaxProfile->getKey(),
            'is_default' => true,
            'is_active' => $this->is_active,
            'sort_order' => 0,
        ]);

        $defaultVariant->saveQuietly();
    }

    private function syncPrimaryCategoryPivot(): void
    {
        if (! Schema::hasTable('product_category') || ! $this->category_id) {
            return;
        }

        DB::table('product_category')->updateOrInsert(
            ['product_id' => $this->getKey(), 'category_id' => $this->category_id],
            ['is_primary' => true, 'sort_order' => 0],
        );
    }

    private function commerceTablesReady(): bool
    {
        return Schema::hasTable('commerce_settings')
            && Schema::hasTable('currencies')
            && Schema::hasTable('warehouses')
            && Schema::hasTable('product_prices')
            && Schema::hasTable('stock_balances')
            && Schema::hasTable('stock_movements');
    }

    private function catalogTablesReady(): bool
    {
        return Schema::hasTable('units')
            && Schema::hasTable('tax_profiles')
            && Schema::hasTable('product_variants');
    }
}
