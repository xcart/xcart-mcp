<?php

declare(strict_types=1);

namespace Tests\Support;

use Psr\SimpleCache\CacheInterface;

/**
 * In-memory PSR-16 cache for RateLimiter tests. TTL is ignored (irrelevant for
 * the synchronous, single-process assertions the tests make).
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function get($key, $default = null)
    {
        return \array_key_exists($key, $this->store) ? $this->store[$key] : $default;
    }

    public function set($key, $value, $ttl = null): bool
    {
        $this->store[$key] = $value;

        return true;
    }

    public function delete($key): bool
    {
        unset($this->store[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->store = [];

        return true;
    }

    public function getMultiple($keys, $default = null): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get($key, $default);
        }

        return $out;
    }

    public function setMultiple($values, $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple($keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has($key): bool
    {
        return \array_key_exists($key, $this->store);
    }
}
