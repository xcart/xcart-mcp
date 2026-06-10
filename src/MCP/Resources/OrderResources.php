<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Resources;

use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Exception\ToolCallException;
use XLite\Model\Order;

class OrderResources
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    #[McpResource(
        uri: 'xcart://orders/recent',
        name: 'recent_orders',
        title: 'Recent Orders',
        description: 'Last 50 orders: id, date, total, payment status, shipping status, customer email',
        mimeType: 'application/json'
    )]
    public function getRecentOrders(): array
    {
        $qb = $this->em->createQueryBuilder();

        $orders = $qb
            ->select('o')
            ->from(Order::class, 'o')
            ->leftJoin('o.profile', 'p')
            ->orderBy('o.date', 'DESC')
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();

        $items = $this->formatOrderList($orders, detailed: true);

        return [
            'count' => count($items),
            'items' => $items,
        ];
    }

    #[McpResourceTemplate(
        uriTemplate: 'xcart://orders/{orderId}',
        name: 'order_detail',
        title: 'Order Detail',
        description: 'Full order details: items, totals, shipping address, payment info, notes, history',
        mimeType: 'application/json'
    )]
    public function getOrder(int $orderId): array
    {
        $order = $this->em->getRepository(Order::class)->find($orderId);

        if (!$order) {
            throw new ToolCallException("Order #{$orderId} not found");
        }

        $profile = $order->getProfile();

        // Order items
        $items = [];
        foreach ($order->getItems() as $item) {
            $items[] = [
                'id' => $item->getItemId(),
                'product_id' => $item->getObject()?->getProductId(),
                'name' => $item->getName(),
                'sku' => $item->getSku(),
                'price' => (float) $item->getPrice(),
                'quantity' => (int) $item->getAmount(),
                'total' => (float) $item->getTotal(),
            ];
        }

        // Shipping address
        $shippingAddress = null;
        $address = $order->getShippingAddress();
        if ($address) {
            $shippingAddress = [
                'name' => trim(($address->getFirstname() ?? '') . ' ' . ($address->getLastname() ?? '')),
                'street' => $address->getStreet(),
                'city' => $address->getCity(),
                'state' => $address->getState()?->getCode(),
                'zipcode' => $address->getZipcode(),
                'country' => $address->getCountry()?->getCode(),
            ];
        }

        // Surcharges as totals breakdown
        $surcharges = [];
        foreach ($order->getSurcharges() as $surcharge) {
            $surcharges[] = [
                'type' => $surcharge->getType(),
                'name' => $surcharge->getName(),
                'value' => (float) $surcharge->getValue(),
            ];
        }

        // Payment method
        $paymentMethod = null;
        $paymentTransaction = $order->getPaymentTransactions()->first();
        if ($paymentTransaction) {
            $method = $paymentTransaction->getPaymentMethod();
            $paymentMethod = $method ? $method->getName() : null;
        }

        // Shipping method
        $shippingMethod = $order->getShippingMethodName() ?: null;

        // Notes
        $notes = [];
        if (method_exists($order, 'getNotes')) {
            foreach ($order->getNotes() as $note) {
                $notes[] = [
                    'date' => date('c', $note->getDate()),
                    'author' => $note->getAuthorName() ?? 'system',
                    'text' => $note->getNote(),
                ];
            }
        }
        if ($order->getAdminNotes()) {
            $notes[] = [
                'date' => date('c', $order->getDate()),
                'author' => 'admin',
                'text' => $order->getAdminNotes(),
            ];
        }

        // Order history
        $history = [];
        if (method_exists($order, 'getHistoryEvents')) {
            foreach ($order->getHistoryEvents() as $event) {
                $history[] = [
                    'date' => date('c', $event->getDate()),
                    'change' => $event->getDescription(),
                ];
            }
        }

        return [
            'id' => $order->getOrderId(),
            'order_number' => $order->getOrderNumber(),
            'date' => date('c', $order->getDate()),
            'payment_status' => $order->getPaymentStatusCode(),
            'shipping_status' => $order->getShippingStatusCode(),
            'total' => (float) $order->getTotal(),
            'subtotal' => (float) $order->getSubtotal(),
            'currency' => $order->getCurrency()
                ? $order->getCurrency()->getCode()
                : 'USD',
            'customer' => $profile ? [
                'id' => $profile->getProfileId(),
                'email' => $profile->getLogin(),
            ] : null,
            'shipping_address' => $shippingAddress,
            'items' => $items,
            'surcharges' => $surcharges,
            'payment_method' => $paymentMethod,
            'shipping_method' => $shippingMethod,
            'notes' => $notes,
            'history' => $history,
        ];
    }

    #[McpResource(
        uri: 'xcart://orders/stats',
        name: 'order_stats',
        title: 'Order Stats',
        description: 'Order statistics: total count, revenue, average order value, status breakdown, today/week/month totals',
        mimeType: 'application/json'
    )]
    public function getOrderStats(): array
    {
        $qb = $this->em->createQueryBuilder();

        // Overall stats
        $overall = $qb
            ->select(
                'COUNT(o.order_id) AS total_orders',
                'SUM(o.total) AS total_revenue',
                'AVG(o.total) AS avg_order_value',
            )
            ->from(Order::class, 'o')
            ->getQuery()
            ->getSingleResult();

        // Currency from most recent order
        $currency = $this->em->createQueryBuilder()
            ->select('c.code')
            ->from(Order::class, 'o')
            ->leftJoin('o.currency', 'c')
            ->orderBy('o.date', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $currencyCode = $currency['code'] ?? 'USD';

        $now = time();
        $todayStart = strtotime('today midnight');
        $weekStart = strtotime('monday this week midnight');
        $monthStart = strtotime('first day of this month midnight');

        // Today
        $today = $this->getOrderPeriodStats($todayStart, $now);

        // This week
        $week = $this->getOrderPeriodStats($weekStart, $now);

        // This month
        $month = $this->getOrderPeriodStats($monthStart, $now);

        // By payment status
        $paymentStatuses = $this->em->createQueryBuilder()
            ->select('ps.code AS status', 'COUNT(o.order_id) AS cnt')
            ->from(Order::class, 'o')
            ->leftJoin('o.paymentStatus', 'ps')
            ->groupBy('ps.code')
            ->getQuery()
            ->getResult();

        $byPaymentStatus = [];
        foreach ($paymentStatuses as $row) {
            $byPaymentStatus[$row['status'] ?? 'Unknown'] = (int) $row['cnt'];
        }

        // By shipping status
        $shippingStatuses = $this->em->createQueryBuilder()
            ->select('ss.code AS status', 'COUNT(o.order_id) AS cnt')
            ->from(Order::class, 'o')
            ->leftJoin('o.shippingStatus', 'ss')
            ->groupBy('ss.code')
            ->getQuery()
            ->getResult();

        $byShippingStatus = [];
        foreach ($shippingStatuses as $row) {
            $byShippingStatus[$row['status'] ?? 'Unknown'] = (int) $row['cnt'];
        }

        return [
            'total_orders' => (int) $overall['total_orders'],
            'total_revenue' => round((float) $overall['total_revenue'], 2),
            'average_order_value' => round((float) $overall['avg_order_value'], 2),
            'currency' => $currencyCode,
            'today' => $today,
            'this_week' => $week,
            'this_month' => $month,
            'by_payment_status' => $byPaymentStatus,
            'by_shipping_status' => $byShippingStatus,
        ];
    }

    #[McpResource(
        uri: 'xcart://orders/pending',
        name: 'pending_orders',
        title: 'Pending Orders',
        description: 'Orders requiring attention: unpaid, unshipped, or with issues',
        mimeType: 'application/json'
    )]
    public function getPendingOrders(): array
    {
        // Unpaid orders (Pending or Authorized payment status)
        $unpaid = $this->em->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->leftJoin('o.profile', 'p')
            ->leftJoin('o.paymentStatus', 'ps')
            ->where('ps.code IN (:statuses)')
            ->setParameter('statuses', ['P', 'A'])
            ->orderBy('o.date', 'DESC')
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();

        // Unshipped orders (paid but shipping status is New or Processing)
        $unshipped = $this->em->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->leftJoin('o.profile', 'p')
            ->leftJoin('o.paymentStatus', 'ps2')
            ->leftJoin('o.shippingStatus', 'ss')
            ->where('ps2.code = :paid')
            ->andWhere('ss.code IN (:shippingStatuses)')
            ->setParameter('paid', 'P')
            ->setParameter('shippingStatuses', ['N', 'P'])
            ->orderBy('o.date', 'ASC')
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();

        return [
            'unpaid' => [
                'count' => count($unpaid),
                'items' => $this->formatOrderList($unpaid),
            ],
            'unshipped' => [
                'count' => count($unshipped),
                'items' => $this->formatOrderList($unshipped),
            ],
            'total_requiring_attention' => count($unpaid) + count($unshipped),
        ];
    }

    private function getOrderPeriodStats(int $from, int $to): array
    {
        $result = $this->em->createQueryBuilder()
            ->select('COUNT(o.order_id) AS orders', 'COALESCE(SUM(o.total), 0) AS revenue')
            ->from(Order::class, 'o')
            ->where('o.date >= :from')
            ->andWhere('o.date <= :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleResult();

        return [
            'orders' => (int) $result['orders'],
            'revenue' => round((float) $result['revenue'], 2),
        ];
    }

    private function formatOrderList(array $orders, bool $detailed = false): array
    {
        $items = [];
        foreach ($orders as $order) {
            $profile = $order->getProfile();
            $entry = [
                'id' => $order->getOrderId(),
                'order_number' => $order->getOrderNumber(),
                'date' => date('c', $order->getDate()),
                'total' => (float) $order->getTotal(),
                'payment_status' => $order->getPaymentStatusCode(),
                'shipping_status' => $order->getShippingStatusCode(),
                'customer_email' => $profile?->getLogin(),
            ];

            if ($detailed) {
                $entry['subtotal'] = (float) $order->getSubtotal();
                $entry['currency'] = $order->getCurrency()
                    ? $order->getCurrency()->getCode()
                    : 'USD';
                $entry['items_count'] = $order->getItems()->count();
            }

            $items[] = $entry;
        }

        return $items;
    }
}
