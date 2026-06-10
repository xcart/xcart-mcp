<?php

declare(strict_types=1);

namespace Tests\Unit\MCP\Tools;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use XC\MCP\MCP\Tools\ProductTools;
use XLite\Model\Product;

class ProductToolsTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private EntityRepository&MockObject $productRepo;
    private ProductTools $tools;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->productRepo = $this->createMock(EntityRepository::class);

        $this->em->method('getRepository')
            ->with(Product::class)
            ->willReturn($this->productRepo);

        $this->tools = new ProductTools($this->em);
    }

    public function testCreateProduct(): void
    {
        $this->productRepo
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['sku' => 'NEW-SKU-001'])
            ->willReturn(null);

        $this->em
            ->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(Product::class));

        $this->em
            ->expects($this->once())
            ->method('flush');

        $result = $this->tools->createProduct(
            name: 'Test Product',
            sku: 'NEW-SKU-001',
            price: 29.99,
            description: 'A test product',
            quantity: 100,
        );

        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('sku', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertSame('Test Product', $result['name']);
        $this->assertSame('NEW-SKU-001', $result['sku']);
        $this->assertSame('Product created successfully', $result['message']);
    }

    public function testCreateProductDuplicateSku(): void
    {
        $existingProduct = $this->createMock(Product::class);
        $existingProduct->method('getId')->willReturn(42);

        $this->productRepo
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['sku' => 'EXISTING-SKU'])
            ->willReturn($existingProduct);

        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("SKU 'EXISTING-SKU' already exists (product #42)");

        $this->tools->createProduct(
            name: 'Duplicate Product',
            sku: 'EXISTING-SKU',
            price: 19.99,
        );
    }

    public function testSearchProductsWithFilters(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(1);
        $product->method('getName')->willReturn('Widget');
        $product->method('getSku')->willReturn('WDG-001');
        $product->method('getPrice')->willReturn(15.0);
        $product->method('getQuantity')->willReturn(50);
        $product->method('getEnabled')->willReturn(true);

        $resultQuery = $this->createMock(AbstractQuery::class);
        $resultQuery->method('getResult')->willReturn([$product]);

        $countQuery = $this->createMock(AbstractQuery::class);
        $countQuery->method('getSingleScalarResult')->willReturn(1);

        $countQb = $this->createMock(QueryBuilder::class);
        $countQb->method('select')->willReturnSelf();
        $countQb->method('getQuery')->willReturn($countQuery);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('setFirstResult')->willReturnSelf();
        $qb->method('getQuery')->willReturn($resultQuery);

        $this->em
            ->expects($this->once())
            ->method('createQueryBuilder')
            ->willReturn($qb);

        // priceMin and priceMax filters should trigger 2 andWhere calls
        // (query is null so no keyword filter)
        $qb->expects($this->exactly(2))
            ->method('andWhere')
            ->willReturnSelf();

        $result = $this->tools->searchProducts(
            priceMin: 10.0,
            priceMax: 50.0,
            limit: 10,
            offset: 0,
        );

        $this->assertArrayHasKey('total_found', $result);
        $this->assertArrayHasKey('items', $result);
        $this->assertCount(1, $result['items']);
        $this->assertSame(1, $result['items'][0]['id']);
        $this->assertSame('Widget', $result['items'][0]['name']);
        $this->assertSame('WDG-001', $result['items'][0]['sku']);
        $this->assertSame(15.0, $result['items'][0]['price']);
    }

    public function testDeleteProductNotFound(): void
    {
        $this->productRepo
            ->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);

        $this->em->expects($this->never())->method('remove');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Product #999 not found');

        $this->tools->deleteProduct(999);
    }

    public function testUpdateStockRelative(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(1);
        $product->method('getName')->willReturn('Widget');
        $product->method('getSku')->willReturn('WDG-001');
        $product->method('getQuantity')->willReturn(10);
        $product->expects($this->once())->method('setQuantity')->with(7);

        $this->productRepo
            ->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($product);

        $this->em->expects($this->once())->method('flush');

        $result = $this->tools->updateStock(productId: 1, quantity: -3, relative: true);

        $this->assertSame(10, $result['old_quantity']);
        $this->assertSame(7, $result['new_quantity']);
        $this->assertSame('Stock updated', $result['message']);
    }

    public function testUpdateStockNegativeResult(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getQuantity')->willReturn(2);

        $this->productRepo
            ->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($product);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Stock cannot be negative');

        $this->tools->updateStock(productId: 1, quantity: -5, relative: true);
    }
}
