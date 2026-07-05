# Theme Engine

Alta-Trade Theme Engine is a controlled storefront styling layer. Themes are data, not arbitrary Blade templates: the storefront keeps the same routes, cart, checkout and product logic while the active theme changes CSS variables and allowed layout/component variants.

## Tables

- `theme_generation_runs`: AI Theme Studio run log with input, analysis, detected `style_profile`, selected preset, guardrails, generated payload and failure state.
- `storefront_themes`: current theme records, including `tokens`, `layout_config`, `component_config`, `style_profile`, `selected_preset`, guardrail metadata, `css_variables`, optional sanitized `custom_css`, preview image and activation state.
- `storefront_theme_versions`: snapshots of theme payloads and style metadata before significant updates and initial seed/generated versions.

## Theme Schema

`app/Services/Themes/ThemeSchema.php` defines defaults and allowed values.

Tokens:

- `colors`: primary, secondary, accent, background, surface, text, states and border colors.
- `typography`: body/heading family, size, weights and letter spacing.
- `radius`, `shadows`, `spacing`, `buttons`, `badges`.

Layout config:

- `headerVariant`, `topBarVariant`, `heroVariant`, `categoryGridVariant`, `productCardVariant`, `productPageVariant`, `footerVariant`.
- `containerWidth`, `density`, `mobileNavVariant`.

Component config:

- top bar/search/sticky header/category menu toggles.
- product badges, brand, SKU, quick buy and short specs toggles.
- `cardImageRatio`.

## Resolving

`ThemeResolver` resolves a theme in this order:

1. Admin/manager preview query `?theme={slug}` if the theme is not archived.
2. Cached active published theme from `active_storefront_theme`.
3. Published `alta-trade-dark-automotive`.
4. Runtime fallback model from `ThemeSchema`.

Preview mode never activates a theme and shows a storefront banner.

## Admin

`Дизайн -> Теми storefront` is backed by `StorefrontThemeResource`.

- Admin: create, edit, preview, activate, duplicate, archive and version.
- Manager: view and preview only.
- Content manager: no access.

Activation calls `StorefrontTheme::activate()`, publishes the selected theme, deactivates all others and clears resolver cache.

## AI Theme Studio

`Дизайн -> AI Theme Studio` is admin-only.

Workflow:

1. Validate and capture a public HTTP(S) URL with SSRF protection.
2. Extract safe style signals from HTML/CSS fallback capture.
3. Analyze style with AI when enabled, otherwise heuristic analysis.
4. Classify the source into a generic `style_profile` with ecommerce type, visual mode, density, card style, CTA style, homepage structure, header style, badge style, confidence and evidence.
5. Map the profile to a generic base preset such as `light_marketplace_compact`, `dark_automotive_bold`, `premium_spacious_catalog`, `promo_discount_grid` or `clean_minimal_catalog`.
6. Ask AI to refine only controlled ThemeSchema JSON. If AI is disabled, the selected preset becomes the draft payload.
7. Validate the payload, apply generic guardrails, then validate again.
8. Create a draft `ai_generated` theme and first version.
9. Store success/failure, detected profile, selected preset, guardrails and warnings on `theme_generation_runs`.

The generated theme is never active by default.

### Style Classifier

`WebsiteStyleClassifier` never creates site-specific presets. It uses capture/analysis signals to classify style generically:

- `ecommerce_type`: marketplace, brand store, promo shop, premium/minimal catalog, automotive/electronics/fashion or universal.
- `visual_mode`: light, dark or mixed.
- `density`: compact, normal or spacious.
- `card_style`, `cta_style`, `homepage_structure`, `header_style`, `badge_style`.
- `confidence` and short evidence strings.

For a light compact marketplace with yellow CTAs and many product rails the profile is generic: light, compact, marketplace/automotive catalog, marketplace card, yellow CTA, sectional homepage. It is not named after the source website.

### Preset Mapper

`ThemePresetMapper` converts the generic style profile into base ThemeSchema defaults. Presets provide safe starting tokens/layout/components and are selected only from `style_profile`, never from source URL or brand name.

### Guardrails

`ThemeGuardrailService` protects the generated payload from contradicting the classifier:

- confident light profiles keep light backgrounds/surfaces and dark text;
- confident dark profiles keep dark backgrounds/surfaces and light text;
- yellow/orange/red/blue CTA profiles keep matching but generic accent colors;
- compact profiles keep compact spacing and compact/marketplace cards;
- sectional profiles keep wide/sectional storefront layout variants;
- marketplace cards keep quick buy and badges enabled;
- sale-heavy badge profiles keep badges enabled.

The validator still blocks scripts, imports, remote images/assets, unsafe CSS, invalid variants, invalid colors and source domain/brand fragments. Guardrails can sanitize generated names and custom CSS in the generation pipeline, but custom CSS is a fallback, not the main compatibility mechanism.

### CSS Compatibility

Storefront templates expose `data-theme-visual-mode`, `data-theme-density`, `data-theme-card-style`, `data-theme-homepage` and `data-theme-preset` on `<body>`. `resources/css/app.css` contains a generic compatibility layer scoped to light themes so legacy dark utility classes do not dominate token-driven light themes. New theme behavior should prefer CSS variables and theme classes over per-theme custom CSS.

### Regenerate From Source

AI generated themes with `source_url` have a `Regenerate from source` action in `StorefrontThemeResource`. It repeats capture/analyze/classify/map/generate/guardrail, updates the existing inactive draft, creates a new version and does not activate the theme automatically. Active themes are not regenerated in place; duplicate or switch active theme first.

## Legal And Safety Rules

The validator blocks:

- `<script>` / `<style>` tags.
- `@import`.
- remote `url(...)`.
- remote image URLs in generated payloads.
- unsafe CSS such as `javascript:`, `expression()` or `behavior:`.
- unknown layout/component variants.
- invalid hex colors.
- source domain/brand fragments in theme name/custom CSS.

The capture service blocks localhost, private/reserved IP ranges, unresolved hosts and non-HTTP(S) schemes. It does not save source website assets as theme assets.

## System Themes

The idempotent `StorefrontThemeSeeder` creates:

- Alta Trade Dark Automotive.
- Clean Marketplace.
- Premium Parts.
- Discount Auto.

It updates only system theme slugs and skips custom/AI themes.

## Backlog

- Headless browser screenshots as analysis artifacts, not theme assets.
- Visual preview screenshots per theme.
- UI for browsing versions and rollback.
- More storefront variant hooks for cart/checkout/product page micro-layouts.
- Optional generated CSS patterns after a stricter CSS AST sanitizer.
