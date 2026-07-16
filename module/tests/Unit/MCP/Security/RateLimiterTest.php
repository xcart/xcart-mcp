<?php

declare(strict_types=1);

namespace Tests\Unit\MCP\Security;

use PHPUnit\Framework\TestCase;
use Tests\Support\ArrayCache;
use XC\MCP\MCP\Security\McpRateLimitException;
use XC\MCP\MCP\Security\RateLimiter;

/**
 * Current signature: RateLimiter(CacheInterface $cache, int $windowSeconds = 60).
 * check(string $key, int $maxRequests = 60). Uses a has()/set() spin-lock around
 * a get()/set() increment; a real in-memory cache exercises that path faithfully.
 */
final class RateLimiterTest extends TestCase
{
    private ArrayCache $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayCache();
    }

    public function testAllowsRequestsUnderLimitAndIncrements(): void
    {
        $limiter = new RateLimiter($this->cache);

        $this->assertSame(0, $limiter->getCount('api-key-1'));

        $limiter->check('api-key-1', 60);
        $this->assertSame(1, $limiter->getCount('api-key-1'));

        $limiter->check('api-key-1', 60);
        $this->assertSame(2, $limiter->getCount('api-key-1'));
    }

    public function testBlocksRequestsAtLimit(): void
    {
        $limiter = new RateLimiter($this->cache);

        // Five allowed calls fill the window (0->5).
        for ($i = 0; $i < 5; $i++) {
            $limiter->check('api-key-2', 5);
        }
        $this->assertSame(5, $limiter->getCount('api-key-2'));

        $this->expectException(McpRateLimitException::class);
        $this->expectExceptionMessage('Rate limit exceeded: 5 requests per 60 seconds');

        $limiter->check('api-key-2', 5);
    }

    public function testDifferentKeysAreIndependent(): void
    {
        $limiter = new RateLimiter($this->cache);

        for ($i = 0; $i < 3; $i++) {
            $limiter->check('key-a', 3);
        }

        // key-a is now at its limit.
        try {
            $limiter->check('key-a', 3);
            $this->fail('Expected McpRateLimitException for key-a');
        } catch (McpRateLimitException) {
            // expected
        }

        // key-b is untouched and must still be allowed.
        $limiter->check('key-b', 3);
        $this->assertSame(1, $limiter->getCount('key-b'));
    }

    public function testGetCountReflectsStoredValue(): void
    {
        $limiter = new RateLimiter($this->cache);

        $limiter->check('counter-key', 60);
        $limiter->check('counter-key', 60);
        $limiter->check('counter-key', 60);

        $this->assertSame(3, $limiter->getCount('counter-key'));
    }

    public function testResetClearsCounter(): void
    {
        $limiter = new RateLimiter($this->cache);

        $limiter->check('reset-key', 60);
        $this->assertSame(1, $limiter->getCount('reset-key'));

        $limiter->reset('reset-key');
        $this->assertSame(0, $limiter->getCount('reset-key'));
    }

    public function testCustomWindowSecondsAppearInExceptionMessage(): void
    {
        $limiter = new RateLimiter($this->cache, windowSeconds: 30);

        $limiter->check('window-key', 1);

        $this->expectException(McpRateLimitException::class);
        $this->expectExceptionMessage('Rate limit exceeded: 1 requests per 30 seconds');

        $limiter->check('window-key', 1);
    }

    public function testLockIsReleasedAfterEachCheck(): void
    {
        $limiter = new RateLimiter($this->cache);

        $limiter->check('lock-key', 60);

        // If the lock were leaked, the internal lock key would remain set and the
        // next check would spin-fail. A successful second call proves release.
        $lockKey = 'mcp_rate_' . md5('lock-key') . '_lock';
        $this->assertFalse($this->cache->has($lockKey));

        $limiter->check('lock-key', 60);
        $this->assertSame(2, $limiter->getCount('lock-key'));
    }
}
