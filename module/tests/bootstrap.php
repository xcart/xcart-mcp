<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for the X-Cart MCP module.
 *
 * The module ships bundled vendor packages as raw directories with NO composer
 * autoloader. X-Cart supplies Doctrine, XLite, PSR log/simple-cache at runtime,
 * so tests stub those. Here we register PSR-4 autoloaders for the module code
 * (XC\MCP\ -> src/) and the bundled MCP SDK (Mcp\ -> vendor/mcp/sdk/src/), then
 * load the runtime stubs.
 */

$moduleRoot = dirname(__DIR__);

// XC\MCP\ -> src/
spl_autoload_register(static function (string $class) use ($moduleRoot): void {
    $prefix = 'XC\\MCP\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = $moduleRoot . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

// Mcp\ -> vendor/mcp/sdk/src/
spl_autoload_register(static function (string $class) use ($moduleRoot): void {
    $prefix = 'Mcp\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = $moduleRoot . '/vendor/mcp/sdk/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

require_once __DIR__ . '/stubs.php';

// Reusable test doubles.
require_once __DIR__ . '/Support/StubClassMetadata.php';
require_once __DIR__ . '/Support/StubEntityManager.php';
require_once __DIR__ . '/Support/ArrayCache.php';
