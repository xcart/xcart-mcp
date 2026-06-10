<?php

declare(strict_types=1);

namespace Tests\Unit\MCP\Tools;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use XC\MCP\MCP\Tools\SearchTools;

class SearchToolsTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private SearchTools $tools;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->tools = new SearchTools($this->em);
    }

    private function createQueryBuilderMock(mixed $result, bool $isSingleScalar = false): QueryBuilder&MockObject
    {
        $query = $this->createMock(AbstractQuery::class);

        if ($isSingleScalar) {
            $query->method('getSingleScalarResult')->willReturn($result);
        } else {
            $query->method('getResult')->willReturn($result);
        }

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('leftJoin')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        return $qb;
    }

    public function testGlobalSearchQueryTooShort(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Search query must be at least 2 characters long');

        $this->tools->globalSearch(query: 'a');
    }

    public function testGlobalSearchInvalidEntityType(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Invalid entity type 'invalid'");

        $this->tools->globalSearch(query: 'test', entityType: 'invalid');
    }

    public function testGlobalSearchProductsOnly(): void
    {
        $product = $this->createMock(\XLite\Model\Product::class);
        $product->method('getId')->willReturn(1);
        $product->method('getName')->willReturn('Widget');
        $product->method('getSku')->willReturn('WDG-001');
        $product->method('getPrice')->willReturn(15.0);

        // Result query builder (products)
        $resultQb = $this->createQueryBuilderMock([$product]);
        // Count query builder (products count)
        $countQb = $this->createQueryBuilderMock(1, isSingleScalar: true);

        $this->em
            ->expects($this->exactly(2))
            ->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls($resultQb, $countQb);

        $result = $this->tools->globalSearch(query: 'Widget', entityType: 'product');

        $this->assertSame('Widget', $result['query']);
        $this->assertCount(1, $result['results']['products']);
        $this->assertSame(1, $result['results']['products'][0]['id']);
        $this->assertSame('Widget', $result['results']['products'][0]['name']);
        $this->assertSame(1, $result['counts']['products']);
        $this->assertEmpty($result['results']['orders']);
        $this->assertEmpty($result['results']['categories']);
        $this->assertEmpty($result['results']['customers']);
    }

    public function testGlobalSearchTrimsQuery(): void
    {
        // 8 query builders: 4 entity types * 2 (result + count)
        $qbs = [];
        for ($i = 0; $i < 8; $i++) {
            $isSingleScalar = ($i % 2 === 1);
            $qbs[] = $this->createQueryBuilderMock(
                $isSingleScalar ? 0 : [],
                isSingleScalar: $isSingleScalar,
            );
        }

        $this->em
            ->expects($this->exactly(8))
            ->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls(...$qbs);

        $result = $this->tools->globalSearch(query: '  test  ');

        $this->assertSame('test', $result['query']);
    }

    public function testGlobalSearchLimitCappedAt50(): void
    {
        $qbs = [];
        for ($i = 0; $i < 8; $i++) {
            $isSingleScalar = ($i % 2 === 1);
            $qb = $this->createQueryBuilderMock(
                $isSingleScalar ? 0 : [],
                isSingleScalar: $isSingleScalar,
            );
            if (!$isSingleScalar) {
                $qb->expects($this->once())->method('setMaxResults')->with(50)->willReturnSelf();
            }
            $qbs[] = $qb;
        }

        $this->em
            ->expects($this->exactly(8))
            ->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls(...$qbs);

        $this->tools->globalSearch(query: 'test', limit: 100);
    }
}
