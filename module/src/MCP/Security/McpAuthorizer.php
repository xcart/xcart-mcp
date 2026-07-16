<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Security;

use Psr\Log\LoggerInterface;
use XC\MCP\MCP\Server\ToolCatalog;

class McpAuthorizer
{
    public function __construct(
        private readonly SecurityContextHolder $contextHolder,
        private readonly LoggerInterface $logger,
        private readonly ToolCatalog $toolCatalog,
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
     * Check if a tool is classified as dangerous.
     *
     * Danger is sourced solely from #[ToolAnnotation(destructiveHint: true)] via
     * ToolCatalog, so a new destructive tool is gated automatically without
     * touching any static list.
     */
    public function isDangerousTool(string $toolName): bool
    {
        return $this->toolCatalog->isDangerous($toolName);
    }

    /**
     * @return string[]
     */
    public function getDangerousTools(): array
    {
        return $this->toolCatalog->getDangerousToolNames();
    }
}
