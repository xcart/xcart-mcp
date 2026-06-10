<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Server;

use Mcp\Server\Builder;

/**
 * Implement this interface in other modules to register additional MCP
 * capabilities (tools, resources, prompts) with the ServerFactory.
 *
 * Tag services with "mcp.capability_provider" in services.yaml:
 *   App\MCP\MyProvider:
 *     tags: ['mcp.capability_provider']
 */
interface McpCapabilityProvider
{
    /**
     * Register capabilities on the MCP Server Builder.
     *
     * Use $builder->addTool(), $builder->addResource(), $builder->addPrompt()
     * to register your module's MCP capabilities.
     */
    public function register(Builder $builder): void;
}
