<?php

declare(strict_types=1);

namespace Tests\Unit\Server;

use Mcp\Server;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use XC\MCP\MCP\Security\SecurityContext;
use XC\MCP\MCP\Server\ServerFactory;
use XLite\Model\Profile;

class ServerFactoryTest extends TestCase
{
    private ContainerInterface&MockObject $container;
    private LoggerInterface&MockObject $logger;
    private CacheInterface&MockObject $cache;
    private ServerFactory $factory;

    protected function setUp(): void
    {
        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->cache = $this->createMock(CacheInterface::class);

        $this->factory = new ServerFactory(
            $this->container,
            $this->logger,
            $this->cache,
        );
    }

    public function testCreateServerReturnsServer(): void
    {
        $server = $this->factory->createServer();

        $this->assertInstanceOf(Server::class, $server);
    }

    public function testCreateServerForHttpWithSecurityContext(): void
    {
        $profile = $this->createMock(Profile::class);
        $profile->method('getId')->willReturn(1);

        $securityContext = new SecurityContext(
            profile: $profile,
            apiKeyId: 42,
            fullAccess: false,
            allowedTools: ['product_search'],
        );

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with('MCP HTTP server created', [
                'api_key_id' => 42,
                'admin_id' => 1,
                'full_access' => false,
            ]);

        $server = $this->factory->createServerForHttp($securityContext);

        $this->assertInstanceOf(Server::class, $server);
    }

    public function testCreateServerForStdio(): void
    {
        $server = $this->factory->createServerForStdio();

        $this->assertInstanceOf(Server::class, $server);
    }
}
