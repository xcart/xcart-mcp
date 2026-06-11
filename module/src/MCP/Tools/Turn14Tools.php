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

class Turn14Tools
{
    private Connection $conn;
    private ?CategoryFactory $categoryFactory = null;
    private string $catTable;
    private string $catTransTable;
    private string $t14Table = 'xc_category_map_turn14';
    private ?bool $tableExists = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly McpAuthorizer $authorizer,
        private readonly TableResolver $tableResolver,
    ) {
        $this->conn = $this->em->getConnection();
        $this->catTable = $this->tableResolver->resolve(Category::class);
        $this->catTransTable = $this->tableResolver->resolve(\XLite\Model\CategoryTranslation::class);
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
                'Turn14 category mapping table not found. The Turn14 integration module must be installed and its data imported before using Turn14 tools.'
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
        name: 'turn14_categories_list',
        title: 'List Turn14 Categories',
        description: 'List Turn14 imported categories with mapping status. Filter by mapped/unmapped, parent, or search by name.'
    )]
    #[ToolAnnotation(readOnlyHint: true)]
    public function listCategories(
        ?string $filter = null,
        ?int $parentId = null,
        ?string $search = null,
        int $limit = 50,
        int $offset = 0,
    ): array {
        $this->authorizer->authorizeTool('turn14_categories_list');
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

        // Count
        $total = (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM {$this->t14Table} t WHERE {$whereClause}",
            $params
        );

        // Fetch with X-Cart category name if mapped
        $rows = $this->conn->fetchAllAssociative(
            "SELECT t.id, t.parent_id, t.remoteId, t.name, t.category_id, t.categoryName, t.inTree, t.position,
                    ct.name AS xcart_category_name
             FROM {$this->t14Table} t
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
                "SELECT COUNT(*) FROM {$this->t14Table} WHERE category_id IS NULL"
            ),
            'mapped_count' => (int) $this->conn->fetchOne(
                "SELECT COUNT(*) FROM {$this->t14Table} WHERE category_id IS NOT NULL"
            ),
        ];
    }

    #[McpTool(
        name: 'turn14_category_map',
        title: 'Map Turn14 Category',
        description: 'Map a Turn14 category to an existing X-Cart category. If xcart_category_id is omitted, creates a new X-Cart category.'
    )]
    #[ToolAnnotation(readOnlyHint: false)]
    public function mapCategory(
        int $turn14CategoryId,
        ?int $xcartCategoryId = null,
        ?int $createUnderParentId = null,
    ): array {
        $this->authorizer->authorizeTool('turn14_category_map');
        $this->requireTable();

        $lang = TableResolver::getDefaultLanguage();

        // Get Turn14 category
        $turn14 = $this->conn->fetchAssociative(
            "SELECT * FROM {$this->t14Table} WHERE id = :id",
            ['id' => $turn14CategoryId]
        );

        if (!$turn14) {
            throw new ToolCallException("Turn14 category #{$turn14CategoryId} not found");
        }

        // If no X-Cart category specified, create one
        if ($xcartCategoryId === null) {
            $xcartCategoryId = $this->getCategoryFactory()->create(
                name: $turn14['name'] ?: $turn14['remoteId'],
                parentId: $createUnderParentId,
                lang: $lang,
            );
        } else {
            $xcartCategory = $this->em->getRepository(Category::class)->find($xcartCategoryId);
            if (!$xcartCategory) {
                throw new ToolCallException("X-Cart category #{$xcartCategoryId} not found");
            }
        }

        // Update mapping
        $this->conn->executeStatement(
            "UPDATE {$this->t14Table} SET category_id = :catId, updatedAt = :now WHERE id = :id",
            [
                'catId' => $xcartCategoryId,
                'now' => time(),
                'id' => $turn14CategoryId,
            ]
        );

        // Get X-Cart category name
        $xcartName = $this->conn->fetchOne(
            "SELECT name FROM {$this->catTransTable} WHERE id = :id AND code = :lang",
            ['id' => $xcartCategoryId, 'lang' => $lang]
        );

        return [
            'turn14_id' => $turn14CategoryId,
            'turn14_name' => $turn14['name'] ?: $turn14['remoteId'],
            'xcart_category_id' => $xcartCategoryId,
            'xcart_category_name' => $xcartName ?: null,
            'message' => 'Turn14 category mapped successfully',
        ];
    }

    #[McpTool(
        name: 'turn14_category_unmap',
        title: 'Unmap Turn14 Category',
        description: 'Remove mapping between a Turn14 category and X-Cart category.'
    )]
    #[ToolAnnotation(readOnlyHint: false)]
    public function unmapCategory(int $turn14CategoryId): array
    {
        $this->authorizer->authorizeTool('turn14_category_unmap');
        $this->requireTable();

        $turn14 = $this->conn->fetchAssociative(
            "SELECT * FROM {$this->t14Table} WHERE id = :id",
            ['id' => $turn14CategoryId]
        );

        if (!$turn14) {
            throw new ToolCallException("Turn14 category #{$turn14CategoryId} not found");
        }

        if ($turn14['category_id'] === null) {
            return [
                'turn14_id' => $turn14CategoryId,
                'message' => 'Category is already unmapped',
            ];
        }

        $this->conn->executeStatement(
            "UPDATE {$this->t14Table} SET category_id = NULL, updatedAt = :now WHERE id = :id",
            ['now' => time(), 'id' => $turn14CategoryId]
        );

        return [
            'turn14_id' => $turn14CategoryId,
            'turn14_name' => $turn14['name'] ?: $turn14['remoteId'],
            'previous_xcart_id' => (int) $turn14['category_id'],
            'message' => 'Mapping removed',
        ];
    }

    #[McpTool(
        name: 'turn14_auto_map',
        title: 'Auto-Map Turn14 Categories',
        description: 'Automatically map Turn14 categories to X-Cart categories by matching names. Returns proposed mappings for review, or applies them if confirmed.'
    )]
    #[ToolAnnotation(readOnlyHint: false)]
    public function autoMap(
        bool $apply = false,
        bool $exactOnly = false,
    ): array {
        $this->authorizer->authorizeTool('turn14_auto_map');
        $this->requireTable();

        $lang = TableResolver::getDefaultLanguage();

        // Get all unmapped Turn14 categories
        $unmapped = $this->conn->fetchAllAssociative(
            "SELECT id, parent_id, remoteId, name FROM {$this->t14Table} WHERE category_id IS NULL"
        );

        // Get all X-Cart categories with names
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

        foreach ($unmapped as $t14) {
            $t14Name = $t14['name'] ?: $t14['remoteId'];
            $t14NameLower = mb_strtolower(trim($t14Name));

            $match = null;
            $matchType = null;

            // 1. Exact match
            if (isset($xcartByName[$t14NameLower])) {
                $match = $xcartByName[$t14NameLower];
                $matchType = 'exact';
            }

            // 2. Fuzzy match (contains / contained)
            if (!$match && !$exactOnly) {
                $bestScore = 0;
                foreach ($xcartByName as $xcName => $xc) {
                    if (str_contains($t14NameLower, $xcName) || str_contains($xcName, $t14NameLower)) {
                        $score = similar_text($t14NameLower, $xcName);
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
                    'turn14_id' => (int) $t14['id'],
                    'turn14_name' => $t14Name,
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
                        "UPDATE {$this->t14Table} SET category_id = :catId, updatedAt = :now WHERE id = :id",
                        [
                            'catId' => $proposal['xcart_category_id'],
                            'now' => time(),
                            'id' => $proposal['turn14_id'],
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
        name: 'turn14_bulk_map',
        title: 'Bulk Map Turn14 Categories',
        description: 'Map multiple Turn14 categories at once. Accepts an array of {turn14_id, xcart_category_id} pairs.'
    )]
    #[ToolAnnotation(readOnlyHint: false)]
    public function bulkMap(
        array $mappings,
    ): array {
        $this->authorizer->authorizeTool('turn14_bulk_map');
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
                $t14Id = $mapping['turn14_id'] ?? null;
                $xcId = $mapping['xcart_category_id'] ?? null;

                if (!$t14Id || !$xcId) {
                    $errors[] = "Invalid mapping entry: missing turn14_id or xcart_category_id";
                    continue;
                }

                // Verify Turn14 category exists
                $exists = $this->conn->fetchOne(
                    "SELECT COUNT(*) FROM {$this->t14Table} WHERE id = :id",
                    ['id' => $t14Id]
                );

                if (!$exists) {
                    $errors[] = "Turn14 category #{$t14Id} not found";
                    continue;
                }

                // Verify X-Cart category exists
                $xcExists = $this->em->getRepository(Category::class)->find($xcId);
                if (!$xcExists) {
                    $errors[] = "X-Cart category #{$xcId} not found";
                    continue;
                }

                $this->conn->executeStatement(
                    "UPDATE {$this->t14Table} SET category_id = :catId, updatedAt = :now WHERE id = :id",
                    ['catId' => $xcId, 'now' => time(), 'id' => $t14Id]
                );

                $applied++;
                $results[] = ['turn14_id' => $t14Id, 'xcart_category_id' => $xcId];
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
}
