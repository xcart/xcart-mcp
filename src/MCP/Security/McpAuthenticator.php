<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Security;

use Psr\Http\Message\ServerRequestInterface;
use XLite\Model\Repo\Profile\APIKey as ApiKeyRepository;

class McpAuthenticator
{
    public function __construct(
        private readonly ApiKeyRepository $apiKeyRepo,
    ) {}

    /**
     * Authenticate an HTTP request using Bearer token.
     *
     * Extracts the API key from the Authorization header, validates it against
     * the database, and ensures the associated profile is an active admin.
     *
     * @throws McpAuthenticationException When authentication fails
     */
    public function authenticate(ServerRequestInterface $request): SecurityContext
    {
        $token = $this->extractBearerToken($request);

        if ($token === null) {
            throw new McpAuthenticationException('Missing Authorization header');
        }

        return $this->authenticateByKey($token);
    }

    /**
     * Authenticate a STDIO session.
     *
     * Checks the MCP_API_KEY environment variable if set. If no key is
     * configured, grants full access (trusted local process).
     */
    public function authenticateStdio(): SecurityContext
    {
        $envKey = getenv('MCP_API_KEY');

        if ($envKey !== false && $envKey !== '') {
            return $this->authenticateByKey($envKey);
        }

        // No key configured — full access for local STDIO process
        return SecurityContext::fullAccess();
    }

    /**
     * Validate an API key string and build a SecurityContext.
     *
     * @throws McpAuthenticationException When the key is invalid, disabled, or non-admin
     */
    private function authenticateByKey(string $key): SecurityContext
    {
        // Check config-based key first
        $configKey = \XLite\Core\Config::getInstance()->XC?->MCP?->mcp_api_key ?? '';
        if ($configKey !== '' && hash_equals($configKey, $key)) {
            return SecurityContext::fullAccess();
        }

        // Fall back to DB API key lookup
        $apiKey = $this->apiKeyRepo->findActiveApiKey($key);

        if ($apiKey === null) {
            throw new McpAuthenticationException('Invalid or expired API key');
        }

        $profile = $apiKey->getProfile();

        if (!$profile->isAdmin()) {
            throw new McpAuthenticationException('MCP access requires admin profile');
        }

        return new SecurityContext(
            profile: $profile,
            apiKeyId: $apiKey->getId(),
        );
    }

    /**
     * Extract a Bearer token from the Authorization header.
     */
    private function extractBearerToken(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');

        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        return null;
    }
}
