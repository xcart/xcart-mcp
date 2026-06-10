<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Resources;

use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpResource;
use QSL\Make\Model\Level1;
use QSL\Make\Model\Level2;
use QSL\Make\Model\Level3;
use QSL\Make\Model\Level4;
use QSL\Make\Model\Level4Product;
use QSL\ShopByBrand\Model\Brand;
use QSL\ShopByBrand\Model\BrandProducts;
use QSL\ShopByBrand\Model\Image\Brand\Image as BrandImage;
use XC\MCP\MCP\Util\TableResolver;

class VehicleResources
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TableResolver $tableResolver,
    ) {}

    #[McpResource(
        uri: 'xcart://vehicles/stats',
        name: 'vehicle_stats',
        title: 'Vehicle Stats',
        description: 'Vehicle database stats: total/enabled/disabled per level (makes, models, years, submodels), fitment count.',
        mimeType: 'application/json'
    )]
    public function getVehicleStats(): array
    {
        try {
            $conn = $this->em->getConnection();
            $stats = [];

            $levels = [
                'makes' => Level1::class,
                'models' => Level2::class,
                'years' => Level3::class,
                'submodels' => Level4::class,
            ];

            foreach ($levels as $label => $class) {
                $table = $this->tableResolver->resolve($class);
                $row = $conn->fetchAssociative(
                    "SELECT COUNT(*) AS total, SUM(enabled) AS enabled FROM {$table}"
                );
                $stats[$label] = [
                    'total' => (int) $row['total'],
                    'enabled' => (int) $row['enabled'],
                    'disabled' => (int) $row['total'] - (int) $row['enabled'],
                ];
            }

            $l4pTable = $this->tableResolver->resolve(Level4Product::class);
            try {
                $stats['fitments'] = (int) $conn->fetchOne("SELECT COUNT(*) FROM {$l4pTable}");
            } catch (\Throwable) {
                $stats['fitments'] = 0;
            }

            return $stats;
        } catch (\Throwable $e) {
            return [
                'error' => 'QSL/Make module is not installed or vehicle tables are unavailable.',
                'detail' => $e->getMessage(),
            ];
        }
    }

    #[McpResource(
        uri: 'xcart://vehicles/makes',
        name: 'vehicle_makes',
        title: 'Vehicle Makes',
        description: 'List of all vehicle makes with enabled status and model counts.',
        mimeType: 'application/json'
    )]
    public function getVehicleMakes(): array
    {
        try {
            $conn = $this->em->getConnection();
            $l1Table = $this->tableResolver->resolve(Level1::class);
            $l2Table = $this->tableResolver->resolve(Level2::class);

            $rows = $conn->fetchAllAssociative(
                "SELECT l1.id, l1.value AS name, l1.enabled,
                        (SELECT COUNT(*) FROM {$l2Table} l2 WHERE l2.level1_id = l1.id) AS model_count,
                        (SELECT COUNT(*) FROM {$l2Table} l2 WHERE l2.level1_id = l1.id AND l2.enabled = 1) AS enabled_model_count
                 FROM {$l1Table} l1
                 ORDER BY l1.value ASC"
            );

            $items = [];
            foreach ($rows as $row) {
                $items[] = [
                    'id' => (int) $row['id'],
                    'name' => $row['name'],
                    'enabled' => (bool) $row['enabled'],
                    'model_count' => (int) $row['model_count'],
                    'enabled_model_count' => (int) $row['enabled_model_count'],
                ];
            }

            return [
                'total' => count($items),
                'items' => $items,
            ];
        } catch (\Throwable $e) {
            return [
                'error' => 'QSL/Make module is not installed or vehicle tables are unavailable.',
                'detail' => $e->getMessage(),
            ];
        }
    }

    #[McpResource(
        uri: 'xcart://brands/list',
        name: 'brands_list',
        title: 'Brands List',
        description: 'All brands with product counts and logo status.',
        mimeType: 'application/json'
    )]
    public function getBrandsList(): array
    {
        try {
            // Verify module availability
            $this->em->getClassMetadata(Brand::class)->getTableName();

            $brands = $this->em->createQueryBuilder()
                ->select('b')
                ->from(Brand::class, 'b')
                ->orderBy('b.position', 'ASC')
                ->getQuery()
                ->getResult();

            $items = [];
            foreach ($brands as $brand) {
                $productCount = (int) $this->em->createQueryBuilder()
                    ->select('COUNT(bp.id)')
                    ->from(BrandProducts::class, 'bp')
                    ->where('bp.brand = :brand')
                    ->setParameter('brand', $brand)
                    ->getQuery()
                    ->getSingleScalarResult();

                $hasLogo = (bool) $this->em->createQueryBuilder()
                    ->select('COUNT(bi.id)')
                    ->from(BrandImage::class, 'bi')
                    ->where('bi.brand = :brand')
                    ->setParameter('brand', $brand)
                    ->getQuery()
                    ->getSingleScalarResult();

                $items[] = [
                    'id' => $brand->getBrandId(),
                    'name' => $brand->getName(),
                    'enabled' => $brand->getEnabled(),
                    'position' => $brand->getPosition(),
                    'product_count' => $productCount,
                    'has_logo' => $hasLogo,
                ];
            }

            return [
                'total' => count($items),
                'items' => $items,
            ];
        } catch (\Throwable $e) {
            return [
                'error' => 'QSL/ShopByBrand module is not installed or brand tables are unavailable.',
                'detail' => $e->getMessage(),
            ];
        }
    }
}
