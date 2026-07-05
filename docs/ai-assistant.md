# Alta-Trade AI Assistant

## Architecture

Product AI Assistant is a backend-only module for generating product content suggestions. Storefront and checkout do not call AI. A manual Filament action creates an `ai_runs` record, sends sanitized product data to the AI provider, stores the raw structured result in `output_payload`, and creates `ai_suggestions` for admin/manager review.

AI configuration is owned by `ai_settings`. `.env` is only a development fallback when no encrypted DB key is saved.

## Encrypted Key Storage

`AiSetting` stores:

- `encrypted_api_key`: standard OpenAI API key for model requests.
- `encrypted_admin_api_key`: optional OpenAI Admin API key for costs sync.

Both fields use Laravel `Crypt`. They are hidden from model arrays and JSON. Filament shows only masked fingerprints such as `sk-...abcd`; an empty key field on save keeps the existing key.

## Budget And Spend

`AiSettingsService` checks:

- AI enabled flag;
- available API key from DB or env fallback;
- internal monthly budget;
- hard limit state.

Before every AI request, hard limit is enforced. If the current month estimate is greater than or equal to `monthly_budget`, the request is blocked before HTTP and an `AiBudgetExceededException` is raised.

After a successful AI response, `tokens_input`, `tokens_output`, and `cost_estimate` are written to `ai_runs`. Pricing comes from `config/ai_pricing.php`. Unknown models keep `cost_estimate` nullable but still store tokens when available.

## Costs Sync

Internal estimate works without Admin API access. If `encrypted_admin_api_key` is present, `OpenAiUsageService` can sync aggregated monthly OpenAI costs and store snapshots in `ai_usage_snapshots`.

Without Admin API key, sync returns a clear message:

```text
Для синхронізації фактичних OpenAI costs потрібен Admin API key.
```

## Standard API Key Vs Admin API Key

The standard API key is used for product enrichment and connection tests.

The Admin API key is optional and should only be used for organization/project usage or cost endpoints. Managers and content managers cannot access AI Settings and cannot view or edit either key.

## Suggestion Review And Apply

Review suggestions in `AI -> AI-пропозиції`. The table and view page expose `Застосувати` and `Відхилити` actions when the record is still `pending` or `accepted` and the current user is authorized.

Pending/accepted suggestions can be edited before Apply. The editable fields are `suggested_value` and JSON payload data; entity type, entity id, field, and old value stay read-only. Saving an edit only updates the suggestion and writes `edited_by` / `edited_at`. Applied and rejected suggestions cannot be edited.

Automatically applied fields:

- `short_description` -> `products.short_description`
- `full_description` -> `products.description`
- `description` -> `products.description`
- `seo_title` -> `products.seo_title`
- `seo_description` -> `products.seo_description`
- `image_alt_text` only when the `products.image_alt_text` column exists
- `main_image` / `main_image_candidate` only when `local_path` or `storage_path` points to an existing local file

After a successful Apply, the suggestion is kept for audit with `status=applied`, `applied_by`, and `applied_at`, but the default table workflow filter hides it from the active list. Use the history workflow filter to show applied/rejected records.

`attributes`, `gtin_candidates`, image search queries, placeholder prompts, and remote image candidates stay review-only in this phase. Applying a deleted-product suggestion or unsupported field returns a clear Filament notification instead of crashing the panel.

## Image Assistant First Phase

The old `AI фото` button has been replaced in the UI by ProductResource `Підібрати фото`. Image candidates are now an image workflow, not the final output of AI suggestions.

The picker is intentionally safe:

- no web scraping;
- no automatic Google/image search downloads;
- no automatic use of remote URLs;
- manual URL candidates are stored with a rights warning and `can_apply=false`;
- local image candidates can be applied only after a local file exists in `storage/app/public`, `public/storage`, or `public/images`.

Legacy suggestion fields such as `image_search_queries` may still exist as debug/secondary AI suggestions, but the primary workflow is `product_image_candidates` -> import selected -> gallery -> set main. Remote image candidates are never applied directly to `Product.main_image`.

## Product Completeness

ProductResource shows a live `Заповненість` badge. The score is 0-100 and uses weighted checks:

- base fields: name, slug, SKU, brand, category;
- commerce fields: price, stock status, stock quantity;
- media fields: main image, gallery, `image_alt_text`;
- content and SEO: short description, description, SEO title, SEO description;
- specifications.

Statuses are `critical` below 40, `warning` below 70, `info` below 90, and `success` at 90+. Product filters expose low completeness, without photo, without SEO, without description, and without specifications. The dashboard widget also summarizes ready products, products needing completion, products without photo, and products without SEO.

## insufficient_quota Handling

If OpenAI returns `insufficient_quota`, Alta-Trade stores a failed `AiRun` with a sanitized message:

```text
Ключ прийнято, але OpenAI повернув insufficient_quota. Потрібно поповнити API billing або перевірити Project budget.
```

No API key is written to `ai_runs.error`, logs, input payload, output payload, UI responses, or command output.

## Known Limitations

- No web scraping.
- No automatic photo search or download.
- No GTIN lookup API yet.
- Attributes and GTIN candidates are suggestions only and require manual verification.
- Attribute dictionary, legal image sourcing workflow, and bulk AI catalog audit are backlog items.
- Cost estimates depend on the local pricing config and can differ from actual OpenAI billing.

## Operations

Use:

```bash
php artisan alta:ai-health
```

This prints enabled/provider/model, yes/no key status, budget, current estimated spend, hard limit state, and last health check without exposing secrets.

Use `--live` only when a real backend connection test is intended:

```bash
php artisan alta:ai-health --live
```
