# Order Lifecycle

Order Lifecycle is the first internal workflow layer for ALTA_WEB_PLATFORM orders. It is intentionally local-first: no payment gateway, Nova Poshta API, CRM/ERP, or automatic refund flow is connected in this phase.

## Statuses

Order statuses:

- `new`: order was created by checkout
- `confirmed`: manager confirmed the order
- `processing`: order is being prepared
- `ready_to_ship`: order is packed and ready for dispatch
- `shipped`: order was sent
- `completed`: order is closed
- `cancelled`: order is cancelled
- `failed`: problem order
- `returned`: reserved for future return workflow

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

Status labels and colors are centralized in enum classes. UI code must use the enum/helper methods and tolerate unknown legacy string values.

## Methods And Snapshots

Payment methods live in `payment_methods`:

- `cash_on_delivery`: Післяплата
- `bank_transfer`: Банківський переказ
- `cash`: Готівка

Delivery methods live in `delivery_methods`:

- `nova_poshta`: Нова пошта
- `pickup`: Самовивіз
- `courier`: Кур’єрська доставка

Checkout shows only active methods and rejects inactive methods even if a request is manually changed. Orders store both method ids and method-name snapshots:

- `payment_method_id`
- `payment_method_name`
- `delivery_method_id`
- `delivery_method_name`

The snapshot name is the historical display value for the order. Renaming a method later must not rewrite older orders.

Customer data follows the same snapshot rule. `orders.customer_id` links to customer master data, while `customer_name`, `phone`, `email`, `city`, and `address` remain the order-specific historical values. Editing a customer must not rewrite old orders.

## Checkout Defaults

Checkout creates:

- `status = new`
- `delivery_status = pending`
- `payment_status = unpaid` for cash and cash on delivery
- `payment_status = pending` for bank transfer
- `order_status_histories.type = system` with `Замовлення створено`
- `stock_movements.type = sale`

Cash on delivery is not marked as `paid` automatically. In this phase, `paid` is a manual admin action.

## Allowed Transitions

Standard order path:

- `new -> confirmed`
- `confirmed -> processing`
- `processing -> ready_to_ship`
- `ready_to_ship -> shipped`
- `shipped -> completed`

Delivery path:

- `pending -> preparing`
- `preparing -> ready_to_ship`
- `ready_to_ship -> shipped`
- `shipped -> delivered`

Payment can be moved to `paid` manually while the order is not terminal. Payment refunds are represented as a status value but do not perform real refund operations in this phase.

All normal lifecycle changes must go through `App\Services\Commerce\OrderLifecycleService`.

## History

Order history lives in `order_status_histories`.

History rows record:

- type: `status`, `payment_status`, `delivery_status`, `note`, or `system`
- previous value
- new value
- comment
- acting user
- timestamp

History is read-only in normal UI. It is an audit trail, not an editable working table.

## Notifications

Order notifications are handled by `App\Services\Commerce\OrderNotificationService` and documented in [`docs/order-notifications.md`](order-notifications.md).

Lifecycle actions create notification outbox records only after the status transaction succeeds:

- checkout: `order_created`
- confirm: `order_confirmed`
- processing: `order_processing`
- ready to ship: `order_ready_to_ship`
- shipped: `order_shipped`
- completed: `order_completed`
- paid: `payment_paid`
- cancelled: `order_cancelled`

Notification failure never rolls back the checkout or lifecycle status change. The outbox row is marked `failed` or `skipped`, and the operator can resend it manually. Repeated cancel remains blocked by the terminal `cancelled` status, so it does not duplicate either stock compensation or the cancel notification.

## Cancellation

Before shipment, cancellation:

- requires a reason
- sets `status = cancelled`
- sets `payment_status = cancelled`
- sets `delivery_status = cancelled`
- stores `cancelled_at`
- stores `cancel_reason`
- writes status history
- restores stock through `StockService`
- creates a compensating `stock_movements.type = return`
- keeps the original `sale` movement unchanged

The return movement note is `Order cancelled: {order_number}`.

Repeated cancel is blocked by the terminal `cancelled` status, so stock is not returned twice.

Orders that are already `shipped` or `completed` are not automatically cancelled with stock restore. Post-shipment returns need a future explicit return workflow.

## Health Check Coverage

`php artisan commerce:health-check` is read-only and reports lifecycle risks:

- missing or unknown order status
- missing or unknown payment status
- missing or unknown delivery status
- missing method snapshots when method ids are set
- orders without customer links as warnings
- linked orders missing customer snapshot fields as warnings
- zero active payment methods
- zero active delivery methods
- cancelled orders without `cancelled_at`
- paid orders without `paid_at`
- shipped orders without `shipped_at`
- completed orders without `completed_at`

The command reports issues only. It does not auto-fix data.

## Known Limits

Not implemented in this phase:

- online payments
- LiqPay, WayForPay, Stripe, or acquiring integrations
- Nova Poshta API
- automatic refund flow
- returns after shipped/completed
- customer account order tracking
- SMS, Viber, Telegram, WhatsApp, CRM, or Nova Poshta notification integrations
- CRM/ERP integration
- accounting postings
