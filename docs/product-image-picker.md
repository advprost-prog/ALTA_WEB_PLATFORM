# Product Image Picker

## Overview

Product Image Picker replaces the previous text-only image assistant. It is provider-based and stores candidates in `product_image_candidates`, then imports selected images into `product_images`.

The picker now supports three operator-controlled modes:

- `serpapi`: Google Images through the licensed SerpAPI endpoint.
- `page_url`: product page URL extraction from HTML.
- `direct_image_url` / legacy `manual_url`: direct image URLs only.

The app does not scrape Google Images directly. SerpAPI requests use `https://serpapi.com/search` with `engine=google_images`. The provider reads `image_results` first and falls back to the live SerpAPI key `images_results` when present; it does not use `images`, `items`, `results`, `organic_results`, or `shopping_results` as candidate sources.

## Data Flow

1. ProductResource action `Підібрати фото` receives the selected mode and input.
2. `ProductImageSearchService` calls SerpAPI, the page extractor, or the direct URL provider.
3. `ImageCandidateValidator` downloads and validates each candidate.
4. Candidates are stored with status, dimensions, MIME, warnings, quality score, source metadata, and validation diagnostics.
5. The operator reviews candidates in the `Кандидати фото` relation manager. The default tab shows only importable candidates; rejected/debug records live in a separate tab.
6. Selected candidates are imported by `ProductImageImportService`.
7. Downloaded images are converted to WebP by `ImageConversionService`.
8. Imported files are stored under `storage/app/public/product-gallery/{product_id}`.
9. `ProductImage` records store `source_url`, `source_domain`, `imported_by`, `imported_at`, `quality_score`, `metadata`, `is_main`, and `file_hash`.

## Validation And Security

`ImageDownloadService` allows only `http` and `https`. It rejects `file://`, `ftp://`, localhost, `127.0.0.1`, private/reserved IPs, link-local/reserved ranges, and internal hostnames without public-looking domains. Redirects are followed manually and each redirect target is validated before download. Resolved DNS records are checked when available.

External reads are intentionally short-lived:

- source/product page connect timeout: 2 seconds;
- source/product page total timeout: 4 seconds;
- image connect timeout: 2 seconds;
- image total timeout: 5 seconds;
- manual redirects: 3.

The picker has a hard denylist in `config/image_search.php`: `yandex.ru`, `market.yandex.ru`, all `*.yandex.ru`, and the `.ru` / `.by` TLDs. Both `image_url` and `source_url` are checked before a download, so a SerpAPI result with a CDN image but a blocked marketplace page is stored as `blocked_domain` and never imported.

Technical validation checks:

- HTTP success;
- content length under configured max MB;
- real MIME type;
- allowed MIME: JPEG, PNG, WebP;
- dimensions above configured minimum;
- obvious placeholder/banner/watermark keywords in URL/title.
- HTML in direct image mode returns an operator-friendly message that points to the product page URL mode.
- extreme banner-like aspect ratios are rejected.

Warnings are split in metadata:

- `critical_warnings`: download/security/MIME/size/ratio failures; these force `can_import=false` and `status=rejected`.
- `non_critical_warnings`: rights review, unavailable AI vision, and similar operator-review notes; these can remain importable.

In product page URL mode, `text/html` is valid input. `ProductPageImageExtractor` reads `og:image`, `og:image:secure_url`, `twitter:image`, JSON-LD `image` fields, `img` source attributes, `picture source srcset`, and `img srcset`. Relative URLs are resolved against the page URL; favicon/logo/icon/svg/gif/pixel/banner/placeholder candidates are filtered before validation.

Known small images, icon/logo/banner/payment/delivery/avatar assets, and banner ratios are filtered before storage when enough HTML metadata is available. The validator still downloads each surviving URL and checks the real file before it becomes importable.

For SerpAPI results, the picker validates a wider raw pool than the requested display limit. If an `original` image URL blocks download, but the SerpAPI `link` points to a source page, the picker tries `ProductPageImageExtractor` on that page and can create an importable `serpapi_source_page` candidate from the extracted product image. The blocked original is kept only as rejected/debug.

Timeouts, connection failures, blocked domains, non-HTML source pages, invalid HTML, and empty HTML are stored as rejected/debug candidates. They must not bubble up to Livewire as stack traces. A failed candidate does not stop the rest of the search.

Vision validation is not connected in this phase. Candidates receive a warning that AI watermark/text/logo review is unavailable and the operator makes the final rights/quality decision.

## SerpAPI Diagnostics

Run:

```bash
php artisan alta:image-search-test {productSlug}
```

Options:

- `--query=` to test one explicit query.
- `--limit=5` to control mapped candidates.
- `--show-rejected` to print a short rejected/debug preview with category, HTTP status, source, and message.
- `--raw` to print a compact, redacted JSON summary.

The command reports generated queries, whether the SerpAPI key is set, HTTP status, response error, `search_metadata.status`, `image_results` count, first result keys, preview candidates, `valid_importable_count`, `review_count`, `rejected_count`, and grouped rejected reasons. It never prints the API key.

To diagnose one candidate import, run:

```bash
php artisan alta:image-import-test {candidateId}
php artisan alta:image-import-test {candidateId} --import --set-main
```

Without `--import`, the command is a dry run: it prints product/candidate details, URL safety, download status, MIME, dimensions, duplicate checks, and WebP capabilities without creating `ProductImage`. With `--import`, it calls the same import service used by the Filament UI and prints the resulting `product_image_id`, storage path, and public URL when successful.

## Import And Main Photo

Import never writes a remote URL directly to `Product.main_image`. The importer downloads the image again, checks duplicate `source_url`, computes a `file_hash`, prevents duplicate file imports for the same product, converts the file to WebP, and creates a gallery record.

Import results are reported per selected candidate. Each result includes `candidate_id`, `source_domain`, `image_url`, `status`, `reason`, `message`, download status, MIME, dimensions, storage path, and `product_image_id` when created. UI notifications show imported/skipped/failed counts plus the first failure reasons, so operators should not see a bare `Пропущено: 1` without context.

Duplicate handling is explicit:

- `duplicate_source_url`: the same direct source/image URL was already imported;
- `duplicate_file_hash`: a different URL downloaded to the same file bytes;
- `rejected_candidate`: the selected candidate is not currently importable;
- `download_failed`, `conversion_failed`, and `save_failed`: technical failures stored in candidate metadata under `import_result`.

For HTML page extraction, multiple images may share the same page `source_url`; duplicate detection also stores and compares the actual candidate `image_url` in metadata so different images from the same page can be imported while the same image is still skipped.

Any gallery photo can be set as main. `ProductImage::setAsMain()` updates `Product.main_image`, marks the selected record as `is_main`, and clears `is_main` on other gallery records for the same product.

If a main gallery record is deleted, the model reassigns the next gallery image as main or clears `Product.main_image`. Physical files are not deleted automatically by the admin gallery action.

## WebP Conversion

The conversion service uses PHP GD directly. Required functions are `imagecreatefromstring` and `imagewebp`. If GD/WebP support is missing, the importer returns a clear error and does not create a broken gallery record.

`ImageConversionService::capabilities()` reports whether `gd`, `imagecreatefromstring`, `imagewebp`, and `IMG_WEBP` support are available. Missing support produces `conversion_failed` with an operator-facing message that PHP GD/WebP must be enabled.

The target format is WebP, max 1200x1200, quality 82, preserving aspect ratio without crop.

## Candidate Preview

Таблиця кандидатів використовує компактний адаптивний layout: фото 88px з `object-fit: contain`, короткий рядок джерела/статусу/якості та обрізаний блок перевірки. Дії рядка зібрані в меню `Дії`, щоб таблиця не вимагала горизонтального скролу на вузьких екранах. `Переглянути` відкриває модальне вікно з більшим фото та metadata кандидата.

Для вибраних записів доступні bulk actions:

- `Додати вибрані в галерею` імпортує вибрані importable-кандидати.
- `Відхилити вибрані` масово переводить придатні/перевірені кандидати у rejected і пропускає imported або вже rejected записи.

Відхиленими/debug-кандидатами можна керувати прямо з таблиці кандидатів:

- `Повернути в схвалені` повертає відхилений або failed-кандидат у статус `approved`, очищає причину відхилення і знову робить його доступним для імпорту. Під час наступного імпорту система все одно повторно завантажує та перевіряє фото.
- `Видалити` видаляє один запис відхиленого/debug-кандидата і не чіпає фото в галереї.
- `Видалити відхилені` масово видаляє вибрані відхилені/debug-кандидати та пропускає importable/imported записи.

## Settings

Image search settings live in AI Settings:

- enabled flag;
- provider;
- encrypted SerpAPI/image search key;
- safe mode;
- max candidates;
- min width and height;
- preferred format;
- max download size;
- manual URL candidates flag.

Full provider keys are never shown after saving.

## Backlog

- Manufacturer/supplier feed provider.
- Vision quality check for product relevance, watermarks, text overlays, shop logos, collages, and banners.
- Bulk catalog photo audit and import queue.
