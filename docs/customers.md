# Customer Core

Customer Core separates storefront buyers from internal admin users.

## User vs Customer

- `User` is an internal system actor: admin, manager, content manager, or operator.
- `Customer` is a buyer master-data record for storefront orders.
- A customer is not automatically an auth user.
- Public customer login, registration, password reset, and account pages are not implemented in this phase.

## Master Data

Customers live in `customers`.

Core fields:

- `type`: `individual` or `company`
- personal/company name fields
- `phone`, `email`
- `normalized_phone`, `normalized_email`
- `tax_id`, `edrpou`
- `notes`
- `is_active`
- `marketing_consent`

Phone and email are nullable so incomplete historical orders can still be represented. The normalized fields are used for matching and duplicate diagnostics. There is no database unique constraint on normalized contact fields in this phase.

## Addresses

Customer addresses live in `customer_addresses`.

Address types:

- `delivery`
- `billing`
- `pickup`
- `other`

A customer can have many addresses. A default address is managed per customer/type at the model layer. Address changes never update old order snapshots.

## Order Snapshots

Orders keep historical customer data:

- `customer_name`
- `phone`
- `email`
- `city`
- `address`
- payment method snapshot
- delivery method snapshot
- currency/price snapshots

`orders.customer_id` is only a live master-data link. If a customer changes name, phone, email, or address later, the order view must still show the original order snapshot.

## Checkout Resolving

`App\Services\Commerce\CustomerService` resolves buyers during checkout.

Rules:

- normalize phone and email first
- primary match by `normalized_phone`
- secondary match by `normalized_email`
- create a customer when no safe match exists
- do not overwrite good customer data with empty or shorter checkout data
- when phone and email point to different customers, prefer the phone match and do not copy the conflicting email
- do not auto-merge customers

Checkout remains the same for the buyer: name, phone, email, city, address, delivery method, payment method. No `customer_id`, login, or password is shown publicly.

## Backfill

Existing orders can be linked with:

```bash
php artisan customers:backfill-from-orders --dry-run
php artisan customers:backfill-from-orders --limit=500
```

The command scans orders without `customer_id`, creates or links customers from order snapshots, and prints:

- `scanned`
- `created_customers`
- `linked_orders`
- `skipped`
- `potential_duplicates`

`--dry-run` does not mutate the database. Heavy backfill is intentionally not run inside a migration.

## Admin UI

`CustomerResource` shows customer master data, addresses, order count, total spent, last order date, and potential duplicates. Customer addresses are edited from the customer view. Customer orders are shown as related read-only operational history.

Editing a customer does not rewrite order snapshots.

## Health Check

`commerce:health-check` is read-only and reports:

- orders without `customer_id` as warnings
- customers without phone and email as warnings
- duplicate normalized phone/email as warnings
- linked order snapshots whose phone/email point to another customer as potential-duplicate warnings
- invalid customer email as warnings
- broken `orders.customer_id` references as critical
- broken `customer_addresses.customer_id` references as critical
- linked orders missing basic customer snapshot fields as warnings

## Known Limits

Not implemented:

- customer login/account
- registration/password flows
- customer merge
- loyalty or discounts
- marketing campaigns
- GDPR export/delete automation
- CRM/ERP integration
- delivery provider APIs
- Nova Poshta integration
