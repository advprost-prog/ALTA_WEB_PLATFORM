# Commerce Architecture

ALTA_WEB_PLATFORM starts in a simple commerce mode: one default currency and one default warehouse. Multi-currency and multi-warehouse workflows are prepared in the database and admin UI, but are disabled by default.

## Base Mode

- `commerce_settings.multi_currency_enabled = false`
- `commerce_settings.multi_warehouse_enabled = false`
- Default currency is `UAH`.
- Default warehouse is `Основний склад`.
- Public storefront and basic admin product forms do not show currency or warehouse selectors.
- `products.price`, `products.old_price`, and `products.stock` remain as a compatibility/cache layer, but public price and availability decisions are resolved through commerce services and normalized tables.

## Settings

Commerce settings are stored separately from public site settings in `commerce_settings`.

The application expects one settings row. `App\Models\CommerceSetting::current()` returns the first settings row by id, creates it when absent, and ensures `default_currency_id` and `default_warehouse_id` are present.

The Filament page `Налаштування магазину` controls:

- multi-currency mode
- multi-warehouse mode
- default currency
- default warehouse

## Currencies

Currencies live in `currencies`.

Important fields:

- `code`: `UAH`, `USD`, `EUR`
- `precision`
- `rate_to_base`
- `is_base`
- `is_active`

Only one currency can have `is_base = true`; saving a new base currency clears the flag from the others. Rates are stored as decimals and are not fetched automatically in this phase.

`UAH` is created as the active base currency by the migration/seed path.

## Warehouses

Warehouses live in `warehouses`.

Important fields:

- `name`
- `code`
- `address`
- `is_default`
- `is_active`

Only one warehouse can have `is_default = true`; saving a new default warehouse clears the flag from the others.

`Основний склад` is created by the migration/seed path.

## Product Prices

Product prices live in `product_prices`.

Each product can have one price per currency:

- `product_id`
- `currency_id`
- `price`
- `compare_at_price`
- `is_active`

In simple mode, product price changes on `products.price` and `products.old_price` synchronize the default currency row. In multi-currency mode, the Filament product form shows the currency price repeater and does not require a storefront currency selector.

The storefront does not synthesize missing currency prices in this phase.

## Product Catalog Core (SKU Layer)

Catalog core introduces SKU-level accounting while keeping product-level storefront compatibility.

Highlights:

- `products` remain storefront cards
- `product_variants` become sellable/accounting units
- `product_prices`, `stock_balances`, `stock_movements`, and `order_items` support `product_variant_id`
- checkout, pricing, stock, and fulfillment services resolve variant-first with safe legacy fallback

Fallback behavior is warehouse-aware. In mixed data states, resolver picks variant row if present for a warehouse and falls back to legacy row (`product_variant_id = null`) only for warehouses where variant row is missing.

See `docs/product-catalog.md` for schema and operational details.

## Product Admin Modes

In simple mode, the Filament product form shows only:

- `Ціна`
- `Стара ціна`
- `Залишок`

It does not show currency or warehouse selectors. Saving the product updates the default `product_prices` row and default `stock_balances` row.

In multi-currency mode, the product form shows `Ціни за валютами`. Active currencies are selectable, the default currency is marked in the option label, and the database unique key prevents duplicate prices for the same product/currency pair.

In multi-warehouse mode, the product form shows `Залишки за складами`. Active warehouses are selectable, the default warehouse is marked in the option label, `reserved_quantity` is read-only, and available quantity is shown as `quantity - reserved_quantity`.

## Storefront Pricing

Public pricing is centralized in `App\Services\Commerce\ProductPricingService`.

The service resolves:

- current storefront currency
- default currency
- selected-currency product price
- default product price fallback
- UI payload with `price`, `compare_at_price`, `currency_code`, `currency_symbol`, `is_fallback_price`, and `is_available_for_selected_currency`

When `multi_currency_enabled = false`, the storefront always uses the default currency and reads prices from `product_prices.default_currency_id`. No currency selector is shown.

When `multi_currency_enabled = true`, the storefront can show prices in the selected active currency. If a product has no active `product_prices` row in the selected currency, the service does not convert from another currency. The UI may show the default currency price as a fallback, but checkout treats that line as unavailable for the selected currency and blocks the order until the cart is valid in one currency.

`rate_to_base` is stored as a snapshot/reference field only. It is not used for automatic storefront conversion in this phase.

## Storefront Currency Selection

The currency switcher lives in the public header and is visible only when multi-currency mode is enabled and more than one active currency exists.

Rules:

- currencies are loaded only from active `currencies`
- selection is stored in the session under `storefront_currency_id`
- inactive or missing selected currency falls back to the default currency
- changing currency redirects the customer back to the current page
- cart and checkout never create mixed-currency orders

The selected currency is a storefront preference, not a database field on the customer.

## Stock Balances

Current stock lives in `stock_balances`.

Each product can have one balance per warehouse:

- `product_id`
- `warehouse_id`
- `quantity`
- `reserved_quantity`

Available quantity is `quantity - reserved_quantity`. Negative total or reserved stock is rejected, and reserved stock cannot exceed total stock.

In simple mode, product stock changes on `products.stock` synchronize the default warehouse balance.

## Storefront Availability

Public availability is centralized in `App\Services\Commerce\ProductAvailabilityService`.

The storefront never shows warehouse names or warehouse selection to customers.

Rules:

- available quantity is always `quantity - reserved_quantity`
- in simple mode, availability uses only `commerce_settings.default_warehouse_id`
- in multi-warehouse mode, listing/detail pages may show an aggregate public status across active warehouses
- cart limits and checkout validation use the maximum quantity that one active warehouse can fulfill
- a product is not purchasable when checkout cannot resolve a valid stock source for the requested quantity

Simple mode can show a quantity label such as `Залишок: X шт`. Multi-warehouse mode intentionally keeps the public status generic, because exposing per-warehouse inventory would leak internal fulfillment details and imply a customer choice that does not exist in this phase.

## Fulfillment Warehouse Resolution

Checkout warehouse selection is centralized in `App\Services\Commerce\FulfillmentService`, while `StockService` remains the source of truth for stock mutations and movements.

First-phase resolution:

- if `multi_warehouse_enabled = false`, stock is deducted from the default warehouse
- if `multi_warehouse_enabled = true`, the system tries the default warehouse first
- if the default warehouse lacks enough available stock, the system searches another active warehouse with enough stock for the whole order item
- `order_items.warehouse_id` stores the actual fulfillment warehouse
- `stock_movements.warehouse_id` stores the same actual warehouse for `sale` movements

This phase intentionally does not support split fulfillment. One order item is not split across several warehouses. If no single active warehouse can fulfill the item quantity, checkout is blocked with a customer-facing message and no order is created.

The customer does not see warehouses because the first phase is a storefront checkout, not a public logistics planning tool. Warehouse choice is an internal operational decision.

## Manual Stock Adjustment

The Filament page `Коригування залишку` updates stock through `App\Services\Commerce\StockService`.

The page supports:

- absolute quantity
- signed delta
- optional note
- automatic default warehouse in simple mode
- explicit warehouse selection in multi-warehouse mode

Rules:

- operations run inside a database transaction
- `quantity` cannot become negative
- `quantity` cannot become lower than `reserved_quantity`
- no movement is created when the quantity is unchanged
- successful changes create `stock_movements.type = adjustment`

## Stock Movements

Stock movements live in `stock_movements` and are the audit trail for stock changes.

Current movement signs:

- `sale`: negative quantity
- `adjustment`: signed delta
- `initial`: positive starting quantity
- `return`: positive quantity
- `transfer_in`: positive quantity
- `transfer_out`: negative quantity

`balance_after` stores the resulting `stock_balances.quantity` after the movement. Checkout uses `Product::applyStockChange()`, which delegates stock operations to `StockService`, so the stock balance update and movement creation happen together inside the order transaction.

The Filament resource `Рух товарів` is read-only. It is an audit trail, not a working document; records are not edited directly through normal admin UI.

## Warehouse Transfers

The Filament page `Переміщення між складами` is available only when `multi_warehouse_enabled = true`.

Rules:

- source and target warehouses must be different
- quantity must be greater than zero
- source available quantity is `quantity - reserved_quantity`
- transfer cannot exceed source available quantity
- operation runs inside one transaction
- source balance is decreased and gets `transfer_out`
- target balance is increased and gets `transfer_in`
- both movements store `balance_after`

This is a commerce-level transfer between warehouse balances. It is not a purchasing, receiving, supplier, or full ERP logistics module.

## Orders

Orders and order items store commerce snapshots so historical orders do not depend on future product, currency, or warehouse changes.

`orders` stores:

- `customer_id`
- `customer_name`
- `phone`
- `email`
- `city`
- `address`
- `currency_id`
- `currency_code`
- `exchange_rate_to_base`
- `warehouse_id`
- `total_amount`
- `status`
- `payment_status`
- `delivery_status`
- `payment_method_id`
- `payment_method_name`
- `delivery_method_id`
- `delivery_method_name`
- lifecycle timestamps such as `confirmed_at`, `paid_at`, `shipped_at`, `completed_at`, and `cancelled_at`

`order_items` stores:

- `warehouse_id`
- `unit_price`
- `price`
- `total`

In simple mode, order creation automatically uses the default currency and default warehouse. Checkout runs in a database transaction and blocks stock changes that would make the default balance negative.

## Customers

Customer master data is documented in [`docs/customers.md`](customers.md).

`Customer` is the storefront buyer. It is separate from `User`, which remains an internal admin/operator account. A customer is not an auth user in this phase.

Checkout links each new order to a customer when possible, but the order snapshot remains the historical truth for that order. Customer address edits, phone/email changes, and name corrections do not rewrite existing order snapshots.

When a checkout/order phone and email point to different customers, the system avoids auto-merge and reports the case as a potential duplicate through diagnostics.

Existing orders can be linked with the read-controlled command:

```bash
php artisan customers:backfill-from-orders --dry-run
```

The command is not run from migrations and does not perform aggressive merge.

## Order Lifecycle

Order lifecycle changes are centralized in `App\Services\Commerce\OrderLifecycleService`.

Detailed operator notes live in [`docs/order-lifecycle.md`](order-lifecycle.md).

Base order statuses:

- `new`: new storefront order
- `confirmed`: manager confirmed the order
- `processing`: order is being prepared
- `ready_to_ship`: order is packed and ready for dispatch
- `shipped`: order was sent to the customer
- `completed`: order is closed
- `cancelled`: order is cancelled
- `failed` and `returned`: reserved for problem/return flows

Payment statuses:

- `unpaid`
- `pending`
- `paid`
- `partially_paid`
- `failed`
- `refunded`
- `cancelled`

Delivery statuses:

- `not_required`
- `pending`
- `preparing`
- `ready_to_ship`
- `shipped`
- `delivered`
- `failed`
- `returned`
- `cancelled`

Checkout creates orders with `status = new`, `delivery_status = pending`, and a payment status based on the selected method. Cash on delivery and cash start as `unpaid`; bank transfer starts as `pending`. The system does not mark online payments as paid because no payment gateway is integrated in this phase.

Filament order actions call `OrderLifecycleService` instead of updating status fields directly. The standard action path is:

- confirm
- mark processing
- mark ready to ship
- mark shipped
- mark completed
- mark paid
- cancel

`completed` and `cancelled` are terminal states for normal admin actions. After an order reaches either state, routine lifecycle changes are blocked unless a future explicit administrative exception action is added.

## Payment And Delivery Methods

Payment methods live in `payment_methods` and delivery methods live in `delivery_methods`.

Seeded payment methods:

- `cash_on_delivery`: Післяплата
- `bank_transfer`: Банківський переказ
- `cash`: Готівка

Seeded delivery methods:

- `nova_poshta`: Нова пошта
- `pickup`: Самовивіз
- `courier`: Кур’єрська доставка

The storefront checkout shows only active methods. Orders store both the method id and a method-name snapshot, so historical orders remain readable even if a method is renamed or disabled later. The legacy `orders.payment_method` and `orders.delivery_method` fields remain as compatibility/code fields during this transition.

## Order Events

Order lifecycle history lives in `order_status_histories`.

Each event stores:

- `order_id`
- `type`: `status`, `payment_status`, `delivery_status`, `note`, or `system`
- `from_value`
- `to_value`
- `comment`
- `created_by`

Checkout creates a system event with `Замовлення створено`. Service-driven status changes create history rows with the acting user id when the action came from the admin panel. History is append-only in the normal application flow and is not edited through the order UI.

## Order Notifications

Order notification templates live in `notification_templates`, and delivery attempts live in `notification_outbox`.

Detailed operator notes live in [`docs/order-notifications.md`](order-notifications.md).

Supported first-phase channels:

- `email`
- `admin_panel`
- `log`

Supported first-phase customer email events:

- `order_created`
- `order_confirmed`
- `order_processing`
- `order_ready_to_ship`
- `order_shipped`
- `order_completed`
- `order_cancelled`
- `payment_paid`

Checkout and `OrderLifecycleService` trigger notification creation only after the order transaction or lifecycle transaction succeeds. Notification failures are stored in outbox as `failed` or `skipped` and do not roll back checkout, status transitions, history, or stock compensation.

The outbox is an audit trail. Sent records are not mutated into repeat sends; a manual resend creates a new attempt. `NotificationOutboxResource` is read-only except for the explicit resend action on safe statuses.

## Cancellation And Stock Compensation

Checkout creates a `stock_movements.type = sale` movement and decreases the selected fulfillment warehouse balance.

First-phase cancellation rules:

- orders that are not shipped or completed can be cancelled by a manager
- cancellation requires a reason/comment
- cancellation restores each order item quantity through `StockService`
- the compensation movement uses `stock_movements.type = return`
- the movement note is `Order cancelled: {order_number}`
- the original `sale` movement is kept as audit history
- `status`, `payment_status`, and `delivery_status` are moved to `cancelled`
- the cancel reason and status history event are stored on the order
- repeated cancel is blocked by the terminal `cancelled` status, so stock is not returned twice

Orders that are already `shipped` or `completed` are not automatically cancelled in this phase. Post-shipment returns require a future explicit return workflow so stock, refunds, and customer communication can be handled deliberately.

`commerce:health-check` includes read-only lifecycle checks for unknown statuses, missing method snapshots, missing active methods, and missing lifecycle timestamps. It reports issues but never changes order data.

## Checkout Transaction Guarantees

Public order creation is centralized in `App\Services\Commerce\CheckoutService`.

Inside one database transaction, checkout:

- reloads cart products
- validates that the selected currency is active
- validates that every product has an active price in the selected order currency
- resolves one fulfillment warehouse per order item
- creates the customer/order/order items
- links the order to customer master data when possible
- stores customer name, phone, email, city, and address snapshots
- stores order currency snapshots
- stores payment and delivery method snapshots
- stores order item price, total, product name, SKU, and warehouse snapshots
- creates an order system event
- calls `StockService` to update `stock_balances`
- creates `stock_movements.type = sale`

If any product has no valid price, no valid active warehouse, insufficient available quantity, inactive currency, or a stock movement failure, the transaction is rolled back and no partial order remains.

## Lifecycle Phase Limits

This phase intentionally does not include:

- online payment gateways
- LiqPay, WayForPay, Stripe, or card acquiring
- Nova Poshta API integration
- SMS, Viber, Telegram, WhatsApp, CRM, or Nova Poshta notification integrations
- automatic post-shipment returns
- accounting postings
- CRM or ERP integration
- fraud/risk scoring

## Storefront Backward Compatibility

The storefront now resolves public pricing and availability through commerce services backed by `product_prices` and `stock_balances`.

`products.price`, `products.old_price`, and `products.stock` are still maintained as compatibility/cache fields for older admin flows, sorting, imports, and legacy assumptions. They should not be treated as the public checkout source of truth.

The migration backfills existing products into:

- `product_prices` for the default currency
- `stock_balances` for the default warehouse

Existing orders are backfilled with default currency and warehouse snapshots. Existing order items get `unit_price = price` and the default warehouse.

## First Phase Limits

Not implemented in this phase:

- purchasing documents
- suppliers
- full inventory count documents
- automatic currency rates
- automatic storefront currency conversion
- exchange-rate API integrations
- split fulfillment between several warehouses for one order item
- cart-level stock reservation
- advanced transfer documents with approval workflows

The goal is safe store-level price and stock operations without overloading the base shop workflow.

## Release Checklist

Before deploy:

- run `php artisan commerce:health-check`
- fix any critical issue reported by the command
- run `php artisan config:clear`
- run `php artisan route:clear`
- run `php artisan view:clear`
- run `php artisan test`
- run `git diff --check`
- run `npm run build` when the storefront build is part of the release

The health-check is read-only. It does not auto-fix data, create records, or mutate stock.

## Commerce Health Check

`php artisan commerce:health-check` validates the commerce data layer and release readiness.

It checks:

- one commerce settings record and deterministic current selection
- default currency and default warehouse presence
- default currency and default warehouse activeness
- exactly one base currency
- exactly one default warehouse
- products without a default currency price
- products without a default warehouse stock balance
- product prices that point to missing or inactive currencies
- stock balances that point to missing or inactive warehouses
- stock balances where `quantity < reserved_quantity`
- stock balances with negative available quantity
- orders without `currency_id` or `currency_code`
- order items without `unit_price` or `warehouse_id`
- stock movements without `product_id` or `warehouse_id`
- the latest stock movement per product and warehouse, compared against the current balance when that check is safe
- missing base order notification templates
- broken notification outbox records
- pending or failed notification warnings
- customer duplicate/contact warnings
- broken customer/order/address references

Use `--json` for machine-readable output in release tooling.

## Acceptance Scenarios

Simple mode:

- `multi_currency_enabled = false`
- `multi_warehouse_enabled = false`
- no storefront currency switcher
- default product price is shown
- default availability is shown
- cart add works
- checkout creates an order with default currency and default warehouse
- stock balance decreases
- sale stock movement is created
- order snapshots stay stable after later price changes

Multi-currency mode:

- `multi_currency_enabled = true`
- currency switcher is visible when more than one active currency exists
- currency selection is stored in session
- selected-currency price is used when available
- missing selected-currency price is not auto-converted
- checkout never creates a mixed-currency order
- inactive selected currency falls back to the default currency in the current implementation

Multi-warehouse mode:

- `multi_warehouse_enabled = true`
- buyers do not see warehouse names or warehouse selection
- storefront shows aggregate availability
- checkout prefers the default warehouse when it can fulfill the line
- checkout falls back to another active warehouse when the default warehouse is short
- checkout blocks when no single active warehouse can fulfill the line
- split fulfillment does not run

Cart edge cases:

- quantity less than or equal to zero is rejected
- quantity above available stock is capped or blocked by the cart logic
- deleted products are removed from the cart
- inactive products are removed from the cart
- missing prices block checkout
- inactive currencies do not stay selected in checkout
- inactive warehouses block fulfillment
- stock changes between cart and checkout are revalidated
- checkout tokens prevent duplicate submit on the same cart session

Admin acceptance scenarios:

- product form hides price and stock repeaters in simple mode
- product form shows currency prices and warehouse balances in multi mode
- manual stock adjustment creates movements only when quantity changes
- warehouse transfer is hidden when multi-warehouse mode is off
- warehouse transfer works only between different warehouses
- stock movement resource is read-only
- order edit route is read-only and shows snapshots
- currency deletion is blocked while the currency is still referenced
- warehouse deletion is blocked while the warehouse is still referenced

## Known Limitations

- no automatic currency rates
- no split fulfillment
- buyers do not see warehouses
- no full ERP-style inventory module
- no supplier or purchase flow
- no auto-fix in `commerce:health-check`
