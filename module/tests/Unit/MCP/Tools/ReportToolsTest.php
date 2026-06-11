<?php

declare(strict_types=1);

namespace Tests\Unit\MCP\Tools;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use XC\MCP\MCP\Tools\ReportTools;

class ReportToolsTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private ReportTools $tools;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->tools = new ReportTools($this->em);
    }

    private function createSalesQueryBuilder(int $orderCount, float $revenue): QueryBuilder&MockObject
    {
        $query = $this->createMock(AbstractQuery::class);
        $query->method('getSingleResult')->willReturn([
            'orderCount' => $orderCount,
            'revenue' => $revenue,
        ]);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        return $qb;
    }

    // ── salesReport ──

    public function testSalesReportInvalidPeriod(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Invalid period 'invalid'");

        $this->tools->salesReport(period: 'invalid');
    }

    public function testSalesReportCustomPeriodMissingDates(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('dateFrom and dateTo are required for custom period');

        $this->tools->salesReport(period: 'custom');
    }

    public function testSalesReportCustomPeriodInvalidDate(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid date format');

        $this->tools->salesReport(
            period: 'custom',
            dateFrom: 'not-a-date',
            dateTo: '2026-01-31',
        );
    }

    public function testSalesReportMonth(): void
    {
        // Current period and previous period each call getSalesData
        $currentQb = $this->createSalesQueryBuilder(50, 5000.00);
        $previousQb = $this->createSalesQueryBuilder(40, 4000.00);

        $this->em
            ->expects($this->exactly(2))
            ->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls($currentQb, $previousQb);

        $result = $this->tools->salesReport(period: 'month');

        $this->assertSame('month', $result['period']);
        $this->assertArrayHasKey('date_from', $result);
        $this->assertArrayHasKey('date_to', $result);
        $this->assertSame(50, $result['current']['orders']);
        $this->assertSame(5000.00, $result['current']['revenue']);
        $this->assertSame(40, $result['previous']['orders']);
        $this->assertSame(4000.00, $result['previous']['revenue']);
        $this->assertSame('+25.0%', $result['change']['orders']);
        $this->assertSame('+25.0%', $result['change']['revenue']);
    }

    public function testSalesReportCustomPeriod(): void
    {
        $currentQb = $this->createSalesQueryBuilder(10, 1000.00);
        $previousQb = $this->createSalesQueryBuilder(0, 0.00);

        $this->em
            ->expects($this->exactly(2))
            ->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls($currentQb, $previousQb);

        $result = $this->tools->salesReport(
            period: 'custom',
            dateFrom: '2026-01-01',
            dateTo: '2026-01-31',
        );

        $this->assertSame('custom', $result['period']);
        $this->assertSame('2026-01-01', $result['date_from']);
        $this->assertSame('2026-01-31', $result['date_to']);
        $this->assertSame(10, $result['current']['orders']);
        // Previous was 0, so change should be +100.0%
        $this->assertSame('+100.0%', $result['change']['orders']);
    }

    public function testSalesReportChangeCalculationBothZero(): void
    {
        $currentQb = $this->createSalesQueryBuilder(0, 0.0);
        $previousQb = $this->createSalesQueryBuilder(0, 0.0);

        $this->em
            ->expects($this->exactly(2))
            ->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls($currentQb, $previousQb);

        $result = $this->tools->salesReport(period: 'day');

        $this->assertSame('0.0%', $result['change']['orders']);
        $this->assertSame('0.0%', $result['change']['revenue']);
    }

    // ── topProducts ──

    public function testTopProductsInvalidSortBy(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Invalid sortBy 'invalid'");

        $this->tools->topProducts(sortBy: 'invalid');
    }

    public function testTopProductsInvalidPeriod(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Invalid period 'invalid'");

        $this->tools->topProducts(period: 'invalid');
    }

    public function testTopProducts(): void
    {
        $query = $this->createMock(AbstractQuery::class);
        $query->method('getArrayResult')->willReturn([
            [
                'product_id' => 1,
                'product_name' => 'Widget',
                'product_sku' => 'WDG-001',
                'totalQuantity' => '25',
                'totalRevenue' => '499.75',
            ],
        ]);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('innerJoin')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('groupBy')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $this->em
            ->expects($this->once())
            ->method('createQueryBuilder')
            ->willReturn($qb);

        $result = $this->tools->topProducts(limit: 10, period: 'month', sortBy: 'revenue');

        $this->assertSame('month', $result['period']);
        $this->assertSame('revenue', $result['sort_by']);
        $this->assertCount(1, $result['items']);
        $this->assertSame(1, $result['items'][0]['product_id']);
        $this->assertSame('Widget', $result['items'][0]['name']);
        $this->assertSame(25, $result['items'][0]['quantity_sold']);
        $this->assertSame(499.75, $result['items'][0]['revenue']);
    }

    // ── inventoryReport ──

    public function testInventoryReport(): void
    {
        // Stats QB
        $statsQuery = $this->createMock(AbstractQuery::class);
        $statsQuery->method('getSingleResult')->willReturn([
            'total' => '100',
            'stockValue' => '50000.50',
        ]);
        $statsQb = $this->createMock(QueryBuilder::class);
        $statsQb->method('select')->willReturnSelf();
        $statsQb->method('from')->willReturnSelf();
        $statsQb->method('where')->willReturnSelf();
        $statsQb->method('getQuery')->willReturn($statsQuery);

        // Out of stock list QB
        $outProduct = $this->createMock(\XLite\Model\Product::class);
        $outProduct->method('getId')->willReturn(10);
        $outProduct->method('getName')->willReturn('Out Item');
        $outProduct->method('getSku')->willReturn('OUT-001');
        $outProduct->method('getPrice')->willReturn(20.0);

        $outListQuery = $this->createMock(AbstractQuery::class);
        $outListQuery->method('getResult')->willReturn([$outProduct]);
        $outListQb = $this->createMock(QueryBuilder::class);
        $outListQb->method('select')->willReturnSelf();
        $outListQb->method('from')->willReturnSelf();
        $outListQb->method('where')->willReturnSelf();
        $outListQb->method('andWhere')->willReturnSelf();
        $outListQb->method('orderBy')->willReturnSelf();
        $outListQb->method('setMaxResults')->willReturnSelf();
        $outListQb->method('getQuery')->willReturn($outListQuery);

        // Out of stock count QB
        $outCountQuery = $this->createMock(AbstractQuery::class);
        $outCountQuery->method('getSingleScalarResult')->willReturn(1);
        $outCountQb = $this->createMock(QueryBuilder::class);
        $outCountQb->method('select')->willReturnSelf();
        $outCountQb->method('from')->willReturnSelf();
        $outCountQb->method('where')->willReturnSelf();
        $outCountQb->method('andWhere')->willReturnSelf();
        $outCountQb->method('getQuery')->willReturn($outCountQuery);

        // Low stock list QB
        $lowListQuery = $this->createMock(AbstractQuery::class);
        $lowListQuery->method('getResult')->willReturn([]);
        $lowListQb = $this->createMock(QueryBuilder::class);
        $lowListQb->method('select')->willReturnSelf();
        $lowListQb->method('from')->willReturnSelf();
        $lowListQb->method('where')->willReturnSelf();
        $lowListQb->method('andWhere')->willReturnSelf();
        $lowListQb->method('setParameter')->willReturnSelf();
        $lowListQb->method('orderBy')->willReturnSelf();
        $lowListQb->method('setMaxResults')->willReturnSelf();
        $lowListQb->method('getQuery')->willReturn($lowListQuery);

        // Low stock count QB
        $lowCountQuery = $this->createMock(AbstractQuery::class);
        $lowCountQuery->method('getSingleScalarResult')->willReturn(0);
        $lowCountQb = $this->createMock(QueryBuilder::class);
        $lowCountQb->method('select')->willReturnSelf();
        $lowCountQb->method('from')->willReturnSelf();
        $lowCountQb->method('where')->willReturnSelf();
        $lowCountQb->method('andWhere')->willReturnSelf();
        $lowCountQb->method('setParameter')->willReturnSelf();
        $lowCountQb->method('getQuery')->willReturn($lowCountQuery);

        // Overstock list QB
        $overListQuery = $this->createMock(AbstractQuery::class);
        $overListQuery->method('getResult')->willReturn([]);
        $overListQb = $this->createMock(QueryBuilder::class);
        $overListQb->method('select')->willReturnSelf();
        $overListQb->method('from')->willReturnSelf();
        $overListQb->method('where')->willReturnSelf();
        $overListQb->method('andWhere')->willReturnSelf();
        $overListQb->method('setParameter')->willReturnSelf();
        $overListQb->method('orderBy')->willReturnSelf();
        $overListQb->method('setMaxResults')->willReturnSelf();
        $overListQb->method('getQuery')->willReturn($overListQuery);

        // Overstock count QB
        $overCountQuery = $this->createMock(AbstractQuery::class);
        $overCountQuery->method('getSingleScalarResult')->willReturn(0);
        $overCountQb = $this->createMock(QueryBuilder::class);
        $overCountQb->method('select')->willReturnSelf();
        $overCountQb->method('from')->willReturnSelf();
        $overCountQb->method('where')->willReturnSelf();
        $overCountQb->method('andWhere')->willReturnSelf();
        $overCountQb->method('setParameter')->willReturnSelf();
        $overCountQb->method('getQuery')->willReturn($overCountQuery);

        $this->em
            ->expects($this->exactly(7))
            ->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls(
                $statsQb,
                $outListQb,
                $outCountQb,
                $lowListQb,
                $lowCountQb,
                $overListQb,
                $overCountQb,
            );

        $result = $this->tools->inventoryReport();

        $this->assertSame(100, $result['total_products']);
        $this->assertSame(50000.50, $result['total_stock_value']);
        $this->assertSame(1, $result['out_of_stock']['count']);
        $this->assertCount(1, $result['out_of_stock']['items']);
        $this->assertSame(10, $result['out_of_stock']['items'][0]['id']);
        $this->assertSame(0, $result['low_stock']['count']);
        $this->assertSame(5, $result['low_stock']['threshold']);
        $this->assertSame(0, $result['overstocked']['count']);
        $this->assertSame(500, $result['overstocked']['threshold']);
    }
}
