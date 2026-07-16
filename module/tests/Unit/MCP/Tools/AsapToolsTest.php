<?php

declare(strict_types=1);

namespace Tests\Unit\MCP\Tools;

use Doctrine\DBAL\Connection;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\StubEntityManager;
use XC\MCP\MCP\Security\McpAuthorizer;
use XC\MCP\MCP\Security\SecurityContextHolder;
use XC\MCP\MCP\Server\ToolCatalog;
use XC\MCP\MCP\Tools\AsapTools;
use XC\MCP\MCP\Util\TableResolver;

/**
 * Graceful degradation: when the ASAP mapping table is absent, listing must
 * throw a clean ToolCallException, never a fatal error. The AsapTools ctor
 * eagerly resolves table names, so those metadata lookups must succeed; only
 * the runtime table-existence probe (fetchOne) fails.
 */
final class AsapToolsTest extends TestCase
{
    public function testListCategoriesThrowsToolCallExceptionWhenTableAbsent(): void
    {
        $authorizer = new McpAuthorizer(
            new SecurityContextHolder(),
            new NullLogger(),
            new ToolCatalog(),
        );

        // fetchOne() throws -> requireTable() catches -> tableExists=false.
        $connection = new class extends Connection {
            public function fetchOne($sql, array $params = [], array $types = [])
            {
                throw new \RuntimeException('table not found');
            }
        };

        $em = new StubEntityManager(
            [
                \XLite\Model\Config::class => 'xc_config',
                \XLite\Model\Category::class => 'xc_categories',
                \XLite\Model\CategoryTranslation::class => 'xc_category_translations',
            ],
            $connection,
        );
        $tableResolver = new TableResolver($em);

        $tools = new AsapTools($em, $authorizer, $tableResolver);

        $this->expectException(ToolCallException::class);
        $tools->listCategories();
    }
}
