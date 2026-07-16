<?php

declare(strict_types=1);

namespace Tests\Unit\MCP\Security;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use XC\MCP\MCP\Security\McpAuthorizationException;
use XC\MCP\MCP\Security\McpAuthorizer;
use XC\MCP\MCP\Security\SecurityContext;
use XC\MCP\MCP\Security\SecurityContextHolder;
use XC\MCP\MCP\Server\ToolCatalog;

/**
 * Current signature: McpAuthorizer(SecurityContextHolder, LoggerInterface, ToolCatalog).
 * authorizeTool(name) reads the context from the holder; danger gating reads
 * \XLite\Core\Config, whose test stub reports dangerous tools DISABLED.
 */
final class McpAuthorizerTest extends TestCase
{
    private function authorizer(SecurityContextHolder $holder): McpAuthorizer
    {
        return new McpAuthorizer($holder, new NullLogger(), new ToolCatalog());
    }

    public function testFullAccessBypassesAllChecks(): void
    {
        // Default holder context is fullAccess().
        $authorizer = $this->authorizer(new SecurityContextHolder());

        $authorizer->authorizeTool('product_delete');
        $authorizer->authorizeTool('product_bulk_update_prices');
        $authorizer->authorizeTool('vehicle_disable_all_then_enable');
        $authorizer->authorizeTool('anything');

        $this->addToAssertionCount(1);
    }

    public function testDangerousToolBlockedWhenDisabledAndNotFullAccess(): void
    {
        $holder = new SecurityContextHolder();
        $holder->setContext(new SecurityContext(apiKeyId: 7));
        $authorizer = $this->authorizer($holder);

        $this->expectException(McpAuthorizationException::class);
        $this->expectExceptionMessage('Tool "product_delete" is classified as dangerous and is disabled');

        $authorizer->authorizeTool('product_delete');
    }

    public function testNonDangerousToolAllowedWithEmptyAllowedList(): void
    {
        $holder = new SecurityContextHolder();
        $holder->setContext(new SecurityContext(apiKeyId: 7)); // empty allowedTools => all allowed
        $authorizer = $this->authorizer($holder);

        $authorizer->authorizeTool('product_search');
        $authorizer->authorizeTool('order_search');

        $this->addToAssertionCount(1);
    }

    public function testToolNotInExplicitAllowedListIsDenied(): void
    {
        $holder = new SecurityContextHolder();
        $holder->setContext(new SecurityContext(apiKeyId: 7, allowedTools: ['product_search']));
        $authorizer = $this->authorizer($holder);

        $this->expectException(McpAuthorizationException::class);
        $this->expectExceptionMessage('Tool "order_search" is not allowed for this API key');

        $authorizer->authorizeTool('order_search');
    }

    public function testIsDangerousTool(): void
    {
        $authorizer = $this->authorizer(new SecurityContextHolder());

        $this->assertTrue($authorizer->isDangerousTool('product_delete'));
        $this->assertTrue($authorizer->isDangerousTool('product_bulk_update_prices'));
        $this->assertTrue($authorizer->isDangerousTool('vehicle_disable_all_then_enable'));
        $this->assertFalse($authorizer->isDangerousTool('product_search'));
    }

    public function testGetDangerousToolsMatchesCatalog(): void
    {
        $authorizer = $this->authorizer(new SecurityContextHolder());

        $dangerous = $authorizer->getDangerousTools();
        sort($dangerous);

        $expected = ['product_bulk_update_prices', 'product_delete', 'vehicle_disable_all_then_enable'];
        sort($expected);

        $this->assertSame($expected, $dangerous);
    }
}
