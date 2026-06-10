<?php

declare(strict_types=1);

namespace Tests\Unit\MCP\Resources;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use XC\MCP\MCP\Resources\OrderResources;
use XLite\Model\Order;

class OrderResourcesTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private EntityRepository&MockObject $orderRepo;
    private OrderResources $resources;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->orderRepo = $this->createMock(EntityRepository::class);

        $this->em->method('getRepository')
            ->with(Order::class)
            ->willReturn($this->orderRepo);

        $this->resources = new OrderResources($this->em);
    }

    // ── getRecentOrders ──

    public function testGetRecentOrdersEmpty(): void
    {
        $query = $this->createMock(AbstractQuery::class);
        $query->method('getResult')->willReturn([]);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('leftJoin')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $this->em->expects($this->once())->method('createQueryBuilder')->willReturn($qb);

        $result = $this->resources->getRecentOrders();

        $this->assertSame(0, $result['count']);
        $this->assertEmpty($result['items']);
    }

    // ── getOrder ──

    public function testGetOrderNotFound(): void
    {
        $this->orderRepo
            ->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Order #999 not found');

        $this->resources->getOrder(999);
    }

    // ── getOrderStats ──

    public function testGetOrderStats(): void
    {
        // Overall stats
        $overallQuery = $this->createMock(AbstractQuery::class);
        $overallQuery->method('getSingleResult')->willReturn([
            'total_orders' => '150',
            'total_revenue' => '25000.50',
            'avg_order_value' => '166.67',
        ]);
        $overallQb = $this->createMock(QueryBuilder::class);
        $overallQb->method('select')->willReturnSelf();
        $overallQb->method('from')->willReturnSelf();
        $overallQb->method('getQuery')->willReturn($overallQuery);

        // Currency
        $currencyQuery = $this->createMock(AbstractQuery::class);
        $currencyQuery->method('getOneOrNullResult')->willReturn(['code' => 'EUR']);
        $currencyQb = $this->createMock(QueryBuilder::class);
        $currencyQb->method('select')->willReturnSelf();
        $currencyQb->method('from')->willReturnSelf();
        $currencyQb->method('leftJoin')->willReturnSelf();
        $currencyQb->method('orderBy')->willReturnSelf();
        $currencyQb->method('setMaxResults')->willReturnSelf();
        $currencyQb->method('getQuery')->willReturn($currencyQuery);

        // Period stats (today, week, month = 3 calls)
        $periodQbs = [];
        foreach ([['5', '500.00'], ['20', '3000.00'], ['100', '15000.00']] as [$orders, $revenue]) {
            $pQuery = $this->createMock(AbstractQuery::class);
            $pQuery->method('getSingleResult')->willReturn([
                'orders' => $orders,
                'revenue' => $revenue,
            ]);
            $pQb = $this->createMock(QueryBuilder::class);
            $pQb->method('select')->willReturnSelf();
            $pQb->method('from')->willReturnSelf();
            $pQb->method('where')->willReturnSelf();
            $pQb->method('andWhere')->willReturnSelf();
            $pQb->method('setParameter')->willReturnSelf();
            $pQb->method('getQuery')->willReturn($pQuery);
            $periodQbs[] = $pQb;
        }

        // Payment status breakdown
        $paymentQuery = $this->createMock(AbstractQuery::class);
        $paymentQuery->method('getResult')->willReturn([
            ['status' => 'Paid', 'cnt' => '100'],
            ['status' => 'Pending', 'cnt' => '50'],
        ]);
        $paymentQb = $this->createMock(QueryBuilder::class);
        $paymentQb->method('select')->willReturnSelf();
        $paymentQb->method('from')->willReturnSelf();
        $paymentQb->method('groupBy')->willReturnSelf();
        $paymentQb->method('getQuery')->willReturn($paymentQuery);

        // Shipping status breakdown
        $shippingQuery = $this->createMock(AbstractQuery::class);
        $shippingQuery->method('getResult')->willReturn([
            ['status' => 'Shipped', 'cnt' => '80'],
        ]);
        $shippingQb = $this->createMock(QueryBuilder::class);
        $shippingQb->method('select')->willReturnSelf();
        $shippingQb->method('from')->willReturnSelf();
        $shippingQb->method('groupBy')->willReturnSelf();
        $shippingQb->method('getQuery')->willReturn($shippingQuery);

        $this->em
            ->expects($this->exactly(7))
            ->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls(
                $overallQb,
                $currencyQb,
                $periodQbs[0],
                $periodQbs[1],
                $periodQbs[2],
                $paymentQb,
                $shippingQb,
            );

        $result = $this->resources->getOrderStats();

        $this->assertSame(150, $result['total_orders']);
        $this->assertSame(25000.50, $result['total_revenue']);
        $this->assertSame(166.67, $result['average_order_value']);
        $this->assertSame('EUR', $result['currency']);
        $this->assertSame(5, $result['today']['orders']);
        $this->assertSame(20, $result['this_week']['orders']);
        $this->assertSame(100, $result['this_month']['orders']);
        $this->assertSame(100, $result['by_payment_status']['Paid']);
        $this->assertSame(50, $result['by_payment_status']['Pending']);
        $this->assertSame(80, $result['by_shipping_status']['Shipped']);
    }

    // ── getPendingOrders ──

    public function testGetPendingOrdersEmpty(): void
    {
        $unpaidQuery = $this->createMock(AbstractQuery::class);
        $unpaidQuery->method('getResult')->willReturn([]);
        $unpaidQb = $this->createMock(QueryBuilder::class);
        $unpaidQb->method('select')->willReturnSelf();
        $unpaidQb->method('from')->willReturnSelf();
        $unpaidQb->method('leftJoin')->willReturnSelf();
        $unpaidQb->method('where')->willReturnSelf();
        $unpaidQb->method('andWhere')->willReturnSelf();
        $unpaidQb->method('setParameter')->willReturnSelf();
        $unpaidQb->method('orderBy')->willReturnSelf();
        $unpaidQb->method('setMaxResults')->willReturnSelf();
        $unpaidQb->method('getQuery')->willReturn($unpaidQuery);

        $unshippedQuery = $this->createMock(AbstractQuery::class);
        $unshippedQuery->method('getResult')->willReturn([]);
        $unshippedQb = $this->createMock(QueryBuilder::class);
        $unshippedQb->method('select')->willReturnSelf();
        $unshippedQb->method('from')->willReturnSelf();
        $unshippedQb->method('leftJoin')->willReturnSelf();
        $unshippedQb->method('where')->willReturnSelf();
        $unshippedQb->method('andWhere')->willReturnSelf();
        $unshippedQb->method('setParameter')->willReturnSelf();
        $unshippedQb->method('orderBy')->willReturnSelf();
        $unshippedQb->method('setMaxResults')->willReturnSelf();
        $unshippedQb->method('getQuery')->willReturn($unshippedQuery);

        $this->em
            ->expects($this->exactly(2))
            ->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls($unpaidQb, $unshippedQb);

        $result = $this->resources->getPendingOrders();

        $this->assertSame(0, $result['unpaid']['count']);
        $this->assertSame(0, $result['unshipped']['count']);
        $this->assertSame(0, $result['total_requiring_attention']);
    }
}
