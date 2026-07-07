<?php

namespace App\Services\Commerce;

use App\Enums\DeliveryStatus;
use App\Enums\OrderNotificationEvent;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrderLifecycleService
{
    /**
     * @var array<string, array<int, string>>
     */
    private const ORDER_TRANSITIONS = [
        'new' => ['confirmed', 'processing', 'cancelled'],
        'confirmed' => ['processing', 'ready_to_ship', 'cancelled'],
        'processing' => ['ready_to_ship', 'cancelled'],
        'ready_to_ship' => ['shipped', 'cancelled'],
        'shipped' => ['completed'],
        'completed' => [],
        'cancelled' => [],
        'failed' => ['cancelled'],
        'returned' => [],
        'awaiting_payment' => ['confirmed', 'processing', 'cancelled'],
    ];

    /**
     * @var array<string, array<int, string>>
     */
    private const DELIVERY_TRANSITIONS = [
        'not_required' => [],
        'pending' => ['preparing', 'ready_to_ship', 'cancelled'],
        'preparing' => ['ready_to_ship', 'cancelled'],
        'ready_to_ship' => ['shipped', 'cancelled'],
        'shipped' => ['delivered', 'returned'],
        'delivered' => ['returned'],
        'failed' => ['returned', 'cancelled'],
        'returned' => [],
        'cancelled' => [],
    ];

    public function __construct(
        private readonly StockService $stockService,
        private readonly OrderNotificationService $notificationService,
    ) {}

    public function confirm(Order $order, ?User $user = null, ?string $comment = null): Order
    {
        $order = $this->changeOrderStatus($order, OrderStatus::Confirmed, $user, $comment);
        $this->notificationService->queueOrderNotification($order, OrderNotificationEvent::OrderConfirmed, $user);

        return $order;
    }

    public function markProcessing(Order $order, ?User $user = null, ?string $comment = null): Order
    {
        $order = DB::transaction(function () use ($order, $user, $comment): Order {
            $this->changeOrderStatus($order, OrderStatus::Processing, $user, $comment);
            $this->changeDeliveryStatus($order, DeliveryStatus::Preparing, $user, $comment);

            return $order->refresh();
        });
        $this->notificationService->queueOrderNotification($order, OrderNotificationEvent::OrderProcessing, $user);

        return $order;
    }

    public function markReadyToShip(Order $order, ?User $user = null, ?string $comment = null): Order
    {
        $order = DB::transaction(function () use ($order, $user, $comment): Order {
            $this->changeOrderStatus($order, OrderStatus::ReadyToShip, $user, $comment);
            $this->changeDeliveryStatus($order, DeliveryStatus::ReadyToShip, $user, $comment);

            return $order->refresh();
        });
        $this->notificationService->queueOrderNotification($order, OrderNotificationEvent::OrderReadyToShip, $user);

        return $order;
    }

    public function markShipped(Order $order, ?User $user = null, ?string $comment = null): Order
    {
        $order = DB::transaction(function () use ($order, $user, $comment): Order {
            $this->changeOrderStatus($order, OrderStatus::Shipped, $user, $comment);
            $this->changeDeliveryStatus($order, DeliveryStatus::Shipped, $user, $comment);

            return $order->refresh();
        });
        $this->notificationService->queueOrderNotification($order, OrderNotificationEvent::OrderShipped, $user);

        return $order;
    }

    public function markCompleted(Order $order, ?User $user = null, ?string $comment = null): Order
    {
        $order = $this->changeOrderStatus($order, OrderStatus::Completed, $user, $comment);
        $this->notificationService->queueOrderNotification($order, OrderNotificationEvent::OrderCompleted, $user);

        return $order;
    }

    public function markPaid(Order $order, ?User $user = null, ?string $comment = null): Order
    {
        $order = $this->changePaymentStatus($order, PaymentStatus::Paid, $user, $comment);
        $this->notificationService->queueOrderNotification($order, OrderNotificationEvent::PaymentPaid, $user);

        return $order;
    }

    public function cancel(Order $order, ?User $user = null, ?string $reason = null): Order
    {
        $reason = trim((string) $reason);

        if ($reason === '') {
            throw new RuntimeException('Вкажіть причину скасування замовлення.');
        }

        $order = DB::transaction(function () use ($order, $user, $reason): Order {
            $order->refresh()->loadMissing(['items.product']);
            $this->assertOrderCanBeChanged($order);

            $status = OrderStatus::tryFrom((string) $order->status);
            $deliveryStatus = DeliveryStatus::tryFrom((string) $order->delivery_status);

            if (in_array($status, [OrderStatus::Shipped, OrderStatus::Completed], true)
                || in_array($deliveryStatus, [DeliveryStatus::Shipped, DeliveryStatus::Delivered], true)) {
                throw new RuntimeException('Відправлене або завершене замовлення не скасовується автоматично в цій фазі.');
            }

            foreach ($order->items as $item) {
                if (! $item->product || ! $item->warehouse_id || (int) $item->quantity <= 0) {
                    continue;
                }

                $this->stockService->applyDelta(
                    subject: $item->variant ?? $item->product,
                    warehouseId: (int) $item->warehouse_id,
                    delta: (float) $item->quantity,
                    type: StockMovement::TYPE_RETURN,
                    note: 'Order cancelled: '.$order->number,
                    createdBy: $user?->id,
                    related: $order,
                );
            }

            if ($order->payment_status !== PaymentStatus::Cancelled->value) {
                $this->changePaymentStatus($order, PaymentStatus::Cancelled, $user, $reason);
            }

            if ($order->delivery_status !== DeliveryStatus::Cancelled->value) {
                $this->changeDeliveryStatus($order, DeliveryStatus::Cancelled, $user, $reason);
            }

            $this->changeOrderStatus($order, OrderStatus::Cancelled, $user, $reason, [
                'cancel_reason' => $reason,
            ]);

            return $order->refresh();
        });
        $this->notificationService->queueOrderNotification($order, OrderNotificationEvent::OrderCancelled, $user, [
            'cancel_reason' => $reason,
        ]);

        return $order;
    }

    public function changeOrderStatus(
        Order $order,
        OrderStatus $status,
        ?User $user = null,
        ?string $comment = null,
        array $extra = [],
    ): Order {
        return DB::transaction(function () use ($order, $status, $user, $comment, $extra): Order {
            $order->refresh();
            $this->assertOrderTransitionAllowed($order, $status);

            $this->persistStatusChange(
                order: $order,
                column: 'status',
                type: OrderStatusHistory::TYPE_STATUS,
                toValue: $status->value,
                user: $user,
                comment: $comment,
                extra: array_merge($this->timestampForOrderStatus($status), $extra),
            );

            return $order->refresh();
        });
    }

    public function changePaymentStatus(Order $order, PaymentStatus $status, ?User $user = null, ?string $comment = null): Order
    {
        return DB::transaction(function () use ($order, $status, $user, $comment): Order {
            $order->refresh();
            $this->assertOrderCanBeChanged($order);

            $this->persistStatusChange(
                order: $order,
                column: 'payment_status',
                type: OrderStatusHistory::TYPE_PAYMENT_STATUS,
                toValue: $status->value,
                user: $user,
                comment: $comment,
                extra: $status === PaymentStatus::Paid ? ['paid_at' => $order->paid_at ?? now()] : [],
            );

            return $order->refresh();
        });
    }

    public function changeDeliveryStatus(Order $order, DeliveryStatus $status, ?User $user = null, ?string $comment = null): Order
    {
        return DB::transaction(function () use ($order, $status, $user, $comment): Order {
            $order->refresh();
            $this->assertDeliveryTransitionAllowed($order, $status);

            $this->persistStatusChange(
                order: $order,
                column: 'delivery_status',
                type: OrderStatusHistory::TYPE_DELIVERY_STATUS,
                toValue: $status->value,
                user: $user,
                comment: $comment,
                extra: $status === DeliveryStatus::Shipped ? ['shipped_at' => $order->shipped_at ?? now()] : [],
            );

            return $order->refresh();
        });
    }

    public function recordSystemEvent(Order $order, string $comment, ?User $user = null): OrderStatusHistory
    {
        return $this->recordEvent(
            order: $order,
            type: OrderStatusHistory::TYPE_SYSTEM,
            fromValue: null,
            toValue: null,
            comment: $comment,
            user: $user,
        );
    }

    public function canTransitionTo(Order $order, OrderStatus $status): bool
    {
        $current = OrderStatus::tryFrom((string) $order->status);

        if (! $current || $current === $status || $current->isTerminal()) {
            return false;
        }

        return in_array($status->value, self::ORDER_TRANSITIONS[$current->value] ?? [], true);
    }

    public function canMarkPaid(Order $order): bool
    {
        $current = OrderStatus::tryFrom((string) $order->status);

        if (! $current || $current->isTerminal()) {
            return false;
        }

        return ! in_array($order->payment_status, [PaymentStatus::Paid->value, PaymentStatus::Refunded->value, PaymentStatus::Cancelled->value], true);
    }

    public function canCancel(Order $order): bool
    {
        $status = OrderStatus::tryFrom((string) $order->status);
        $deliveryStatus = DeliveryStatus::tryFrom((string) $order->delivery_status);

        if (! $status || $status->isTerminal()) {
            return false;
        }

        return ! in_array($status, [OrderStatus::Shipped, OrderStatus::Completed], true)
            && ! in_array($deliveryStatus, [DeliveryStatus::Shipped, DeliveryStatus::Delivered], true);
    }

    private function assertOrderTransitionAllowed(Order $order, OrderStatus $toStatus): void
    {
        $current = OrderStatus::tryFrom((string) $order->status);

        if (! $current) {
            throw new RuntimeException('Поточний статус замовлення невідомий: '.$order->status);
        }

        if ($current === $toStatus) {
            return;
        }

        if ($current->isTerminal()) {
            throw new RuntimeException('Завершене або скасоване замовлення не змінюється звичайною дією.');
        }

        if (! in_array($toStatus->value, self::ORDER_TRANSITIONS[$current->value] ?? [], true)) {
            throw new RuntimeException('Перехід зі статусу "'.$current->label().'" у "'.$toStatus->label().'" не дозволений.');
        }
    }

    private function assertDeliveryTransitionAllowed(Order $order, DeliveryStatus $toStatus): void
    {
        $this->assertOrderCanBeChanged($order);

        $current = DeliveryStatus::tryFrom((string) $order->delivery_status);

        if (! $current) {
            throw new RuntimeException('Поточний статус доставки невідомий: '.$order->delivery_status);
        }

        if ($current === $toStatus) {
            return;
        }

        if (! in_array($toStatus->value, self::DELIVERY_TRANSITIONS[$current->value] ?? [], true)) {
            throw new RuntimeException('Перехід доставки зі статусу "'.$current->label().'" у "'.$toStatus->label().'" не дозволений.');
        }
    }

    private function assertOrderCanBeChanged(Order $order): void
    {
        $current = OrderStatus::tryFrom((string) $order->status);

        if (! $current) {
            throw new RuntimeException('Поточний статус замовлення невідомий: '.$order->status);
        }

        if ($current->isTerminal()) {
            throw new RuntimeException('Завершене або скасоване замовлення не змінюється звичайною дією.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function timestampForOrderStatus(OrderStatus $status): array
    {
        return match ($status) {
            OrderStatus::Confirmed => ['confirmed_at' => now()],
            OrderStatus::Shipped => ['shipped_at' => now()],
            OrderStatus::Completed => ['completed_at' => now()],
            OrderStatus::Cancelled => ['cancelled_at' => now()],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function persistStatusChange(
        Order $order,
        string $column,
        string $type,
        string $toValue,
        ?User $user,
        ?string $comment,
        array $extra = [],
    ): void {
        $fromValue = $order->{$column};

        if ($fromValue === $toValue && $extra === []) {
            return;
        }

        $payload = array_merge([$column => $toValue], $extra);
        $order->forceFill($payload)->save();

        if ($fromValue === $toValue) {
            return;
        }

        $this->recordEvent(
            order: $order,
            type: $type,
            fromValue: $fromValue,
            toValue: $toValue,
            comment: $comment,
            user: $user,
        );
    }

    private function recordEvent(
        Order $order,
        string $type,
        ?string $fromValue,
        ?string $toValue,
        ?string $comment,
        ?User $user,
    ): OrderStatusHistory {
        return $order->statusHistories()->create([
            'type' => $type,
            'from_value' => $fromValue,
            'to_value' => $toValue,
            'comment' => $comment,
            'created_by' => $user?->id,
        ]);
    }
}
