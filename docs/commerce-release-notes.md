# Commerce Release Notes

This release stabilizes the commerce module after the storefront move to multi-currency, multi-warehouse, cart, and checkout flows.

## What Was Added

- a read-only `commerce:health-check` artisan command
- a checkout submission token to prevent accidental double submit in the same session
- release checklist and acceptance scenarios in the architecture docs
- release notes for operators and reviewers
- regression tests for health-check, checkout protection, and read-only admin snapshot behavior

## Data Layer

These tables and models are part of the release surface:

- `commerce_settings` / `App\Models\CommerceSetting`
- `currencies` / `App\Models\Currency`
- `warehouses` / `App\Models\Warehouse`
- `product_prices` / `App\Models\ProductPrice`
- `stock_balances` / `App\Models\StockBalance`
- `stock_movements` / `App\Models\StockMovement`
- `orders` / `App\Models\Order`
- `order_items` / `App\Models\OrderItem`

## Core Services

- `App\Services\Commerce\ProductPricingService` resolves storefront currency and product prices
- `App\Services\Commerce\ProductAvailabilityService` resolves storefront availability
- `App\Services\Commerce\FulfillmentService` chooses a single warehouse for checkout
- `App\Services\Commerce\StockService` updates stock balances and stock movements inside transactions
- `App\Services\Commerce\CheckoutService` assembles the cart payload and creates orders

## Mode Summary

Simple mode:

- storefront uses the default currency and default warehouse
- product price and stock compatibility fields stay synchronized with normalized tables
- no currency switcher and no warehouse selector are shown to the buyer

Multi-currency mode:

- active currencies are selectable in the storefront
- the selected currency is stored in session
- missing currency prices are not auto-converted
- checkout uses one active currency snapshot for the whole order

Multi-warehouse mode:

- buyers still do not see warehouse choices
- storefront availability is aggregated across active warehouses
- checkout selects one warehouse per order line
- split fulfillment is not supported in this phase

## Pre-Deploy Checks

1. Run `git pull`
2. Run `composer install` if dependencies changed or the environment is new
3. Run `npm ci` or `npm install` if the frontend lockfile changed or the environment is new
4. Run `npm run build`
5. Run `php artisan migrate`
6. Run `php artisan config:clear`
7. Run `php artisan route:clear`
8. Run `php artisan view:clear`
9. Run `php artisan commerce:health-check`
10. Run `php artisan test`
11. Run `git diff --check`
12. Verify the storefront
13. Verify checkout end to end
14. Verify Filament commerce settings
15. Verify stock movements after a test order

Do not use `php artisan migrate:fresh` on staging or production.

## Notes For Operators

- the health-check is read-only
- there is no auto-fix path in this phase
- warehouse internals stay hidden from the customer
- historical orders rely on snapshots and should not be edited as live product data