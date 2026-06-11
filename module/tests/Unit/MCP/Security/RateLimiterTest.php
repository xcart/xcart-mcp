<?php

declare(strict_types=1);

namespace Tests\Unit\MCP\Security;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use XC\MCP\MCP\Security\McpRateLimitException;
use XC\MCP\MCP\Security\RateLimiter;

class RateLimiterTest extends TestCase
{
    private CacheInterface&MockObject $cache;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheInterface::class);
    }

    public function testAllowsRequestsUnderLimit(): void
    {
        $limiter = new RateLimiter($this->cache, maxRequests: 60, windowSeconds: 60);

        $cacheKey = 'mcp_rate_' . md5('api-key-1');

        $this->cache
            ->expects($this->once())
            ->method('get')
            ->with($cacheKey, 0)
            ->willReturn(10); // 10 requests so far, well under 60

        $this->cache
            ->expects($this->once())
            ->method('set')
            ->with($cacheKey, 11, 60);

        // Should not throw
        $limiter->check('api-key-1');
    }

    public function testBlocksRequestsOverLimit(): void
    {
        $limiter = new RateLimiter($this->cache, maxRequests: 5, windowSeconds: 60);

        $cacheKey = 'mcp_rate_' . md5('api-key-2');

        $this->cache
            ->expects($this->once())
            ->method('get')
            ->with($cacheKey, 0)
            ->willReturn(5); // Already at limit

        $this->cache
            ->expects($this->never())
            ->method('set');

        $this->expectException(McpRateLimitException::class);
        $this->expectExceptionMessage('Rate limit exceeded: 5 requests per 60 seconds');

        $limiter->check('api-key-2');
    }

    public function testDifferentKeysIndependent(): void
    {
        $limiter = new RateLimiter($this->cache, maxRequests: 3, windowSeconds: 60);

        $cacheKeyA = 'mcp_rate_' . md5('key-a');
        $cacheKeyB = 'mcp_rate_' . md5('key-b');

        // Key A is at limit
        $this->cache
            ->method('get')
            ->willReturnCallback(function (string $key) use ($cacheKeyA, $cacheKeyB): int {
                return match ($key) {
                    $cacheKeyA => 3,  // At limit
                    $cacheKeyB => 1,  // Under limit
                    default => 0,
                };
            });

        $this->cache
            ->expects($this->once())
            ->method('set')
            ->with($cacheKeyB, 2, 60);

        // Key A should be blocked
        try {
            $limiter->check('key-a');
            $this->fail('Expected McpRateLimitException for key-a');
        } catch (McpRateLimitException) {
            // Expected
        }

        // Key B should be allowed
        $limiter->check('key-b');
    }

    public function testGetCount(): void
    {
        $limiter = new RateLimiter($this->cache, maxRequests: 60);

        $cacheKey = 'mcp_rate_' . md5('api-key-1');

        $this->cache
            ->expects($this->once())
            ->method('get')
            ->with($cacheKey, 0)
            ->willReturn(25);

        $this->assertSame(25, $limiter->getCount('api-key-1'));
    }

    public function testReset(): void
    {
        $limiter = new RateLimiter($this->cache, maxRequests: 60);

        $cacheKey = 'mcp_rate_' . md5('api-key-1');

        $this->cache
            ->expects($this->once())
            ->method('delete')
            ->with($cacheKey);

        $limiter->reset('api-key-1');
    }

    public function testBoundaryExactlyAtLimit(): void
    {
        $limiter = new RateLimiter($this->cache, maxRequests: 10, windowSeconds: 30);

        $cacheKey = 'mcp_rate_' . md5('boundary-key');

        // At 9, should still be allowed (one more request before hitting 10)
        $this->cache
            ->expects($this->once())
            ->method('get')
            ->with($cacheKey, 0)
            ->willReturn(9);

        $this->cache
            ->expects($this->once())
            ->method('set')
            ->with($cacheKey, 10, 30);

        $limiter->check('boundary-key');
    }
}
