# Product Catalog Core

This document describes the Product Catalog Core layer introduced for SKU-based commerce accounting.

Scope of this phase:

- product card + SKU/variant architecture
- catalog dictionaries: units, tax profiles, attributes
- SKU-level price/stock/order snapshots
- compatibility with legacy product-level fields

Out of scope for this phase:

- delivery provider integrations
- Nova Poshta API integration
- shipment tracking workflows
- RRO/PRRO or fiscal integrations

## Data Model

Core entities:

- `products`: storefront card and marketing-level data
- `product_variants`: sellable/accounting SKU unit
- `units`: dictionary for base/sales/purchase units
- `tax_profiles`: VAT and fiscal grouping dictionary
- `variant_packages`: packaging options per SKU
- `product_barcodes`: barcode set per SKU/package
- `attributes`, `product_attribute_values`, `category_attributes`: attribute dictionary and assignments
- `product_category`: additional categories per product

Compatibility layer remains active:

- `products.price`, `products.old_price`, `products.stock`, `products.sku`
- `product_prices`, `stock_balances`, `stock_movements` now support `product_variant_id`

## Product vs Variant

`Product` remains the storefront entry and SEO/media aggregate.

`ProductVariant` is the operational source for:

- SKU identity (`sku`, `name`)
- unit semantics (`base_unit_id`, `sales_unit_id`, `purchase_unit_id`)
- tax semantics (`tax_profile_id`, `vat_rate` via tax profile)
- excise flags (`is_excise_applicable`, `excise_rate`, `requires_excise_stamp_entry`)

Rules:

- one default variant per product (`is_default = true`)
- default variant syncs key compatibility fields back to product
- excise-enabled variant auto-fills default `excise_rate = 5.00` when rate is missing
- excise-disabled variant clears `excise_rate` and `requires_excise_stamp_entry`

## Pricing and Stock Resolution

Services are variant-aware with backward-compatible fallback:

- `App\Services\Commerce\ProductPricingService`
- `App\Services\Commerce\ProductAvailabilityService`
- `App\Services\Commerce\FulfillmentService`
- `App\Services\Commerce\StockService`

Resolution strategy:

- prefer rows linked to resolved `product_variant_id`
- fallback to legacy rows with `product_variant_id = null` when needed
- apply fallback per warehouse (not globally), which prevents mixed-row checkout regressions

## Checkout Snapshots

`CheckoutService` writes variant-aware snapshots into `order_items`:

- `product_variant_id`
- unit snapshot (`base_unit_id`, `sales_unit_id`, `unit_name`, `unit_short_name`)
- tax snapshot (`tax_profile_id`, `tax_profile_name`, `tax_profile_code`, `vat_rate`)
- excise snapshot fields

Cart keys support both modes:

- legacy product key (default variant)
- explicit variant key (`variant:{id}`)

## Admin Surface (Filament)

Added resources:

- `Units`
- `TaxProfiles`
- `ProductAttributes`

`ProductVariant` is intentionally a technical/internal resource in admin UX.

Product-centered workflow:

- admins work from `Каталог -> Товари`
- `products.has_variants = false` means a simple product: the default variant is treated as the product in UI
- SKU fields for the simple/default case are edited as `Продажні налаштування`, `Одиниці`, `Оподаткування`, `Пакування`, `Штрихкоди`, `Ціни`, and `Залишки` of the product
- the word "variant" is not emphasized in simple-product workflow even though data is stored in the default `product_variants` row
- `products.has_variants = true` enables explicit variant management from the `Варіанти` tab and relation manager
- package/barcode/price/stock/tax records belong to the concrete variant when multi-variant mode is enabled

Navigation policy:

- `ProductVariantResource` is hidden from sidebar navigation
- there are no standalone sidebar items for variant packages, barcodes, or variant images
- SKU is not presented as a second product-like business object

Product edit shows the `Варіанти` tab and relation manager only when `has_variants = true`.

Variant edit includes relation managers for:

- packages
- barcodes
- variant images

## Diagnostics

`php artisan commerce:health-check` now validates catalog-core risks:

- missing default dictionary entries (`piece`, `kg`, `no_vat`, `vat_20`)
- simple products with more than one active variant
- multi-variant products without active variants
- simple products without a default variant
- multi-variant products without a default variant
- active products with inactive default variant
- active products without active default variant
- products with multiple default variants
- variants without mandatory unit/tax links
- inconsistent excise field combinations
- order items with variant id but missing unit/tax snapshots
- inactive attributes linked to active products

## UX Notes

- `ProductVariant/SKU` remains the accounting and sellable backend entity
- admin UX is product-centered and does not require separate SKU section navigation
- for simple products, one default SKU is edited as product fields; admins do not manually choose `product_variant_id`
- for multi-variant products, additional variants are managed in product page context and each variant owns its units, tax, excise, packages, barcodes, prices, and stock balances
- `Unit` and `TaxProfile` are dictionaries, not enums
- delivery/shipment provider flows are not implemented in this phase

## Test Coverage

Key regression areas covered:

- checkout/cart with variant keys and legacy compatibility
- per-warehouse fallback behavior in multi-warehouse checkout
- variant excise normalization rules
- catalog-core health-check diagnostics
