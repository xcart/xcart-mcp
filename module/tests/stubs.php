<?php

declare(strict_types=1);

/**
 * Minimal stub types that X-Cart supplies at runtime but are absent from the
 * module's bundled vendor/. Only what the unit tests need is defined. Every
 * definition is guarded so a real implementation (if ever autoloadable) wins.
 */

// ── Psr\Log ──────────────────────────────────────────────────────────────────
namespace Psr\Log {
    if (!\interface_exists(LoggerInterface::class)) {
        interface LoggerInterface
        {
            public function emergency($message, array $context = []): void;
            public function alert($message, array $context = []): void;
            public function critical($message, array $context = []): void;
            public function error($message, array $context = []): void;
            public function warning($message, array $context = []): void;
            public function notice($message, array $context = []): void;
            public function info($message, array $context = []): void;
            public function debug($message, array $context = []): void;
            public function log($level, $message, array $context = []): void;
        }
    }

    if (!\class_exists(NullLogger::class)) {
        final class NullLogger implements LoggerInterface
        {
            public function emergency($message, array $context = []): void {}
            public function alert($message, array $context = []): void {}
            public function critical($message, array $context = []): void {}
            public function error($message, array $context = []): void {}
            public function warning($message, array $context = []): void {}
            public function notice($message, array $context = []): void {}
            public function info($message, array $context = []): void {}
            public function debug($message, array $context = []): void {}
            public function log($level, $message, array $context = []): void {}
        }
    }
}

// ── Psr\SimpleCache ──────────────────────────────────────────────────────────
namespace Psr\SimpleCache {
    if (!\interface_exists(CacheInterface::class)) {
        interface CacheInterface
        {
            public function get($key, $default = null);
            public function set($key, $value, $ttl = null): bool;
            public function delete($key): bool;
            public function clear(): bool;
            public function getMultiple($keys, $default = null): iterable;
            public function setMultiple($values, $ttl = null): bool;
            public function deleteMultiple($keys): bool;
            public function has($key): bool;
        }
    }
}

// ── Psr\Http\Message ─────────────────────────────────────────────────────────
namespace Psr\Http\Message {
    if (!\interface_exists(ServerRequestInterface::class)) {
        // Minimal — only the methods the MCP authenticator calls.
        interface ServerRequestInterface
        {
            public function getHeaderLine($name): string;
        }
    }
}

// ── Doctrine\ORM ─────────────────────────────────────────────────────────────
namespace Doctrine\ORM {
    if (!\interface_exists(EntityManagerInterface::class)) {
        // Loose: no return types so no concrete ClassMetadata / Connection class
        // is required by the interface itself.
        interface EntityManagerInterface
        {
            public function getClassMetadata($className);
            public function getConnection();
            public function createQueryBuilder();
        }
    }
}

// ── Doctrine\DBAL ────────────────────────────────────────────────────────────
namespace Doctrine\DBAL {
    if (!\class_exists(Connection::class)) {
        // Concrete, non-final: tests subclass or configure a double. Every method
        // throws by default so an un-configured call is loud, not silent.
        class Connection
        {
            public function fetchOne($sql, array $params = [], array $types = [])
            {
                throw new \RuntimeException('stub connection');
            }

            public function fetchAssociative($sql, array $params = [], array $types = [])
            {
                throw new \RuntimeException('stub connection');
            }

            public function fetchAllAssociative($sql, array $params = [], array $types = [])
            {
                throw new \RuntimeException('stub connection');
            }

            public function executeQuery($sql, array $params = [], array $types = [])
            {
                throw new \RuntimeException('stub connection');
            }

            public function executeStatement($sql, array $params = [], array $types = [])
            {
                throw new \RuntimeException('stub connection');
            }

            public function lastInsertId($name = null)
            {
                throw new \RuntimeException('stub connection');
            }

            public function createQueryBuilder()
            {
                throw new \RuntimeException('stub connection');
            }
        }
    }
}

// ── XLite\Model ──────────────────────────────────────────────────────────────
namespace XLite\Model {
    if (!\class_exists(Profile::class)) {
        class Profile
        {
            public function getId(): ?int
            {
                return 1;
            }

            public function isAdmin(): bool
            {
                return true;
            }
        }
    }

    // Used only as ::class strings in the code under test; defined empty for safety.
    if (!\class_exists(Config::class)) {
        class Config {}
    }
    if (!\class_exists(Category::class)) {
        class Category {}
    }
    if (!\class_exists(CategoryTranslation::class)) {
        class CategoryTranslation {}
    }
}

// ── XLite\Model\Profile (API key entity) ─────────────────────────────────────
namespace XLite\Model\Profile {
    if (!\class_exists(APIKey::class)) {
        class APIKey
        {
            public function getProfile(): ?\XLite\Model\Profile
            {
                return null;
            }

            public function getId(): ?int
            {
                return null;
            }
        }
    }
}

// ── XLite\Model\Repo\Profile (API key repository) ────────────────────────────
namespace XLite\Model\Repo\Profile {
    if (!\class_exists(APIKey::class)) {
        class APIKey
        {
            public function findActiveApiKey(string $key): ?\XLite\Model\Profile\APIKey
            {
                return null;
            }
        }
    }
}

// ── XLite\Core ───────────────────────────────────────────────────────────────
namespace XLite\Core {
    if (!\class_exists(Config::class)) {
        class Config
        {
            public static function getInstance(): object
            {
                return new class {
                    public $XC = null;
                    public $General = null;
                };
            }
        }
    }

    if (!\class_exists(Database::class)) {
        class Database
        {
            public static function getEM(): ?object
            {
                return null;
            }
        }
    }
}
