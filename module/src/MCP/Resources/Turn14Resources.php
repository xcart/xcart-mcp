<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Resources;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Exception\ToolCallException;
use XC\MCP\MCP\Util\TableResolver;
use XLite\Model\Category;

class Turn14Resources
{
    private Connection $conn;
    private string $catTable;
    private string $catTransTable;
    private string $t14Table;
    private ?bool $tableExists = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TableResolver $tableResolver,
    ) {
        $this->conn = $this->em->getConnection();
        $this->catTable = $this->tableResolver->resolve(Category::class);
        $this->catTransTable = $this->tableResolver->resolve(\XLite\Model\CategoryTranslation::class);
        $this->t14Table = $this->tableResolver->resolveTable('category_map_turn14');
    }

    private function requireTable(): void
    {
        if ($this->tableExists === null) {
            try {
                $this->conn->fetchOne("SELECT 1 FROM {$this->t14Table} LIMIT 1");
                $this->tableExists = true;
            } catch (\Throwable) {
                $this->tableExists = false;
            }
        }
        if (!$this->tableExists) {
            throw new ToolCallException(
                'Turn14 category mapping table not found. The Turn14 integration module must be installed and its data imported before using Turn14 resources.'
            );
        }
    }

    #[McpResource(
        uri: 'xcart://turn14/categories',
        name: 'turn14_category_tree',
        title: 'Turn14 Category Tree',
        description: 'Turn14 imported category tree with mapping status to X-Cart categories',
        mimeType: 'application/json'
    )]
    public function getCategoryTree(): array
    {
        $this->requireTable();

        $lang = TableResolver::getDefaultLanguage();

        $rows = $this->conn->fetchAllAssociative(
            "SELECT t.id, t.parent_id, t.remoteId, t.name, t.category_id, t.inTree, t.position,
                    ct.name AS xcart_category_name
             FROM {$this->t14Table} t
             LEFT JOIN {$this->catTransTable} ct ON t.category_id = ct.id AND ct.code = :lang
             ORDER BY t.parent_id IS NULL DESC, t.name ASC",
            ['lang' => $lang]
        );

        // Build flat map
        $flatMap = [];
        foreach ($rows as $row) {
            $flatMap[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'remote_id' => $row['remoteId'],
                'name' => $row['name'] ?: $row['remoteId'],
                'mapped_to' => $row['category_id'] ? [
                    'category_id' => (int) $row['category_id'],
                    'name' => $row['xcart_category_name'],
                ] : null,
                'children' => [],
            ];
        }

        // Build tree
        $tree = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $parentId = $row['parent_id'] ? (int) $row['parent_id'] : null;

            if ($parentId === null || !isset($flatMap[$parentId])) {
                $tree[] = &$flatMap[$id];
            } else {
                $flatMap[$parentId]['children'][] = &$flatMap[$id];
            }
        }
        unset($flatMap);

        // Stats
        $total = count($rows);
        $mapped = 0;
        foreach ($rows as $row) {
            if ($row['category_id'] !== null) {
                $mapped++;
            }
        }

        return [
            'total' => $total,
            'mapped' => $mapped,
            'unmapped' => $total - $mapped,
            'categories' => $tree,
        ];
    }

    #[McpResource(
        uri: 'xcart://turn14/categories/unmapped',
        name: 'turn14_unmapped_categories',
        title: 'Turn14 Unmapped Categories',
        description: 'Turn14 categories not yet mapped to X-Cart categories',
        mimeType: 'application/json'
    )]
    public function getUnmappedCategories(): array
    {
        $this->requireTable();

        $rows = $this->conn->fetchAllAssociative(
            "SELECT t.id, t.parent_id, t.remoteId, t.name,
                    pt.name AS parent_name
             FROM {$this->t14Table} t
             LEFT JOIN {$this->t14Table} pt ON t.parent_id = pt.id
             WHERE t.category_id IS NULL
             ORDER BY t.parent_id IS NULL DESC, t.name ASC"
        );

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'] ?: $row['remoteId'],
                'remote_id' => $row['remoteId'],
                'parent' => $row['parent_id'] ? [
                    'id' => (int) $row['parent_id'],
                    'name' => $row['parent_name'],
                ] : null,
            ];
        }

        return [
            'count' => count($items),
            'items' => $items,
        ];
    }

    #[McpResource(
        uri: 'xcart://turn14/mapping-summary',
        name: 'turn14_mapping_summary',
        title: 'Turn14 Mapping Summary',
        description: 'Summary of Turn14 to X-Cart category mapping progress with suggestions',
        mimeType: 'application/json'
    )]
    public function getMappingSummary(): array
    {
        $this->requireTable();

        $lang = TableResolver::getDefaultLanguage();

        $total = (int) $this->conn->fetchOne("SELECT COUNT(*) FROM {$this->t14Table}");
        $mapped = (int) $this->conn->fetchOne("SELECT COUNT(*) FROM {$this->t14Table} WHERE category_id IS NOT NULL");
        $roots = (int) $this->conn->fetchOne("SELECT COUNT(*) FROM {$this->t14Table} WHERE parent_id IS NULL");
        $children = (int) $this->conn->fetchOne("SELECT COUNT(*) FROM {$this->t14Table} WHERE parent_id IS NOT NULL");

        // X-Cart category stats
        $xcartTotal = (int) $this->conn->fetchOne("SELECT COUNT(*) FROM {$this->catTable}");

        // Products link to categories through the CategoryProducts junction entity.
        // On Category this is a OneToMany inverse, so it has no Doctrine joinTable —
        // resolve the junction table from its own entity metadata and degrade
        // gracefully if it can't be queried.
        $xcartWithProducts = null;
        try {
            $cpTable = $this->em->getClassMetadata(\XLite\Model\CategoryProducts::class)->getTableName();
            $xcartWithProducts = (int) $this->conn->fetchOne(
                "SELECT COUNT(DISTINCT category_id) FROM {$cpTable}"
            );
        } catch (\Throwable) {
            $xcartWithProducts = null;
        }

        // Find potential name matches
        $potentialMatches = $this->conn->fetchAllAssociative(
            "SELECT t.id AS turn14_id, t.name AS turn14_name, c.category_id, ct.name AS xcart_name
             FROM {$this->t14Table} t
             INNER JOIN {$this->catTransTable} ct ON LOWER(TRIM(ct.name)) = LOWER(TRIM(t.name)) AND ct.code = :lang
             INNER JOIN {$this->catTable} c ON ct.id = c.category_id
             WHERE t.category_id IS NULL
             LIMIT 30",
            ['lang' => $lang]
        );

        $exactMatches = [];
        foreach ($potentialMatches as $m) {
            $exactMatches[] = [
                'turn14_id' => (int) $m['turn14_id'],
                'turn14_name' => $m['turn14_name'],
                'xcart_category_id' => (int) $m['category_id'],
                'xcart_name' => $m['xcart_name'],
            ];
        }

        return [
            'turn14' => [
                'total' => $total,
                'mapped' => $mapped,
                'unmapped' => $total - $mapped,
                'root_categories' => $roots,
                'subcategories' => $children,
                'mapping_percent' => $total > 0 ? round($mapped / $total * 100, 1) : 0,
            ],
            'xcart' => [
                'total_categories' => $xcartTotal,
                'categories_with_products' => $xcartWithProducts,
            ],
            'exact_name_matches' => $exactMatches,
            'exact_match_count' => count($exactMatches),
        ];
    }
}
