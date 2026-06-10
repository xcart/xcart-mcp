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
     * Get the default language code from X-Cart config.
     */
    public static function getDefaultLanguage(): string
    {
        return \XLite\Core\Config::getInstance()->General?->default_language ?? 'en';
    }
}
