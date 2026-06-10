<?php

declare(strict_types=1);

namespace Tests\Unit\MCP\Tools;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use XC\MCP\MCP\Tools\CategoryTools;
use XLite\Model\Category;
use XLite\Model\Product;

class CategoryToolsTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private EntityRepository&MockObject $categoryRepo;
    private EntityRepository&MockObject $productRepo;
    private CategoryTools $tools;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->categoryRepo = $this->createMock(EntityRepository::class);
        $this->productRepo = $this->createMock(EntityRepository::class);

        $this->em->method('getRepository')
            ->willReturnCallback(function (string $class) {
                return match ($class) {
                    Category::class => $this->categoryRepo,
                    Product::class => $this->productRepo,
                    default => $this->createMock(EntityRepository::class),
                };
            });

        $this->tools = new CategoryTools($this->em);
    }

    // ── createCategory ──

    public function testCreateCategoryWithoutParent(): void
    {
        $this->em->expects($this->once())->method('persist')
            ->with($this->isInstanceOf(Category::class));
        $this->em->expects($this->once())->method('flush');

        $result = $this->tools->createCategory(
            name: 'Electronics',
            description: 'Electronic goods',
            enabled: true,
        );

        $this->assertSame('Electronics', $result['name']);
        $this->assertNull($result['parent_id']);
        $this->assertSame('Category created successfully', $result['message']);
    }

    public function testCreateCategoryWithParent(): void
    {
        $parent = $this->createMock(Category::class);
        $parent->method('getId')->willReturn(10);

        $this->categoryRepo
            ->expects($this->once())
            ->method('find')
            ->with(10)
            ->willReturn($parent);

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $result = $this->tools->createCategory(
            name: 'Laptops',
            parentId: 10,
        );

        $this->assertSame('Laptops', $result['name']);
        $this->assertSame(10, $result['parent_id']);
        $this->assertSame('Category created successfully', $result['message']);
    }

    public function testCreateCategoryParentNotFound(): void
    {
        $this->categoryRepo
            ->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);

        $this->em->expects($this->never())->method('persist');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Parent category #999 not found');

        $this->tools->createCategory(name: 'Sub', parentId: 999);
    }

    // ── assignProductToCategory ──

    public function testAssignProductToCategory(): void
    {
        $category = $this->createMock(Category::class);
        $category->method('getId')->willReturn(5);
        $category->method('getName')->willReturn('Shoes');

        $categories = new ArrayCollection();

        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(1);
        $product->method('getName')->willReturn('Sneaker');
        $product->method('getCategories')->willReturn($categories);
        $product->expects($this->once())->method('addCategory')->with($category);

        $this->productRepo->method('find')->with(1)->willReturn($product);
        $this->categoryRepo->method('find')->with(5)->willReturn($category);

        $this->em->expects($this->once())->method('flush');

        $result = $this->tools->assignProductToCategory(productId: 1, categoryId: 5);

        $this->assertSame(1, $result['product_id']);
        $this->assertSame(5, $result['category_id']);
        $this->assertSame('Product assigned to category', $result['message']);
    }

    public function testAssignProductAlreadyInCategory(): void
    {
        $category = $this->createMock(Category::class);
        $category->method('getId')->willReturn(5);

        $categories = new ArrayCollection([$category]);

        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCategories')->willReturn($categories);

        $this->productRepo->method('find')->with(1)->willReturn($product);
        $this->categoryRepo->method('find')->with(5)->willReturn($category);

        $result = $this->tools->assignProductToCategory(productId: 1, categoryId: 5);

        $this->assertSame('Product is already assigned to this category', $result['message']);
    }

    public function testAssignProductNotFound(): void
    {
        $this->productRepo->method('find')->with(999)->willReturn(null);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Product #999 not found');

        $this->tools->assignProductToCategory(productId: 999, categoryId: 1);
    }

    public function testAssignCategoryNotFound(): void
    {
        $product = $this->createMock(Product::class);
        $this->productRepo->method('find')->with(1)->willReturn($product);
        $this->categoryRepo->method('find')->with(999)->willReturn(null);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Category #999 not found');

        $this->tools->assignProductToCategory(productId: 1, categoryId: 999);
    }

    // ── removeProductFromCategory ──

    public function testRemoveProductFromCategory(): void
    {
        $category = $this->createMock(Category::class);
        $category->method('getId')->willReturn(5);
        $category->method('getName')->willReturn('Shoes');

        $categories = new ArrayCollection([$category]);

        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(1);
        $product->method('getName')->willReturn('Sneaker');
        $product->method('getCategories')->willReturn($categories);
        $product->expects($this->once())->method('removeCategory')->with($category);

        $this->productRepo->method('find')->with(1)->willReturn($product);
        $this->categoryRepo->method('find')->with(5)->willReturn($category);

        $this->em->expects($this->once())->method('flush');

        $result = $this->tools->removeProductFromCategory(productId: 1, categoryId: 5);

        $this->assertSame('Product removed from category', $result['message']);
    }

    public function testRemoveProductNotInCategory(): void
    {
        $category = $this->createMock(Category::class);
        $category->method('getId')->willReturn(5);

        $categories = new ArrayCollection();

        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCategories')->willReturn($categories);

        $this->productRepo->method('find')->with(1)->willReturn($product);
        $this->categoryRepo->method('find')->with(5)->willReturn($category);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Product #1 is not assigned to category #5');

        $this->tools->removeProductFromCategory(productId: 1, categoryId: 5);
    }
}
