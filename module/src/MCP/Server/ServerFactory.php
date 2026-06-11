<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Server;

use Mcp\Capability\Registry;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Server;
use Mcp\Server\Session\Psr16SessionStore;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use XC\MCP\MCP\Security\SecurityContext;
use XC\MCP\MCP\Security\SecurityContextHolder;

class ServerFactory
{
    /** @var iterable<McpCapabilityProvider> */
    private readonly iterable $capabilityProviders;

    /**
     * @param iterable<McpCapabilityProvider> $capabilityProviders
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly CacheInterface $cache,
        private readonly SecurityContextHolder $contextHolder,
        iterable $capabilityProviders = [],
    ) {
        $this->capabilityProviders = $capabilityProviders;
    }

    /**
     * Build a base MCP Server instance with discovery and all capabilities.
     */
    public function createServer(): Server
    {
        $mcpConfig = \XLite\Core\Config::getInstance()->XC?->MCP;
        $serverName = $mcpConfig?->server_name ?: 'X-Cart MCP Server';

        // Own the registry so we can prune unavailable/forbidden capabilities
        // after discovery has populated it (SDK 0.6 RegistryInterface::unregister*).
        $registry = new Registry(logger: $this->logger);

        $builder = Server::builder()
            ->setServerInfo(
                $serverName,
                '1.0.0',
                'AI integration for X-Cart e-commerce platform'
            )
            ->setInstructions($this->getInstructions())
            ->setContainer($this->container)
            ->setLogger($this->logger)
            ->setRegistry($registry)
            // Session store with built-in garbage collection (setSession() defaults
            // gcProbability/gcDivisor to 1/100), so expired sessions in the PSR-16
            // cache get reaped instead of accumulating.
            ->setSession(new Psr16SessionStore($this->cache, 'mcp_session_', 7200))
            ->setProtocolVersion(ProtocolVersion::V2025_11_25)
            // MCP Apps extension (io.modelcontextprotocol/ui): lets the server expose
            // interactive HTML UI resources (ui://) that supporting clients render in a
            // sandboxed iframe. Capability is advertised; clients that don't support it
            // simply ignore the ui:// resources and tool _meta.ui links.
            ->enableExtension(new McpApps());

        // Discovery: scan MCP capability classes in the module.
        // No cache — avoids stale empty results when module files change.
        // Scanning ~40 PHP files via Finder is fast enough (~5ms).
        $builder->setDiscovery(
            basePath: dirname(__DIR__),
            scanDirs: ['Resources', 'Tools', 'Prompts'],
        );

        // Additional capability providers registered by other modules.
        // Since setDiscovery() can only be called once, providers register
        // their capabilities via addLoader() on the builder instead.
        foreach ($this->capabilityProviders as $provider) {
            $provider->register($builder);
        }

        $server = $builder->build();

        // Hide capabilities the store can't actually serve, so tools/list reflects
        // real capability instead of failing at call time.
        $this->pruneUnavailableCapabilities($registry, $mcpConfig);

        return $server;
    }

    /**
     * Remove tools/resources that cannot work in this install:
     *  - dangerous tools when dangerous_tools_enabled is off;
     *  - tools/resources of optional QSL modules that aren't installed.
     *
     * unregister*() is idempotent in SDK 0.6, so removing an absent name is a no-op.
     */
    private function pruneUnavailableCapabilities(RegistryInterface $registry, ?object $mcpConfig): void
    {
        // 1. Dangerous tools — gate by config (was previously only enforced at call time).
        if (!($mcpConfig?->dangerous_tools_enabled ?? false)) {
            foreach (['product_delete', 'product_bulk_update_prices', 'vehicle_disable_all_then_enable'] as $tool) {
                $registry->unregisterTool($tool);
            }
        }

        // 2. Vehicle tools/resources — require QSL\Make (Level1 entity).
        if (!$this->entityTableAvailable('QSL\Make\Model\Level1')) {
            foreach ([
                'vehicle_makes_list', 'vehicle_models_list', 'vehicle_years_list',
                'vehicle_make_toggle', 'vehicle_model_toggle', 'vehicle_bulk_toggle_makes',
                'vehicle_bulk_toggle_models', 'vehicle_set_year_range', 'vehicle_models_keep_only',
                'vehicle_disable_all_then_enable', 'vehicle_stats',
            ] as $tool) {
                $registry->unregisterTool($tool);
            }
            $registry->unregisterResource('xcart://vehicles/stats');
            $registry->unregisterResource('xcart://vehicles/makes');
        }

        // 3. Brand tools/resources — require QSL\ShopByBrand (Brand entity).
        if (!$this->entityTableAvailable('QSL\ShopByBrand\Model\Brand')) {
            foreach ([
                'brand_list', 'brand_get', 'brand_toggle', 'brand_update', 'brand_products',
            ] as $tool) {
                $registry->unregisterTool($tool);
            }
            $registry->unregisterResource('xcart://brands/list');
        }
    }

    /**
     * Whether a Doctrine entity is mapped and its table is queryable.
     * Uses ::class-style string (no autoload of a possibly-absent class).
     */
    private function entityTableAvailable(string $entityClass): bool
    {
        try {
            $em = \XLite\Core\Database::getEM();
            $table = $em->getClassMetadata($entityClass)->getTableName();
            $em->getConnection()->executeQuery("SELECT 1 FROM {$table} LIMIT 1");

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Create a server instance for HTTP transport with a security context.
     *
     * Sets the security context on the shared holder so that tool/resource
     * classes can access it for authorization checks during this request.
     */
    public function createServerForHttp(SecurityContext $securityContext): Server
    {
        // Populate the context holder BEFORE building/running the server.
        // All tool/resource classes injected with SecurityContextHolder
        // will read this context when authorizing operations.
        $this->contextHolder->setContext($securityContext);

        $server = $this->createServer();

        $this->logger->info('MCP HTTP server created', [
            'api_key_id' => $securityContext->getApiKeyId(),
            'admin_id' => $securityContext->getProfile()?->getId(),
            'full_access' => $securityContext->isFullAccess(),
        ]);

        return $server;
    }

    /**
     * Create a server instance for STDIO transport.
     *
     * STDIO uses in-memory sessions (single session per process).
     * SecurityContextHolder defaults to full access for STDIO.
     */
    public function createServerForStdio(): Server
    {
        return $this->createServer();
    }

    /**
     * Server instructions for the AI agent describing available capabilities.
     */
    private function getInstructions(): string
    {
        return <<<'TEXT'
        This MCP server provides access to X-Cart e-commerce platform.

        Available capabilities:
        - Read product catalog, orders, categories, customer profiles
        - Create, update, and delete products
        - Manage order statuses and add notes
        - Search across the entire store
        - Generate sales and inventory reports

        Use resources (xcart://) to read data.
        Use tools to perform actions.
        Use prompts for guided analysis workflows.
        TEXT;
    }
}
