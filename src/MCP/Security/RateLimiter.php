<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Security;

use Psr\SimpleCache\CacheInterface;

class RateLimiter
{
    private const CACHE_PREFIX = 'mcp_rate_';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly int $windowSeconds = 60,
    ) {}

    /**
     * Check whether a request is allowed for the given key.
     *
     * Uses a lock key to prevent TOCTOU race conditions where concurrent
     * requests could both pass the limit check before either increments.
     * Fails closed: if the lock cannot be acquired, the request is rejected.
     *
     * @throws McpRateLimitException When the rate limit is exceeded or lock cannot be acquired
     */
    public function check(string $key, int $maxRequests = 60): void
    {
        $cacheKey = self::CACHE_PREFIX . md5($key);
        $lockKey = $cacheKey . '_lock';

        // Spin-wait for lock (max ~100ms)
        $acquired = false;
        for ($i = 0; $i < 10; $i++) {
            if (!$this->cache->has($lockKey)) {
                $this->cache->set($lockKey, 1, 2);
                $acquired = true;
                break;
            }
            usleep(10_000);
        }

        if (!$acquired) {
            throw new McpRateLimitException('Rate limiter busy, try again shortly');
        }

        try {
            $current = (int) $this->cache->get($cacheKey, 0);

            if ($current >= $maxRequests) {
                throw new McpRateLimitException(
                    sprintf('Rate limit exceeded: %d requests per %d seconds', $maxRequests, $this->windowSeconds)
                );
            }

            $this->cache->set($cacheKey, $current + 1, $this->windowSeconds);
        } finally {
            $this->cache->delete($lockKey);
        }
    }

    /**
     * Get the current request count for a key.
     */
    public function getCount(string $key): int
    {
        $cacheKey = self::CACHE_PREFIX . md5($key);

        return (int) $this->cache->get($cacheKey, 0);
    }

    /**
     * Reset the counter for a key.
     */
    public function reset(string $key): void
    {
        $cacheKey = self::CACHE_PREFIX . md5($key);
        $this->cache->delete($cacheKey);
    }
}
