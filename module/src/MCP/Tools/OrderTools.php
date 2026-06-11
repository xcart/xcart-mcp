<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use XC\MCP\MCP\Security\McpAuthorizer;
use XC\MCP\MCP\Security\ToolAnnotation;
use XC\MCP\MCP\Util\QueryHelper;
use XLite\Model\Order;
use XLite\Model\OrderHistoryEvents;

class OrderTools
{
    /**
     * X-Cart payment status codes (from xc_order_payment_statuses).
     * setPaymentStatus() accepts these codes via findOneByCode().
     */
    private const PAYMENT_STATUSES = [
        'Q'  => 'Awaiting payment',
        'A'  => 'Authorized',
        'PP' => 'Partially Paid',
        'P'  => 'Paid',
        'D'  => 'Declined',
        'C'  => 'Cancelled',
        'R'  => 'Refunded',
    ];

    /**
     * X-Cart shipping status codes (from xc_order_shipping_statuses).
     */
    private const SHIPPING_STATUSES = [
        'N' => 'New',
        'P' => 'Processing',
        'S' => 'Shipped',
        'D' => 'Delivered',
        'R' => 'Returned',
        'WND' => 'Will Not Deliver',
        'WFA' => 'Waiting for approve',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly McpAuthorizer $authorizer,
    ) {}

    #[McpTool(
        name: 'order_update_status',
        title: 'Update Order Status',
        description: 'Update order payment and/or shipping status'
    )]
    #[ToolAnnotation(readOnlyHint: false, idempotentHint: true)]
    public function updateOrderStatus(
        int $orderId,
        ?string $paymentStatus = null,
        ?string $shippingStatus = null,
    ): array {
        $this->authorizer->authorizeTool('order_update_status');

        if ($paymentStatus === null && $shippingStatus === null) {
            throw new ToolCallException('At least one of paymentStatus or shippingStatus must be provided');
        }

        $order = $this->em->getRepository(Order::class)->find($orderId);
        if (!$order) {
            throw new ToolCallException("Order #{$orderId} not found");
        }

        $changes = [];

        if ($paymentStatus !== null) {
            if (!isset(self::PAYMENT_STATUSES[$paymentStatus])) {
                $allowed = array_map(
                    fn($code, $label) => "{$code} ({$label})",
                    array_keys(self::PAYMENT_STATUSES),
                    array_values(self::PAYMENT_STATUSES)
                );
                throw new ToolCallException(
                    "Invalid payment status '{$paymentStatus}'. Allowed: " . implode(', ', $allowed)
                );
            }
            $oldCode = $order->getPaymentStatusCode();
            $order->setPaymentStatus($paymentStatus);
            $changes[] = "Payment: {$oldCode} -> {$paymentStatus}";
        }

        if ($shippingStatus !== null) {
            if (!isset(self::SHIPPING_STATUSES[$shippingStatus])) {
                $allowed = array_map(
                    fn($code, $label) => "{$code} ({$label})",
                    array_keys(self::SHIPPING_STATUSES),
                    array_values(self::SHIPPING_STATUSES)
                );
                throw new ToolCallException(
                    "Invalid shipping status '{$shippingStatus}'. Allowed: " . implode(', ', $allowed)
                );
            }
            $oldCode = $order->getShippingStatusCode();
            $order->setShippingStatus($shippingStatus);
            $changes[] = "Shipping: {$oldCode} -> {$shippingStatus}";
        }

        $this->em->flush();

        return [
            'id' => $order->getId(),
            'order_number' => $order->getOrderNumber(),
            'payment_status' => $order->getPaymentStatusCode(),
            'shipping_status' => $order->getShippingStatusCode(),
            'changes' => $changes,
            'message' => 'Order status updated',
        ];
    }

    #[McpTool(
        name: 'order_add_note',
        title: 'Add Order Note',
        description: 'Add an admin note to an order'
    )]
    #[ToolAnnotation(readOnlyHint: false)]
    public function addOrderNote(
        int $orderId,
        string $note,
    ): array {
        $this->authorizer->authorizeTool('order_add_note');

        $order = $this->em->getRepository(Order::class)->find($orderId);
        if (!$order) {
            throw new ToolCallException("Order #{$orderId} not found");
        }

        if (trim($note) === '') {
            throw new ToolCallException('Note text cannot be empty');
        }

        $event = new OrderHistoryEvents();
        $event->setOrder($order);
        $event->setComment($note);
        $event->setAuthor('admin');
        $event->setDate(time());

        $this->em->persist($event);
        $this->em->flush();

        return [
            'id' => $order->getId(),
            'order_number' => $order->getOrderNumber(),
            'note' => $note,
            'message' => 'Note added to order',
        ];
    }

    #[McpTool(
        name: 'order_search',
        title: 'Search Orders',
        description: 'Search orders by date range, status, amount, customer email'
    )]
    #[ToolAnnotation(readOnlyHint: true)]
    public function searchOrders(
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $paymentStatus = null,
        ?string $shippingStatus = null,
        ?string $customerEmail = null,
        ?float $totalMin = null,
        ?float $totalMax = null,
        string $sortBy = 'date',
        string $sortOrder = 'desc',
        int $limit = 20,
        int $offset = 0,
    ): array {
        $this->authorizer->authorizeTool('order_search');

        $limit = min($limit, 100);

        $qb = $this->em->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o');

        if ($dateFrom !== null) {
            try {
                $from = new \DateTimeImmutable($dateFrom);
            } catch (\Exception) {
                throw new ToolCallException("Invalid dateFrom format: '{$dateFrom}'. Use ISO 8601 or YYYY-MM-DD.");
            }
            $qb->andWhere('o.date >= :dateFrom')
                ->setParameter('dateFrom', $from->getTimestamp());
        }

        if ($dateTo !== null) {
            try {
                $to = new \DateTimeImmutable($dateTo);
            } catch (\Exception) {
                throw new ToolCallException("Invalid dateTo format: '{$dateTo}'. Use ISO 8601 or YYYY-MM-DD.");
            }
            $qb->andWhere('o.date <= :dateTo')
                ->setParameter('dateTo', $to->getTimestamp());
        }

        if ($paymentStatus !== null) {
            if (!isset(self::PAYMENT_STATUSES[$paymentStatus])) {
                $allowed = array_map(
                    fn($code, $label) => "{$code} ({$label})",
                    array_keys(self::PAYMENT_STATUSES),
                    array_values(self::PAYMENT_STATUSES)
                );
                throw new ToolCallException(
                    "Invalid payment status '{$paymentStatus}'. Allowed: " . implode(', ', $allowed)
                );
            }
            $qb->innerJoin('o.paymentStatus', 'ps')
                ->andWhere('ps.code = :paymentStatus')
                ->setParameter('paymentStatus', $paymentStatus);
        }

        if ($shippingStatus !== null) {
            if (!isset(self::SHIPPING_STATUSES[$shippingStatus])) {
                $allowed = array_map(
                    fn($code, $label) => "{$code} ({$label})",
                    array_keys(self::SHIPPING_STATUSES),
                    array_values(self::SHIPPING_STATUSES)
                );
                throw new ToolCallException(
                    "Invalid shipping status '{$shippingStatus}'. Allowed: " . implode(', ', $allowed)
                );
            }
            $qb->innerJoin('o.shippingStatus', 'ss')
                ->andWhere('ss.code = :shippingStatus')
                ->setParameter('shippingStatus', $shippingStatus);
        }

        if ($customerEmail !== null) {
            $qb->innerJoin('o.profile', 'pr')
                ->andWhere('pr.login LIKE :email')
                ->setParameter('email', QueryHelper::likeContains($customerEmail));
        }

        if ($totalMin !== null) {
            $qb->andWhere('o.total >= :totalMin')
                ->setParameter('totalMin', $totalMin);
        }

        if ($totalMax !== null) {
            $qb->andWhere('o.total <= :totalMax')
                ->setParameter('totalMax', $totalMax);
        }

        // Count total before pagination
        $countQb = clone $qb;
        $countQb->select('COUNT(o.order_id)');
        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        // Sorting
        $allowedSortFields = [
            'date' => 'o.date',
            'total' => 'o.total',
            'order_number' => 'o.orderNumber',
        ];
        $sortField = $allowedSortFields[$sortBy] ?? 'o.date';
        $sortDir = strtolower($sortOrder) === 'asc' ? 'ASC' : 'DESC';
        $qb->orderBy($sortField, $sortDir);

        $qb->setMaxResults($limit)
            ->setFirstResult($offset);

        $orders = $qb->getQuery()->getResult();

        $items = [];
        foreach ($orders as $order) {
            $profile = $order->getProfile();
            $items[] = [
                'id' => $order->getId(),
                'order_number' => $order->getOrderNumber(),
                'date' => $order->getDate() ? date('c', $order->getDate()) : null,
                'total' => $order->getTotal(),
                'payment_status' => $order->getPaymentStatusCode(),
                'shipping_status' => $order->getShippingStatusCode(),
                'customer_email' => $profile?->getLogin(),
            ];
        }

        return [
            'total_found' => $total,
            'items' => $items,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    #[McpTool(
        name: 'order_get_items',
        title: 'Get Order Items',
        description: 'Get detailed list of items in an order'
    )]
    #[ToolAnnotation(readOnlyHint: true)]
    public function getOrderItems(int $orderId): array
    {
        $this->authorizer->authorizeTool('order_get_items');

        $order = $this->em->getRepository(Order::class)->find($orderId);
        if (!$order) {
            throw new ToolCallException("Order #{$orderId} not found");
        }

        $items = [];
        foreach ($order->getItems() as $item) {
            $items[] = [
                'id' => $item->getId(),
                'product_id' => $item->getObject()?->getId(),
                'name' => $item->getName(),
                'sku' => $item->getSku(),
                'price' => $item->getPrice(),
                'quantity' => $item->getAmount(),
                'total' => $item->getTotal(),
            ];
        }

        return [
            'order_id' => $order->getId(),
            'order_number' => $order->getOrderNumber(),
            'items_count' => count($items),
            'items' => $items,
            'subtotal' => $order->getSubtotal(),
            'total' => $order->getTotal(),
        ];
    }
}
