<?php

namespace App\Models;

use App\Models\Concerns\ResolvesImageUrls;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Category extends Model
{
    use HasFactory;
    use ResolvesImageUrls;

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'description',
        'image',
        'is_active',
        'sort_order',
        'seo_title',
        'seo_description',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function getImageUrlAttribute(): string
    {
        return $this->resolveImageUrl($this->image, 'images/placeholders/category-placeholder.svg');
    }

    public static function buildHierarchy(Collection $categories): Collection
    {
        $indexed = $categories->keyBy('id');

        return $indexed
            ->filter(fn (self $category): bool => $category->parent_id === null)
            ->values()
            ->map(fn (self $category): self => self::hydrateHierarchy($category, $indexed))
            ->values();
    }

    public static function flattenHierarchy(Collection $categories, int $depth = 0, array $parents = []): Collection
    {
        return $categories->flatMap(function (self $category) use ($depth, $parents): Collection {
            $category->depth = $depth;
            $category->breadcrumb_name = implode(' > ', array_merge($parents, [$category->name]));

            $children = $category->children->isNotEmpty()
                ? self::flattenHierarchy($category->children, $depth + 1, array_merge($parents, [$category->name]))
                : collect();

            return $children->prepend($category);
        });
    }

    public function getBreadcrumbPathAttribute(): array
    {
        $ancestors = [];
        $category = $this;

        while ($category->parent) {
            $ancestors[] = $category->parent;
            $category = $category->parent;
        }

        return array_reverse($ancestors);
    }

    private static function hydrateHierarchy(self $category, Collection $indexed): self
    {
        $children = $indexed
            ->filter(fn (self $item): bool => $item->parent_id === $category->id)
            ->values()
            ->map(fn (self $item): self => self::hydrateHierarchy($item, $indexed))
            ->values();

        $category->setRelation('children', $children);

        return $category;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
