<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use XC\MCP\MCP\Security\McpAuthorizer;
use XC\MCP\MCP\Security\ToolAnnotation;
use XLite\Model\Order;
use XLite\Model\OrderItem;
use XLite\Model\Product;

class ReportTools
{
    private const ALLOWED_PERIODS = ['day', 'week', 'month', 'quarter', 'year', 'custom'];
    private const LOW_STOCK_THRESHOLD = 5;
    private const OVERSTOCK_THRESHOLD = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly McpAuthorizer $authorizer,
    ) {}

    #[McpTool(
        name: 'report_sales',
        title: 'Sales Report',
        description: 'Sales report for a period: revenue, orders count, average order value, comparison with previous period',
        // MCP Apps (io.modelcontextprotocol/ui): link this tool to the interactive
        // dashboard UI resource. Clients that support the extension render it; others ignore it.
        meta: ['io.modelcontextprotocol/ui' => ['resourceUri' => 'ui://sales-dashboard']],
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'period' => ['type' => 'string'],
                'date_from' => ['type' => 'string'],
                'date_to' => ['type' => 'string'],
                'current' => [
                    'type' => 'object',
                    'properties' => [
                        'orders' => ['type' => 'integer'],
                        'revenue' => ['type' => 'number'],
                        'avg_order_value' => ['type' => 'number'],
                    ],
                ],
                'previous' => [
                    'type' => 'object',
                    'properties' => [
                        'orders' => ['type' => 'integer'],
                        'revenue' => ['type' => 'number'],
                        'avg_order_value' => ['type' => 'number'],
                    ],
                ],
                'change' => [
                    'type' => 'object',
                    'properties' => [
                        'orders' => ['type' => 'object'],
                        'revenue' => ['type' => 'object'],
                        'avg_order_value' => ['type' => 'object'],
                    ],
                ],
            ],
            'required' => ['period', 'current', 'previous'],
        ]
    )]
    #[ToolAnnotation(readOnlyHint: true)]
    public function salesReport(
        string $period = 'month',
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ): array {
        $this->authorizer->authorizeTool('report_sales');

        if (!in_array($period, self::ALLOWED_PERIODS, true)) {
            throw new ToolCallException(
                "Invalid period '{$period}'. Allowed: " . implode(', ', self::ALLOWED_PERIODS)
            );
        }

        $now = new \DateTimeImmutable();

        if ($period === 'custom') {
            if ($dateFrom === null || $dateTo === null) {
                throw new ToolCallException('dateFrom and dateTo are required for custom period');
            }
            try {
                $currentFrom = new \DateTimeImmutable($dateFrom);
                $currentTo = new \DateTimeImmutable($dateTo);
            } catch (\Exception) {
                throw new ToolCallException('Invalid date format. Use ISO 8601 or YYYY-MM-DD.');
            }
            $interval = $currentFrom->diff($currentTo);
            $previousFrom = $currentFrom->sub(new \DateInterval('P' . $interval->days . 'D'));
            $previousTo = $currentFrom->modify('-1 day');
        } else {
            [$currentFrom, $currentTo, $previousFrom, $previousTo] = $this->calculatePeriodDates($period, $now);
        }

        $current = $this->getSalesData($currentFrom, $currentTo);
        $previous = $this->getSalesData($previousFrom, $previousTo);

        return [
            'period' => $period,
            'date_from' => $currentFrom->format('Y-m-d'),
            'date_to' => $currentTo->format('Y-m-d'),
            'current' => $current,
            'previous' => $previous,
            'change' => [
                'orders' => $this->calculateChange($previous['orders'], $current['orders']),
                'revenue' => $this->calculateChange($previous['revenue'], $current['revenue']),
                'avg_order_value' => $this->calculateChange($previous['avg_order_value'], $current['avg_order_value']),
            ],
        ];
    }

    #[McpTool(
        name: 'report_top_products',
        title: 'Top Products Report',
        description: 'Top selling products by revenue or quantity for a given period',
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'period' => ['type' => 'string'],
                'sort_by' => ['type' => 'string'],
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'product_id' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                            'sku' => ['type' => 'string'],
                            'quantity_sold' => ['type' => 'integer'],
                            'revenue' => ['type' => 'number'],
                        ],
                    ],
                ],
            ],
            'required' => ['items'],
        ]
    )]
    #[ToolAnnotation(readOnlyHint: true)]
    public function topProducts(
        int $limit = 10,
        string $period = 'month',
        string $sortBy = 'revenue',
    ): array {
        $this->authorizer->authorizeTool('report_top_products');

        if (!in_array($sortBy, ['revenue', 'quantity'], true)) {
            throw new ToolCallException("Invalid sortBy '{$sortBy}'. Allowed: revenue, quantity");
        }

        $limit = min($limit, 50);
        $now = new \DateTimeImmutable();

        if (!in_array($period, self::ALLOWED_PERIODS, true)) {
            throw new ToolCallException(
                "Invalid period '{$period}'. Allowed: " . implode(', ', self::ALLOWED_PERIODS)
            );
        }

        [$dateFrom, $dateTo] = $this->calculatePeriodDates($period, $now);

        $orderField = $sortBy === 'revenue' ? 'totalRevenue' : 'totalQuantity';

        $qb = $this->em->createQueryBuilder()
            ->select(
                'IDENTITY(oi.object) AS product_id',
                'oi.name AS product_name',
                'oi.sku AS product_sku',
                'SUM(oi.amount) AS totalQuantity',
                'SUM(oi.total) AS totalRevenue',
            )
            ->from(OrderItem::class, 'oi')
            ->innerJoin('oi.order', 'o')
            ->where('o.date >= :dateFrom')
            ->andWhere('o.date <= :dateTo')
            ->setParameter('dateFrom', $dateFrom->getTimestamp())
            ->setParameter('dateTo', $dateTo->getTimestamp())
            ->groupBy('oi.object, oi.name, oi.sku')
            ->orderBy($orderField, 'DESC')
            ->setMaxResults($limit);

        $results = $qb->getQuery()->getArrayResult();

        $items = [];
        foreach ($results as $row) {
            $items[] = [
                'product_id' => $row['product_id'],
                'name' => $row['product_name'],
                'sku' => $row['product_sku'],
                'quantity_sold' => (int) $row['totalQuantity'],
                'revenue' => round((float) $row['totalRevenue'], 2),
            ];
        }

        return [
            'period' => $period,
            'sort_by' => $sortBy,
            'items' => $items,
        ];
    }

    #[McpTool(
        name: 'report_inventory',
        title: 'Inventory Report',
        description: 'Inventory report: stock value, out of stock items, low stock items, overstocked items',
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'total_products' => ['type' => 'integer'],
                'total_stock_value' => ['type' => 'number'],
                'out_of_stock' => ['type' => 'object', 'properties' => [
                    'count' => ['type' => 'integer'],
                    'items' => ['type' => 'array'],
                ]],
                'low_stock' => ['type' => 'object', 'properties' => [
                    'count' => ['type' => 'integer'],
                    'threshold' => ['type' => 'integer'],
                    'items' => ['type' => 'array'],
                ]],
                'overstocked' => ['type' => 'object', 'properties' => [
                    'count' => ['type' => 'integer'],
                    'threshold' => ['type' => 'integer'],
                    'items' => ['type' => 'array'],
                ]],
            ],
            'required' => ['total_products', 'total_stock_value'],
        ]
    )]
    #[ToolAnnotation(readOnlyHint: true)]
    public function inventoryReport(): array
    {
        $this->authorizer->authorizeTool('report_inventory');

        $lowStockThreshold = self::LOW_STOCK_THRESHOLD;
        $overstockThreshold = self::OVERSTOCK_THRESHOLD;

        // Total products and stock value
        $statsQb = $this->em->createQueryBuilder()
            ->select(
                'COUNT(p.product_id) AS total',
                'SUM(p.price * p.amount) AS stockValue',
            )
            ->from(Product::class, 'p')
            ->where('p.enabled = true');

        $stats = $statsQb->getQuery()->getSingleResult();

        // Out of stock
        $outOfStockQb = $this->em->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->leftJoin('p.translations', 't', 'WITH', 't.code = :lang')
            ->where('p.enabled = true')
            ->andWhere('p.amount = 0')
            ->setParameter('lang', \XC\MCP\MCP\Util\TableResolver::getDefaultLanguage())
            ->orderBy('t.name', 'ASC')
            ->setMaxResults(50);

        $outOfStockProducts = $outOfStockQb->getQuery()->getResult();
        $outOfStockItems = [];
        foreach ($outOfStockProducts as $product) {
            $outOfStockItems[] = [
                'id' => $product->getId(),
                'name' => $product->getName(),
                'sku' => $product->getSku(),
                'price' => $product->getPrice(),
            ];
        }

        $outOfStockCount = (int) $this->em->createQueryBuilder()
            ->select('COUNT(p.product_id)')
            ->from(Product::class, 'p')
            ->where('p.enabled = true')
            ->andWhere('p.amount = 0')
            ->getQuery()
            ->getSingleScalarResult();

        // Low stock (1..threshold)
        $lowStockQb = $this->em->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->where('p.enabled = true')
            ->andWhere('p.amount > 0')
            ->andWhere('p.amount <= :threshold')
            ->setParameter('threshold', $lowStockThreshold)
            ->orderBy('p.amount', 'ASC')
            ->setMaxResults(50);

        $lowStockProducts = $lowStockQb->getQuery()->getResult();
        $lowStockItems = [];
        foreach ($lowStockProducts as $product) {
            $lowStockItems[] = [
                'id' => $product->getId(),
                'name' => $product->getName(),
                'sku' => $product->getSku(),
                'quantity' => $product->getQuantity(),
                'price' => $product->getPrice(),
            ];
        }

        $lowStockCount = (int) $this->em->createQueryBuilder()
            ->select('COUNT(p.product_id)')
            ->from(Product::class, 'p')
            ->where('p.enabled = true')
            ->andWhere('p.amount > 0')
            ->andWhere('p.amount <= :threshold')
            ->setParameter('threshold', $lowStockThreshold)
            ->getQuery()
            ->getSingleScalarResult();

        // Overstocked
        $overstockedQb = $this->em->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->where('p.enabled = true')
            ->andWhere('p.amount >= :threshold')
            ->setParameter('threshold', $overstockThreshold)
            ->orderBy('p.amount', 'DESC')
            ->setMaxResults(50);

        $overstockedProducts = $overstockedQb->getQuery()->getResult();
        $overstockedItems = [];
        foreach ($overstockedProducts as $product) {
            $overstockedItems[] = [
                'id' => $product->getId(),
                'name' => $product->getName(),
                'sku' => $product->getSku(),
                'quantity' => $product->getQuantity(),
                'price' => $product->getPrice(),
            ];
        }

        $overstockedCount = (int) $this->em->createQueryBuilder()
            ->select('COUNT(p.product_id)')
            ->from(Product::class, 'p')
            ->where('p.enabled = true')
            ->andWhere('p.amount >= :threshold')
            ->setParameter('threshold', $overstockThreshold)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total_products' => (int) $stats['total'],
            'total_stock_value' => round((float) ($stats['stockValue'] ?? 0), 2),
            'out_of_stock' => [
                'count' => $outOfStockCount,
                'items' => $outOfStockItems,
            ],
            'low_stock' => [
                'count' => $lowStockCount,
                'threshold' => $lowStockThreshold,
                'items' => $lowStockItems,
            ],
            'overstocked' => [
                'count' => $overstockedCount,
                'threshold' => $overstockThreshold,
                'items' => $overstockedItems,
            ],
        ];
    }

    /**
     * Calculate date ranges for a given period.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: \DateTimeImmutable, 3: \DateTimeImmutable}
     */
    private function calculatePeriodDates(string $period, \DateTimeImmutable $now): array
    {
        return match ($period) {
            'day' => [
                $now->setTime(0, 0),
                $now->setTime(23, 59, 59),
                $now->modify('-1 day')->setTime(0, 0),
                $now->modify('-1 day')->setTime(23, 59, 59),
            ],
            'week' => [
                $now->modify('monday this week')->setTime(0, 0),
                $now->setTime(23, 59, 59),
                $now->modify('monday last week')->setTime(0, 0),
                $now->modify('sunday last week')->setTime(23, 59, 59),
            ],
            'month' => [
                $now->modify('first day of this month')->setTime(0, 0),
                $now->setTime(23, 59, 59),
                $now->modify('first day of last month')->setTime(0, 0),
                $now->modify('last day of last month')->setTime(23, 59, 59),
            ],
            'quarter' => [
                $this->getQuarterStart($now)->setTime(0, 0),
                $now->setTime(23, 59, 59),
                $this->getQuarterStart($now)->modify('-3 months')->setTime(0, 0),
                $this->getQuarterStart($now)->modify('-1 day')->setTime(23, 59, 59),
            ],
            'year' => [
                $now->modify('first day of january this year')->setTime(0, 0),
                $now->setTime(23, 59, 59),
                $now->modify('first day of january last year')->setTime(0, 0),
                $now->modify('last day of december last year')->setTime(23, 59, 59),
            ],
            default => throw new ToolCallException("Unsupported period: {$period}"),
        };
    }

    private function getQuarterStart(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $month = (int) $date->format('n');
        $quarterStartMonth = (int) (ceil($month / 3) - 1) * 3 + 1;

        return $date->setDate(
            (int) $date->format('Y'),
            $quarterStartMonth,
            1,
        );
    }

    /**
     * Get aggregate sales data for a date range.
     *
     * @return array{orders: int, revenue: float, avg_order_value: float}
     */
    private function getSalesData(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select(
                'COUNT(o.order_id) AS orderCount',
                'COALESCE(SUM(o.total), 0) AS revenue',
            )
            ->from(Order::class, 'o')
            ->where('o.date >= :dateFrom')
            ->andWhere('o.date <= :dateTo')
            ->setParameter('dateFrom', $from->getTimestamp())
            ->setParameter('dateTo', $to->getTimestamp());

        $result = $qb->getQuery()->getSingleResult();

        $orders = (int) $result['orderCount'];
        $revenue = round((float) $result['revenue'], 2);
        $avg = $orders > 0 ? round($revenue / $orders, 2) : 0.0;

        return [
            'orders' => $orders,
            'revenue' => $revenue,
            'avg_order_value' => $avg,
        ];
    }

    /**
     * @return array{percent: float, formatted: string}
     */
    private function calculateChange(float|int $previous, float|int $current): array
    {
        if ($previous == 0) {
            $percent = $current == 0 ? 0.0 : 100.0;
        } else {
            $percent = round((($current - $previous) / abs($previous)) * 100, 1);
        }

        $sign = $percent >= 0 ? '+' : '';

        return [
            'percent' => $percent,
            'formatted' => $sign . $percent . '%',
        ];
    }
}
