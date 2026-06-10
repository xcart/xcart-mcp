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

class ProductResources
{
    public const LOW_STOCK_THRESHOLD = 5;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    #[McpResource(
        uri: 'xcart://products/list',
        name: 'product_list',
        title: 'Product List',
        description: 'List of enabled products: id, name, sku, price, stock quantity. Limited to 50 items. Use product_search tool for filtering.',
        mimeType: 'application/json'
    )]
    public function listProducts(): array
    {
        $total = (int) $this->em->createQueryBuilder()
            ->select('COUNT(p.product_id)')
            ->from(Product::class, 'p')
            ->where('p.enabled = :enabled')
            ->setParameter('enabled', true)
            ->getQuery()
            ->getSingleScalarResult();

        $products = $this->em->createQueryBuilder()
            ->select('p.product_id', 'p.sku', 'p.price', 'p.amount', 'p.enabled')
            ->addSelect('t.name')
            ->addSelect('ct.name AS category_name')
            ->from(Product::class, 'p')
            ->leftJoin('p.translations', 't', 'WITH', 't.code = :lang')
            ->leftJoin('p.categoryProducts', 'cp')
            ->leftJoin('cp.category', 'c')
            ->leftJoin('c.translations', 'ct', 'WITH', 'ct.code = :lang')
            ->where('p.enabled = :enabled')
            ->setParameter('enabled', true)
            ->setParameter('lang', \XC\MCP\MCP\Util\TableResolver::getDefaultLanguage())
            ->orderBy('p.product_id', 'DESC')
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();

        $items = [];
        $seen = [];

        foreach ($products as $row) {
            $id = $row['product_id'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $items[] = [
                'id' => $id,
                'name' => $row['name'],
                'sku' => $row['sku'],
                'price' => (float) $row['price'],
                'quantity' => (int) $row['amount'],
                'enabled' => (bool) $row['enabled'],
                'category' => $row['category_name'],
            ];
        }

        return [
            'total' => $total,
            'items' => $items,
            'limit' => 50,
            'note' => 'Use product_search tool with filters for specific products',
        ];
    }

    #[McpResourceTemplate(
        uriTemplate: 'xcart://products/{productId}',
        name: 'product_detail',
        title: 'Product Detail',
        description: 'Full product details: name, description, price, stock, images, categories, attributes',
        mimeType: 'application/json'
    )]
    public function getProduct(int $productId): array
    {
        $product = $this->em->getRepository(Product::class)->find($productId);

        if (!$product) {
            throw new ToolCallException("Product #{$productId} not found");
        }

        $categories = [];
        foreach ($product->getCategoryProducts() as $cp) {
            $category = $cp->getCategory();
            $categories[] = [
                'id' => $category->getCategoryId(),
                'name' => $category->getName(),
                'path' => CategoryPathBuilder::build($category),
            ];
        }

        $images = [];
        foreach ($product->getImages() as $image) {
            $images[] = [
                'id' => $image->getId(),
                'url' => $image->getURL(),
                'alt' => $image->getAlt(),
                'position' => $image->getOrderby(),
            ];
        }

        $attributes = [];
        foreach ($product->getAttributeValues() as $attrValue) {
            $attribute = $attrValue->getAttribute();
            $group = $attribute->getAttributeGroup();

            $attributes[] = [
                'name' => $attribute->getName(),
                'value' => (string) $attrValue->getValue(),
                'group' => $group ? $group->getName() : null,
            ];
        }

        return [
            'id' => $product->getProductId(),
            'name' => $product->getName(),
            'sku' => $product->getSku(),
            'price' => (float) $product->getPrice(),
            'sale_price' => $product->getSalePrice() ? (float) $product->getSalePrice() : null,
            'quantity' => (int) $product->getAmount(),
            'weight' => (float) $product->getWeight(),
            'enabled' => $product->getEnabled(),
            'description' => $product->getDescription(),
            'brief_description' => $product->getBriefDescription(),
            'meta_title' => $product->getMetaTitle(),
            'meta_description' => $product->getMetaDesc(),
            'created_at' => $product->getDate()
                ? date('c', $product->getDate())
                : null,
            'updated_at' => $product->getUpdateDate()
                ? date('c', $product->getUpdateDate())
                : null,
            'categories' => $categories,
            'images' => $images,
            'attributes' => $attributes,
            'url' => $product->getCleanURL() ? '/' . $product->getCleanURL() : null,
        ];
    }

    #[McpResource(
        uri: 'xcart://products/stats',
        name: 'product_stats',
        title: 'Product Stats',
        description: 'Product catalog statistics: total count, enabled/disabled, in stock/out of stock, price range, categories count',
        mimeType: 'application/json'
    )]
    public function getProductStats(): array
    {
        $stats = $this->em->createQueryBuilder()
            ->select(
                'COUNT(p.product_id) AS total',
                'SUM(CASE WHEN p.enabled = true THEN 1 ELSE 0 END) AS enabled',
                'SUM(CASE WHEN p.enabled = false THEN 1 ELSE 0 END) AS disabled',
                'SUM(CASE WHEN p.amount > 0 THEN 1 ELSE 0 END) AS in_stock',
                'SUM(CASE WHEN p.amount <= 0 THEN 1 ELSE 0 END) AS out_of_stock',
                'MIN(p.price) AS price_min',
                'MAX(p.price) AS price_max',
                'AVG(p.price) AS price_avg',
            )
            ->from(Product::class, 'p')
            ->getQuery()
            ->getSingleResult();

        $categoriesCount = (int) $this->em->createQueryBuilder()
            ->select('COUNT(DISTINCT c.category_id)')
            ->from(Category::class, 'c')
            ->where('c.enabled = :enabled')
            ->setParameter('enabled', true)
            ->getQuery()
            ->getSingleScalarResult();

        $withImages = (int) $this->em->createQueryBuilder()
            ->select('COUNT(DISTINCT p.product_id)')
            ->from(Product::class, 'p')
            ->innerJoin('p.images', 'i')
            ->getQuery()
            ->getSingleScalarResult();

        $lastAdded = $this->em->createQueryBuilder()
            ->select('MAX(p.date)')
            ->from(Product::class, 'p')
            ->getQuery()
            ->getSingleScalarResult();

        $total = (int) $stats['total'];

        return [
            'total' => $total,
            'enabled' => (int) $stats['enabled'],
            'disabled' => (int) $stats['disabled'],
            'in_stock' => (int) $stats['in_stock'],
            'out_of_stock' => (int) $stats['out_of_stock'],
            'price_min' => round((float) $stats['price_min'], 2),
            'price_max' => round((float) $stats['price_max'], 2),
            'price_avg' => round((float) $stats['price_avg'], 2),
            'categories_count' => $categoriesCount,
            'with_images' => $withImages,
            'without_images' => $total - $withImages,
            'last_added' => $lastAdded ? date('c', (int) $lastAdded) : null,
        ];
    }

    #[McpResource(
        uri: 'xcart://products/low-stock',
        name: 'low_stock_products',
        title: 'Low Stock Products',
        description: 'Products with quantity <= 5 (low stock threshold). Sorted by quantity ascending.',
        mimeType: 'application/json'
    )]
    public function getLowStockProducts(): array
    {
        $threshold = self::LOW_STOCK_THRESHOLD;

        $products = $this->em->createQueryBuilder()
            ->select('p.product_id', 't.name', 'p.sku', 'p.amount', 'p.price')
            ->from(Product::class, 'p')
            ->leftJoin('p.translations', 't', 'WITH', 't.code = :lang')
            ->where('p.amount <= :threshold')
            ->andWhere('p.enabled = :enabled')
            ->setParameter('threshold', $threshold)
            ->setParameter('enabled', true)
            ->setParameter('lang', \XC\MCP\MCP\Util\TableResolver::getDefaultLanguage())
            ->orderBy('p.amount', 'ASC')
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();

        $items = [];
        foreach ($products as $row) {
            $items[] = [
                'id' => $row['product_id'],
                'name' => $row['name'],
                'sku' => $row['sku'],
                'quantity' => (int) $row['amount'],
                'price' => (float) $row['price'],
            ];
        }

        return [
            'threshold' => $threshold,
            'count' => count($items),
            'items' => $items,
        ];
    }

}
