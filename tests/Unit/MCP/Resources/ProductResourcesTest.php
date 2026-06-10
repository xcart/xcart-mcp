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
use XC\MCP\MCP\Resources\ProductResources;
use XLite\Model\Product;

class ProductResourcesTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private ProductResources $resources;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->resources = new ProductResources($this->em);
    }

    public function testListProductsReturnsLimitedResults(): void
    {
        $countQuery = $this->createMock(AbstractQuery::class);
        $countQuery->method('getSingleScalarResult')->willReturn(120);

        $listQuery = $this->createMock(AbstractQuery::class);
        $listQuery->method('getResult')->willReturn([
            ['product_id' => 1, 'name' => 'Product A', 'sku' => 'SKU-A', 'price' => '10.00', 'quantity' => '50', 'enabled' => true, 'category_name' => 'Electronics'],
            ['product_id' => 2, 'name' => 'Product B', 'sku' => 'SKU-B', 'price' => '20.00', 'quantity' => '30', 'enabled' => true, 'category_name' => 'Clothing'],
            ['product_id' => 3, 'name' => 'Product C', 'sku' => 'SKU-C', 'price' => '30.00', 'quantity' => '0', 'enabled' => true, 'category_name' => null],
        ]);

        $countQb = $this->createMock(QueryBuilder::class);
        $countQb->method('select')->willReturnSelf();
        $countQb->method('from')->willReturnSelf();
        $countQb->method('where')->willReturnSelf();
        $countQb->method('setParameter')->willReturnSelf();
        $countQb->method('getQuery')->willReturn($countQuery);

        $listQb = $this->createMock(QueryBuilder::class);
        $listQb->method('select')->willReturnSelf();
        $listQb->method('addSelect')->willReturnSelf();
        $listQb->method('from')->willReturnSelf();
        $listQb->method('leftJoin')->willReturnSelf();
        $listQb->method('where')->willReturnSelf();
        $listQb->method('setParameter')->willReturnSelf();
        $listQb->method('orderBy')->willReturnSelf();
        $listQb->method('setMaxResults')->willReturnSelf();
        $listQb->method('getQuery')->willReturn($listQuery);

        $this->em
            ->expects($this->exactly(2))
            ->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls($countQb, $listQb);

        $result = $this->resources->listProducts();

        $this->assertSame(120, $result['total']);
        $this->assertSame(50, $result['limit']);
        $this->assertCount(3, $result['items']);
        $this->assertSame(1, $result['items'][0]['id']);
        $this->assertSame('Product A', $result['items'][0]['name']);
        $this->assertSame('SKU-A', $result['items'][0]['sku']);
        $this->assertSame(10.0, $result['items'][0]['price']);
        $this->assertSame(50, $result['items'][0]['quantity']);
        $this->assertSame('Electronics', $result['items'][0]['category']);
    }

    public function testGetProductNotFound(): void
    {
        $productRepo = $this->createMock(EntityRepository::class);
        $productRepo
            ->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);

        $this->em->method('getRepository')
            ->with(Product::class)
            ->willReturn($productRepo);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Product #999 not found');

        $this->resources->getProduct(999);
    }

    public function testGetProductStats(): void
    {
        $statsQuery = $this->createMock(AbstractQuery::class);
        $statsQuery->method('getSingleResult')->willReturn([
            'total' => '150',
            'enabled' => '130',
            'disabled' => '20',
            'in_stock' => '120',
            'out_of_stock' => '30',
            'price_min' => '5.99',
            'price_max' => '299.99',
            'price_avg' => '45.678',
        ]);

        $categoriesQuery = $this->createMock(AbstractQuery::class);
        $categoriesQuery->method('getSingleScalarResult')->willReturn(25);

        $withImagesQuery = $this->createMock(AbstractQuery::class);
        $withImagesQuery->method('getSingleScalarResult')->willReturn(100);

        $lastAddedQuery = $this->createMock(AbstractQuery::class);
        $lastAddedQuery->method('getSingleScalarResult')->willReturn(1706000000);

        $statsQb = $this->createMock(QueryBuilder::class);
        $statsQb->method('select')->willReturnSelf();
        $statsQb->method('from')->willReturnSelf();
        $statsQb->method('getQuery')->willReturn($statsQuery);

        $categoriesQb = $this->createMock(QueryBuilder::class);
        $categoriesQb->method('select')->willReturnSelf();
        $categoriesQb->method('from')->willReturnSelf();
        $categoriesQb->method('where')->willReturnSelf();
        $categoriesQb->method('setParameter')->willReturnSelf();
        $categoriesQb->method('getQuery')->willReturn($categoriesQuery);

        $imagesQb = $this->createMock(QueryBuilder::class);
        $imagesQb->method('select')->willReturnSelf();
        $imagesQb->method('from')->willReturnSelf();
        $imagesQb->method('innerJoin')->willReturnSelf();
        $imagesQb->method('getQuery')->willReturn($withImagesQuery);

        $lastAddedQb = $this->createMock(QueryBuilder::class);
        $lastAddedQb->method('select')->willReturnSelf();
        $lastAddedQb->method('from')->willReturnSelf();
        $lastAddedQb->method('getQuery')->willReturn($lastAddedQuery);

        $this->em
            ->expects($this->exactly(4))
            ->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls($statsQb, $categoriesQb, $imagesQb, $lastAddedQb);

        $result = $this->resources->getProductStats();

        $this->assertSame(150, $result['total']);
        $this->assertSame(130, $result['enabled']);
        $this->assertSame(20, $result['disabled']);
        $this->assertSame(120, $result['in_stock']);
        $this->assertSame(30, $result['out_of_stock']);
        $this->assertSame(5.99, $result['price_min']);
        $this->assertSame(299.99, $result['price_max']);
        $this->assertSame(45.68, $result['price_avg']);
        $this->assertSame(25, $result['categories_count']);
        $this->assertSame(100, $result['with_images']);
        $this->assertSame(50, $result['without_images']);
    }

    public function testGetLowStockProducts(): void
    {
        $query = $this->createMock(AbstractQuery::class);
        $query->method('getResult')->willReturn([
            ['product_id' => 10, 'name' => 'Low Stock Item', 'sku' => 'LOW-001', 'quantity' => '2', 'price' => '15.00'],
            ['product_id' => 20, 'name' => 'Almost Out', 'sku' => 'LOW-002', 'quantity' => '1', 'price' => '25.00'],
        ]);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $this->em
            ->expects($this->once())
            ->method('createQueryBuilder')
            ->willReturn($qb);

        $result = $this->resources->getLowStockProducts();

        $this->assertSame(5, $result['threshold']);
        $this->assertSame(2, $result['count']);
        $this->assertCount(2, $result['items']);
        $this->assertSame(10, $result['items'][0]['id']);
        $this->assertSame('Low Stock Item', $result['items'][0]['name']);
        $this->assertSame('LOW-001', $result['items'][0]['sku']);
        $this->assertSame(2, $result['items'][0]['quantity']);
        $this->assertSame(15.0, $result['items'][0]['price']);
        $this->assertSame(20, $result['items'][1]['id']);
        $this->assertSame(1, $result['items'][1]['quantity']);
    }
}
