<div align="center">

<img src="config/images/icon.png" alt="X-Cart MCP" width="120" height="120" />

# X-Cart MCP Server

**Model Context Protocol server for X-Cart 5.6 — let AI agents manage your store.**

[![Install with an AI agent](https://img.shields.io/badge/🤖_install_with-an_AI_agent-6E56CF?style=for-the-badge)](AGENTS.md)

[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4.svg)](https://www.php.net/)
[![X-Cart](https://img.shields.io/badge/X--Cart-5.6-FF6600.svg)](https://www.x-cart.com/)
[![MCP](https://img.shields.io/badge/MCP-2025--11--25-6E56CF.svg)](https://modelcontextprotocol.io/)

</div>

---

This module embeds a [Model Context Protocol](https://modelcontextprotocol.io/) server directly into X-Cart 5.6. It lets AI agents — **Claude Desktop, Claude Code, Cursor, Codex** and any other MCP client — read your catalog, manage orders, run reports, and map supplier category feeds through a single standardized protocol.

> 🤖 **Installing this with an AI agent?** Follow **[AGENTS.md](AGENTS.md)** — a deterministic, step-by-step install/verify/troubleshoot procedure written for agents (exact commands, expected outputs, decision trees).

- **51 tools** (48 enabled by default; 3 destructive tools gated behind a setting) — products, orders, categories, search, reports, vehicle fitment, brands, supplier mapping
- **29 resources** (24 fixed + 5 templated) — read-only views over store data
- **13 prompts** — guided multi-step workflows
- **Two transports** — local **STDIO** and authenticated **Streamable HTTP** (`/mcp`)
- **Self-contained** — bundled `vendor/`, installs even when `composer require` is unavailable
- **Secure by default** — Bearer-token auth, per-key rate limiting, gated dangerous operations, no secrets in responses

> Built on the official [`mcp/sdk`](https://github.com/modelcontextprotocol/php-sdk) (PHP). Default protocol version: `2025-11-25`.

---

## Table of contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Connecting an MCP client](#connecting-an-mcp-client)
- [Verifying the installation](#verifying-the-installation)
- [Capabilities](#capabilities)
- [Supplier category mapping](#supplier-category-mapping)
- [Security](#security)
- [Configuration](#configuration)
- [Troubleshooting](#troubleshooting)
- [Development](#development)
- [Documentation](#documentation)
- [Contributing](#contributing)
- [License](#license)

---

## Requirements

| | |
|---|---|
| X-Cart | 5.6.x |
| PHP | 8.1+ (8.3+ recommended) |
| Extensions | standard X-Cart set (pdo_mysql, mbstring, json) |
| Optional | Redis (config/session cache), an OIDC IdP (only for OAuth mode) |

No external services are required for the default setup. The MCP SDK and its PSR-7 dependencies are **bundled** under `vendor/`, so the module works on installs where `composer require` is blocked (a common X-Cart situation).

---

## Installation

```bash
# 1. Copy the module into your X-Cart installation
cp -r xcart-mcp /path/to/xcart/modules/XC/MCP

# 2. Enable and rebuild
php service-tool/bin/console xcst:rebuild --enable XC-MCP
```

The install hook (`#[AsInstallLifetimeHook]`) runs automatically and:

1. Tries `composer require` for the packages in `composer.json`.
2. If composer is unavailable (e.g. a bitbucket VCS repo with no SSH key), copies the bundled `modules/XC/MCP/vendor/` into the main `vendor/` and registers the packages in `installed.json`.
3. Rebuilds the autoload classmap.
4. Generates a random API key (`mcp_<32 hex>`) and busts the config cache so the key is live immediately.

The generated API key is shown in **Admin → Settings → MCP AI Integration**.

> **Disable / remove:** `php service-tool/bin/console xcst:rebuild --disable XC-MCP`

---

## Connecting an MCP client

### Claude Desktop / Claude Code — STDIO (local)

A local process, no API key needed (the local process is trusted and gets full access):

```json
{
  "mcpServers": {
    "xcart": {
      "command": "php",
      "args": ["/path/to/xcart/modules/XC/MCP/bin/mcp-server"]
    }
  }
}
```

### Cursor / Codex / remote clients — Streamable HTTP

```json
{
  "mcpServers": {
    "xcart": {
      "type": "streamable-http",
      "url": "https://your-store.example.com/mcp",
      "headers": { "Authorization": "Bearer YOUR_API_KEY" }
    }
  }
}
```

### Quick test with MCP Inspector

```bash
npx @modelcontextprotocol/inspector php modules/XC/MCP/bin/mcp-server
```

---

## Verifying the installation

```bash
# 1. The route is registered
php bin/console debug:router | grep mcp
# Expected: mcp_endpoint  POST|GET|DELETE  /mcp

# 2. The HTTP endpoint responds (replace URL + key)
curl -sk -X POST https://your-store.example.com/mcp \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}}}'

# 3. STDIO transport (no key needed)
echo '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}}}' \
  | php modules/XC/MCP/bin/mcp-server
```

A successful `initialize` returns server capabilities and an `Mcp-Session-Id` header (HTTP). Include that header on every subsequent request.

---

## Capabilities

### Tools (51)

| Group | Count | Highlights |
|-------|-------|-----------|
| Products | 6 | create / update / search / delete, stock, bulk price change |
| Orders | 4 | status updates, notes, search, line items |
| Categories | 4 | CRUD, assign / remove products |
| Search | 1 | cross-entity global search |
| Reports | 3 | sales, top products, inventory (with output schemas) |
| Vehicles | 11 | makes / models / years CRUD, year-range, bulk model filtering |
| Brands | 5 | list, get, toggle, update, products |
| ASAP Network | 6 | supplier category mapping |
| Turn14 | 5 | supplier category mapping |
| SEMA Data | 6 | supplier category mapping (`xc_category_map_sema`) |

### Resources (29)

24 fixed URIs + 5 templated, e.g. `xcart://products/list`, `xcart://orders/recent`, `xcart://categories/tree`, `xcart://store/dashboard`, `xcart://sema/mapping-summary`, `xcart://products/{id}`. Read-only, no passwords or payment data ever returned.

### Prompts (13)

Guided workflows such as `analyze_store`, `process_pending_orders`, `optimize_catalog`, `pricing_analysis`, `map_asap_categories`, `vehicle_onboarding`.

> Optional modules degrade gracefully: vehicle and brand tools require the `QSL\Make` / `QSL\ShopByBrand` modules and are hidden from `tools/list` when those aren't installed.

---

## Supplier category mapping

A core use case: importing and mapping large supplier category feeds (ASAP Network, Turn14, **SEMA Data** — ~5,800 categories) onto your X-Cart category tree. Each supplier exposes the same toolset:

- `<supplier>_categories_list` — browse with mapping status
- `<supplier>_category_map` — map to an existing X-Cart category, or **create a new one** (top-level or under a parent)
- `<supplier>_auto_map` — match by name (exact + fuzzy), preview or apply; duplicates collapse to one X-Cart category
- `<supplier>_bulk_map` — apply many mappings at once (transactional)
- `<supplier>_deduplicate_report` — analyze names that repeat across roots

New X-Cart categories are created with correct nested-set (`lpos`/`rpos`) values inside a transaction, so the catalog tree stays consistent at scale.

---

## Security

| Layer | Implementation |
|-------|----------------|
| Authentication | Bearer token, checked against the module config key, then X-Cart's API-key table; non-admin profiles rejected |
| Authorization | Per-tool / per-resource ACL; dangerous tools gated by `dangerous_tools_enabled` |
| Rate limiting | 60 req/min per API key (configurable), PSR-16 sliding window, fail-closed |
| Data filtering | No passwords, payment data, or secrets in any response |
| OAuth (optional) | JWT validation via OIDC discovery (SDK `AuthorizationMiddleware`); off by default |

Dangerous tools (`product_delete`, `product_bulk_update_prices`, `vehicle_disable_all_then_enable`) are hidden from `tools/list` and rejected at call time unless explicitly enabled.

---

## Configuration

**Admin → Settings → MCP AI Integration**:

- `mcp_enabled`, `http_transport_enabled` — master switches
- `mcp_api_key` — HTTP Bearer token
- `rate_limit` — requests per minute per key (default 60)
- `dangerous_tools_enabled` — unlock destructive tools
- `server_name`, `request_logging`
- `mcp_oauth_*` — optional OAuth (issuer / audience / JWKS)

---

## Troubleshooting

Common cases (full decision tree in [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)):

| Symptom | Likely cause | Fix |
|---------|-------------|-----|
| 302 redirect / HTML instead of JSON | Route not registered | `xcst:rebuild --enable XC-MCP` |
| 401 "Missing Authorization header" | Client not sending Bearer token | add the header; on nginx pass `HTTP_AUTHORIZATION` |
| 401 "Invalid or expired API key" | Stale config cache | `redis-cli FLUSHALL` + `bin/console cache:clear` |
| 503 "MCP server is disabled" | Disabled in settings | enable in admin |
| "error decoding response body" | Upstream gzip | `gzip off;` for `location /mcp` |

---

## Development

```bash
# Run unit tests
php modules/XC/MCP/vendor/bin/phpunit modules/XC/MCP/tests   # or the project's phpunit

# After editing PHP, rebuild the class cache
php service-tool/bin/console xcst:rebuild --force
```

Adding a new supplier mapping is a copy-paste of `*Tools.php` + `*Resources.php` with a new table name — services are autowired, no DI changes needed. See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

---

## Documentation

- [AGENTS.md](AGENTS.md) — agent-oriented install/verify/troubleshoot procedure
- [docs/API-REFERENCE.md](docs/API-REFERENCE.md) — every tool, resource, and prompt with parameters (generated from live introspection)
- [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) — install, nginx, caching, troubleshooting decision tree
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — architecture and internals

---

## Contributing

Issues and pull requests are welcome. Please keep changes focused, match the existing code style (`declare(strict_types=1)`, constructor DI, Doctrine QueryBuilder / `TableResolver` instead of hardcoded table prefixes), and add a note in the docs when you add or change a capability.

---

## License

Licensed under the [Apache License 2.0](LICENSE). "X-Cart" is a trademark of X-Cart Holdings LLC; this is an independent integration module and is not an official X-Cart product.
