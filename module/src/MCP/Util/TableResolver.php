<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Util;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Resolves actual database table names from Doctrine entity metadata.
 * Avoids hardcoding the xc_ prefix which may differ across installations.
 */
class TableResolver
{
    /** @var array<string, string> */
    private array $cache = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Get the table name for an entity class.
     */
    public function resolve(string $entityClass): string
    {
        if (!isset($this->cache[$entityClass])) {
            $this->cache[$entityClass] = $this->em->getClassMetadata($entityClass)->getTableName();
        }

        return $this->cache[$entityClass];
    }

    /**
     * Resolve the installation's table-name prefix from Doctrine metadata.
     *
     * Supplier mapping tables (category_map_asap/turn14/sema) are plain DBAL
     * tables, not Doctrine entities, so their name cannot be resolved directly.
     * We derive the prefix from the always-present core Config entity — its
     * unprefixed table name is "config", so whatever precedes it in the mapped
     * table name is the prefix (e.g. "xc_" or a custom "xlite_"). This avoids
     * hardcoding "xc_", which silently breaks on non-default prefixes.
     */
    public function resolvePrefix(): string
    {
        $configTable = $this->resolve(\XLite\Model\Config::class);

        return str_ends_with($configTable, 'config')
            ? substr($configTable, 0, -\strlen('config'))
            : '';
    }

    /**
     * Resolve a non-entity table name by prefixing its base name.
     */
    public function resolveTable(string $baseName): string
    {
        return $this->resolvePrefix() . $baseName;
    }

    /**
     * Get the default language code from X-Cart config.
     */
    public static function getDefaultLanguage(): string
    {
        return \XLite\Core\Config::getInstance()->General?->default_language ?? 'en';
    }
}
