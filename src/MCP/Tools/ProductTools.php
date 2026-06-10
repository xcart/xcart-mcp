<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use XC\MCP\MCP\Security\McpAuthorizer;
use XC\MCP\MCP\Security\ToolAnnotation;
use XC\MCP\MCP\Util\QueryHelper;
use XC\MCP\MCP\Util\TableResolver;
use XLite\Model\Category;
use XLite\Model\Product;

class ProductTools
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly McpAuthorizer $authorizer,
    ) {}

    private function lang(): string
    {
        return TableResolver::getDefaultLanguage();
    }

    #[McpTool(
        name: 'product_create',
        title: 'Create Product',
        description: 'Create a new product in the catalog'
    )]
    #[ToolAnnotation(readOnlyHint: false)]
    public function createProduct(
        string $name,
        string $sku,
        float $price,
        ?string $description = null,
        ?string $briefDescription = null,
        ?int $categoryId = null,
        ?int $quantity = null,
        ?float $weight = null,
        bool $enabled = true,
    ): array {
        $this->authorizer->authorizeTool('product_create');

        // Check SKU uniqueness
        $existing = $this->em->getRepository(Product::class)->findOneBy(['sku' => $sku]);
        if ($existing) {
            throw new ToolCallException("SKU '{$sku}' already exists (product #{$existing->getId()})");
        }

        // Check category exists if given
        $category = null;
        if ($categoryId !== null) {
            $category = $this->em->getRepository(Category::class)->find($categoryId);
            if (!$category) {
                throw new ToolCallException("Category #{$categoryId} not found");
            }
        }

        $product = new Product();
        $product->setName($name);
        $product->setSku($sku);
        $product->setPrice($price);
        $product->setEnabled($enabled);

        if ($description !== null) {
            $product->setDescription(strip_tags($description, '<p><br><b><i><strong><em><ul><ol><li><a><h1><h2><h3><h4><h5><h6><table><tr><td><th><thead><tbody><img><span><div>'));
        }
        if ($briefDescription !== null) {
            $product->setBriefDescription(strip_tags($briefDescription, '<p><br><b><i><strong><em><ul><ol><li><a>'));
        }
        if ($quantity !== null) {
            $product->setQuantity($quantity);
        }
        if ($weight !== null) {
            $product->setWeight($weight);
        }

        if ($category !== null) {
            $product->addCategory($category);
        }

        $this->em->persist($product);
        $this->em->flush();

        return [
            'id' => $product->getId(),
            'name' => $product->getName(),
            'sku' => $product->getSku(),
            'price' => $product->getPrice(),
            'enabled' => $product->getEnabled(),
            'message' => 'Product created successfully',
        ];
    }

    #[McpTool(
        name: 'product_update',
        title: 'Update Product',
        description: 'Update an existing product. Only provided fields will be changed.'
    )]
    #[ToolAnnotation(readOnlyHint: false, idempotentHint: true)]
    public function updateProduct(
        int $productId,
        ?string $name = null,
        ?string $sku = null,
        ?float $price = null,
        ?string $description = null,
        ?string $briefDescription = null,
        ?int $quantity = null,
        ?float $weight = null,
        ?bool $enabled = null,
    ): array {
        $this->authorizer->authorizeTool('product_update');

        $product = $this->em->getRepository(Product::class)->find($productId);
        if (!$product) {
            throw new ToolCallException("Product #{$productId} not found");
        }

        // Check SKU uniqueness if changed
        if ($sku !== null && $sku !== $product->getSku()) {
            $existing = $this->em->getRepository(Product::class)->findOneBy(['sku' => $sku]);
            if ($existing) {
                throw new ToolCallException("SKU '{$sku}' already exists (product #{$existing->getId()})");
            }
            $product->setSku($sku);
        }

        if ($name !== null) {
            $product->setName($name);
        }
        if ($price !== null) {
            $product->setPrice($price);
        }
        if ($description !== null) {
            $product->setDescription(strip_tags($description, '<p><br><b><i><strong><em><ul><ol><li><a><h1><h2><h3><h4><h5><h6><table><tr><td><th><thead><tbody><img><span><div>'));
        }
        if ($briefDescription !== null) {
            $product->setBriefDescription(strip_tags($briefDescription, '<p><br><b><i><strong><em><ul><ol><li><a>'));
        }
        if ($quantity !== null) {
            $product->setQuantity($quantity);
        }
        if ($weight !== null) {
            $product->setWeight($weight);
        }
        if ($enabled !== null) {
            $product->setEnabled($enabled);
        }

        $this->em->flush();

        return [
            'id' => $product->getId(),
            'name' => $product->getName(),
            'sku' => $product->getSku(),
            'price' => $product->getPrice(),
            'quantity' => $product->getQuantity(),
            'enabled' => $product->getEnabled(),
            'message' => 'Product updated successfully',
        ];
    }

    #[McpTool(
        name: 'product_search',
        title: 'Search Products',
        description: 'Search products by name, SKU, price range, category, stock status. Returns up to {limit} results.'
    )]
    #[ToolAnnotation(readOnlyHint: true)]
    public function searchProducts(
        ?string $query = null,
        ?string $sku = null,
        ?float $priceMin = null,
        ?float $priceMax = null,
        ?int $categoryId = null,
        ?bool $inStock = null,
        ?bool $enabled = null,
        string $sortBy = 'name',
        string $sortOrder = 'asc',
        int $limit = 20,
        int $offset = 0,
    ): array {
        $this->authorizer->authorizeTool('product_search');

        $limit = min($limit, 100);

        $qb = $this->em->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p');

        $qb->leftJoin('p.translations', 't', 'WITH', 't.code = :lang')
            ->setParameter('lang', $this->lang());

        if ($query !== null) {
            $qb->andWhere('t.name LIKE :query OR t.description LIKE :query OR p.sku LIKE :query')
                ->setParameter('query', QueryHelper::likeContains($query));
        }

        if ($sku !== null) {
            $qb->andWhere('p.sku LIKE :sku')
                ->setParameter('sku', QueryHelper::likeContains($sku));
        }

        if ($priceMin !== null) {
            $qb->andWhere('p.price >= :priceMin')
                ->setParameter('priceMin', $priceMin);
        }

        if ($priceMax !== null) {
            $qb->andWhere('p.price <= :priceMax')
                ->setParameter('priceMax', $priceMax);
        }

        if ($categoryId !== null) {
            $qb->innerJoin('p.categoryProducts', 'cp')
                ->innerJoin('cp.category', 'c')
                ->andWhere('c.category_id = :categoryId')
                ->setParameter('categoryId', $categoryId);
        }

        if ($inStock === true) {
            $qb->andWhere('p.amount > 0');
        } elseif ($inStock === false) {
            $qb->andWhere('p.amount = 0');
        }

        if ($enabled !== null) {
            $qb->andWhere('p.enabled = :enabled')
                ->setParameter('enabled', $enabled);
        }

        // Count total before pagination
        $countQb = clone $qb;
        $countQb->select('COUNT(p.product_id)');
        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        // Sorting
        $allowedSortFields = [
            'name' => 't.name',
            'price' => 'p.price',
            'quantity' => 'p.amount',
            'created_at' => 'p.date',
        ];
        $sortField = $allowedSortFields[$sortBy] ?? 't.name';
        $sortDir = strtolower($sortOrder) === 'desc' ? 'DESC' : 'ASC';
        $qb->orderBy($sortField, $sortDir);

        $qb->setMaxResults($limit)
            ->setFirstResult($offset);

        $products = $qb->getQuery()->getResult();

        $items = [];
        foreach ($products as $product) {
            $items[] = [
                'id' => $product->getId(),
                'name' => $product->getName(),
                'sku' => $product->getSku(),
                'price' => $product->getPrice(),
                'quantity' => $product->getQuantity(),
                'enabled' => $product->getEnabled(),
            ];
        }

        return [
            'query' => $query,
            'total_found' => $total,
            'items' => $items,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    #[McpTool(
        name: 'product_delete',
        title: 'Delete Product',
        description: 'Delete a product by ID. This action is irreversible.'
    )]
    #[ToolAnnotation(destructiveHint: true)]
    public function deleteProduct(int $productId): array
    {
        $this->authorizer->authorizeTool('product_delete');

        $product = $this->em->getRepository(Product::class)->find($productId);
        if (!$product) {
            throw new ToolCallException("Product #{$productId} not found");
        }

        $name = $product->getName();
        $sku = $product->getSku();

        $this->em->remove($product);
        $this->em->flush();

        return [
            'id' => $productId,
            'name' => $name,
            'sku' => $sku,
            'message' => 'Product deleted',
        ];
    }

    #[McpTool(
        name: 'product_update_stock',
        title: 'Update Product Stock',
        description: 'Update product stock quantity. Use relative=false for absolute value, relative=true for +/- adjustment.'
    )]
    #[ToolAnnotation(readOnlyHint: false)]
    public function updateStock(
        int $productId,
        int $quantity,
        bool $relative = false,
    ): array {
        $this->authorizer->authorizeTool('product_update_stock');

        $product = $this->em->getRepository(Product::class)->find($productId);
        if (!$product) {
            throw new ToolCallException("Product #{$productId} not found");
        }

        $oldQuantity = $product->getQuantity();

        if ($relative) {
            $newQuantity = $oldQuantity + $quantity;
        } else {
            $newQuantity = $quantity;
        }

        if ($newQuantity < 0) {
            throw new ToolCallException(
                "Stock cannot be negative. Current: {$oldQuantity}, requested change: {$quantity}, would result in: {$newQuantity}"
            );
        }

        $product->setQuantity($newQuantity);
        $this->em->flush();

        return [
            'id' => $product->getId(),
            'name' => $product->getName(),
            'sku' => $product->getSku(),
            'old_quantity' => $oldQuantity,
            'new_quantity' => $newQuantity,
            'message' => 'Stock updated',
        ];
    }

    #[McpTool(
        name: 'product_bulk_update_prices',
        title: 'Bulk Update Product Prices',
        description: 'Update prices for multiple products by percentage. Positive = increase, negative = decrease.'
    )]
    #[ToolAnnotation(destructiveHint: true)]
    /**
     * @param int[] $productIds
     */
    public function bulkUpdatePrices(
        array $productIds,
        float $percentChange,
    ): array {
        $this->authorizer->authorizeTool('product_bulk_update_prices');

        $ids = array_filter(
            array_map('intval', $productIds),
            static fn(int $id): bool => $id > 0,
        );

        if (empty($ids)) {
            throw new ToolCallException('No valid product IDs provided. Expected array of integers, e.g. [1, 2, 3]');
        }

        $products = $this->em->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->where('p.product_id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        if (empty($products)) {
            throw new ToolCallException('No products found for the given IDs');
        }

        $multiplier = 1 + ($percentChange / 100);
        $updated = [];

        foreach ($products as $product) {
            $oldPrice = $product->getPrice();
            $newPrice = round($oldPrice * $multiplier, 2);

            if ($newPrice < 0) {
                $newPrice = 0.0;
            }

            $product->setPrice($newPrice);
            $updated[] = [
                'id' => $product->getId(),
                'name' => $product->getName(),
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
            ];
        }

        $this->em->flush();

        $notFound = array_diff($ids, array_column($updated, 'id'));

        return [
            'updated' => count($updated),
            'percent_change' => $percentChange,
            'products' => $updated,
            'not_found_ids' => array_values($notFound),
        ];
    }
}
