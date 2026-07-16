<?php

declare(strict_types=1);

namespace Tests\Unit\MCP\Tools;

use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\StubEntityManager;
use XC\MCP\MCP\Security\McpAuthorizer;
use XC\MCP\MCP\Security\SecurityContextHolder;
use XC\MCP\MCP\Server\ToolCatalog;
use XC\MCP\MCP\Tools\VehicleTools;
use XC\MCP\MCP\Util\TableResolver;

/**
 * Graceful degradation: when the optional QSL\Make module is absent, vehicle
 * tools must throw a clean ToolCallException, never a fatal error.
 */
final class VehicleToolsTest extends TestCase
{
    public function testListMakesThrowsToolCallExceptionWhenModuleAbsent(): void
    {
        // fullAccess context bypasses gating so authorizeTool() returns and we
        // reach requireModule(), which fails because the Level1 entity is not
        // mapped (getClassMetadata throws -> caught -> moduleAvailable=false).
        $authorizer = new McpAuthorizer(
            new SecurityContextHolder(),
            new NullLogger(),
            new ToolCatalog(),
        );

        $em = new StubEntityManager([
            'QSL\Make\Model\Level1' => new \RuntimeException('entity not mapped'),
        ]);
        $tableResolver = new TableResolver($em);

        $tools = new VehicleTools($em, $authorizer, $tableResolver);

        $this->expectException(ToolCallException::class);
        $tools->listMakes();
    }
}
