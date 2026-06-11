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
use XC\MCP\MCP\Resources\CategoryResources;
use XLite\Model\Category;

class CategoryResourcesTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private EntityRepository&MockObject $categoryRepo;
    private CategoryResources $resources;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->categoryRepo = $this->createMock(EntityRepository::class);

        $this->em->method('getRepository')
            ->with(Category::class)
            ->willReturn($this->categoryRepo);

        $this->resources = new CategoryResources($this->em);
    }

    // ── getCategoryTree ──

    public function testGetCategoryTreeEmpty(): void
    {
        $catQuery = $this->createMock(AbstractQuery::class);
        $catQuery->method('getResult')->willReturn([]);

        $catQb = $this->createMock(QueryBuilder::class);
        $catQb->method('select')->willReturnSelf();
        $catQb->method('addSelect')->willReturnSelf();
        $catQb->method('from')->willReturnSelf();
        $catQb->method('orderBy')->willReturnSelf();
        $catQb->method('getQuery')->willReturn($catQuery);

        $countQuery = $this->createMock(AbstractQuery::class);
        $countQuery->method('getResult')->willReturn([]);

        $countQb = $this->createMock(QueryBuilder::class);
        $countQb->method('select')->willReturnSelf();
        $countQb->method('from')->willReturnSelf();
        $countQb->method('leftJoin')->willReturnSelf();
        $countQb->method('groupBy')->willReturnSelf();
        $countQb->method('getQuery')->willReturn($countQuery);

        $this->em
            ->expects($this->exactly(2))
            ->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls($catQb, $countQb);

        $result = $this->resources->getCategoryTree();

        $this->assertArrayHasKey('categories', $result);
        $this->assertEmpty($result['categories']);
    }

    public function testGetCategoryTreeBuildsFlatList(): void
    {
        $catQuery = $this->createMock(AbstractQuery::class);
        $catQuery->method('getResult')->willReturn([
            ['category_id' => 1, 'name' => 'Root', 'enabled' => true, 'depth' => 0, 'lpos' => 1, 'rpos' => 6, 'parent_id' => null],
            ['category_id' => 2, 'name' => 'Child', 'enabled' => true, 'depth' => 1, 'lpos' => 2, 'rpos' => 3, 'parent_id' => 1],
        ]);

        $catQb = $this->createMock(QueryBuilder::class);
        $catQb->method('select')->willReturnSelf();
        $catQb->method('addSelect')->willReturnSelf();
        $catQb->method('from')->willReturnSelf();
        $catQb->method('orderBy')->willReturnSelf();
        $catQb->method('getQuery')->willReturn($catQuery);

        $countQuery = $this->createMock(AbstractQuery::class);
        $countQuery->method('getResult')->willReturn([
            ['category_id' => 1, 'product_count' => '5'],
            ['category_id' => 2, 'product_count' => '3'],
        ]);

        $countQb = $this->createMock(QueryBuilder::class);
        $countQb->method('select')->willReturnSelf();
        $countQb->method('from')->willReturnSelf();
        $countQb->method('leftJoin')->willReturnSelf();
        $countQb->method('groupBy')->willReturnSelf();
        $countQb->method('getQuery')->willReturn($countQuery);

        $this->em
            ->expects($this->exactly(2))
            ->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls($catQb, $countQb);

        $result = $this->resources->getCategoryTree();

        $this->assertCount(1, $result['categories']);
        $this->assertSame('Root', $result['categories'][0]['name']);
        $this->assertSame(5, $result['categories'][0]['product_count']);
        $this->assertCount(1, $result['categories'][0]['children']);
        $this->assertSame('Child', $result['categories'][0]['children'][0]['name']);
        $this->assertSame(3, $result['categories'][0]['children'][0]['product_count']);
    }

    // ── getCategory ──

    public function testGetCategoryNotFound(): void
    {
        $this->categoryRepo
            ->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Category #999 not found');

        $this->resources->getCategory(999);
    }

    // ── getCategoryProducts ──

    public function testGetCategoryProductsNotFound(): void
    {
        $this->categoryRepo
            ->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Category #999 not found');

        $this->resources->getCategoryProducts(999);
    }

    public function testGetCategoryProducts(): void
    {
        $category = $this->createMock(Category::class);
        $category->method('getName')->willReturn('Electronics');

        $this->categoryRepo->method('find')->with(1)->willReturn($category);

        $productsQuery = $this->createMock(AbstractQuery::class);
        $productsQuery->method('getResult')->willReturn([
            ['product_id' => 10, 'name' => 'Widget', 'sku' => 'W-001', 'price' => '19.99', 'quantity' => '50', 'enabled' => true],
        ]);

        $productsQb = $this->createMock(QueryBuilder::class);
        $productsQb->method('select')->willReturnSelf();
        $productsQb->method('from')->willReturnSelf();
        $productsQb->method('innerJoin')->willReturnSelf();
        $productsQb->method('where')->willReturnSelf();
        $productsQb->method('setParameter')->willReturnSelf();
        $productsQb->method('orderBy')->willReturnSelf();
        $productsQb->method('setMaxResults')->willReturnSelf();
        $productsQb->method('getQuery')->willReturn($productsQuery);

        $countQuery = $this->createMock(AbstractQuery::class);
        $countQuery->method('getSingleScalarResult')->willReturn(1);

        $countQb = $this->createMock(QueryBuilder::class);
        $countQb->method('select')->willReturnSelf();
        $countQb->method('from')->willReturnSelf();
        $countQb->method('innerJoin')->willReturnSelf();
        $countQb->method('where')->willReturnSelf();
        $countQb->method('setParameter')->willReturnSelf();
        $countQb->method('getQuery')->willReturn($countQuery);

        $this->em
            ->expects($this->exactly(2))
            ->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls($productsQb, $countQb);

        $result = $this->resources->getCategoryProducts(1);

        $this->assertSame(1, $result['category_id']);
        $this->assertSame('Electronics', $result['category_name']);
        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['items']);
        $this->assertSame(10, $result['items'][0]['id']);
        $this->assertSame('Widget', $result['items'][0]['name']);
        $this->assertSame(19.99, $result['items'][0]['price']);
        $this->assertSame(50, $result['items'][0]['quantity']);
    }
}
