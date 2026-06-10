<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Resources;

use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Exception\ToolCallException;
use XC\MCP\MCP\Resources\ProductResources;
use XLite\Core\Config;
use XLite\Model\Order;
use XLite\Model\Product;

class StoreResources
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    private function getConfig()
    {
        return Config::getInstance();
    }

    #[McpResource(
        uri: 'xcart://store/config',
        name: 'store_config',
        title: 'Store Config',
        description: 'Store configuration: name, URL, currency, language, timezone. No secrets or credentials.',
        mimeType: 'application/json'
    )]
    public function getStoreConfig(): array
    {
        return [
            'store_name' => $this->getConfig()->Company->company_name ?? null,
            'url' => $this->getConfig()->Security->web_dir ?? null,
            'admin_url' => $this->getConfig()->Security->admin_self
                ? ($this->getConfig()->Security->web_dir ?? '') . '/' . $this->getConfig()->Security->admin_self
                : null,
            'currency' => $this->getConfig()->General->shop_currency ?? 'USD',
            'default_language' => $this->getConfig()->General->default_language ?? 'en',
            'timezone' => $this->getConfig()->Units->time_zone ?? date_default_timezone_get(),
            'xcart_version' => defined('XLite::XC_VERSION')
                ? \XLite::XC_VERSION
                : '5.6.x',
            'php_version' => PHP_VERSION,
        ];
    }

    #[McpResource(
        uri: 'xcart://store/modules',
        name: 'active_modules',
        title: 'Active Modules',
        description: 'List of active X-Cart modules with versions',
        mimeType: 'application/json'
    )]
    public function getActiveModules(): array
    {
        $conn = $this->em->getConnection();
        $rows = $conn->fetchAllAssociative(
            "SELECT author, name, version, state FROM service_module WHERE state = 'enabled' ORDER BY author, name"
        );

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'author' => $row['author'],
                'name' => $row['name'],
                'module_id' => $row['author'] . '\\' . $row['name'],
                'version' => $row['version'],
                'enabled' => true,
            ];
        }

        return [
            'count' => count($items),
            'modules' => $items,
        ];
    }

    #[McpResource(
        uri: 'xcart://store/dashboard',
        name: 'store_dashboard',
        title: 'Store Dashboard',
        description: 'Store dashboard: today sales, orders, recent activity, low stock and pending order counts',
        mimeType: 'application/json'
    )]
    public function getDashboard(): array
    {
        $now = time();
        $todayStart = strtotime('today midnight');
        $yesterdayStart = strtotime('yesterday midnight');
        $monthStart = strtotime('first day of this month midnight');
        $lastMonthStart = strtotime('first day of last month midnight');
        $lastMonthEnd = $monthStart - 1;

        // Today
        $today = $this->getPeriodStats($todayStart, $now);

        // Yesterday
        $yesterday = $this->getPeriodStats($yesterdayStart, $todayStart - 1);

        // This month
        $thisMonth = $this->getPeriodStats($monthStart, $now);

        // Last month
        $lastMonth = $this->getPeriodStats($lastMonthStart, $lastMonthEnd);

        // Recent orders (last 5)
        $recentOrders = $this->em->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->orderBy('o.date', 'DESC')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        $recentOrdersFormatted = [];
        foreach ($recentOrders as $order) {
            $recentOrdersFormatted[] = [
                'id' => $order->getOrderId(),
                'date' => date('c', $order->getDate()),
                'total' => (float) $order->getTotal(),
                'status' => $order->getPaymentStatusCode(),
            ];
        }

        // Low stock count
        $lowStockCount = (int) $this->em->createQueryBuilder()
            ->select('COUNT(p.product_id)')
            ->from(Product::class, 'p')
            ->where('p.amount <= :threshold')
            ->andWhere('p.enabled = :enabled')
            ->setParameter('threshold', ProductResources::LOW_STOCK_THRESHOLD)
            ->setParameter('enabled', true)
            ->getQuery()
            ->getSingleScalarResult();

        // Pending orders count (unpaid)
        $pendingOrdersCount = (int) $this->em->createQueryBuilder()
            ->select('COUNT(o.order_id)')
            ->from(Order::class, 'o')
            ->leftJoin('o.paymentStatus', 'ps')
            ->where('ps.code IN (:statuses)')
            ->setParameter('statuses', ['P', 'A'])
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'today' => $today,
            'yesterday' => $yesterday,
            'this_month' => $thisMonth,
            'last_month' => $lastMonth,
            'recent_orders' => $recentOrdersFormatted,
            'low_stock_count' => $lowStockCount,
            'pending_orders_count' => $pendingOrdersCount,
        ];
    }

    private function getPeriodStats(int $from, int $to): array
    {
        $result = $this->em->createQueryBuilder()
            ->select(
                'COUNT(o.order_id) AS orders',
                'COALESCE(SUM(o.total), 0) AS revenue',
            )
            ->from(Order::class, 'o')
            ->where('o.date >= :from')
            ->andWhere('o.date <= :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleResult();

        // New customers in period
        $newCustomers = (int) $this->em->createQueryBuilder()
            ->select('COUNT(p.profile_id)')
            ->from(\XLite\Model\Profile::class, 'p')
            ->where('p.access_level = :level')
            ->andWhere('p.added >= :from')
            ->andWhere('p.added <= :to')
            ->setParameter('level', 0)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'orders' => (int) $result['orders'],
            'revenue' => round((float) $result['revenue'], 2),
            'new_customers' => $newCustomers,
        ];
    }
}
