<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Util;

use Doctrine\DBAL\Connection;
use Mcp\Exception\ToolCallException;

/**
 * Creates X-Cart categories via raw SQL with proper nested set (lpos/rpos)
 * calculations and transaction safety.
 *
 * X-Cart uses nested sets for category hierarchy. The ORM's Category entity
 * cannot be used for INSERT because lpos/rpos are NOT NULL and the ORM doesn't
 * populate them. Additionally, the xc_categories table may have extra NOT NULL
 * columns added by various modules — these are detected dynamically.
 */
class CategoryFactory
{
    /** Core columns always present in xc_categories */
    private const CORE_COLUMNS = [
        'lpos', 'rpos', 'enabled', 'show_title', 'depth', 'pos',
        'metaDescType', 'parent_id',
    ];

    /**
     * Module-added columns that may be NOT NULL without defaults.
     * Maps column name => default value to use when inserting.
     */
    private const OPTIONAL_COLUMNS = [
        'useCustomOG'         => 0,
        'autoPendingEnabled'  => 0,
        'csCreated'           => '__NOW__',
        'csLastUpdate'        => '__NOW__',
        'xcPendingExport'     => 0,
        'wheelTireSizeFilter' => '',
    ];

    private ?array $extraColumns = null;

    public function __construct(
        private readonly Connection $conn,
        private readonly string $categoriesTable,
        private readonly string $translationsTable,
    ) {}

    /**
     * Create a new X-Cart category with proper nested set values.
     *
     * @throws ToolCallException
     */
    public function create(
        string $name,
        ?int $parentId = null,
        bool $enabled = true,
        string $description = '',
        string $lang = 'en',
    ): int {
        $this->conn->beginTransaction();

        try {
            // A top-level category must still nest INSIDE the hidden root node
            // (depth = -1). Resolve that root and treat it as the parent. The
            // previous behaviour appended past MAX(rpos), which placed the node
            // outside the root's lpos/rpos range and corrupted the nested set so
            // the category never appeared in the catalog tree.
            if ($parentId === null) {
                $parentId = (int) $this->conn->fetchOne(
                    "SELECT category_id FROM {$this->categoriesTable} WHERE depth = -1 ORDER BY lpos ASC LIMIT 1"
                ) ?: null;
                if ($parentId === null) {
                    throw new ToolCallException('Root category (depth = -1) not found; cannot create a top-level category');
                }
            }

            $parent = $this->conn->fetchAssociative(
                "SELECT category_id, lpos, rpos, depth FROM {$this->categoriesTable} WHERE category_id = :id FOR UPDATE",
                ['id' => $parentId]
            );
            if (!$parent) {
                throw new ToolCallException("Parent category #{$parentId} not found");
            }

            $parentRpos = (int) $parent['rpos'];
            $newLpos = $parentRpos;
            $newRpos = $parentRpos + 1;
            $depth = (int) $parent['depth'] + 1;

            // Make room: shift the parent's own rpos and every node to its right.
            $this->conn->executeStatement(
                "UPDATE {$this->categoriesTable} SET rpos = rpos + 2 WHERE rpos >= :rpos",
                ['rpos' => $parentRpos]
            );
            $this->conn->executeStatement(
                "UPDATE {$this->categoriesTable} SET lpos = lpos + 2 WHERE lpos > :rpos",
                ['rpos' => $parentRpos]
            );

            $now = time();

            // Build dynamic column list and values
            $columns = 'lpos, rpos, enabled, show_title, depth, pos, metaDescType, parent_id';
            $placeholders = ':lpos, :rpos, :enabled, 1, :depth, 0, :metaDescType, :parentId';
            $params = [
                'lpos' => $newLpos,
                'rpos' => $newRpos,
                'enabled' => $enabled ? 1 : 0,
                'depth' => $depth,
                'metaDescType' => 'A',
                'parentId' => $parentId,
            ];

            // Append any extra NOT NULL columns detected in this installation
            foreach ($this->getExtraColumns() as $col => $default) {
                $value = ($default === '__NOW__') ? $now : $default;
                $columns .= ", {$col}";
                $placeholders .= ", :extra_{$col}";
                $params["extra_{$col}"] = $value;
            }

            $this->conn->executeStatement(
                "INSERT INTO {$this->categoriesTable} ({$columns}) VALUES ({$placeholders})",
                $params
            );

            $categoryId = (int) $this->conn->lastInsertId();

            $this->conn->executeStatement(
                "INSERT INTO {$this->translationsTable}
                    (id, name, description, metaTags, metaDesc, metaTitle, code)
                 VALUES (:id, :name, :description, '', '', '', :lang)",
                [
                    'id' => $categoryId,
                    'name' => $name,
                    'description' => $description,
                    'lang' => $lang,
                ]
            );

            $this->conn->commit();

            return $categoryId;
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }

    /**
     * Detect which optional NOT NULL columns actually exist in this installation.
     * Result is cached for the lifetime of this instance.
     *
     * @return array<string, mixed> column => default value
     */
    private function getExtraColumns(): array
    {
        if ($this->extraColumns !== null) {
            return $this->extraColumns;
        }

        $this->extraColumns = [];

        $tableColumns = $this->conn->fetchAllAssociative(
            "SHOW COLUMNS FROM {$this->categoriesTable}"
        );

        $existingColumns = [];
        foreach ($tableColumns as $row) {
            $existingColumns[$row['Field']] = $row;
        }

        foreach (self::OPTIONAL_COLUMNS as $col => $default) {
            if (isset($existingColumns[$col])) {
                $info = $existingColumns[$col];
                // Only include if NOT NULL and no server default
                if ($info['Null'] === 'NO' && $info['Default'] === null) {
                    $this->extraColumns[$col] = $default;
                }
            }
        }

        return $this->extraColumns;
    }
}
