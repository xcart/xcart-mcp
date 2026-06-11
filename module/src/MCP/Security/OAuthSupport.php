<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Security;

use Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware;
use Mcp\Server\Transport\Http\OAuth\JwksProvider;
use Mcp\Server\Transport\Http\OAuth\JwtTokenValidator;
use Mcp\Server\Transport\Http\OAuth\OidcDiscovery;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Psr\SimpleCache\CacheInterface;

/**
 * Optional OAuth 2.0 bearer-token auth for the HTTP transport (MCP spec / SDK 0.5+).
 *
 * OFF by default. When enabled in settings, the SDK's AuthorizationMiddleware
 * validates a JWT access token (issuer/audience checked against a JWKS fetched via
 * OIDC discovery) before any MCP handler runs, and a Protected Resource Metadata
 * document is exposed so clients can discover the authorization server.
 *
 * This is an alternative to the built-in static API key. It requires an external
 * identity provider (issuer) and therefore cannot be exercised without one — treat
 * as experimental until validated against a real IdP. With OAuth disabled the
 * controller keeps using {@see McpAuthenticator} (Bearer API key) unchanged.
 *
 * Config keys (category XC\MCP):
 *   mcp_oauth_enabled   bool
 *   mcp_oauth_issuer    string  e.g. https://idp.example.com/realms/store
 *   mcp_oauth_audience  string  optional; defaults to this resource URL
 *   mcp_oauth_jwks_uri  string  optional; overrides JWKS URI from discovery
 */
class OAuthSupport
{
    public function __construct(
        private readonly CacheInterface $cache,
    ) {}

    public function isEnabled(): bool
    {
        $cfg = \XLite\Core\Config::getInstance()->XC?->MCP;

        return (bool) ($cfg?->mcp_oauth_enabled ?? false);
    }

    /**
     * Build the OAuth middleware to append to the transport stack.
     *
     * @return list<\Psr\Http\Server\MiddlewareInterface> empty if not configured
     *
     * @throws \Throwable on construction failure (caller decides how to fail)
     */
    public function buildMiddleware(string $resourceUrl): array
    {
        $cfg = \XLite\Core\Config::getInstance()->XC?->MCP;

        $issuer = trim((string) ($cfg?->mcp_oauth_issuer ?? ''));
        if ($issuer === '') {
            return [];
        }

        $audience = trim((string) ($cfg?->mcp_oauth_audience ?? '')) ?: $resourceUrl;
        $jwksUri = trim((string) ($cfg?->mcp_oauth_jwks_uri ?? '')) ?: null;

        $discovery = new OidcDiscovery(cache: $this->cache);
        $jwksProvider = new JwksProvider($discovery, cache: $this->cache);
        $validator = new JwtTokenValidator($issuer, $audience, $jwksProvider, jwksUri: $jwksUri);
        $metadata = new ProtectedResourceMetadata(
            authorizationServers: [$issuer],
            resource: $resourceUrl,
            resourceName: 'X-Cart MCP Server',
        );

        return [new AuthorizationMiddleware($validator, $metadata)];
    }
}
