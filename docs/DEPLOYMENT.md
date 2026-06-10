# Deployment Guide: XC/MCP Module

## Prerequisites

- X-Cart 5.6+ with PHP 8.1+ (8.3+ recommended)
- Symfony 6.4+ (bundled with X-Cart)
- Doctrine ORM (bundled with X-Cart)
- Composer is **optional** — dependencies are bundled (see Step 2)

## Step 1: Copy Module Files

```bash
cp -r modules/XC/MCP /path/to/xcart/modules/XC/MCP
```

## Step 2: Dependencies (automatic)

The MCP SDK (`mcp/sdk:^0.6`) and its PSR-7 dependencies are **bundled** under
`modules/XC/MCP/vendor/`. The install hook installs them automatically during the
rebuild in Step 3:

1. It tries `composer require` first.
2. If composer is unavailable (e.g. a bitbucket VCS repo with no SSH key — common on
   X-Cart), it copies the bundled `vendor/` into the main `vendor/` and registers the
   packages in `installed.json`, then rebuilds the autoloader.

You do not need to run `composer require` manually. If you want to anyway:

```bash
composer require mcp/sdk:^0.6 nyholm/psr7 symfony/psr-http-message-bridge
```

## Step 3: Rebuild X-Cart

```bash
# Via service-tool (standard way):
php service-tool/bin/console xcst:rebuild --enable=XC-MCP

# If rebuild fails, reset and force:
php service-tool/bin/console xcst:rebuild --enable=XC-MCP --force
```

This triggers:
1. Module state change to `enabled` in `service_module` table
2. Bundle discovery (`MCPBundle.php` registered in `config/dynamic/xcart_bundles.php`)
3. Route loading from `config/routes.yaml`
4. Service container compilation from `config/services.yaml`
5. Fixtures loading from `config/install.yaml` (module settings in `xc_config`)

**Note:** Do NOT use `php bin/console xcart:rebuild` — it doesn't exist. The rebuild command is in service-tool.

## Step 4: API Key

On first install, the module auto-generates a random API key (`mcp_<32 hex chars>`) and stores it in module settings (`mcp_api_key` in `xc_config` table). You can view it in admin under **Settings > MCP AI Integration**.

Alternatively, you can use standard X-Cart API keys from **Store setup > API keys** (must belong to an admin profile).

## Step 5: Verify

### HTTP endpoint

```bash
curl -X POST http://your-store.com/mcp \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"test","version":"1.0.0"}}}'
```

Expected: JSON-RPC response with `result.serverInfo.name = "X-Cart MCP Server"`.

### STDIO

```bash
php modules/XC/MCP/bin/mcp-server
```

Or via MCP Inspector:

```bash
npx @modelcontextprotocol/inspector php modules/XC/MCP/bin/mcp-server
```

---

## API Key Setup

MCP HTTP transport requires authentication. Two options:

1. **Auto-generated key** (recommended): On first install, the module generates a random `mcp_<hex>` key stored in module settings. View it in **Settings > MCP AI Integration**.

2. **X-Cart API keys**: Create in **Store setup > API keys**. The key must be active (enabled, not expired) and belong to an admin profile.

---

## Known Compatibility Notes

### Namespace: `XLite\`, not `XCart\`

X-Cart 5.6 uses the `XLite\` root namespace for all core classes:
- `XLite\Model\Product`, `XLite\Model\Order`, `XLite\Model\Category`, etc.
- `XLite\Model\Profile\APIKey` (uppercase `APIKey`, not `ApiKey`)
- `XLite\Model\Repo\Profile\APIKey` with method `findActiveApiKey()`
- `XLite\Core\Config::getInstance()` (singleton, not available via DI)
- `XLite::XC_VERSION` for the platform version constant
- Module base class: `XLite\Module\AModule`

### Route path: `/mcp`

The endpoint is at `/mcp` (not `/api/mcp`) to avoid the X-Cart JWT firewall which intercepts all `^/api/` requests.

### Bundle registration

X-Cart discovers Symfony bundles via `{ModuleName}Bundle.php` in the module's `src/` directory. The `MCPBundle.php` file must exist for routes and services to load.

### DI bindings

Several services require explicit DI bindings because X-Cart doesn't alias them by default:
- `$cache: '@cache.psr16.common'` (PSR-16 CacheInterface)
- `$container: '@service_container'` (PSR ContainerInterface)
- `McpCapabilityProvider` interface must be excluded from autowiring scan

### Config access

X-Cart module settings are stored in the database, not Symfony parameters. Access via:
```php
$config = \XLite\Core\Config::getInstance()->XC?->MCP;
$value = $config?->setting_name ?? $default;
```

### MCP SDK v0.6 API

```php
// HTTP transport
$transport = new StreamableHttpTransport($psr7Request);
$psr7Response = $server->run($transport);

// STDIO transport
$transport = new StdioTransport();
$server->run($transport);
```

---

## Docker Deployment

For Docker-based X-Cart installations:

1. Copy module files to the mounted volume
2. Run composer inside the PHP-FPM container:
   ```bash
   docker exec -u www-data xcart-fpm composer require mcp/sdk:^0.4 nyholm/psr7 symfony/psr-http-message-bridge
   ```
3. Rebuild with module enabled:
   ```bash
   docker exec xcart-fpm rm -rf var/cache/*
   docker exec xcart-fpm php service-tool/bin/console xcst:rebuild --enable=XC-MCP
   ```

**SSH key note:** If Composer needs Bitbucket/GitHub SSH access, ensure the container user (`www-data` or equivalent) has proper SSH keys configured.

---

## Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| 404 on `/mcp` | Bundle not registered | Ensure `MCPBundle.php` exists, run `xcart:rebuild` |
| 401 Unauthorized | Invalid/missing API key | Check key is active and belongs to admin profile |
| 500 on `/mcp` | Missing composer packages | Run `composer require mcp/sdk:^0.4 nyholm/psr7 symfony/psr-http-message-bridge` |
| Class not found `XCart\...` | Wrong namespace | All core classes use `XLite\` namespace |
| Parameter not found | Config accessed as DI param | Use `\XLite\Core\Config::getInstance()` singleton |
| JWT auth error on endpoint | Route under `/api/` prefix | Route must be `/mcp`, not `/api/mcp` |
| Disk space errors | Full disk prevents cache | Clear `var/cache/*` and composer cache |
