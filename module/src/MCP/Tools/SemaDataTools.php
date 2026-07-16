<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Tools;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use XC\MCP\MCP\Security\McpAuthorizer;
use XC\MCP\MCP\Security\ToolAnnotation;
use XC\MCP\MCP\Util\CategoryFactory;
use XC\MCP\MCP\Util\QueryHelper;
use XC\MCP\MCP\Util\TableResolver;
use XLite\Model\Category;

class SemaDataTools
{
    private Connection $conn;
    private ?CategoryFactory $categoryFactory = null;
    private string $catTable;
    private string $catTransTable;
    private string $semaTable;
    private ?bool $tableExists = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly McpAuthorizer $authorizer,
        private readonly TableResolver $tableResolver,
    ) {
        $this->conn = $this->em->getConnection();
        $this->catTable = $this->tableResolver->resolve(Category::class);
        $this->catTransTable = $this->tableResolver->resolve(\XLite\Model\CategoryTranslation::class);
        $this->semaTable = $this->tableResolver->resolveTable('category_map_sema');
    }

    private function requireTable(): void
    {
        if ($this->tableExists === null) {
            try {
                $this->conn->fetchOne("SELECT 1 FROM {$this->semaTable} LIMIT 1");
                $this->tableExists = true;
            } catch (\Throwable) {
                $this->tableExists = false;
            }
        }
        if (!$this->tableExists) {
            throw new ToolCallException(
                'SEMA Data category mapping table not found. The XC\SemaData module must be installed and its data imported.'
            );
        }
    }

    private function getCategoryFactory(): CategoryFactory
    {
        if ($this->categoryFactory === null) {
            $this->categoryFactory = new CategoryFactory(
                $this->conn,
                $this->catTable,
                $this->catTransTable,
            );
        }
        return $this->categoryFactory;
    }

    #[McpTool(
        name: 'sema_categories_list',
        title: 'List SEMA Data Categories',
        description: 'List SEMA Data imported categories with mapping status. Filter by mapped/unmapped, parent, or search by name. SEMA Data has ~5800 categories that may repeat across root categories.'
    )]
    #[ToolAnnotation(readOnlyHint: true)]
    public function listCategories(
        ?string $filter = null,
        ?int $parentId = null,
        ?string $search = null,
        int $limit = 50,
        int $offset = 0,
    ): array {
        $this->authorizer->authorizeTool('sema_categories_list');
        $this->requireTable();

        $lang = TableResolver::getDefaultLanguage();
        $where = ['1=1'];
        $params = [];

        if ($filter === 'unmapped') {
            $where[] = 't.category_id IS NULL';
        } elseif ($filter === 'mapped') {
            $where[] = 't.category_id IS NOT NULL';
        }

        if ($parentId !== null) {
            $where[] = 't.parent_id = :parentId';
            $params['parentId'] = $parentId;
        }

        if ($search !== null) {
            $where[] = '(t.name LIKE :search OR t.remoteId LIKE :search)';
            $params['search'] = QueryHelper::likeContains($search);
        }

        $whereClause = implode(' AND ', $where);

        $total = (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM {$this->semaTable} t WHERE {$whereClause}",
            $params
        );

        $rows = $this->conn->fetchAllAssociative(
            "SELECT t.id, t.parent_id, t.remoteId, t.name, t.category_id, t.categoryName, t.inTree, t.position,
                    ct.name AS xcart_category_name
             FROM {$this->semaTable} t
             LEFT JOIN {$this->catTransTable} ct ON t.category_id = ct.id AND ct.code = :lang
             WHERE {$whereClause}
             ORDER BY t.parent_id IS NULL DESC, t.name ASC
             LIMIT :limit OFFSET :offset",
            array_merge($params, ['lang' => $lang, 'limit' => $limit, 'offset' => $offset]),
            array_merge(
                array_fill_keys(array_keys($params), \PDO::PARAM_STR),
                ['lang' => \PDO::PARAM_STR, 'limit' => \PDO::PARAM_INT, 'offset' => \PDO::PARAM_INT]
            )
        );

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'parent_id' => $row['parent_id'] ? (int) $row['parent_id'] : null,
                'remote_id' => $row['remoteId'],
                'name' => $row['name'],
                'mapped_to' => $row['category_id'] ? [
                    'category_id' => (int) $row['category_id'],
                    'name' => $row['xcart_category_name'],
                ] : null,
                'in_tree' => (bool) $row['inTree'],
            ];
        }

        return [
            'total' => $total,
            'items' => $items,
            'limit' => $limit,
            'offset' => $offset,
            'unmapped_count' => (int) $this->conn->fetchOne(
                "SELECT COUNT(*) FROM {$this->semaTable} WHERE category_id IS NULL"
            ),
            'mapped_count' => (int) $this->conn->fetchOne(
                "SELECT COUNT(*) FROM {$this->semaTable} WHERE category_id IS NOT NULL"
            ),
        ];
    }

    #[McpTool(
        name: 'sema_category_map',
        title: 'Map SEMA Data Category',
        description: 'Map a SEMA Data category to an existing X-Cart category. If xcart_category_id is omitted, creates a new X-Cart category.'
    )]
    #[ToolAnnotation(readOnlyHint: false)]
    public function mapCategory(
        int $semaCategoryId,
        ?int $xcartCategoryId = null,
        ?int $createUnderParentId = null,
    ): array {
        $this->authorizer->authorizeTool('sema_category_map');
        $this->requireTable();

        $lang = TableResolver::getDefaultLanguage();

        $sema = $this->conn->fetchAssociative(
            "SELECT * FROM {$this->semaTable} WHERE id = :id",
            ['id' => $semaCategoryId]
        );

        if (!$sema) {
            throw new ToolCallException("SEMA Data category #{$semaCategoryId} not found");
        }

        if ($xcartCategoryId === null) {
            $xcartCategoryId = $this->getCategoryFactory()->create(
                name: $sema['name'] ?: $sema['remoteId'],
                parentId: $createUnderParentId,
                lang: $lang,
            );
        } else {
            $xcartCategory = $this->em->getRepository(Category::class)->find($xcartCategoryId);
            if (!$xcartCategory) {
                throw new ToolCallException("X-Cart category #{$xcartCategoryId} not found");
            }
        }

        $this->conn->executeStatement(
            "UPDATE {$this->semaTable} SET category_id = :catId, updatedAt = :now WHERE id = :id",
            [
                'catId' => $xcartCategoryId,
                'now' => time(),
                'id' => $semaCategoryId,
            ]
        );

        $xcartName = $this->conn->fetchOne(
            "SELECT name FROM {$this->catTransTable} WHERE id = :id AND code = :lang",
            ['id' => $xcartCategoryId, 'lang' => $lang]
        );

        return [
            'sema_id' => $semaCategoryId,
            'sema_name' => $sema['name'] ?: $sema['remoteId'],
            'xcart_category_id' => $xcartCategoryId,
            'xcart_category_name' => $xcartName ?: null,
            'message' => 'SEMA Data category mapped successfully',
        ];
    }

    #[McpTool(
        name: 'sema_category_unmap',
        title: 'Unmap SEMA Data Category',
        description: 'Remove mapping between a SEMA Data category and X-Cart category.'
    )]
    #[ToolAnnotation(readOnlyHint: false)]
    public function unmapCategory(int $semaCategoryId): array
    {
        $this->authorizer->authorizeTool('sema_category_unmap');
        $this->requireTable();

        $sema = $this->conn->fetchAssociative(
            "SELECT * FROM {$this->semaTable} WHERE id = :id",
            ['id' => $semaCategoryId]
        );

        if (!$sema) {
            throw new ToolCallException("SEMA Data category #{$semaCategoryId} not found");
        }

        if ($sema['category_id'] === null) {
            return [
                'sema_id' => $semaCategoryId,
                'message' => 'Category is already unmapped',
            ];
        }

        $this->conn->executeStatement(
            "UPDATE {$this->semaTable} SET category_id = NULL, updatedAt = :now WHERE id = :id",
            ['now' => time(), 'id' => $semaCategoryId]
        );

        return [
            'sema_id' => $semaCategoryId,
            'sema_name' => $sema['name'] ?: $sema['remoteId'],
            'previous_xcart_id' => (int) $sema['category_id'],
            'message' => 'Mapping removed',
        ];
    }

    #[McpTool(
        name: 'sema_auto_map',
        title: 'Auto-Map SEMA Data Categories',
        description: 'Automatically map SEMA Data categories to X-Cart categories by matching names. When the same subcategory appears under multiple SEMA Data root categories, all duplicates are mapped to the same X-Cart category. Returns proposed mappings for review, or applies them if confirmed.'
    )]
    #[ToolAnnotation(readOnlyHint: false)]
    public function autoMap(
        bool $apply = false,
        bool $exactOnly = false,
    ): array {
        $this->authorizer->authorizeTool('sema_auto_map');
        $this->requireTable();

        $lang = TableResolver::getDefaultLanguage();

        $unmapped = $this->conn->fetchAllAssociative(
            "SELECT id, parent_id, remoteId, name FROM {$this->semaTable} WHERE category_id IS NULL"
        );

        $xcartCategories = $this->conn->fetchAllAssociative(
            "SELECT c.category_id, ct.name, c.depth
             FROM {$this->catTable} c
             LEFT JOIN {$this->catTransTable} ct ON c.category_id = ct.id AND ct.code = :lang
             WHERE ct.name IS NOT NULL AND ct.name != ''",
            ['lang' => $lang]
        );

        // Build lookup: lowercase name -> category
        $xcartByName = [];
        foreach ($xcartCategories as $xc) {
            $key = mb_strtolower(trim($xc['name']));
            $xcartByName[$key] = $xc;
        }

        $proposals = [];
        $applied = 0;

        foreach ($unmapped as $sema) {
            $semaName = $sema['name'] ?: $sema['remoteId'];
            $semaNameLower = mb_strtolower(trim($semaName));

            $match = null;
            $matchType = null;

            // 1. Exact match
            if (isset($xcartByName[$semaNameLower])) {
                $match = $xcartByName[$semaNameLower];
                $matchType = 'exact';
            }

            // 2. Fuzzy match (contains / contained)
            if (!$match && !$exactOnly) {
                $bestScore = 0;
                foreach ($xcartByName as $xcName => $xc) {
                    if (str_contains($semaNameLower, $xcName) || str_contains($xcName, $semaNameLower)) {
                        $score = similar_text($semaNameLower, $xcName);
                        if ($score > $bestScore) {
                            $bestScore = $score;
                            $match = $xc;
                            $matchType = 'fuzzy';
                        }
                    }
                }
            }

            if ($match) {
                $proposals[] = [
                    'sema_id' => (int) $sema['id'],
                    'sema_name' => $semaName,
                    'xcart_category_id' => (int) $match['category_id'],
                    'xcart_category_name' => $match['name'],
                    'match_type' => $matchType,
                ];
            }
        }

        if ($apply && !empty($proposals)) {
            $this->conn->beginTransaction();
            try {
                foreach ($proposals as $proposal) {
                    $this->conn->executeStatement(
                        "UPDATE {$this->semaTable} SET category_id = :catId, updatedAt = :now WHERE id = :id",
                        [
                            'catId' => $proposal['xcart_category_id'],
                            'now' => time(),
                            'id' => $proposal['sema_id'],
                        ]
                    );
                    $applied++;
                }
                $this->conn->commit();
            } catch (\Throwable $e) {
                $this->conn->rollBack();
                throw $e;
            }
        }

        $result = [
            'total_unmapped' => count($unmapped),
            'matches_found' => count($proposals),
            'proposals' => $proposals,
        ];

        if ($apply) {
            $result['applied'] = $applied;
            $result['message'] = "{$applied} mappings applied";
        } else {
            $result['message'] = count($proposals) . ' matches found. Call with apply=true to apply mappings.';
        }

        return $result;
    }

    #[McpTool(
        name: 'sema_bulk_map',
        title: 'Bulk Map SEMA Data Categories',
        description: 'Map multiple SEMA Data categories at once. Accepts an array of {sema_id, xcart_category_id} pairs. Use this after reviewing auto_map proposals or for batch mapping from LLM-generated suggestions.'
    )]
    #[ToolAnnotation(readOnlyHint: false)]
    public function bulkMap(
        array $mappings,
    ): array {
        $this->authorizer->authorizeTool('sema_bulk_map');
        $this->requireTable();

        if (empty($mappings)) {
            throw new ToolCallException('No mappings provided');
        }

        $results = [];
        $applied = 0;
        $errors = [];

        $this->conn->beginTransaction();
        try {
            foreach ($mappings as $mapping) {
                $semaId = $mapping['sema_id'] ?? null;
                $xcId = $mapping['xcart_category_id'] ?? null;

                if (!$semaId || !$xcId) {
                    $errors[] = "Invalid mapping entry: missing sema_id or xcart_category_id";
                    continue;
                }

                $exists = $this->conn->fetchOne(
                    "SELECT COUNT(*) FROM {$this->semaTable} WHERE id = :id",
                    ['id' => $semaId]
                );

                if (!$exists) {
                    $errors[] = "SEMA Data category #{$semaId} not found";
                    continue;
                }

                $xcExists = $this->em->getRepository(Category::class)->find($xcId);
                if (!$xcExists) {
                    $errors[] = "X-Cart category #{$xcId} not found";
                    continue;
                }

                $this->conn->executeStatement(
                    "UPDATE {$this->semaTable} SET category_id = :catId, updatedAt = :now WHERE id = :id",
                    ['catId' => $xcId, 'now' => time(), 'id' => $semaId]
                );

                $applied++;
                $results[] = ['sema_id' => $semaId, 'xcart_category_id' => $xcId];
            }

            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }

        return [
            'applied' => $applied,
            'errors' => $errors,
            'mappings' => $results,
            'message' => "{$applied} mappings applied" . (count($errors) ? ", " . count($errors) . " errors" : ""),
        ];
    }

    #[McpTool(
        name: 'sema_deduplicate_report',
        title: 'SEMA Data Deduplication Report',
        description: 'Analyze SEMA Data category duplication. Returns categories that appear under multiple root categories with their full paths. Useful for understanding the SEMA Data category structure before mapping.'
    )]
    #[ToolAnnotation(readOnlyHint: true)]
    public function deduplicateReport(
        int $limit = 50,
    ): array {
        $this->authorizer->authorizeTool('sema_deduplicate_report');
        $this->requireTable();

        // Find all duplicate category names with their parents
        $duplicates = $this->conn->fetchAllAssociative(
            "SELECT a.name,
                    GROUP_CONCAT(DISTINCT a.id ORDER BY a.id) AS ids,
                    GROUP_CONCAT(DISTINCT a.parent_id ORDER BY a.parent_id) AS parent_ids,
                    COUNT(*) AS occurrence_count
             FROM {$this->semaTable} a
             WHERE a.name IS NOT NULL AND a.name != ''
             GROUP BY a.name
             HAVING COUNT(DISTINCT parent_id) > 1
             ORDER BY occurrence_count DESC
             LIMIT :limit",
            ['limit' => $limit],
            ['limit' => \PDO::PARAM_INT]
        );

        $result = [];
        foreach ($duplicates as $dup) {
            $parentIds = array_filter(array_map('intval', explode(',', $dup['parent_ids'])));
            $ids = array_map('intval', explode(',', $dup['ids']));

            // Get parent names
            $parentNames = [];
            if (!empty($parentIds)) {
                $placeholders = implode(',', array_fill(0, count($parentIds), '?'));
                $parents = $this->conn->fetchAllAssociative(
                    "SELECT id, name FROM {$this->semaTable} WHERE id IN ({$placeholders})",
                    array_values($parentIds)
                );
                foreach ($parents as $p) {
                    $parentNames[(int) $p['id']] = $p['name'];
                }
            }

            $paths = [];
            foreach ($parentIds as $pid) {
                $paths[] = ($parentNames[$pid] ?? '(root)') . ' -> ' . $dup['name'];
            }

            $result[] = [
                'name' => $dup['name'],
                'occurrence_count' => (int) $dup['occurrence_count'],
                'sema_ids' => $ids,
                'parent_ids' => $parentIds,
                'paths' => $paths,
            ];
        }

        return [
            'total_duplicated_names' => count($result),
            'duplicates' => $result,
            'recommendation' => 'Map all duplicates of the same name to the same X-Cart category. Use sema_bulk_map to apply mappings efficiently.',
        ];
    }
}
