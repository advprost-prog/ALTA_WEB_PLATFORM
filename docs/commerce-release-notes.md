# Commerce Release Notes

This release stabilizes the commerce module after the storefront move to multi-currency, multi-warehouse, cart, and checkout flows.

## Order Lifecycle Update

This update adds the first internal order lifecycle layer after the commerce foundation release.

Added:

- centralized order status enums for order, payment, and delivery states
- `payment_methods` and `delivery_methods` directories with active/inactive flags
- order method snapshots: payment/delivery method id and method name
- lifecycle timestamps on orders
- `order_status_histories` for status, payment, delivery, note, and system events
- `App\Services\Commerce\OrderLifecycleService`
- Filament order actions for confirm, processing, ready to ship, shipped, completed, paid, and cancel
- cancellation stock compensation before shipment through `StockService`
- lifecycle health-check diagnostics for broken statuses, snapshots, active methods, and timestamps
- repeated cancel protection so stock is not returned twice

Admin workflow:

- new storefront orders start as `new`
- managers confirm the order, move it through processing and shipping states, and mark payment manually
- `completed` closes the order
- `cancelled` closes the order and blocks routine lifecycle changes
- cancel before shipping restores stock with a `return` movement and keeps the original `sale` movement as audit history
- shipped and completed orders are not automatically cancelled in this phase

Storefront workflow:

- checkout shows only active payment and delivery methods
- orders store snapshots of method names
- the thank-you page shows the order number, total, selected payment/delivery methods, and a simple accepted status
- no payment gateway, Nova Poshta API, or automatic notification channel is connected in this phase

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
- `payment_methods` / `App\Models\PaymentMethod`
- `delivery_methods` / `App\Models\DeliveryMethod`
- `orders` / `App\Models\Order`
- `order_items` / `App\Models\OrderItem`
- `order_status_histories` / `App\Models\OrderStatusHistory`

## Core Services

- `App\Services\Commerce\ProductPricingService` resolves storefront currency and product prices
- `App\Services\Commerce\ProductAvailabilityService` resolves storefront availability
- `App\Services\Commerce\FulfillmentService` chooses a single warehouse for checkout
- `App\Services\Commerce\StockService` updates stock balances and stock movements inside transactions
- `App\Services\Commerce\CheckoutService` assembles the cart payload and creates orders
- `App\Services\Commerce\OrderLifecycleService` validates order lifecycle transitions and writes order history

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
10. For email delivery, set `MAIL_*` in the environment or configure `Сервер повідомлень` in admin; never commit SMTP credentials
11. Run `php artisan config:clear`
12. Run `php artisan notifications:test-email test@example.com`
13. Run `php artisan notifications:send-pending --dry-run`
14. Run `php artisan test`
15. Run `git diff --check`
16. Verify the storefront
17. Verify checkout end to end
18. Verify Filament commerce settings
19. Verify stock movements after a test order

Do not use `php artisan migrate:fresh` on staging or production.

## Notes For Operators

- the health-check is read-only
- there is no auto-fix path in this phase
- warehouse internals stay hidden from the customer
- historical orders rely on snapshots and should not be edited as live product data
- payment and delivery method names are snapshotted on each order
- manual `paid` status does not imply a real payment gateway transaction
- post-shipment returns remain out of scope and should not be modeled as a simple cancel
- notification mail server settings can be managed in admin; passwords are encrypted in DB and `.env` remains a fallback
- see `docs/order-lifecycle.md` for the current transition map and stabilization rules
