<?php

declare(strict_types=1);

namespace Tests\Unit\MCP\Resources;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use XC\MCP\MCP\Resources\StoreResources;

class StoreResourcesTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private StoreResources $resources;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->resources = new StoreResources($this->em);
    }

    // ── getActiveModules ──

    public function testGetActiveModulesEmpty(): void
    {
        $query = $this->createMock(AbstractQuery::class);
        $query->method('getResult')->willReturn([]);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('addOrderBy')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $this->em->expects($this->once())->method('createQueryBuilder')->willReturn($qb);

        $result = $this->resources->getActiveModules();

        $this->assertSame(0, $result['count']);
        $this->assertEmpty($result['modules']);
    }

    public function testGetActiveModulesWithData(): void
    {
        $module = $this->createMock(\XLite\Model\Module::class);
        $module->method('getAuthor')->willReturn('XC');
        $module->method('getName')->willReturn('MCP');
        $module->method('getModuleName')->willReturn('MCP Server');
        $module->method('getVersion')->willReturn('1.0.0');

        $query = $this->createMock(AbstractQuery::class);
        $query->method('getResult')->willReturn([$module]);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('addOrderBy')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $this->em->expects($this->once())->method('createQueryBuilder')->willReturn($qb);

        $result = $this->resources->getActiveModules();

        $this->assertSame(1, $result['count']);
        $this->assertCount(1, $result['modules']);
        $this->assertSame('XC', $result['modules'][0]['author']);
        $this->assertSame('MCP', $result['modules'][0]['name']);
        $this->assertSame('MCP Server', $result['modules'][0]['module_name']);
        $this->assertSame('1.0.0', $result['modules'][0]['version']);
        $this->assertTrue($result['modules'][0]['enabled']);
    }

    // ── getDashboard ──

    public function testGetDashboard(): void
    {
        // 4 period stats (today, yesterday, this_month, last_month)
        $periodQbs = [];
        foreach ([['3', '300.00'], ['5', '500.00'], ['50', '5000.00'], ['45', '4500.00']] as [$orders, $revenue]) {
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

        // New customers for each period (4 calls)
        $custQbs = [];
        foreach ([1, 2, 10, 8] as $count) {
            $cQuery = $this->createMock(AbstractQuery::class);
            $cQuery->method('getSingleScalarResult')->willReturn($count);
            $cQb = $this->createMock(QueryBuilder::class);
            $cQb->method('select')->willReturnSelf();
            $cQb->method('from')->willReturnSelf();
            $cQb->method('where')->willReturnSelf();
            $cQb->method('andWhere')->willReturnSelf();
            $cQb->method('setParameter')->willReturnSelf();
            $cQb->method('getQuery')->willReturn($cQuery);
            $custQbs[] = $cQb;
        }

        // Recent orders
        $recentQuery = $this->createMock(AbstractQuery::class);
        $recentQuery->method('getResult')->willReturn([]);
        $recentQb = $this->createMock(QueryBuilder::class);
        $recentQb->method('select')->willReturnSelf();
        $recentQb->method('from')->willReturnSelf();
        $recentQb->method('orderBy')->willReturnSelf();
        $recentQb->method('setMaxResults')->willReturnSelf();
        $recentQb->method('getQuery')->willReturn($recentQuery);

        // Low stock count
        $lowStockQuery = $this->createMock(AbstractQuery::class);
        $lowStockQuery->method('getSingleScalarResult')->willReturn(7);
        $lowStockQb = $this->createMock(QueryBuilder::class);
        $lowStockQb->method('select')->willReturnSelf();
        $lowStockQb->method('from')->willReturnSelf();
        $lowStockQb->method('where')->willReturnSelf();
        $lowStockQb->method('andWhere')->willReturnSelf();
        $lowStockQb->method('setParameter')->willReturnSelf();
        $lowStockQb->method('getQuery')->willReturn($lowStockQuery);

        // Pending orders count
        $pendingQuery = $this->createMock(AbstractQuery::class);
        $pendingQuery->method('getSingleScalarResult')->willReturn(12);
        $pendingQb = $this->createMock(QueryBuilder::class);
        $pendingQb->method('select')->willReturnSelf();
        $pendingQb->method('from')->willReturnSelf();
        $pendingQb->method('where')->willReturnSelf();
        $pendingQb->method('setParameter')->willReturnSelf();
        $pendingQb->method('getQuery')->willReturn($pendingQuery);

        // Order: period1, cust1, period2, cust2, period3, cust3, period4, cust4, recent, lowStock, pending
        $this->em
            ->expects($this->exactly(11))
            ->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls(
                $periodQbs[0], $custQbs[0],
                $periodQbs[1], $custQbs[1],
                $periodQbs[2], $custQbs[2],
                $periodQbs[3], $custQbs[3],
                $recentQb,
                $lowStockQb,
                $pendingQb,
            );

        $result = $this->resources->getDashboard();

        $this->assertSame(3, $result['today']['orders']);
        $this->assertSame(300.00, $result['today']['revenue']);
        $this->assertSame(1, $result['today']['new_customers']);
        $this->assertSame(5, $result['yesterday']['orders']);
        $this->assertSame(50, $result['this_month']['orders']);
        $this->assertSame(45, $result['last_month']['orders']);
        $this->assertEmpty($result['recent_orders']);
        $this->assertSame(7, $result['low_stock_count']);
        $this->assertSame(12, $result['pending_orders_count']);
    }
}
