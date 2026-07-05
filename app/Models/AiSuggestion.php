<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class AiSuggestion extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_APPLIED = 'applied';

    public const APPLY_STATUS_CAN_APPLY = 'can_apply';

    public const APPLY_STATUS_REVIEW_ONLY = 'review_only';

    public const APPLY_STATUS_UNSUPPORTED = 'unsupported';

    public const APPLY_STATUS_MISSING_TARGET = 'missing_target';

    public const APPLY_STATUS_ALREADY_APPLIED = 'already_applied';

    public const APPLY_STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING => 'Очікує',
        self::STATUS_ACCEPTED => 'Прийнято',
        self::STATUS_REJECTED => 'Відхилено',
        self::STATUS_APPLIED => 'Застосовано',
    ];

    public const AUTO_APPLICABLE_FIELDS = [
        'short_description',
        'full_description',
        'description',
        'seo_title',
        'seo_description',
        'image_alt_text',
        'main_image',
        'main_image_candidate',
    ];

    public const REVIEW_ONLY_FIELDS = [
        'attributes',
        'gtin_candidates',
        'image_search_queries',
        'image_candidates',
        'image_description',
        'branded_placeholder_prompt',
        'external_image_candidates',
    ];

    public const FIELD_LABELS = [
        'short_description' => 'Короткий опис',
        'full_description' => 'Повний опис',
        'description' => 'Опис',
        'seo_title' => 'SEO title',
        'seo_description' => 'SEO description',
        'image_alt_text' => 'Alt-текст',
        'attributes' => 'Характеристики',
        'gtin_candidates' => 'GTIN candidates',
        'image_search_queries' => 'Пошукові запити фото',
        'image_candidates' => 'Кандидати фото',
        'image_description' => 'Опис зображення',
        'main_image' => 'Основне фото',
        'main_image_candidate' => 'Кандидат основного фото',
        'branded_placeholder_prompt' => 'Placeholder prompt',
        'external_image_candidates' => 'Зовнішні кандидати фото',
    ];

    protected $fillable = [
        'ai_run_id',
        'entity_type',
        'entity_id',
        'field',
        'old_value',
        'suggested_value',
        'suggested_payload',
        'status',
        'applied_by',
        'applied_at',
        'edited_by',
        'edited_at',
    ];

    protected function casts(): array
    {
        return [
            'entity_id' => 'integer',
            'suggested_payload' => 'array',
            'applied_at' => 'datetime',
            'edited_at' => 'datetime',
        ];
    }

    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class);
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function editedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'entity_id');
    }

    public function canBeAppliedAutomatically(): bool
    {
        return $this->applyStatus() === self::APPLY_STATUS_CAN_APPLY;
    }

    public function canBeRejected(): bool
    {
        return $this->isPendingOrAccepted();
    }

    public function isPendingOrAccepted(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_ACCEPTED], true);
    }

    public function isProductSuggestion(): bool
    {
        return $this->entity_type === Product::class;
    }

    public function productExists(): bool
    {
        return $this->isProductSuggestion()
            && filled($this->entity_id)
            && Product::query()->whereKey($this->entity_id)->exists();
    }

    public function isContentField(): bool
    {
        return in_array($this->field, [
            'short_description',
            'full_description',
            'description',
            'seo_title',
            'seo_description',
            'image_alt_text',
        ], true);
    }

    public function canBeEdited(): bool
    {
        return $this->isPendingOrAccepted();
    }

    public function fieldLabel(): string
    {
        return self::FIELD_LABELS[$this->field] ?? $this->field;
    }

    public function applyStatus(): string
    {
        if ($this->status === self::STATUS_APPLIED) {
            return self::APPLY_STATUS_ALREADY_APPLIED;
        }

        if ($this->status === self::STATUS_REJECTED) {
            return self::APPLY_STATUS_REJECTED;
        }

        if (! $this->isProductSuggestion() || ! $this->productExists()) {
            return self::APPLY_STATUS_MISSING_TARGET;
        }

        if (in_array($this->field, self::REVIEW_ONLY_FIELDS, true)) {
            return self::APPLY_STATUS_REVIEW_ONLY;
        }

        if ($this->field === 'image_alt_text' && ! Schema::hasColumn('products', 'image_alt_text')) {
            return self::APPLY_STATUS_REVIEW_ONLY;
        }

        if (in_array($this->field, ['main_image', 'main_image_candidate'], true)) {
            return $this->hasApplicableLocalImageCandidate()
                ? self::APPLY_STATUS_CAN_APPLY
                : self::APPLY_STATUS_REVIEW_ONLY;
        }

        if (in_array($this->field, self::autoApplicableFields(), true)) {
            return self::APPLY_STATUS_CAN_APPLY;
        }

        return self::APPLY_STATUS_UNSUPPORTED;
    }

    public function applyStatusLabel(): string
    {
        return match ($this->applyStatus()) {
            self::APPLY_STATUS_CAN_APPLY => 'Можна застосувати',
            self::APPLY_STATUS_REVIEW_ONLY => 'Тільки перегляд',
            self::APPLY_STATUS_UNSUPPORTED => 'Не підтримується',
            self::APPLY_STATUS_MISSING_TARGET => 'Немає товару',
            self::APPLY_STATUS_ALREADY_APPLIED => 'Застосовано',
            self::APPLY_STATUS_REJECTED => 'Відхилено',
            default => 'Невідомо',
        };
    }

    public function applyStatusColor(): string
    {
        return match ($this->applyStatus()) {
            self::APPLY_STATUS_CAN_APPLY => 'success',
            self::APPLY_STATUS_REVIEW_ONLY => 'warning',
            self::APPLY_STATUS_UNSUPPORTED, self::APPLY_STATUS_MISSING_TARGET => 'danger',
            self::APPLY_STATUS_ALREADY_APPLIED => 'info',
            self::APPLY_STATUS_REJECTED => 'gray',
            default => 'gray',
        };
    }

    public function applyUnavailableReason(): ?string
    {
        return match ($this->applyStatus()) {
            self::APPLY_STATUS_CAN_APPLY => null,
            self::APPLY_STATUS_MISSING_TARGET => 'Товар видалений або недоступний.',
            self::APPLY_STATUS_ALREADY_APPLIED => 'AI-пропозицію вже застосовано.',
            self::APPLY_STATUS_REJECTED => 'AI-пропозицію відхилено.',
            self::APPLY_STATUS_REVIEW_ONLY => $this->reviewOnlyReason(),
            self::APPLY_STATUS_UNSUPPORTED => 'Поле "'.$this->fieldLabel().'" поки не застосовується автоматично.',
            default => 'Цю AI-пропозицію не можна застосувати автоматично.',
        };
    }

    public function hasApplicableLocalImageCandidate(): bool
    {
        $path = $this->suggested_payload['local_path']
            ?? $this->suggested_payload['storage_path']
            ?? null;

        if (blank($path) || preg_match('/^https?:\/\//i', (string) $path) === 1 || str_contains((string) $path, "\0")) {
            return false;
        }

        $path = ltrim((string) $path, '/');

        if (str_starts_with($path, 'images/')) {
            return is_file(public_path($path));
        }

        if (str_starts_with($path, 'storage/')) {
            $storagePath = substr($path, strlen('storage/'));

            return is_file(public_path($path))
                || ($storagePath !== '' && Storage::disk('public')->exists($storagePath));
        }

        return Storage::disk('public')->exists($path);
    }

    /**
     * @return array<int, string>
     */
    public static function autoApplicableFields(): array
    {
        $fields = self::AUTO_APPLICABLE_FIELDS;

        if (! Schema::hasColumn('products', 'image_alt_text')) {
            $fields = array_values(array_diff($fields, ['image_alt_text']));
        }

        return $fields;
    }

    private function reviewOnlyReason(): string
    {
        return match ($this->field) {
            'attributes' => 'attributes не застосовуються автоматично в цій фазі.',
            'gtin_candidates' => 'gtin_candidates потребують ручної перевірки.',
            'image_alt_text' => 'image_alt_text неможливо застосувати, бо в products немає поля image_alt_text.',
            'image_candidates', 'external_image_candidates' => 'Зовнішні фото не застосовуються автоматично без локального файлу і підтвердження.',
            'main_image', 'main_image_candidate' => 'Кандидат фото можна застосувати тільки якщо local_path або storage_path існує локально.',
            'image_search_queries' => 'Пошукові запити є підказкою для ручного пошуку фото.',
            'branded_placeholder_prompt' => 'Placeholder prompt є інструкцією для ручної генерації або дизайну.',
            default => 'Цей тип пропозиції поки не застосовується автоматично.',
        };
    }
}
