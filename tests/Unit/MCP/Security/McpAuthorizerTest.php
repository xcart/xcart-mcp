<?php

declare(strict_types=1);

namespace Tests\Unit\MCP\Security;

use PHPUnit\Framework\TestCase;
use XC\MCP\MCP\Security\McpAuthorizationException;
use XC\MCP\MCP\Security\McpAuthorizer;
use XC\MCP\MCP\Security\SecurityContext;

class McpAuthorizerTest extends TestCase
{
    public function testAuthorizeToolAllowed(): void
    {
        $authorizer = new McpAuthorizer(dangerousToolsEnabled: false);

        $context = new SecurityContext(
            allowedTools: ['product_search', 'product_create', 'order_search'],
        );

        // Should not throw
        $authorizer->authorizeTool($context, 'product_search');
        $authorizer->authorizeTool($context, 'product_create');
        $authorizer->authorizeTool($context, 'order_search');

        $this->assertTrue(true); // Reached without exception
    }

    public function testAuthorizeToolDenied(): void
    {
        $authorizer = new McpAuthorizer(dangerousToolsEnabled: false);

        $context = new SecurityContext(
            allowedTools: ['product_search'],
        );

        $this->expectException(McpAuthorizationException::class);
        $this->expectExceptionMessage('Tool "order_update_status" is not allowed for this API key');

        $authorizer->authorizeTool($context, 'order_update_status');
    }

    public function testAuthorizeDangerousTool(): void
    {
        // Dangerous tools disabled (default)
        $authorizer = new McpAuthorizer(dangerousToolsEnabled: false);

        // Even with full access context that has the tool in allowed list,
        // dangerous tools must be explicitly enabled at the server level
        $context = new SecurityContext(
            allowedTools: ['product_delete', 'product_search'],
        );

        $this->expectException(McpAuthorizationException::class);
        $this->expectExceptionMessage('Tool "product_delete" is classified as dangerous and is disabled');

        $authorizer->authorizeTool($context, 'product_delete');
    }

    public function testAuthorizeDangerousToolWhenEnabled(): void
    {
        $authorizer = new McpAuthorizer(dangerousToolsEnabled: true);

        $context = new SecurityContext(
            allowedTools: ['product_delete'],
        );

        // Should not throw when dangerous tools are enabled and tool is in allowed list
        $authorizer->authorizeTool($context, 'product_delete');

        $this->assertTrue(true);
    }

    public function testAuthorizeToolFullAccessBypassesAllChecks(): void
    {
        $authorizer = new McpAuthorizer(dangerousToolsEnabled: false);

        $context = SecurityContext::fullAccess();

        // Full access bypasses everything, even dangerous tool checks
        $authorizer->authorizeTool($context, 'product_delete');
        $authorizer->authorizeTool($context, 'product_bulk_update_prices');
        $authorizer->authorizeTool($context, 'any_tool_name');

        $this->assertTrue(true);
    }

    public function testAuthorizeToolEmptyAllowedListPermitsAll(): void
    {
        $authorizer = new McpAuthorizer(dangerousToolsEnabled: true);

        $context = new SecurityContext(
            allowedTools: [], // Empty = all allowed
        );

        // Non-dangerous tools should be permitted with empty allowed list
        $authorizer->authorizeTool($context, 'product_search');
        $authorizer->authorizeTool($context, 'order_search');

        $this->assertTrue(true);
    }

    public function testIsDangerousTool(): void
    {
        $authorizer = new McpAuthorizer();

        $this->assertTrue($authorizer->isDangerousTool('product_delete'));
        $this->assertTrue($authorizer->isDangerousTool('product_bulk_update_prices'));
        $this->assertFalse($authorizer->isDangerousTool('product_search'));
        $this->assertFalse($authorizer->isDangerousTool('order_search'));
    }
}
