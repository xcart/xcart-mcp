<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Minimal Doctrine ClassMetadata double: only getTableName() is used by the
 * code under test (TableResolver).
 */
final class StubClassMetadata
{
    public function __construct(private readonly string $tableName) {}

    public function getTableName(): string
    {
        return $this->tableName;
    }
}
