<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Security;

use XLite\Model\Profile;

/**
 * Per-request authorization context.
 *
 * Access policy is DEFAULT-OPEN: an authenticated API key (or a full-access
 * STDIO connection) may call every tool. The $allowedTools allow-list is
 * honoured by {@see canUseTool()} but there is no data source that ever
 * populates it (no per-key config field), so in practice the list is always
 * empty and every tool is permitted. Per-key ACL is intentionally NOT
 * implemented in this module; see the decision-log for the audit rationale.
 */
class SecurityContext
{
    /**
     * @param Profile|null $profile      Authenticated admin profile
     * @param int|null     $apiKeyId     API key identifier for rate limiting and logging
     * @param bool         $fullAccess   Bypass all authorization checks (STDIO local access)
     * @param string[]     $allowedTools Explicit list of allowed tool names (empty = all allowed)
     */
    public function __construct(
        private readonly ?Profile $profile = null,
        private readonly ?int $apiKeyId = null,
        private readonly bool $fullAccess = false,
        private readonly array $allowedTools = [],
    ) {}

    /**
     * Create a security context with unrestricted access.
     * Used for trusted STDIO connections (local process).
     */
    public static function fullAccess(): self
    {
        return new self(fullAccess: true);
    }

    /**
     * Check whether the given tool is permitted in this context.
     * If allowedTools is empty, all tools are permitted (default-open policy).
     */
    public function canUseTool(string $toolName): bool
    {
        if ($this->fullAccess) {
            return true;
        }

        if (empty($this->allowedTools)) {
            return true;
        }

        return in_array($toolName, $this->allowedTools, true);
    }

    public function getProfile(): ?Profile
    {
        return $this->profile;
    }

    public function getApiKeyId(): ?int
    {
        return $this->apiKeyId;
    }

    public function isFullAccess(): bool
    {
        return $this->fullAccess;
    }

    /**
     * @return string[]
     */
    public function getAllowedTools(): array
    {
        return $this->allowedTools;
    }
}
