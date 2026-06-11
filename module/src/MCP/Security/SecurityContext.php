<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Security;

use XLite\Model\Profile;

class SecurityContext
{
    /**
     * @param Profile|null $profile      Authenticated admin profile
     * @param int|null     $apiKeyId     API key identifier for rate limiting and logging
     * @param bool         $fullAccess   Bypass all authorization checks (STDIO local access)
     * @param string[]     $allowedTools Explicit list of allowed tool names (empty = all allowed)
     * @param string[]     $allowedResources Explicit list of allowed resource URIs (empty = all allowed)
     */
    public function __construct(
        private readonly ?Profile $profile = null,
        private readonly ?int $apiKeyId = null,
        private readonly bool $fullAccess = false,
        private readonly array $allowedTools = [],
        private readonly array $allowedResources = [],
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
     * If allowedTools is empty, all tools are permitted (default open policy).
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

    /**
     * Check whether the given resource URI is readable in this context.
     * If allowedResources is empty, all resources are readable (default open policy).
     */
    public function canReadResource(string $uri): bool
    {
        if ($this->fullAccess) {
            return true;
        }

        if (empty($this->allowedResources)) {
            return true;
        }

        return in_array($uri, $this->allowedResources, true);
    }

    /**
     * Check whether a tool has been explicitly listed in the allowed set.
     * Unlike canUseTool(), returns false when the allowedTools list is empty.
     */
    public function isToolExplicitlyAllowed(string $toolName): bool
    {
        if ($this->fullAccess) {
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

    /**
     * @return string[]
     */
    public function getAllowedResources(): array
    {
        return $this->allowedResources;
    }
}
