<?php

declare(strict_types=1);

namespace XC\MCP\LifetimeHook;

use Psr\Log\LoggerInterface;
use XCart\DependencyInjection\Attribute\AsInstallLifetimeHook;

class Install
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    #[AsInstallLifetimeHook]
    public function onInstall(): void
    {
        $this->installDependencies();
        $this->generateApiKey();
        $this->logger->info('XC/MCP module installed');
    }

    private function generateApiKey(): void
    {
        $existing = \XLite\Core\Config::getInstance()->XC?->MCP?->mcp_api_key ?? '';

        if ($existing !== '') {
            return;
        }

        $apiKey = 'mcp_' . bin2hex(random_bytes(16));

        $conn = \XLite\Core\Database::getEM()->getConnection();
        $conn->executeStatement(
            "UPDATE xc_config SET value = ? WHERE name = 'mcp_api_key' AND category = 'XC\\\\MCP'",
            [$apiKey]
        );

        // The raw UPDATE above bypasses the Config repository, so the cached
        // (empty) value would otherwise stay in place and McpAuthenticator would
        // keep rejecting the new key with "Invalid or expired API key" until the
        // datacache is cleared by hand. Bust the config cache so the key is live
        // immediately after install.
        try {
            \XLite\Core\Database::getRepo('XLite\Model\Config')->cleanCache();
            \XLite\Core\Config::dropRuntimeCache();
        } catch (\Throwable $e) {
            $this->logger->warning('XC/MCP: Could not bust config cache after API key generation', [
                'error' => $e->getMessage(),
            ]);
        }

        $this->logger->info('XC/MCP: Generated API key', [
            'key_prefix' => substr($apiKey, 0, 8) . '...',
        ]);
    }

    private function installDependencies(): void
    {
        $rootDir = defined('LC_DIR_ROOT') ? LC_DIR_ROOT : (getcwd() . '/');
        $moduleComposer = $rootDir . 'modules/XC/MCP/composer.json';

        if (!file_exists($moduleComposer)) {
            $this->logger->warning('XC/MCP: Module composer.json not found');
            return;
        }

        $moduleDeps = json_decode(file_get_contents($moduleComposer), true);
        $require = $moduleDeps['require'] ?? [];

        $packages = [];
        foreach ($require as $name => $version) {
            if ($name === 'php' || str_starts_with($name, 'ext-')) {
                continue;
            }
            $packages[] = escapeshellarg("$name:$version");
        }

        if (empty($packages)) {
            return;
        }

        // Check if composer will fail due to VCS repos with no SSH access
        $rootComposerFile = $rootDir . 'composer.json';
        if (file_exists($rootComposerFile)) {
            $rootComposer = json_decode(file_get_contents($rootComposerFile), true);
            $repos = $rootComposer['repositories'] ?? [];
            foreach ($repos as $repo) {
                if (($repo['type'] ?? '') === 'vcs' && str_contains($repo['url'] ?? '', 'bitbucket.org')) {
                    $this->logger->info('XC/MCP: Skipping composer (VCS repo requires SSH), using bundled vendor');
                    $this->installBundledVendor($rootDir);
                    return;
                }
            }
        }

        // Try composer require first
        $cmd = sprintf(
            'cd %s && composer require %s --no-interaction --no-scripts --optimize-autoloader 2>&1',
            escapeshellarg(rtrim($rootDir, '/')),
            implode(' ', $packages)
        );

        $this->logger->info('XC/MCP: Installing dependencies via composer');
        $output = shell_exec($cmd);

        if ($output !== null && (
            str_contains($output, 'Nothing to modify')
            || str_contains($output, 'Generating optimized autoload')
        )) {
            $this->logger->info('XC/MCP: Dependencies installed via composer');
            return;
        }

        // Composer failed — install from bundled vendor
        $this->logger->warning('XC/MCP: Composer failed, installing from bundled vendor');
        $this->installBundledVendor($rootDir);
    }

    /**
     * Copy pre-packaged vendor deps from modules/XC/MCP/vendor/ to the main vendor/
     * and register them in installed.json so autoload picks them up.
     */
    private function installBundledVendor(string $rootDir): void
    {
        $bundledDir = $rootDir . 'modules/XC/MCP/vendor/';
        $targetDir = $rootDir . 'vendor/';
        $installedFile = $targetDir . 'composer/installed.json';

        if (!is_dir($bundledDir)) {
            $this->logger->error('XC/MCP: No bundled vendor directory found');
            return;
        }

        if (!file_exists($installedFile)) {
            $this->logger->error('XC/MCP: vendor/composer/installed.json not found');
            return;
        }

        $data = json_decode(file_get_contents($installedFile), true);
        if (!isset($data['packages'])) {
            return;
        }

        $registered = array_column($data['packages'], 'name');
        $added = [];

        // Scan bundled packages
        foreach (glob($bundledDir . '*/*/composer.json') as $composerFile) {
            $pkg = json_decode(file_get_contents($composerFile), true);
            $name = $pkg['name'] ?? null;
            if (!$name) {
                continue;
            }

            // Copy to main vendor if not already there
            $pkgRelDir = str_replace($bundledDir, '', dirname($composerFile));
            $targetPkgDir = $targetDir . $pkgRelDir;
            if (!is_dir($targetPkgDir)) {
                @mkdir(dirname($targetPkgDir), 0755, true);
                shell_exec(sprintf('cp -r %s %s', escapeshellarg(dirname($composerFile)), escapeshellarg($targetPkgDir)));
                $this->logger->info('XC/MCP: Copied ' . $pkgRelDir);
            }

            // Register in installed.json if missing
            if (!in_array($name, $registered, true)) {
                $data['packages'][] = [
                    'name' => $name,
                    'version' => $pkg['version'] ?? '1.0.0',
                    'version_normalized' => ($pkg['version'] ?? '1.0.0') . '.0',
                    'type' => $pkg['type'] ?? 'library',
                    'installation-source' => 'dist',
                    'autoload' => $pkg['autoload'] ?? [],
                    'description' => $pkg['description'] ?? '',
                    'license' => $pkg['license'] ?? [],
                ];
                $registered[] = $name;
                $added[] = $name;
            }
        }

        if (empty($added)) {
            $this->logger->info('XC/MCP: All bundled packages already registered');
            return;
        }

        file_put_contents($installedFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->logger->info('XC/MCP: Registered bundled packages', ['packages' => $added]);

        // Rebuild autoload
        $cmd = sprintf(
            'cd %s && composer dump-autoload --optimize --no-plugins 2>&1',
            escapeshellarg(rtrim($rootDir, '/'))
        );
        $output = shell_exec($cmd);

        if ($output !== null && str_contains($output, 'Generated optimized autoload files')) {
            $this->logger->info('XC/MCP: Autoload rebuilt');
        } else {
            $this->logger->warning('XC/MCP: Autoload rebuild may have failed', ['output' => $output]);
        }
    }
}
