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
use XLite\Model\Order;
use XLite\Model\Product;
use XLite\Model\Profile;

class SearchTools
{
    private const ALLOWED_ENTITY_TYPES = ['product', 'order', 'category', 'customer'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly McpAuthorizer $authorizer,
    ) {}

    #[McpTool(
        name: 'global_search',
        title: 'Global Search',
        description: 'Search across products, orders, categories, and customers. Returns grouped results.'
    )]
    #[ToolAnnotation(readOnlyHint: true)]
    public function globalSearch(
        string $query,
        ?string $entityType = null,
        int $limit = 10,
    ): array {
        $this->authorizer->authorizeTool('global_search');

        $query = trim($query);

        if (mb_strlen($query) < 2) {
            throw new ToolCallException('Search query must be at least 2 characters long');
        }

        if ($entityType !== null && !in_array($entityType, self::ALLOWED_ENTITY_TYPES, true)) {
            throw new ToolCallException(
                "Invalid entity type '{$entityType}'. Allowed: " . implode(', ', self::ALLOWED_ENTITY_TYPES)
            );
        }

        $limit = min($limit, 50);
        $results = [
            'products' => [],
            'orders' => [],
            'categories' => [],
            'customers' => [],
        ];
        $counts = [
            'products' => 0,
            'orders' => 0,
            'categories' => 0,
            'customers' => 0,
        ];

        // Search products
        if ($entityType === null || $entityType === 'product') {
            $qb = $this->em->createQueryBuilder()
                ->select('p')
                ->from(Product::class, 'p')
                ->leftJoin('p.translations', 't', 'WITH', 't.code = :lang')
                ->where('t.name LIKE :query OR p.sku LIKE :query')
                ->setParameter('lang', TableResolver::getDefaultLanguage())
                ->setParameter('query', QueryHelper::likeContains($query))
                ->setMaxResults($limit);

            $products = $qb->getQuery()->getResult();
            foreach ($products as $product) {
                $results['products'][] = [
                    'id' => $product->getId(),
                    'name' => $product->getName(),
                    'sku' => $product->getSku(),
                    'price' => $product->getPrice(),
                ];
            }

            $countQb = $this->em->createQueryBuilder()
                ->select('COUNT(p.product_id)')
                ->from(Product::class, 'p')
                ->leftJoin('p.translations', 't', 'WITH', 't.code = :lang')
                ->where('t.name LIKE :query OR p.sku LIKE :query')
                ->setParameter('lang', TableResolver::getDefaultLanguage())
                ->setParameter('query', QueryHelper::likeContains($query));
            $counts['products'] = (int) $countQb->getQuery()->getSingleScalarResult();
        }

        // Search orders (by order number or ID)
        if ($entityType === null || $entityType === 'order') {
            $qb = $this->em->createQueryBuilder()
                ->select('o')
                ->from(Order::class, 'o')
                ->leftJoin('o.profile', 'pr')
                ->where('o.orderNumber LIKE :query OR pr.login LIKE :query')
                ->setParameter('query', QueryHelper::likeContains($query))
                ->setMaxResults($limit);

            $orders = $qb->getQuery()->getResult();
            foreach ($orders as $order) {
                $results['orders'][] = [
                    'id' => $order->getId(),
                    'order_number' => $order->getOrderNumber(),
                    'total' => $order->getTotal(),
                    'date' => $order->getDate() ? date('c', $order->getDate()) : null,
                    'payment_status' => $order->getPaymentStatusCode(),
                ];
            }

            $countQb = $this->em->createQueryBuilder()
                ->select('COUNT(o.order_id)')
                ->from(Order::class, 'o')
                ->leftJoin('o.profile', 'pr')
                ->where('o.orderNumber LIKE :query OR pr.login LIKE :query')
                ->setParameter('query', QueryHelper::likeContains($query));
            $counts['orders'] = (int) $countQb->getQuery()->getSingleScalarResult();
        }

        // Search categories
        if ($entityType === null || $entityType === 'category') {
            $qb = $this->em->createQueryBuilder()
                ->select('c')
                ->from(Category::class, 'c')
                ->leftJoin('c.translations', 'ct', 'WITH', 'ct.code = :lang')
                ->where('ct.name LIKE :query')
                ->setParameter('lang', TableResolver::getDefaultLanguage())
                ->setParameter('query', QueryHelper::likeContains($query))
                ->setMaxResults($limit);

            $categories = $qb->getQuery()->getResult();
            foreach ($categories as $category) {
                $results['categories'][] = [
                    'id' => $category->getId(),
                    'name' => $category->getName(),
                    'enabled' => $category->getEnabled(),
                ];
            }

            $countQb = $this->em->createQueryBuilder()
                ->select('COUNT(c.category_id)')
                ->from(Category::class, 'c')
                ->leftJoin('c.translations', 'ct', 'WITH', 'ct.code = :lang')
                ->where('ct.name LIKE :query')
                ->setParameter('lang', TableResolver::getDefaultLanguage())
                ->setParameter('query', QueryHelper::likeContains($query));
            $counts['categories'] = (int) $countQb->getQuery()->getSingleScalarResult();
        }

        // Search customers
        if ($entityType === null || $entityType === 'customer') {
            $qb = $this->em->createQueryBuilder()
                ->select('pr')
                ->from(Profile::class, 'pr')
                ->where('pr.login LIKE :query')
                ->setParameter('query', QueryHelper::likeContains($query))
                ->setMaxResults($limit);

            $profiles = $qb->getQuery()->getResult();
            foreach ($profiles as $profile) {
                $results['customers'][] = [
                    'id' => $profile->getId(),
                    'email' => $profile->getLogin(),
                ];
            }

            $countQb = $this->em->createQueryBuilder()
                ->select('COUNT(pr.profile_id)')
                ->from(Profile::class, 'pr')
                ->where('pr.login LIKE :query')
                ->setParameter('query', QueryHelper::likeContains($query));
            $counts['customers'] = (int) $countQb->getQuery()->getSingleScalarResult();
        }

        return [
            'query' => $query,
            'results' => $results,
            'counts' => $counts,
        ];
    }
}
