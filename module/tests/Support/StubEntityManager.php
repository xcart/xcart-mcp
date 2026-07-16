<?php

declare(strict_types=1);

namespace Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Configurable EntityManager double for unit tests.
 *
 * - getClassMetadata($class) returns a StubClassMetadata whose table name comes
 *   from a per-class map. Classes may be mapped to a \Throwable which is thrown
 *   instead (to exercise "module not installed" degradation paths).
 * - getConnection() returns a supplied Connection double (or the throwing stub).
 * - createQueryBuilder() is not needed by the covered branches; it throws.
 *
 * @phpstan-type MetaValue string|\Throwable
 */
final class StubEntityManager implements EntityManagerInterface
{
    /**
     * @param array<string, string|\Throwable> $tableNames class-string => table name, or a Throwable to raise
     */
    public function __construct(
        private array $tableNames = [],
        private ?Connection $connection = null,
    ) {}

    /**
     * @param class-string|string $className
     */
    public function getClassMetadata($className): StubClassMetadata
    {
        if (!\array_key_exists($className, $this->tableNames)) {
            throw new \RuntimeException("No stub metadata configured for {$className}");
        }

        $value = $this->tableNames[$className];
        if ($value instanceof \Throwable) {
            throw $value;
        }

        return new StubClassMetadata($value);
    }

    public function getConnection(): Connection
    {
        return $this->connection ?? new Connection();
    }

    public function createQueryBuilder()
    {
        throw new \RuntimeException('createQueryBuilder not supported in StubEntityManager');
    }
}
