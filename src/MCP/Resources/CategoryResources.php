<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Resources;

use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Exception\ToolCallException;
use XC\MCP\MCP\Util\CategoryPathBuilder;
use XLite\Model\Category;
use XLite\Model\Product;

class CategoryResources
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    #[McpResource(
        uri: 'xcart://categories/tree',
        name: 'category_tree',
        title: 'Category Tree',
        description: 'Full category hierarchy as a tree with product counts',
        mimeType: 'application/json'
    )]
    public function getCategoryTree(): array
    {
        $qb = $this->em->createQueryBuilder();

        $categories = $qb
            ->select('c.category_id', 'ct.name', 'c.enabled', 'c.depth', 'c.lpos', 'c.rpos')
            ->addSelect('IDENTITY(c.parent) AS parent_id')
            ->from(Category::class, 'c')
            ->leftJoin('c.translations', 'ct', 'WITH', 'ct.code = :lang')
            ->setParameter('lang', \XC\MCP\MCP\Util\TableResolver::getDefaultLanguage())
            ->orderBy('c.lpos', 'ASC')
            ->getQuery()
            ->getResult();

        // Get product counts per category
        $productCounts = $this->em->createQueryBuilder()
            ->select('c.category_id', 'COUNT(cp.id) AS product_count')
            ->from(Category::class, 'c')
            ->leftJoin('c.categoryProducts', 'cp')
            ->groupBy('c.category_id')
            ->getQuery()
            ->getResult();

        $countsMap = [];
        foreach ($productCounts as $row) {
            $countsMap[$row['category_id']] = (int) $row['product_count'];
        }

        // Build flat map
        $flatMap = [];
        foreach ($categories as $cat) {
            $flatMap[$cat['category_id']] = [
                'id' => $cat['category_id'],
                'name' => $cat['name'],
                'product_count' => $countsMap[$cat['category_id']] ?? 0,
                'enabled' => (bool) $cat['enabled'],
                'parent_id' => $cat['parent_id'] ? (int) $cat['parent_id'] : null,
                'children' => [],
            ];
        }

        // Build tree
        $tree = [];
        foreach ($flatMap as $id => &$node) {
            $parentId = $node['parent_id'];
            unset($node['parent_id']);

            if ($parentId === null || !isset($flatMap[$parentId])) {
                $tree[] = &$node;
            } else {
                $flatMap[$parentId]['children'][] = &$node;
            }
        }
        unset($node);

        return [
            'categories' => $tree,
        ];
    }

    #[McpResourceTemplate(
        uriTemplate: 'xcart://categories/{categoryId}',
        name: 'category_detail',
        title: 'Category Detail',
        description: 'Category details with subcategories and product count',
        mimeType: 'application/json'
    )]
    public function getCategory(int $categoryId): array
    {
        $category = $this->em->getRepository(Category::class)->find($categoryId);

        if (!$category) {
            throw new ToolCallException("Category #{$categoryId} not found");
        }

        // Count products in this category
        $productCount = (int) $this->em->createQueryBuilder()
            ->select('COUNT(p.product_id)')
            ->from(Product::class, 'p')
            ->innerJoin('p.categoryProducts', 'cp')
            ->innerJoin('cp.category', 'c')
            ->where('c.category_id = :categoryId')
            ->setParameter('categoryId', $categoryId)
            ->getQuery()
            ->getSingleScalarResult();

        // Subcategories
        $subcategories = $this->em->createQueryBuilder()
            ->select('c.category_id', 'ct.name', 'c.enabled')
            ->from(Category::class, 'c')
            ->leftJoin('c.translations', 'ct', 'WITH', 'ct.code = :lang')
            ->where('IDENTITY(c.parent) = :parentId')
            ->setParameter('parentId', $categoryId)
            ->setParameter('lang', \XC\MCP\MCP\Util\TableResolver::getDefaultLanguage())
            ->orderBy('c.lpos', 'ASC')
            ->getQuery()
            ->getResult();

        $children = [];
        foreach ($subcategories as $sub) {
            $subProductCount = (int) $this->em->createQueryBuilder()
                ->select('COUNT(p.product_id)')
                ->from(Product::class, 'p')
                ->innerJoin('p.categoryProducts', 'cp')
                ->innerJoin('cp.category', 'c')
                ->where('c.category_id = :catId')
                ->setParameter('catId', $sub['category_id'])
                ->getQuery()
                ->getSingleScalarResult();

            $children[] = [
                'id' => $sub['category_id'],
                'name' => $sub['name'],
                'enabled' => (bool) $sub['enabled'],
                'product_count' => $subProductCount,
            ];
        }

        // Build path
        $path = CategoryPathBuilder::build($category);

        return [
            'id' => $category->getCategoryId(),
            'name' => $category->getName(),
            'description' => $category->getDescription(),
            'enabled' => $category->getEnabled(),
            'depth' => $category->getDepth(),
            'path' => $path,
            'product_count' => $productCount,
            'meta_title' => $category->getMetaTitle(),
            'meta_description' => $category->getMetaDesc(),
            'url' => $category->getCleanURL() ? '/' . $category->getCleanURL() : null,
            'image' => $category->getImage() ? [
                'url' => $category->getImage()->getURL(),
                'alt' => $category->getImage()->getAlt(),
            ] : null,
            'subcategories' => $children,
        ];
    }

    #[McpResourceTemplate(
        uriTemplate: 'xcart://categories/{categoryId}/products',
        name: 'category_products',
        title: 'Category Products',
        description: 'Products in a specific category (id, name, sku, price, stock)',
        mimeType: 'application/json'
    )]
    public function getCategoryProducts(int $categoryId): array
    {
        $category = $this->em->getRepository(Category::class)->find($categoryId);

        if (!$category) {
            throw new ToolCallException("Category #{$categoryId} not found");
        }

        $qb = $this->em->createQueryBuilder();

        $products = $qb
            ->select('p.product_id', 't.name', 'p.sku', 'p.price', 'p.amount', 'p.enabled')
            ->from(Product::class, 'p')
            ->leftJoin('p.translations', 't', 'WITH', 't.code = :lang')
            ->innerJoin('p.categoryProducts', 'cp')
            ->innerJoin('cp.category', 'c')
            ->where('c.category_id = :categoryId')
            ->setParameter('categoryId', $categoryId)
            ->setParameter('lang', \XC\MCP\MCP\Util\TableResolver::getDefaultLanguage())
            ->orderBy('p.product_id', 'DESC')
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();

        $totalCount = (int) $this->em->createQueryBuilder()
            ->select('COUNT(p.product_id)')
            ->from(Product::class, 'p')
            ->innerJoin('p.categoryProducts', 'cp')
            ->innerJoin('cp.category', 'c')
            ->where('c.category_id = :categoryId')
            ->setParameter('categoryId', $categoryId)
            ->getQuery()
            ->getSingleScalarResult();

        $items = [];
        foreach ($products as $row) {
            $items[] = [
                'id' => $row['product_id'],
                'name' => $row['name'],
                'sku' => $row['sku'],
                'price' => (float) $row['price'],
                'quantity' => (int) $row['amount'],
                'enabled' => (bool) $row['enabled'],
            ];
        }

        return [
            'category_id' => $categoryId,
            'category_name' => $category->getName(),
            'total' => $totalCount,
            'items' => $items,
            'limit' => 50,
        ];
    }

}
