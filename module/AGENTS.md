# AGENTS.md — Install & Operate the X-Cart MCP Server

> This file is written for AI coding agents. It is the authoritative, step-by-step
> procedure to install, verify, and troubleshoot this module on an X-Cart 5.6 store.
> Follow the steps in order. Each step has a **command**, an **expected result**, and a
> **branch** to take on failure. Do not skip verification steps.

## 0. Facts you must know before acting

- X-Cart uses the `XLite\` namespace for legacy models (`XLite\Model\Product`, `XLite\Model\Order`, `XLite\Model\Category`). New code uses `XCart\`. **`XCart\Model\Product` does not exist.**
- The rebuild command is `php service-tool/bin/console xcst:rebuild ...`. **`php bin/console xcart:rebuild` does NOT exist.**
- `composer require` frequently fails on X-Cart (a bitbucket VCS repo with no SSH key). This module ships a bundled `vendor/` and its install hook falls back to it automatically. Do not try to "fix" composer.
- Cache layers, and what clears each:
  | Cache | Cleared by |
  |-------|-----------|
  | Symfony (`var/cache/`) | `php bin/console cache:clear` |
  | Compiled classes (`var/run/classes/`) | `xcst:rebuild` only |
  | Redis | `redis-cli FLUSHALL` |
  | datacache (`var/datacache/`) | `rm -rf var/datacache/*` |
- After editing module PHP under `modules/XC/MCP/src/`, the **compiled copy** in `var/run/classes/XC/MCP/` is what actually runs. Rebuild, or copy to both paths.

## 1. Preconditions

```bash
php -v                       # expect PHP >= 8.1
test -f service-tool/bin/console && echo OK   # run from the X-Cart root
```

If `service-tool/bin/console` is missing, you are not in the X-Cart root. Stop and locate it (look for `top.inc.php` or `bin/console` siblings).

## 2. Install

```bash
# from the X-Cart root
cp -r /path/to/xcart-mcp modules/XC/MCP
php service-tool/bin/console xcst:rebuild --enable XC-MCP
```

**Expected result:** the rebuild ends with `Done [Total ...]` and exit code 0. The install hook installs dependencies (bundled fallback if composer fails), generates an API key, and busts the config cache.

**On failure:** read the rebuild output. If it mentions a missing vendor class, run the manual vendor copy in [§7 Troubleshooting → vendor](#7-troubleshooting).

## 3. Verify (do not skip)

```bash
# 3.1 Route registered
php bin/console debug:router | grep mcp_endpoint
# Expect: mcp_endpoint  POST|GET|DELETE  /mcp

# 3.2 Module enabled (SQL)
# Expect state = enabled
#   SELECT module_id, state FROM service_module WHERE module_id = 'XC-MCP';

# 3.3 STDIO transport (no API key needed; this is the agent-default transport)
echo '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"agent","version":"1.0"}}}' \
  | php modules/XC/MCP/bin/mcp-server
# Expect: a single line of JSON with "result.serverInfo.name" = "X-Cart MCP Server".
# PHP deprecation notices may appear on STDERR — ignore them, STDOUT stays clean JSON.

# 3.4 List tools over STDIO
printf '%s\n' \
  '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"a","version":"1"}}}' \
  '{"jsonrpc":"2.0","method":"notifications/initialized"}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' \
  | php modules/XC/MCP/bin/mcp-server
# Expect 48 tools by default (3 destructive tools are gated — see §5).
```

## 4. Connect a client

**STDIO (local agent, recommended).** No key; the local process is trusted.

```json
{ "mcpServers": { "xcart": { "command": "php", "args": ["/ABSOLUTE/PATH/modules/XC/MCP/bin/mcp-server"] } } }
```

**HTTP (remote agent).** Needs the API key from `Admin → Settings → MCP AI Integration`, or:

```sql
SELECT value FROM xc_config WHERE name = 'mcp_api_key';
```

```json
{ "mcpServers": { "xcart": { "type": "streamable-http", "url": "https://STORE/mcp",
  "headers": { "Authorization": "Bearer API_KEY" } } } }
```

HTTP flow: `initialize` returns an `Mcp-Session-Id` response header → send it on every subsequent request → send `notifications/initialized` → then `tools/call`.

## 5. Capabilities & gating

- **51 tools total.** 48 are exposed by default. The 3 destructive tools — `product_delete`, `product_bulk_update_prices`, `vehicle_disable_all_then_enable` — are hidden from `tools/list` and rejected at call time unless `dangerous_tools_enabled` is set. To enable: set that config flag, then `php bin/console cache:clear`.
- **Optional-module tools degrade.** Vehicle tools need `QSL\Make`; brand tools need `QSL\ShopByBrand`. If those modules are absent, their tools are pruned from `tools/list` automatically. Supplier tools (ASAP/Turn14/SEMA) return a clear error if their import table is missing.
- Full machine-readable list: [docs/API-REFERENCE.md](docs/API-REFERENCE.md).

## 6. Common task recipes (via MCP tools)

- **Map a supplier feed (e.g. SEMA Data):** `sema_categories_list` (inspect) → `sema_auto_map {apply:false}` (preview) → `sema_auto_map {apply:true}` (exact matches) → `sema_category_map {semaCategoryId, createUnderParentId}` for the rest (creates X-Cart categories with correct nested-set). Map roots first, then children under each mapped root's `xcart_category_id`.
- **Create a category:** `category_create {name, parentId?}`. Omitting `parentId` creates a top-level category nested correctly inside the hidden root.
- **Sales report:** `report_sales {period:"month"}` — returns validated `structuredContent`.

## 7. Troubleshooting (decision tree)

```
POST /mcp fails →
  302 redirect / HTML response   → route not registered → xcst:rebuild --enable XC-MCP
  401 "Missing Authorization"    → client not sending Bearer; on nginx add: fastcgi_param HTTP_AUTHORIZATION $http_authorization;
  401 "Invalid or expired key"   → stale config cache → redis-cli FLUSHALL && php bin/console cache:clear
  503 "MCP server is disabled"   → enable mcp_enabled in admin settings
  500 / JSON-RPC -32603          → PHP exception → tail -50 var/log/$(date +%Y/%m)/xlite.*.log | grep -i mcp
  "error decoding response body" → upstream gzip → nginx: location /mcp { gzip off; }
  connection refused / timeout    → network/firewall/DNS
```

**vendor:** if a `Mcp\…` / `Nyholm\…` / `Symfony\Bridge\PsrHttpMessage\…` class is "not found":

```bash
cp -r modules/XC/MCP/vendor/mcp vendor/
cp -r modules/XC/MCP/vendor/nyholm vendor/
cp -r modules/XC/MCP/vendor/opis vendor/
cp -r modules/XC/MCP/vendor/php-http vendor/
cp -r modules/XC/MCP/vendor/psr/http-server-handler vendor/psr/
cp -r modules/XC/MCP/vendor/psr/http-server-middleware vendor/psr/
cp -r modules/XC/MCP/vendor/symfony/psr-http-message-bridge vendor/symfony/
composer dump-autoload   # or: php bin/console cache:clear
```

## 8. Uninstall

```bash
php service-tool/bin/console xcst:rebuild --disable XC-MCP
```

Removal is clean: the compiled classes under `var/run/classes/XC/MCP/` are removed and the `/mcp` route is unregistered. The API key in `xc_config` is preserved across disable→enable (re-enabling does not break existing clients).

## 9. Detailed references

- [README.md](README.md) — overview and capabilities
- [docs/API-REFERENCE.md](docs/API-REFERENCE.md) — every tool/resource/prompt with parameters (generated from live introspection)
- [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) — nginx, caching, full troubleshooting
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — internals
