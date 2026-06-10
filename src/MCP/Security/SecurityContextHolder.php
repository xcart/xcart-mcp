<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Security;

/**
 * Holds the SecurityContext for the current request.
 *
 * Populated by ServerFactory before the MCP server processes the request.
 * Consumed by tool/resource classes to authorize operations.
 *
 * For STDIO transport, holds a full-access context by default.
 */
class SecurityContextHolder
{
    private SecurityContext $context;

    public function __construct()
    {
        // Default: full access (STDIO / CLI mode)
        $this->context = SecurityContext::fullAccess();
    }

    public function setContext(SecurityContext $context): void
    {
        $this->context = $context;
    }

    public function getContext(): SecurityContext
    {
        return $this->context;
    }
}
