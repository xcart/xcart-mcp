<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use XC\MCP\MCP\Security\McpAuthorizer;
use XC\MCP\MCP\Security\ToolAnnotation;
use XC\MCP\MCP\Util\CategoryFactory;
use XC\MCP\MCP\Util\TableResolver;
use XLite\Model\Category;
use XLite\Model\Product;

class CategoryTools
{
    private CategoryFactory $categoryFactory;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly McpAuthorizer $authorizer,
        private readonly TableResolver $tableResolver,
    ) {
        $conn = $this->em->getConnection();
        $this->categoryFactory = new CategoryFactory(
            $conn,
            $this->tableResolver->resolve(Category::class),
            $this->tableResolver->resolve(\XLite\Model\CategoryTranslation::class),
        );
    }

    #[McpTool(
        name: 'category_create',
        title: 'Create Category',
        description: 'Create a new category. Optionally specify parent for subcategory.'
    )]
    #[ToolAnnotation(readOnlyHint: false)]
    public function createCategory(
        string $name,
        ?int $parentId = null,
        ?string $description = null,
        bool $enabled = true,
    ): array {
        $this->authorizer->authorizeTool('category_create');

        $cleanDesc = $description !== null
            ? strip_tags($description, '<p><br><b><i><strong><em><ul><ol><li><a><h1><h2><h3><h4><h5><h6><img><span><div>')
            : '';

        $categoryId = $this->categoryFactory->create(
            name: $name,
            parentId: $parentId,
            enabled: $enabled,
            description: $cleanDesc,
            lang: TableResolver::getDefaultLanguage(),
        );

        return [
            'id' => $categoryId,
            'name' => $name,
            'parent_id' => $parentId,
            'enabled' => $enabled,
            'message' => 'Category created successfully',
        ];
    }

    #[McpTool(
        name: 'category_update',
        title: 'Update Category',
        description: 'Update an existing category (name, description, enabled status, parent).'
    )]
    #[ToolAnnotation(readOnlyHint: false)]
    public function updateCategory(
        int $categoryId,
        ?string $name = null,
        ?string $description = null,
        ?bool $enabled = null,
        ?int $parentId = null,
    ): array {
        $this->authorizer->authorizeTool('category_update');

        $category = $this->em->getRepository(Category::class)->find($categoryId);
        if (!$category) {
            throw new ToolCallException("Category #{$categoryId} not found");
        }

        $changes = [];

        if ($name !== null) {
            $category->setName($name);
            $changes[] = 'name';
        }

        if ($description !== null) {
            $category->setDescription(strip_tags($description, '<p><br><b><i><strong><em><ul><ol><li><a><h1><h2><h3><h4><h5><h6><img><span><div>'));
            $changes[] = 'description';
        }

        if ($enabled !== null) {
            $category->setEnabled($enabled);
            $changes[] = 'enabled';
        }

        if ($parentId !== null) {
            if ($parentId === $categoryId) {
                throw new ToolCallException("Category cannot be its own parent");
            }
            $parent = $this->em->getRepository(Category::class)->find($parentId);
            if (!$parent) {
                throw new ToolCallException("Parent category #{$parentId} not found");
            }
            $category->setParent($parent);
            $changes[] = 'parent';
        }

        if (empty($changes)) {
            return [
                'id' => $category->getCategoryId(),
                'message' => 'No changes specified',
            ];
        }

        $this->em->flush();

        return [
            'id' => $category->getCategoryId(),
            'name' => $category->getName(),
            'enabled' => $category->getEnabled(),
            'parent_id' => $category->getParent()?->getCategoryId(),
            'changed' => $changes,
            'message' => 'Category updated',
        ];
    }

    #[McpTool(
        name: 'category_assign_product',
        title: 'Assign Product to Category',
        description: 'Assign a product to a category'
    )]
    #[ToolAnnotation(readOnlyHint: false, idempotentHint: true)]
    public function assignProductToCategory(
        int $productId,
        int $categoryId,
    ): array {
        $this->authorizer->authorizeTool('category_assign_product');

        $product = $this->em->getRepository(Product::class)->find($productId);
        if (!$product) {
            throw new ToolCallException("Product #{$productId} not found");
        }

        $category = $this->em->getRepository(Category::class)->find($categoryId);
        if (!$category) {
            throw new ToolCallException("Category #{$categoryId} not found");
        }

        // Check if already assigned
        if ($product->getCategories()->contains($category)) {
            return [
                'product_id' => $productId,
                'category_id' => $categoryId,
                'message' => 'Product is already assigned to this category',
            ];
        }

        $product->addCategory($category);
        $this->em->flush();

        return [
            'product_id' => $product->getId(),
            'product_name' => $product->getName(),
            'category_id' => $category->getId(),
            'category_name' => $category->getName(),
            'message' => 'Product assigned to category',
        ];
    }

    #[McpTool(
        name: 'category_remove_product',
        title: 'Remove Product from Category',
        description: 'Remove a product from a category'
    )]
    #[ToolAnnotation(readOnlyHint: false)]
    public function removeProductFromCategory(
        int $productId,
        int $categoryId,
    ): array {
        $this->authorizer->authorizeTool('category_remove_product');

        $product = $this->em->getRepository(Product::class)->find($productId);
        if (!$product) {
            throw new ToolCallException("Product #{$productId} not found");
        }

        $category = $this->em->getRepository(Category::class)->find($categoryId);
        if (!$category) {
            throw new ToolCallException("Category #{$categoryId} not found");
        }

        if (!$product->getCategories()->contains($category)) {
            throw new ToolCallException(
                "Product #{$productId} is not assigned to category #{$categoryId}"
            );
        }

        $product->removeCategory($category);
        $this->em->flush();

        return [
            'product_id' => $product->getId(),
            'product_name' => $product->getName(),
            'category_id' => $category->getId(),
            'category_name' => $category->getName(),
            'message' => 'Product removed from category',
        ];
    }
}
