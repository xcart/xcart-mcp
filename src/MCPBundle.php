<?php

declare(strict_types=1);

namespace XC\MCP;

use Symfony\Component\HttpKernel\Bundle\Bundle;

final class MCPBundle extends Bundle
{
    private static bool $autoloaderRegistered = false;

    public function boot(): void
    {
        parent::boot();

        // Register XC\MCP\ namespace with the PHP autoloader.
        //
        // X-Cart loads module classes via Symfony DI container compilation into
        // var/run/classes/, but the Composer autoloader does NOT include module
        // namespaces. The MCP SDK's attribute discovery uses class_exists() via
        // Composer's autoloader, so without this registration it finds 0 classes.
        if (!self::$autoloaderRegistered) {
            $baseDir = $this->getPath();
            spl_autoload_register(static function (string $class) use ($baseDir): void {
                if (str_starts_with($class, 'XC\\MCP\\')) {
                    $relative = str_replace('\\', '/', substr($class, 7));
                    $file = $baseDir . '/' . $relative . '.php';
                    if (file_exists($file)) {
                        require_once $file;
                    }
                }
            });
            self::$autoloaderRegistered = true;
        }
    }
}
