# Order Notifications

Order Notifications Core is the first internal notification layer for ALTA_WEB_PLATFORM orders. It adds templates, a notification outbox, safe rendering, manual resend, and controlled lifecycle triggers.

This phase does not add SMS, Viber, Telegram, WhatsApp, CRM webhooks, Nova Poshta tracking notifications, marketing campaigns, online payment receipts, or queue worker deployment automation.

## Events

Supported order notification events are centralized in `App\Enums\OrderNotificationEvent`:

- `order_created`
- `order_confirmed`
- `order_processing`
- `order_ready_to_ship`
- `order_shipped`
- `order_completed`
- `order_cancelled`
- `payment_paid`
- `payment_failed`
- `delivery_failed`

Each event defines a Ukrainian label, default subject, default enabled flag, and recommended channel. The first seeded customer-facing templates cover:

- `order_created`
- `order_confirmed`
- `order_processing`
- `order_ready_to_ship`
- `order_shipped`
- `order_completed`
- `order_cancelled`
- `payment_paid`

`payment_failed` and `delivery_failed` are reserved for future explicit flows and are disabled by default.

## Channels

Supported channels are centralized in `App\Enums\NotificationChannel`:

- `email`: customer-facing email
- `admin_panel`: internal admin-panel outbox entry
- `log`: technical fallback

Only email templates are seeded in this phase. The service can send `admin_panel` and `log` outbox records, but no SMS/Viber/Telegram/CRM provider is connected.

## Templates

Templates live in `notification_templates`.

Important fields:

- `code`: unique template code, using `{event}.{channel}`, for example `order_created.email`
- `event`
- `channel`
- `name`
- `subject`
- `body`
- `is_active`
- `is_system`
- `sort_order`

System templates cannot be deleted through the model. They can be edited or deactivated from the admin UI.

Repeated seeding creates missing base templates but preserves editable fields on existing default templates, including `subject`, `body`, and `is_active`.

Supported variables:

- `{{ order.number }}`
- `{{ order.total }}`
- `{{ order.currency }}`
- `{{ order.status }}`
- `{{ order.payment_status }}`
- `{{ order.delivery_status }}`
- `{{ order.customer_name }}`
- `{{ order.customer_phone }}`
- `{{ order.customer_email }}`
- `{{ order.payment_method }}`
- `{{ order.delivery_method }}`
- `{{ order.created_at }}`
- `{{ cancel_reason }}`

The renderer is deliberately simple. It does not execute PHP or Blade inside template content. Known variables are replaced from a prepared payload. Unknown variables are left as-is, so a typo does not create a fatal notification failure.

Rendered email body content is escaped in the mail view before line breaks are converted to HTML.

## Outbox

Notification attempts live in `notification_outbox`.

Statuses are centralized in `App\Enums\NotificationStatus`:

- `pending`
- `sent`
- `failed`
- `skipped`
- `cancelled`

Every created notification attempt is recorded. If the customer email is missing, the email notification becomes `skipped` with a clear reason. If mail sending throws, the outbox row becomes `failed` with a safe error message.

Sent rows are not sent again in place. Manual resend creates a new outbox attempt, preserving the audit trail.

## Service

`App\Services\Commerce\OrderNotificationService` owns notification behavior:

- `queueOrderNotification(Order $order, OrderNotificationEvent $event, ?User $user = null, array $extraPayload = [])`
- `sendOutbox(NotificationOutbox $notification)`
- `resend(NotificationOutbox $notification, ?User $user = null)`
- `renderTemplate(NotificationTemplate $template, Order $order, array $extraPayload = [])`

`queueOrderNotification` is best-effort. If notification setup fails before an outbox row can be written, the error is logged and the checkout/lifecycle action is not rolled back.

The service has a duplicate guard by `order_id + event + channel`. Repeated lifecycle calls do not create duplicate notifications. Manual resend bypasses that guard by creating a new attempt from an existing outbox row.

## Lifecycle Triggers

Checkout creates `order_created` after the order transaction commits successfully.

`OrderLifecycleService` creates notifications after successful lifecycle transitions:

- `confirm`: `order_confirmed`
- `markProcessing`: `order_processing`
- `markReadyToShip`: `order_ready_to_ship`
- `markShipped`: `order_shipped`
- `markCompleted`: `order_completed`
- `markPaid`: `payment_paid`
- `cancel`: `order_cancelled` with `cancel_reason`

Invalid transitions do not create notifications. Repeated cancel is blocked before any second stock return or second cancel notification can be created.

## Email Behavior

Email uses Laravel Mail with `OrderNotificationMail`. SMTP credentials are not stored in code and `.env` is not modified by this feature.

If the project uses the default local `log` mailer, mail is treated as sent by Laravel and written to logs. If a real SMTP mailer is configured and sending fails, the outbox row is marked `failed`; the order status change remains committed.

### SMTP Setup

Production email delivery should be configured through environment variables only:

- `MAIL_MAILER=smtp`
- `MAIL_HOST`
- `MAIL_PORT`
- `MAIL_USERNAME`
- `MAIL_PASSWORD`
- `MAIL_ENCRYPTION`
- `MAIL_FROM_ADDRESS`
- `MAIL_FROM_NAME="${APP_NAME}"`

Do not commit SMTP usernames, passwords, tokens, or `.env`. Local and staging environments may keep using `log`, `array`, or Mailpit-style SMTP settings when real delivery is not needed.

`config/mail.php` supports both Laravel's `MAIL_SCHEME` key and the operator-facing `MAIL_ENCRYPTION` key for SMTP encryption.

### Test Email Command

Use this command to verify Laravel Mail transport without creating order data or notification outbox rows:

```bash
php artisan notifications:test-email test@example.com
```

The command validates the email address, prints the current mailer, sends a simple technical email, returns exit code `0` on success, and returns exit code `1` on failure. It redacts configured SMTP username/password values from failure output.

### Send Pending Command

Pending outbox rows can be processed manually or by a future cron using:

```bash
php artisan notifications:send-pending
```

Options:

- `--limit=50`
- `--dry-run`
- `--order-id=`
- `--event=`
- `--channel=email`

The command only selects `pending` rows. It does not touch `sent` history, order statuses, stock balances, or stock movements. It continues processing if one notification fails and prints a summary with `processed`, `sent`, `failed`, and `skipped`.

`--dry-run` lists matching rows without sending mail or mutating the database.

### Retry Policy

- `pending` rows are processed by `notifications:send-pending`.
- `failed` and `skipped` rows can be resent from the admin UI.
- Resend creates a new outbox attempt.
- `sent` rows are immutable for automatic processing.
- Retry/resend does not create lifecycle duplicates, change order status, or create stock movements.

## Admin UI

Filament resources:

- `NotificationTemplateResource`: list/view/edit templates, filter by event/channel/activity, edit subject/body/is_active, show available variables, and warn that PHP/Blade/eval are not executed.
- `NotificationOutboxResource`: read-only list/view of notification attempts, shows channel/status/current mailer/error message, filters by order/event/channel/status/date, resend action for `pending`, `failed`, and `skipped`.

`OrderResource` view includes a read-only `Повідомлення` section with outbox rows for the order. It also exposes a resend action for resendable notifications on that order.

## Health Check

`php artisan commerce:health-check` is read-only and includes notification checks:

- missing base email templates: critical
- duplicate `notification_templates.code`: critical
- active templates with empty body: critical
- templates with unknown event/channel: critical
- outbox records without event/channel/status: critical
- outbox records with unknown event/channel/status: critical
- pending notifications older than 24 hours: warning
- failed notifications: warning
- active email templates without subject: warning
- missing production SMTP configuration: critical
- incomplete local SMTP configuration when `MAIL_MAILER=smtp`: warning
- invalid `MAIL_FROM_ADDRESS`: warning or critical depending on environment

Warnings do not fail the command. Critical issues still make the command exit non-zero.

## Security Notes

- SMTP credentials live in environment variables, not in Git.
- `.env` must not be committed.
- Template content is rendered through a simple variable replacer, not PHP, Blade, or `eval`.
- Rendered email body content is escaped by the mail view.
- Transport failures are written to outbox as redacted `error_message` text where configured SMTP username/password values are known.
