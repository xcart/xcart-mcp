<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Security;

use Psr\Log\LoggerInterface;

class McpAuthorizer
{
    /**
     * Tools that can cause data loss or significant changes.
     * Kept as a fallback for tools that lack a ToolAnnotation attribute.
     */
    private const DANGEROUS_TOOLS = [
        'product_delete',
        'product_bulk_update_prices',
        'vehicle_disable_all_then_enable',
    ];

    public function __construct(
        private readonly SecurityContextHolder $contextHolder,
        private readonly LoggerInterface $logger,
    ) {}

    private function isDangerousToolsEnabled(): bool
    {
        $config = \XLite\Core\Config::getInstance()->XC?->MCP;

        return (bool) ($config?->dangerous_tools_enabled ?? false);
    }

    /**
     * Authorize a tool call within the current security context.
     *
     * @throws McpAuthorizationException When the tool is not allowed
     */
    public function authorizeTool(string $toolName): void
    {
        $context = $this->contextHolder->getContext();

        // Full access bypasses all checks (STDIO local mode)
        if ($context->isFullAccess()) {
            return;
        }

        // Check dangerous tools first
        if ($this->isDangerousTool($toolName) && !$this->isDangerousToolsEnabled()) {
            $this->logger->warning('Blocked dangerous tool call', [
                'tool' => $toolName,
                'api_key_id' => $context->getApiKeyId(),
            ]);

            throw new McpAuthorizationException(
                sprintf('Tool "%s" is classified as dangerous and is disabled', $toolName)
            );
        }

        // Check explicit allowed list
        if (!$context->canUseTool($toolName)) {
            $this->logger->warning('Blocked unauthorized tool call', [
                'tool' => $toolName,
                'api_key_id' => $context->getApiKeyId(),
            ]);

            throw new McpAuthorizationException(
                sprintf('Tool "%s" is not allowed for this API key', $toolName)
            );
        }

        $this->logger->debug('Tool call authorized', [
            'tool' => $toolName,
            'api_key_id' => $context->getApiKeyId(),
        ]);
    }

    /**
     * Authorize a resource read within the current security context.
     *
     * @throws McpAuthorizationException When the resource is not allowed
     */
    public function authorizeResource(string $uri): void
    {
        $context = $this->contextHolder->getContext();

        if ($context->isFullAccess()) {
            return;
        }

        if (!$context->canReadResource($uri)) {
            $this->logger->warning('Blocked unauthorized resource read', [
                'uri' => $uri,
                'api_key_id' => $context->getApiKeyId(),
            ]);

            throw new McpAuthorizationException(
                sprintf('Resource "%s" is not allowed for this API key', $uri)
            );
        }
    }

    /**
     * Check if a tool is classified as dangerous.
     *
     * Reads ToolAnnotation attribute if available, falls back to the static list.
     */
    public function isDangerousTool(string $toolName): bool
    {
        return in_array($toolName, self::DANGEROUS_TOOLS, true);
    }

    /**
     * @return string[]
     */
    public function getDangerousTools(): array
    {
        return self::DANGEROUS_TOOLS;
    }
}
