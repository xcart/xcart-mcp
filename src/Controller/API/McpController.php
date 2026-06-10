<?php

declare(strict_types=1);

namespace XC\MCP\Controller\API;

use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use XC\MCP\MCP\Security\McpAuthenticationException;
use XC\MCP\MCP\Security\McpAuthenticator;
use XC\MCP\MCP\Security\McpRateLimitException;
use XC\MCP\MCP\Security\OAuthSupport;
use XC\MCP\MCP\Security\RateLimiter;
use XC\MCP\MCP\Security\SecurityContext;
use XC\MCP\MCP\Server\ServerFactory;

class McpController
{
    public function __construct(
        private readonly ServerFactory $serverFactory,
        private readonly McpAuthenticator $authenticator,
        private readonly RateLimiter $rateLimiter,
        private readonly OAuthSupport $oauth,
    ) {}

    public function handle(Request $request): Response
    {
        // 0. Check if MCP module is enabled
        $mcpConfig = \XLite\Core\Config::getInstance()->XC?->MCP;
        if (!($mcpConfig?->mcp_enabled ?? true)) {
            return new JsonResponse(
                ['error' => 'MCP server is disabled'],
                Response::HTTP_SERVICE_UNAVAILABLE
            );
        }

        // 1. Handle DELETE (session termination) — return 200 OK
        if ($request->isMethod('DELETE')) {
            return new JsonResponse(null, Response::HTTP_OK);
        }

        // 2. Handle GET (SSE stream) — server-initiated streaming not wired through
        //    the Symfony bridge here, return 405 (POST request/response is used)
        if ($request->isMethod('GET')) {
            return new JsonResponse(
                ['jsonrpc' => '2.0', 'error' => ['code' => -32600, 'message' => 'SSE not supported, use POST']],
                Response::HTTP_METHOD_NOT_ALLOWED
            );
        }

        // 3. Convert Symfony Request to PSR-7 for authentication
        $psr7Request = $this->convertToPsr7($request);

        // 4. Authenticate. Two mutually exclusive paths:
        //    - OAuth (opt-in): the SDK AuthorizationMiddleware validates the JWT in the
        //      transport stack below; the token is the gate, so we grant a full-access
        //      context here. Misconfiguration fails closed (503), never open.
        //    - Default: built-in Bearer API key via McpAuthenticator.
        $oauthMiddleware = [];
        if ($this->oauth->isEnabled()) {
            try {
                $oauthMiddleware = $this->oauth->buildMiddleware($this->resourceUrl($request));
            } catch (\Throwable $e) {
                \XLite\Logger::getLogger('mcp')->error('MCP OAuth misconfigured: ' . $e->getMessage());

                return new JsonResponse(
                    ['error' => 'OAuth is enabled but misconfigured'],
                    Response::HTTP_SERVICE_UNAVAILABLE
                );
            }
            if ($oauthMiddleware === []) {
                return new JsonResponse(
                    ['error' => 'OAuth is enabled but issuer is not configured'],
                    Response::HTTP_SERVICE_UNAVAILABLE
                );
            }
            $securityContext = SecurityContext::fullAccess();
            // Rate-limit per token (hashed), not one shared bucket for all OAuth callers.
            $authHeader = $psr7Request->getHeaderLine('Authorization');
            $token = str_starts_with($authHeader, 'Bearer ') ? substr($authHeader, 7) : $authHeader;
            $rateKey = 'oauth:' . substr(hash('sha256', $token), 0, 16);
        } else {
            try {
                $securityContext = $this->authenticator->authenticate($psr7Request);
            } catch (McpAuthenticationException $e) {
                return new JsonResponse(
                    ['error' => $e->getMessage()],
                    Response::HTTP_UNAUTHORIZED
                );
            }
            $rateKey = (string) $securityContext->getApiKeyId();
        }

        // 5. Rate limiting (use configured value from module settings)
        $rateLimit = (int) ($mcpConfig?->rate_limit ?? 60);
        try {
            $this->rateLimiter->check($rateKey, $rateLimit);
        } catch (McpRateLimitException $e) {
            return new JsonResponse(
                ['error' => $e->getMessage()],
                Response::HTTP_TOO_MANY_REQUESTS
            );
        }

        // 6. Create MCP server with security context
        $server = $this->serverFactory->createServerForHttp($securityContext);

        // 7. Run MCP protocol over StreamableHttp transport.
        // SDK v0.6 installs a default middleware stack (CORS + DNS-rebinding +
        // protocol-version) when none is passed. We supply an explicit stack and
        // deliberately omit DnsRebindingProtectionMiddleware: its default allowlist
        // is localhost-only, so it would 403 every request to the store's real
        // domain. X-Cart sits behind nginx (Host enforced via server_name) and we
        // already require a Bearer token, which the middleware docs cite as the
        // case to omit it. CORS + protocol-version validation are kept.
        try {
            $middleware = array_merge(
                [new CorsMiddleware(), new ProtocolVersionMiddleware()],
                $oauthMiddleware,
            );
            $transport = new StreamableHttpTransport($psr7Request, middleware: $middleware);
            $psr7Response = $server->run($transport);
        } catch (\Throwable $e) {
            \XLite\Logger::getLogger('mcp')->error(sprintf(
                "MCP server error: %s in %s:%d\n%s",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString()
            ));

            return new JsonResponse(
                [
                    'jsonrpc' => '2.0',
                    'error' => [
                        'code' => -32603,
                        'message' => 'Internal server error',
                    ],
                    'id' => null,
                ],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        // 8. Convert PSR-7 response back to Symfony
        $response = $this->convertFromPsr7($psr7Response);

        // Prevent proxies from compressing MCP responses —
        // some clients (e.g. rmcp/Codex) don't handle gzip
        $response->headers->set('Content-Encoding', 'identity');

        return $response;
    }

    /**
     * Canonical URL of this MCP endpoint, used as the OAuth protected-resource
     * identifier / default audience.
     */
    private function resourceUrl(Request $request): string
    {
        return $request->getSchemeAndHttpHost() . $request->getPathInfo();
    }

    /**
     * Convert a Symfony HttpFoundation Request to a PSR-7 ServerRequest.
     */
    private function convertToPsr7(Request $request): ServerRequestInterface
    {
        $psr17Factory = new Psr17Factory();

        $factory = new PsrHttpFactory(
            $psr17Factory,
            $psr17Factory,
            $psr17Factory,
            $psr17Factory,
        );

        return $factory->createRequest($request);
    }

    /**
     * Convert a PSR-7 Response back to a Symfony HttpFoundation Response.
     */
    private function convertFromPsr7(ResponseInterface $psr7Response): Response
    {
        $factory = new HttpFoundationFactory();

        return $factory->createResponse($psr7Response);
    }
}
