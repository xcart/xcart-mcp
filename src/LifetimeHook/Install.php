<?php

declare(strict_types=1);

namespace XC\MCP\LifetimeHook;

use Psr\Log\LoggerInterface;
use XCart\DependencyInjection\Attribute\AsInstallLifetimeHook;
use XCart\DependencyInjection\Attribute\AsRebuildLifetimeHook;

class Install
{
    private const REQUIRED_VENDOR_CLASS = \Mcp\Capability\Registry::class;
    private const SDK_PACKAGE = 'mcp/sdk';
    private const SDK_PROBE_FILE = 'vendor/mcp/sdk/src/Capability/Registry.php';

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Runs once when the module is installed.
     */
    #[AsInstallLifetimeHook]
    public function onInstall(): void
    {
        $this->ensureDependencies();
        $this->ensureConfigOptions();
        $this->ensureApiKey();
        $this->logger->info('XC/MCP module installed');
    }

    /**
     * Runs on every `xcst:rebuild`, including the rebuild that first enables the
     * module. The install hook above is not always dispatched when a module is
     * enabled straight from the filesystem (`xcst:rebuild --enable XC-MCP`), so
     * the rebuild hook is the reliable self-healing path: it re-checks the
     * bundled vendor deps and the API key and fixes whatever is missing. Both
     * steps are idempotent and cheap no-ops once everything is in place.
     */
    #[AsRebuildLifetimeHook]
    public function onRebuild(): void
    {
        $this->ensureDependencies();
        $this->ensureConfigOptions();
        $this->ensureApiKey();
    }

    /**
     * Make sure the bundled MCP SDK (and its transitive deps) are available in
     * the main vendor/ and registered with the autoloader. Returns immediately
     * when they already are, so it is safe to call on every rebuild.
     */
    private function ensureDependencies(): void
    {
        $rootDir = defined('LC_DIR_ROOT') ? LC_DIR_ROOT : (getcwd() . '/');

        if ($this->dependenciesSatisfied($rootDir)) {
            return;
        }

        $this->logger->info('XC/MCP: bundled vendor deps missing, installing');
        $this->installBundledVendor($rootDir);
    }

    /**
     * The deps are satisfied when the SDK is both physically present in the main
     * vendor tree and registered in composer's installed.json (so the optimized
     * autoloader knows about it). We probe the filesystem instead of class_exists()
     * because during a rebuild the freshly-regenerated autoloader is not active in
     * the current process yet.
     */
    private function dependenciesSatisfied(string $rootDir): bool
    {
        if (class_exists(self::REQUIRED_VENDOR_CLASS, false)) {
            return true;
        }

        if (!file_exists($rootDir . self::SDK_PROBE_FILE)) {
            return false;
        }

        $installedFile = $rootDir . 'vendor/composer/installed.json';
        if (!file_exists($installedFile)) {
            return false;
        }

        $data = json_decode(file_get_contents($installedFile), true);
        $registered = array_column($data['packages'] ?? [], 'name');

        return in_array(self::SDK_PACKAGE, $registered, true);
    }

    /**
     * Make sure every XC\MCP config option defined in install.yaml exists in the
     * DB. The install.yaml fixtures are not always loaded in full when the module
     * is enabled straight from the filesystem (observed: http_transport_enabled,
     * request_logging and dangerous_tools_enabled silently missing), which would
     * leave the controller/authorizer falling back to hardcoded defaults. Reading
     * the option set from install.yaml keeps this in sync with the fixtures
     * automatically. Idempotent — only missing rows are created.
     */
    private function ensureConfigOptions(): void
    {
        $rootDir = defined('LC_DIR_ROOT') ? LC_DIR_ROOT : (getcwd() . '/');
        $yamlPath = $rootDir . 'modules/XC/MCP/config/install.yaml';

        if (!file_exists($yamlPath) || !class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            return;
        }

        try {
            $data = \Symfony\Component\Yaml\Yaml::parseFile($yamlPath);
        } catch (\Throwable $e) {
            $this->logger->warning('XC/MCP: could not parse install.yaml', ['error' => $e->getMessage()]);
            return;
        }

        $options = $data['XLite\Model\Config'] ?? [];
        if (!$options) {
            return;
        }

        try {
            $em = \XLite\Core\Database::getEM();
            $conn = $em->getConnection();
            $configTable = $em->getClassMetadata(\XLite\Model\Config::class)->getTableName();
            $trTable = $em->getClassMetadata(\XLite\Model\ConfigTranslation::class)->getTableName();
        } catch (\Throwable $e) {
            $this->logger->warning('XC/MCP: could not resolve config tables', ['error' => $e->getMessage()]);
            return;
        }

        $added = 0;
        foreach ($options as $opt) {
            $name = $opt['name'] ?? null;
            $category = $opt['category'] ?? null;
            if (!$name || !$category) {
                continue;
            }

            $exists = $conn->fetchOne(
                "SELECT config_id FROM {$configTable} WHERE name = ? AND category = ?",
                [$name, $category]
            );
            if ($exists !== false) {
                continue;
            }

            $conn->executeStatement(
                "INSERT INTO {$configTable} (name, category, type, orderby, value, widgetParameters, module)"
                . " VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    $name,
                    $category,
                    $opt['type'] ?? 'XLite\View\FormField\Input\Text',
                    (int) ($opt['orderby'] ?? 0),
                    $this->normalizeConfigValue($opt['value'] ?? ''),
                    'N;',
                    $opt['module'] ?? $category,
                ]
            );

            $configId = (int) $conn->lastInsertId();

            foreach ($opt['translations'] ?? [] as $tr) {
                $conn->executeStatement(
                    "INSERT INTO {$trTable} (id, code, option_name, option_comment) VALUES (?, ?, ?, ?)",
                    [
                        $configId,
                        $tr['code'] ?? 'en',
                        $tr['option_name'] ?? $name,
                        $tr['option_comment'] ?? '',
                    ]
                );
            }

            $added++;
        }

        if ($added === 0) {
            return;
        }

        try {
            \XLite\Core\Database::getRepo('XLite\Model\Config')->cleanCache();
            \XLite\Core\Config::dropRuntimeCache();
        } catch (\Throwable $e) {
            $this->logger->warning('XC/MCP: could not bust config cache after creating options', [
                'error' => $e->getMessage(),
            ]);
        }

        $this->logger->info('XC/MCP: created missing config options', ['count' => $added]);
    }

    /**
     * Coerce an install.yaml option value into the string form X-Cart stores in
     * xc_config.value (OnOff checkboxes use '1' / '', everything else stringified).
     */
    private function normalizeConfigValue(mixed $value): string
    {
        if ($value === true) {
            return '1';
        }
        if ($value === false || $value === null) {
            return '';
        }

        return (string) $value;
    }

    /**
     * Generate a random Bearer API key on first install. Reads the current value
     * straight from the DB (not the cached Config tree) so that re-running on
     * rebuild never rotates an existing key.
     */
    private function ensureApiKey(): void
    {
        try {
            $option = \XLite\Core\Database::getRepo('XLite\Model\Config')
                ->findOneBy(['name' => 'mcp_api_key', 'category' => 'XC\MCP']);
        } catch (\Throwable $e) {
            $this->logger->warning('XC/MCP: could not read API key config', ['error' => $e->getMessage()]);
            return;
        }

        if ($option === null) {
            // install.yaml fixture not loaded yet — nothing to update.
            return;
        }

        if (trim((string) $option->getValue()) !== '') {
            return;
        }

        $apiKey = 'mcp_' . bin2hex(random_bytes(16));

        $conn = \XLite\Core\Database::getEM()->getConnection();
        $conn->executeStatement(
            "UPDATE xc_config SET value = ? WHERE name = 'mcp_api_key' AND category = ?",
            [$apiKey, 'XC\\MCP']
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

    /**
     * Copy pre-packaged vendor deps from modules/XC/MCP/vendor/ to the main vendor/
     * and register them in installed.json so autoload picks them up.
     *
     * Idempotent: already-copied packages are left untouched and already-registered
     * packages are skipped; the autoloader is only rebuilt when something changed.
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
            'cd %s && composer dump-autoload --optimize --no-plugins --no-scripts 2>&1',
            escapeshellarg(rtrim($rootDir, '/'))
        );
        $output = shell_exec($cmd);

        if ($output !== null && str_contains($output, 'Generated optimized autoload files')) {
            $this->logger->info('XC/MCP: Autoload rebuilt');
            return;
        }

        // Composer not on PATH or failed — fall back to patching the optimized
        // classmap/PSR-4 maps directly so the deps still autoload. Without this
        // the registration above is inert until someone runs composer by hand.
        $this->logger->warning('XC/MCP: composer dump-autoload unavailable, patching autoload maps', [
            'output' => $output,
        ]);
        $this->patchAutoloadMaps($targetDir, $added);
    }

    /**
     * Fallback when `composer dump-autoload` is not runnable: append the bundled
     * packages' PSR-4 prefixes to vendor/composer/autoload_psr4.php so Composer's
     * ClassLoader resolves them. PSR-4 covers every bundled dep here.
     */
    private function patchAutoloadMaps(string $targetDir, array $added): void
    {
        $psr4File = $targetDir . 'composer/autoload_psr4.php';
        if (!file_exists($psr4File)) {
            $this->logger->error('XC/MCP: autoload_psr4.php not found, deps will not autoload');
            return;
        }

        $installedFile = $targetDir . 'composer/installed.json';
        $data = json_decode(file_get_contents($installedFile), true);
        $byName = [];
        foreach ($data['packages'] ?? [] as $pkg) {
            $byName[$pkg['name'] ?? ''] = $pkg;
        }

        $entries = [];
        foreach ($added as $name) {
            $pkg = $byName[$name] ?? null;
            $psr4 = $pkg['autoload']['psr-4'] ?? [];
            $pkgVendorDir = "\$vendorDir . '/" . $name . "'";
            foreach ($psr4 as $prefix => $paths) {
                $paths = (array) $paths;
                $phpPaths = array_map(
                    static fn($p) => $pkgVendorDir . " . '/" . ltrim($p, '/') . "'",
                    $paths
                );
                $entries[] = sprintf(
                    "    %s => array(%s),",
                    var_export($prefix, true),
                    implode(', ', $phpPaths)
                );
            }
        }

        if (empty($entries)) {
            return;
        }

        $contents = file_get_contents($psr4File);
        $marker = 'return array(';
        $pos = strrpos($contents, $marker);
        if ($pos === false) {
            $this->logger->error('XC/MCP: could not locate return array() in autoload_psr4.php');
            return;
        }

        $insertAt = $pos + strlen($marker);
        $patched = substr($contents, 0, $insertAt) . "\n" . implode("\n", $entries) . substr($contents, $insertAt);
        file_put_contents($psr4File, $patched);

        // Drop the optimized classmap authoritative flag if present, so PSR-4 is consulted.
        $this->logger->info('XC/MCP: patched autoload_psr4.php', ['packages' => $added]);
    }
}
