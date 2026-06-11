<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use QSL\Make\Model\Level1;
use QSL\Make\Model\Level2;
use QSL\Make\Model\Level3;
use QSL\Make\Model\Level4;
use XC\MCP\MCP\Security\McpAuthorizer;
use XC\MCP\MCP\Security\ToolAnnotation;
use XC\MCP\MCP\Util\TableResolver;

/**
 * Vehicle management tools (Year/Make/Model/Submodel).
 *
 * X-Cart uses a 4-level hierarchy:
 *   Level1 = Make, Level2 = Model, Level3 = Year, Level4 = Submodel/Trim
 *
 * Each level has an `enabled` flag. Disabling a Make cascades to all children
 * for storefront display and import filtering.
 */
class VehicleTools
{
    private ?bool $moduleAvailable = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly McpAuthorizer $authorizer,
        private readonly TableResolver $tableResolver,
    ) {}

    /**
     * Check that QSL\Make module tables exist before running any query.
     */
    private function requireModule(): void
    {
        if ($this->moduleAvailable === null) {
            try {
                $table = $this->tableResolver->resolve(Level1::class);
                $this->em->getConnection()->fetchOne("SELECT 1 FROM {$table} LIMIT 1");
                $this->moduleAvailable = true;
            } catch (\Throwable) {
                $this->moduleAvailable = false;
            }
        }
        if (!$this->moduleAvailable) {
            throw new ToolCallException(
                'QSL\\Make module tables not found. The Year/Make/Model module (QSL\\Make) must be installed and enabled.'
            );
        }
    }

    #[McpTool(
        name: 'vehicle_makes_list',
        title: 'List Vehicle Makes',
        description: 'List all vehicle Makes with enabled/disabled status and model counts. Filter by enabled status or search by name.'
    )]
    #[ToolAnnotation(readOnlyHint: true)]
    public function listMakes(
        ?bool $enabled = null,
        ?string $search = null,
        int $limit = 100,
        int $offset = 0,
    ): array {
        $this->authorizer->authorizeTool('vehicle_makes_list');
        $this->requireModule();

        $qb = $this->em->createQueryBuilder()
            ->select('l1')
            ->from(Level1::class, 'l1')
            ->orderBy('l1.value', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($enabled !== null) {
            $qb->andWhere('l1.enabled = :enabled')->setParameter('enabled', $enabled);
        }
        if ($search !== null) {
            $qb->andWhere('l1.value LIKE :search')->setParameter('search', '%' . $search . '%');
        }

        $countQb = clone $qb;
        $countQb->select('COUNT(l1.id)')->setMaxResults(null)->setFirstResult(0);
        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        $makes = $qb->getQuery()->getResult();

        $items = [];
        foreach ($makes as $make) {
            $modelCount = $this->em->createQueryBuilder()
                ->select('COUNT(l2.id)')
                ->from(Level2::class, 'l2')
                ->where('l2.level1 = :make')->setParameter('make', $make)
                ->getQuery()->getSingleScalarResult();

            $enabledModelCount = $this->em->createQueryBuilder()
                ->select('COUNT(l2.id)')
                ->from(Level2::class, 'l2')
                ->where('l2.level1 = :make')->setParameter('make', $make)
                ->andWhere('l2.enabled = true')
                ->getQuery()->getSingleScalarResult();

            $items[] = [
                'id' => $make->getId(),
                'name' => $make->getValue(),
                'enabled' => $make->getEnabled(),
                'show_on_front_page' => $make->getShowOnFrontPage(),
                'model_count' => (int) $modelCount,
                'enabled_model_count' => (int) $enabledModelCount,
            ];
        }

        $statsQb = $this->em->createQueryBuilder()
            ->select('COUNT(l1.id) AS total, SUM(CASE WHEN l1.enabled = true THEN 1 ELSE 0 END) AS enabled_count')
            ->from(Level1::class, 'l1');
        $stats = $statsQb->getQuery()->getSingleResult();

        return [
            'total' => $total,
            'items' => $items,
            'limit' => $limit,
            'offset' => $offset,
            'stats' => [
                'total_makes' => (int) $stats['total'],
                'enabled_makes' => (int) $stats['enabled_count'],
                'disabled_makes' => (int) $stats['total'] - (int) $stats['enabled_count'],
            ],
        ];
    }

    #[McpTool(
        name: 'vehicle_models_list',
        title: 'List Vehicle Models',
        description: 'List vehicle Models for a given Make. Filter by enabled status or search by name.'
    )]
    #[ToolAnnotation(readOnlyHint: true)]
    public function listModels(
        int $makeId,
        ?bool $enabled = null,
        ?string $search = null,
        int $limit = 100,
        int $offset = 0,
    ): array {
        $this->authorizer->authorizeTool('vehicle_models_list');
        $this->requireModule();

        $make = $this->em->getRepository(Level1::class)->find($makeId);
        if (!$make) {
            throw new ToolCallException("Make #{$makeId} not found");
        }

        $qb = $this->em->createQueryBuilder()
            ->select('l2')
            ->from(Level2::class, 'l2')
            ->where('l2.level1 = :make')->setParameter('make', $make)
            ->orderBy('l2.value', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($enabled !== null) {
            $qb->andWhere('l2.enabled = :enabled')->setParameter('enabled', $enabled);
        }
        if ($search !== null) {
            $qb->andWhere('l2.value LIKE :search')->setParameter('search', '%' . $search . '%');
        }

        $countQb = clone $qb;
        $countQb->select('COUNT(l2.id)')->setMaxResults(null)->setFirstResult(0);
        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        $models = $qb->getQuery()->getResult();

        $conn = $this->em->getConnection();
        $l3Table = $this->tableResolver->resolve(Level3::class);
        $items = [];
        foreach ($models as $model) {
            $yearRange = $conn->fetchAssociative(
                "SELECT MIN(value) AS yr_from, MAX(value) AS yr_to, COUNT(*) AS cnt FROM {$l3Table} WHERE level2_id = ?",
                [$model->getId()]
            );
            $items[] = [
                'id' => $model->getId(),
                'name' => $model->getValue(),
                'enabled' => $model->getEnabled(),
                'year_range' => $yearRange['yr_from'] ? "{$yearRange['yr_from']}-{$yearRange['yr_to']}" : null,
                'year_count' => (int) $yearRange['cnt'],
            ];
        }

        return [
            'make' => ['id' => $make->getId(), 'name' => $make->getValue(), 'enabled' => $make->getEnabled()],
            'total' => $total,
            'items' => $items,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    #[McpTool(
        name: 'vehicle_years_list',
        title: 'List Vehicle Years',
        description: 'List available Years for a given Model, with enabled status.'
    )]
    #[ToolAnnotation(readOnlyHint: true)]
    public function listYears(int $modelId, ?bool $enabled = null): array
    {
        $this->authorizer->authorizeTool('vehicle_years_list');
        $this->requireModule();

        $model = $this->em->getRepository(Level2::class)->find($modelId);
        if (!$model) {
            throw new ToolCallException("Model #{$modelId} not found");
        }

        $make = $model->getLevel1();

        $qb = $this->em->createQueryBuilder()
            ->select('l3')
            ->from(Level3::class, 'l3')
            ->where('l3.level2 = :model')->setParameter('model', $model)
            ->orderBy('l3.value', 'DESC');

        if ($enabled !== null) {
            $qb->andWhere('l3.enabled = :enabled')->setParameter('enabled', $enabled);
        }

        $years = $qb->getQuery()->getResult();

        return [
            'make' => ['id' => $make->getId(), 'name' => $make->getValue()],
            'model' => ['id' => $model->getId(), 'name' => $model->getValue(), 'enabled' => $model->getEnabled()],
            'years' => array_map(fn($y) => [
                'id' => $y->getId(),
                'year' => $y->getValue(),
                'enabled' => $y->getEnabled(),
            ], $years),
        ];
    }

    // ─── Enable / Disable ────────────────────────────────────────────

    #[McpTool(
        name: 'vehicle_make_toggle',
        title: 'Toggle Vehicle Make',
        description: 'Enable or disable a Make and optionally all its children (Models, Years, Submodels). Use cascade=true to propagate.'
    )]
    #[ToolAnnotation(readOnlyHint: false, idempotentHint: true)]
    public function toggleMake(int $makeId, bool $enabled, bool $cascade = true): array
    {
        $this->authorizer->authorizeTool('vehicle_make_toggle');
        $this->requireModule();

        $make = $this->em->getRepository(Level1::class)->find($makeId);
        if (!$make) {
            throw new ToolCallException("Make #{$makeId} not found");
        }

        $conn = $this->em->getConnection();
        $l1 = $this->tableResolver->resolve(Level1::class);
        $l2 = $this->tableResolver->resolve(Level2::class);
        $l3 = $this->tableResolver->resolve(Level3::class);
        $l4 = $this->tableResolver->resolve(Level4::class);
        $val = $enabled ? 1 : 0;

        $conn->executeStatement("UPDATE {$l1} SET enabled = ? WHERE id = ?", [$val, $makeId]);
        $affected = ['makes' => 1];

        if ($cascade) {
            $affected['models'] = $conn->executeStatement(
                "UPDATE {$l2} SET enabled = ? WHERE level1_id = ?", [$val, $makeId]
            );
            $affected['years'] = $conn->executeStatement(
                "UPDATE {$l3} l3 JOIN {$l2} l2 ON l3.level2_id = l2.id SET l3.enabled = ? WHERE l2.level1_id = ?",
                [$val, $makeId]
            );
            $affected['submodels'] = $conn->executeStatement(
                "UPDATE {$l4} l4 JOIN {$l3} l3 ON l4.level3_id = l3.id JOIN {$l2} l2 ON l3.level2_id = l2.id SET l4.enabled = ? WHERE l2.level1_id = ?",
                [$val, $makeId]
            );
        }

        return [
            'make' => ['id' => $make->getId(), 'name' => $make->getValue()],
            'enabled' => $enabled,
            'cascade' => $cascade,
            'affected_rows' => $affected,
        ];
    }

    #[McpTool(
        name: 'vehicle_model_toggle',
        title: 'Toggle Vehicle Model',
        description: 'Enable or disable a specific Model (Level2) with cascade to its Years and Submodels.'
    )]
    #[ToolAnnotation(readOnlyHint: false, idempotentHint: true)]
    public function toggleModel(int $modelId, bool $enabled): array
    {
        $this->authorizer->authorizeTool('vehicle_model_toggle');
        $this->requireModule();

        $model = $this->em->getRepository(Level2::class)->find($modelId);
        if (!$model) {
            throw new ToolCallException("Model #{$modelId} not found");
        }

        $make = $model->getLevel1();
        $conn = $this->em->getConnection();
        $l2 = $this->tableResolver->resolve(Level2::class);
        $l3 = $this->tableResolver->resolve(Level3::class);
        $l4 = $this->tableResolver->resolve(Level4::class);
        $val = $enabled ? 1 : 0;

        $conn->executeStatement("UPDATE {$l2} SET enabled = ? WHERE id = ?", [$val, $modelId]);

        $yearsAffected = $conn->executeStatement(
            "UPDATE {$l3} SET enabled = ? WHERE level2_id = ?", [$val, $modelId]
        );

        $submodelsAffected = $conn->executeStatement(
            "UPDATE {$l4} l4 JOIN {$l3} l3 ON l4.level3_id = l3.id SET l4.enabled = ? WHERE l3.level2_id = ?",
            [$val, $modelId]
        );

        return [
            'make' => ['id' => $make->getId(), 'name' => $make->getValue()],
            'model' => ['id' => $model->getId(), 'name' => $model->getValue()],
            'enabled' => $enabled,
            'affected_rows' => [
                'models' => 1,
                'years' => $yearsAffected,
                'submodels' => $submodelsAffected,
            ],
        ];
    }

    #[McpTool(
        name: 'vehicle_bulk_toggle_makes',
        title: 'Bulk Toggle Vehicle Makes',
        description: 'Enable or disable multiple Makes at once by name. Supports wildcards. Example: names=["RAM","Jeep","Ford"]'
    )]
    #[ToolAnnotation(readOnlyHint: false)]
    public function bulkToggleMakes(array $names, bool $enabled, bool $cascade = true): array
    {
        $this->authorizer->authorizeTool('vehicle_bulk_toggle_makes');
        $this->requireModule();

        if (empty($names)) {
            throw new ToolCallException('At least one make name required');
        }

        $results = [];
        foreach ($names as $name) {
            $pattern = str_replace('*', '%', $name);
            $makes = $this->em->createQueryBuilder()
                ->select('l1')
                ->from(Level1::class, 'l1')
                ->where('l1.value LIKE :pattern')->setParameter('pattern', $pattern)
                ->getQuery()->getResult();

            foreach ($makes as $make) {
                $results[] = $this->toggleMake($make->getId(), $enabled, $cascade);
            }
        }

        return ['processed' => count($results), 'enabled' => $enabled, 'results' => $results];
    }

    #[McpTool(
        name: 'vehicle_set_year_range',
        title: 'Set Vehicle Year Range',
        description: 'Enable only Years within a range for a given Make (or all Makes). Years outside the range are disabled.'
    )]
    #[ToolAnnotation(readOnlyHint: false)]
    public function setYearRange(int $yearFrom, int $yearTo, ?int $makeId = null, ?string $makeName = null): array
    {
        $this->authorizer->authorizeTool('vehicle_set_year_range');
        $this->requireModule();

        if ($yearFrom > $yearTo) {
            throw new ToolCallException("yearFrom ({$yearFrom}) must be <= yearTo ({$yearTo})");
        }

        $conn = $this->em->getConnection();
        $l2 = $this->tableResolver->resolve(Level2::class);
        $l3 = $this->tableResolver->resolve(Level3::class);

        $makeFilter = '';
        $params = [(string) $yearFrom, (string) $yearTo];

        if ($makeId !== null || $makeName !== null) {
            if ($makeName !== null) {
                $make = $this->em->createQueryBuilder()
                    ->select('l1')->from(Level1::class, 'l1')
                    ->where('l1.value = :name')->setParameter('name', $makeName)
                    ->setMaxResults(1)->getQuery()->getOneOrNullResult();
                if (!$make) {
                    throw new ToolCallException("Make '{$makeName}' not found");
                }
                $makeId = $make->getId();
            }
            $makeFilter = 'AND l2.level1_id = ?';
            $params[] = $makeId;
        }

        $disabled = $conn->executeStatement(
            "UPDATE {$l3} l3 JOIN {$l2} l2 ON l3.level2_id = l2.id
             SET l3.enabled = 0
             WHERE (CAST(l3.value AS UNSIGNED) < ? OR CAST(l3.value AS UNSIGNED) > ?) {$makeFilter}",
            $params
        );

        $enabled = $conn->executeStatement(
            "UPDATE {$l3} l3 JOIN {$l2} l2 ON l3.level2_id = l2.id
             SET l3.enabled = 1
             WHERE CAST(l3.value AS UNSIGNED) >= ? AND CAST(l3.value AS UNSIGNED) <= ? {$makeFilter}",
            $params
        );

        return [
            'year_range' => "{$yearFrom}-{$yearTo}",
            'make' => $makeName ?? ($makeId ? "#{$makeId}" : 'all'),
            'years_enabled' => $enabled,
            'years_disabled' => $disabled,
        ];
    }

    #[McpTool(
        name: 'vehicle_disable_all_then_enable',
        title: 'Disable All Then Enable Vehicle Makes',
        description: 'Disable ALL Makes, then enable only the specified ones. Perfect for onboarding: "enable only RAM, Jeep, and Ford from 1999-2026".'
    )]
    #[ToolAnnotation(destructiveHint: true)]
    public function disableAllThenEnable(array $makeNames, ?int $yearFrom = null, ?int $yearTo = null): array
    {
        $this->authorizer->authorizeTool('vehicle_disable_all_then_enable');
        $this->requireModule();

        if (empty($makeNames)) {
            throw new ToolCallException('At least one make name required');
        }

        $conn = $this->em->getConnection();
        $l1 = $this->tableResolver->resolve(Level1::class);
        $l2 = $this->tableResolver->resolve(Level2::class);
        $l3 = $this->tableResolver->resolve(Level3::class);
        $l4 = $this->tableResolver->resolve(Level4::class);

        $totalMakes = (int) $conn->fetchOne("SELECT COUNT(*) FROM {$l1}");
        $conn->executeStatement("UPDATE {$l1} SET enabled = 0");
        $conn->executeStatement("UPDATE {$l2} SET enabled = 0");
        $conn->executeStatement("UPDATE {$l3} SET enabled = 0");
        $conn->executeStatement("UPDATE {$l4} SET enabled = 0");

        $enabledMakes = [];
        foreach ($makeNames as $name) {
            $pattern = str_replace('*', '%', $name);
            $makes = $this->em->createQueryBuilder()
                ->select('l1')->from(Level1::class, 'l1')
                ->where('l1.value LIKE :pattern')->setParameter('pattern', $pattern)
                ->getQuery()->getResult();

            foreach ($makes as $make) {
                $this->em->refresh($make);
                $this->toggleMake($make->getId(), true, true);
                $enabledMakes[] = $make->getValue();
            }
        }

        $yearInfo = null;
        if ($yearFrom !== null && $yearTo !== null) {
            foreach ($enabledMakes as $mn) {
                $this->setYearRange($yearFrom, $yearTo, makeName: $mn);
            }
            $yearInfo = "{$yearFrom}-{$yearTo}";
        }

        return [
            'total_makes_in_db' => $totalMakes,
            'enabled_makes' => $enabledMakes,
            'enabled_count' => count($enabledMakes),
            'year_range' => $yearInfo,
            'message' => 'All makes disabled, then enabled: ' . implode(', ', $enabledMakes),
        ];
    }

    #[McpTool(
        name: 'vehicle_bulk_toggle_models',
        title: 'Bulk Toggle Vehicle Models',
        description: 'Enable or disable multiple Models at once for a given Make. Match by exact names or wildcard patterns. Example: makeId=5, names=["F-150","Ranger","Explorer*"], enabled=true'
    )]
    #[ToolAnnotation(readOnlyHint: false)]
    public function bulkToggleModels(int $makeId, array $names, bool $enabled, bool $cascade = true): array
    {
        $this->authorizer->authorizeTool('vehicle_bulk_toggle_models');
        $this->requireModule();

        $make = $this->em->getRepository(Level1::class)->find($makeId);
        if (!$make) {
            throw new ToolCallException("Make #{$makeId} not found");
        }

        if (empty($names)) {
            throw new ToolCallException('At least one model name required');
        }

        $results = [];
        foreach ($names as $name) {
            $pattern = str_replace('*', '%', $name);
            $models = $this->em->createQueryBuilder()
                ->select('l2')
                ->from(Level2::class, 'l2')
                ->where('l2.level1 = :make')->setParameter('make', $make)
                ->andWhere('l2.value LIKE :pattern')->setParameter('pattern', $pattern)
                ->getQuery()->getResult();

            foreach ($models as $model) {
                $result = $this->toggleModel($model->getId(), $enabled);
                $results[] = [
                    'id' => $model->getId(),
                    'name' => $model->getValue(),
                    'enabled' => $enabled,
                    'affected' => $result['affected_rows'],
                ];
            }
        }

        return [
            'make' => ['id' => $make->getId(), 'name' => $make->getValue()],
            'processed' => count($results),
            'enabled' => $enabled,
            'results' => $results,
        ];
    }

    #[McpTool(
        name: 'vehicle_models_keep_only',
        title: 'Keep Only Vehicle Models',
        description: 'Disable ALL Models for a Make, then enable only the specified ones (with cascade to Years/Submodels). Perfect for filtering: "For Ford, keep only F-150, Ranger, Bronco". Supports wildcards.'
    )]
    #[ToolAnnotation(readOnlyHint: false)]
    public function modelsKeepOnly(int $makeId, array $keepNames, ?int $yearFrom = null, ?int $yearTo = null): array
    {
        $this->authorizer->authorizeTool('vehicle_models_keep_only');
        $this->requireModule();

        $make = $this->em->getRepository(Level1::class)->find($makeId);
        if (!$make) {
            throw new ToolCallException("Make #{$makeId} not found");
        }

        if (empty($keepNames)) {
            throw new ToolCallException('At least one model name required in keepNames');
        }

        $conn = $this->em->getConnection();
        $l2 = $this->tableResolver->resolve(Level2::class);
        $l3 = $this->tableResolver->resolve(Level3::class);
        $l4 = $this->tableResolver->resolve(Level4::class);

        // Count total models for this make
        $totalModels = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM {$l2} WHERE level1_id = ?", [$makeId]
        );

        // Disable all models + children for this make
        $conn->executeStatement("UPDATE {$l2} SET enabled = 0 WHERE level1_id = ?", [$makeId]);
        $conn->executeStatement(
            "UPDATE {$l3} l3 JOIN {$l2} l2 ON l3.level2_id = l2.id SET l3.enabled = 0 WHERE l2.level1_id = ?",
            [$makeId]
        );
        $conn->executeStatement(
            "UPDATE {$l4} l4 JOIN {$l3} l3 ON l4.level3_id = l3.id JOIN {$l2} l2 ON l3.level2_id = l2.id SET l4.enabled = 0 WHERE l2.level1_id = ?",
            [$makeId]
        );

        // Enable only matching models
        $enabledModels = [];
        foreach ($keepNames as $name) {
            $pattern = str_replace('*', '%', $name);
            $models = $this->em->createQueryBuilder()
                ->select('l2')
                ->from(Level2::class, 'l2')
                ->where('l2.level1 = :make')->setParameter('make', $make)
                ->andWhere('l2.value LIKE :pattern')->setParameter('pattern', $pattern)
                ->getQuery()->getResult();

            foreach ($models as $model) {
                $this->em->refresh($model);
                $this->toggleModel($model->getId(), true);
                $enabledModels[] = ['id' => $model->getId(), 'name' => $model->getValue()];
            }
        }

        // Optionally restrict year range on the enabled models
        $yearInfo = null;
        if ($yearFrom !== null && $yearTo !== null) {
            foreach ($enabledModels as $m) {
                $conn->executeStatement(
                    "UPDATE {$l3} SET enabled = 0 WHERE level2_id = ? AND (CAST(value AS UNSIGNED) < ? OR CAST(value AS UNSIGNED) > ?)",
                    [$m['id'], $yearFrom, $yearTo]
                );
                $conn->executeStatement(
                    "UPDATE {$l3} SET enabled = 1 WHERE level2_id = ? AND CAST(value AS UNSIGNED) >= ? AND CAST(value AS UNSIGNED) <= ?",
                    [$m['id'], $yearFrom, $yearTo]
                );
            }
            $yearInfo = "{$yearFrom}-{$yearTo}";
        }

        return [
            'make' => ['id' => $make->getId(), 'name' => $make->getValue()],
            'total_models' => $totalModels,
            'kept_models' => $enabledModels,
            'kept_count' => count($enabledModels),
            'disabled_count' => $totalModels - count($enabledModels),
            'year_range' => $yearInfo,
        ];
    }

    #[McpTool(
        name: 'vehicle_stats',
        title: 'Vehicle Statistics',
        description: 'Get vehicle database statistics: counts per level, enabled/disabled breakdown, fitment counts.'
    )]
    #[ToolAnnotation(readOnlyHint: true)]
    public function getStats(): array
    {
        $this->authorizer->authorizeTool('vehicle_stats');
        $this->requireModule();

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
            $row = $conn->fetchAssociative("SELECT COUNT(*) AS total, SUM(enabled) AS enabled FROM {$table}");
            $stats[$label] = [
                'total' => (int) $row['total'],
                'enabled' => (int) $row['enabled'],
                'disabled' => (int) $row['total'] - (int) $row['enabled'],
            ];
        }

        $l4pTable = $this->tableResolver->resolve(\QSL\Make\Model\Level4Product::class);
        try {
            $stats['fitments'] = (int) $conn->fetchOne("SELECT COUNT(*) FROM {$l4pTable}");
        } catch (\Throwable) {
            $stats['fitments'] = 0;
        }

        return $stats;
    }
}
