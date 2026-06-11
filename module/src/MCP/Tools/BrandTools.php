<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use QSL\ShopByBrand\Model\Brand;
use QSL\ShopByBrand\Model\BrandProducts;
use QSL\ShopByBrand\Model\Image\Brand\Image as BrandImage;
use XC\MCP\MCP\Security\McpAuthorizer;
use XC\MCP\MCP\Security\ToolAnnotation;

/**
 * Brand management tools.
 *
 * Brands are QSL\ShopByBrand entities linked to AttributeOptions for display name.
 */
class BrandTools
{
    private ?bool $moduleAvailable = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly McpAuthorizer $authorizer,
    ) {}

    /**
     * Verify that QSL\ShopByBrand module is installed and its tables exist.
     *
     * @throws ToolCallException if the module is not available
     */
    private function requireModule(): void
    {
        if ($this->moduleAvailable === null) {
            try {
                $this->em->getConnection()
                    ->executeQuery('SELECT COUNT(*) FROM ' . $this->em->getClassMetadata(Brand::class)->getTableName());
                $this->moduleAvailable = true;
            } catch (\Throwable) {
                $this->moduleAvailable = false;
            }
        }

        if (!$this->moduleAvailable) {
            throw new ToolCallException(
                'QSL/ShopByBrand module is not installed. Brand tools require this module.'
            );
        }
    }

    #[McpTool(
        name: 'brand_list',
        title: 'List Brands',
        description: 'List all brands with product counts, logo status, and enabled state. Search by name.'
    )]
    #[ToolAnnotation(readOnlyHint: true)]
    public function listBrands(
        ?string $search = null,
        ?bool $enabled = null,
        string $sortBy = 'name',
        int $limit = 100,
        int $offset = 0,
    ): array {
        $this->authorizer->authorizeTool('brand_list');
        $this->requireModule();

        $qb = $this->em->createQueryBuilder()
            ->select('b')
            ->from(Brand::class, 'b')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($enabled !== null) {
            $qb->andWhere('b.enabled = :enabled')->setParameter('enabled', $enabled);
        }

        // Brand name is in the related AttributeOption translations
        if ($search !== null) {
            $qb->join('b.option', 'ao')
                ->join('ao.translations', 'aot')
                ->andWhere('aot.name LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        $countQb = clone $qb;
        $countQb->select('COUNT(b.brand_id)')->setMaxResults(null)->setFirstResult(0);
        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        $brands = $qb->getQuery()->getResult();

        $items = [];
        foreach ($brands as $brand) {
            $productCount = $this->em->createQueryBuilder()
                ->select('COUNT(bp.id)')
                ->from(BrandProducts::class, 'bp')
                ->where('bp.brand = :brand')->setParameter('brand', $brand)
                ->getQuery()->getSingleScalarResult();

            $hasLogo = $this->em->createQueryBuilder()
                ->select('COUNT(bi.id)')
                ->from(BrandImage::class, 'bi')
                ->where('bi.brand = :brand')->setParameter('brand', $brand)
                ->getQuery()->getSingleScalarResult();

            $items[] = [
                'id' => $brand->getBrandId(),
                'name' => $brand->getName(),
                'enabled' => $brand->getEnabled(),
                'aaia_id' => $brand->getAaiaId(),
                'position' => $brand->getPosition(),
                'product_count' => (int) $productCount,
                'has_logo' => (bool) $hasLogo,
            ];
        }

        return [
            'total' => $total,
            'items' => $items,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    #[McpTool(
        name: 'brand_get',
        title: 'Get Brand',
        description: 'Get detailed brand info: name, description, meta, logo, product count, AAIA ID.'
    )]
    #[ToolAnnotation(readOnlyHint: true)]
    public function getBrand(int $brandId): array
    {
        $this->authorizer->authorizeTool('brand_get');
        $this->requireModule();

        $brand = $this->em->getRepository(Brand::class)->find($brandId);
        if (!$brand) {
            throw new ToolCallException("Brand #{$brandId} not found");
        }

        $productCount = $this->em->createQueryBuilder()
            ->select('COUNT(bp.id)')
            ->from(BrandProducts::class, 'bp')
            ->where('bp.brand = :brand')->setParameter('brand', $brand)
            ->getQuery()->getSingleScalarResult();

        $image = $brand->getImage();

        $translations = [];
        foreach ($brand->getTranslations() as $t) {
            $translations[] = [
                'language' => $t->getCode(),
                'description' => $t->getDescription(),
                'meta_title' => $t->getMetaTitle(),
                'meta_description' => $t->getMetaDescription(),
                'meta_keywords' => $t->getMetaKeywords(),
            ];
        }

        return [
            'id' => $brand->getBrandId(),
            'name' => $brand->getName(),
            'enabled' => $brand->getEnabled(),
            'aaia_id' => $brand->getAaiaId(),
            'position' => $brand->getPosition(),
            'product_count' => (int) $productCount,
            'logo' => $image ? [
                'id' => $image->getId(),
                'path' => $image->getPath(),
                'mime' => $image->getMime(),
                'width' => $image->getWidth(),
                'height' => $image->getHeight(),
            ] : null,
            'translations' => $translations,
        ];
    }

    #[McpTool(
        name: 'brand_toggle',
        title: 'Toggle Brand',
        description: 'Enable or disable a brand.'
    )]
    #[ToolAnnotation(readOnlyHint: false, idempotentHint: true)]
    public function toggleBrand(int $brandId, bool $enabled): array
    {
        $this->authorizer->authorizeTool('brand_toggle');
        $this->requireModule();

        $brand = $this->em->getRepository(Brand::class)->find($brandId);
        if (!$brand) {
            throw new ToolCallException("Brand #{$brandId} not found");
        }

        $brand->setEnabled($enabled);
        $this->em->flush();

        return [
            'id' => $brand->getBrandId(),
            'name' => $brand->getName(),
            'enabled' => $enabled,
        ];
    }

    #[McpTool(
        name: 'brand_update',
        title: 'Update Brand',
        description: 'Update brand description, meta title, meta description, meta keywords, or position.'
    )]
    #[ToolAnnotation(readOnlyHint: false)]
    public function updateBrand(
        int $brandId,
        ?string $description = null,
        ?string $metaTitle = null,
        ?string $metaDescription = null,
        ?string $metaKeywords = null,
        ?int $position = null,
    ): array {
        $this->authorizer->authorizeTool('brand_update');
        $this->requireModule();

        $brand = $this->em->getRepository(Brand::class)->find($brandId);
        if (!$brand) {
            throw new ToolCallException("Brand #{$brandId} not found");
        }

        $changes = [];

        if ($position !== null) {
            $brand->setPosition($position);
            $changes[] = "position={$position}";
        }

        if ($description !== null) {
            $brand->setDescription($description);
            $changes[] = 'description updated';
        }
        if ($metaTitle !== null) {
            $brand->setMetaTitle($metaTitle);
            $changes[] = "metaTitle={$metaTitle}";
        }
        if ($metaDescription !== null) {
            $brand->setMetaDescription($metaDescription);
            $changes[] = 'metaDescription updated';
        }
        if ($metaKeywords !== null) {
            $brand->setMetaKeywords($metaKeywords);
            $changes[] = 'metaKeywords updated';
        }

        if (empty($changes)) {
            throw new ToolCallException('No fields to update');
        }

        $this->em->flush();

        return [
            'id' => $brand->getBrandId(),
            'name' => $brand->getName(),
            'changes' => $changes,
        ];
    }

    #[McpTool(
        name: 'brand_products',
        title: 'List Brand Products',
        description: 'List products for a given brand with basic product info.'
    )]
    #[ToolAnnotation(readOnlyHint: true)]
    public function getBrandProducts(int $brandId, int $limit = 50, int $offset = 0): array
    {
        $this->authorizer->authorizeTool('brand_products');
        $this->requireModule();

        $brand = $this->em->getRepository(Brand::class)->find($brandId);
        if (!$brand) {
            throw new ToolCallException("Brand #{$brandId} not found");
        }

        $qb = $this->em->createQueryBuilder()
            ->select('bp')
            ->from(BrandProducts::class, 'bp')
            ->where('bp.brand = :brand')->setParameter('brand', $brand)
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $countQb = clone $qb;
        $countQb->select('COUNT(bp.id)')->setMaxResults(null)->setFirstResult(0);
        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        $bps = $qb->getQuery()->getResult();

        $items = [];
        foreach ($bps as $bp) {
            $product = $bp->getProduct();
            if ($product) {
                $items[] = [
                    'product_id' => $product->getId(),
                    'name' => $product->getName(),
                    'sku' => $product->getSku(),
                    'price' => (float) $product->getPrice(),
                    'enabled' => $product->getEnabled(),
                ];
            }
        }

        return [
            'brand' => ['id' => $brand->getBrandId(), 'name' => $brand->getName()],
            'total_products' => $total,
            'items' => $items,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }
}
