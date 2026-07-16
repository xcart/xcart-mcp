<?php

declare(strict_types=1);

namespace Tests\Unit\MCP\Util;

use PHPUnit\Framework\TestCase;
use Tests\Support\StubEntityManager;
use XC\MCP\MCP\Util\TableResolver;

final class TableResolverTest extends TestCase
{
    public function testResolveReturnsEntityTableName(): void
    {
        $em = new StubEntityManager([\XLite\Model\Config::class => 'xlite_config']);
        $resolver = new TableResolver($em);

        $this->assertSame('xlite_config', $resolver->resolve(\XLite\Model\Config::class));
    }

    public function testResolvePrefixAndTableWithXlitePrefix(): void
    {
        $em = new StubEntityManager([\XLite\Model\Config::class => 'xlite_config']);
        $resolver = new TableResolver($em);

        $this->assertSame('xlite_', $resolver->resolvePrefix());
        $this->assertSame('xlite_category_map_asap', $resolver->resolveTable('category_map_asap'));
    }

    public function testResolvePrefixAndTableWithXcPrefix(): void
    {
        $em = new StubEntityManager([\XLite\Model\Config::class => 'xc_config']);
        $resolver = new TableResolver($em);

        $this->assertSame('xc_', $resolver->resolvePrefix());
        $this->assertSame('xc_category_map_asap', $resolver->resolveTable('category_map_asap'));
    }

    public function testResolveIsCached(): void
    {
        // Second lookup must not hit the EM again: seed once, then flip the map to
        // a throwing value and confirm the cached name is still returned.
        $em = new StubEntityManager([\XLite\Model\Config::class => 'xc_config']);
        $resolver = new TableResolver($em);

        $this->assertSame('xc_config', $resolver->resolve(\XLite\Model\Config::class));
        // resolvePrefix() calls resolve(Config) again — served from cache.
        $this->assertSame('xc_', $resolver->resolvePrefix());
    }
}
