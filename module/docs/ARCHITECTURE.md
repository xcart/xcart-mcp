# Architecture — X-Cart MCP Server

How the module is wired, from an HTTP/STDIO request down to a tool call.

## Layers

```
MCP client (Claude/Cursor/Codex)
        │  JSON-RPC 2.0
        ▼
Transport
   ├─ HTTP:  Controller/API/McpController  (PSR-7 bridge, /mcp route)
   └─ STDIO: bin/mcp-server                (local process)
        │
        ▼
Security:  McpAuthenticator → SecurityContext → SecurityContextHolder
           RateLimiter · OAuthSupport (optional)
        │
        ▼
Server:    ServerFactory → mcp/sdk Server + Registry
           (discovery scans Tools/ Resources/ Prompts, then prunes
            unavailable/dangerous capabilities)
        │
        ▼
Capabilities:  Tools/* · Resources/* · Prompts/*   (autowired services)
        │
        ▼
Data:      Doctrine ORM / DBAL · TableResolver · CategoryFactory
        │
        ▼
X-Cart database (XLite\ entities)
```

## Key classes

| Class | Namespace | Responsibility |
|-------|-----------|----------------|
| `Main` | `XC\MCP` | Module entry point (`AModule`) |
| `MCPBundle` | `XC\MCP` | Symfony bundle registration |
| `LifetimeHook\Install` | `XC\MCP\LifetimeHook` | Install hook: dependency install (composer or bundled vendor fallback), API-key generation, config-cache bust |
| `Controller\API\McpController` | `XC\MCP\Controller\API` | HTTP transport: Symfony⇄PSR-7 bridge, auth, rate-limit, runs the MCP server over `StreamableHttpTransport` |
| `MCP\Server\ServerFactory` | `XC\MCP\MCP\Server` | Builds the `mcp/sdk` `Server`: discovery, session store, protocol version, MCP Apps extension, capability pruning |
| `MCP\Server\McpToolRegistry` | `XC\MCP\MCP\Server` | Reflection-based tool metadata for the admin settings page |
| `MCP\Security\McpAuthenticator` | `XC\MCP\MCP\Security` | Bearer-token / STDIO auth → `SecurityContext` |
| `MCP\Security\McpAuthorizer` | `XC\MCP\MCP\Security` | Per-tool / per-resource ACL; dangerous-tool gating |
| `MCP\Security\RateLimiter` | `XC\MCP\MCP\Security` | PSR-16 sliding-window limiter, fail-closed |
| `MCP\Security\OAuthSupport` | `XC\MCP\MCP\Security` | Optional JWT/OIDC middleware (off by default) |
| `MCP\Util\TableResolver` | `XC\MCP\MCP\Util` | Resolves real table names from Doctrine metadata (no hardcoded `xc_` prefix) |
| `MCP\Util\CategoryFactory` | `XC\MCP\MCP\Util` | Creates categories with correct nested-set (`lpos`/`rpos`) values inside a transaction |
| `MCP\Tools\*` | `XC\MCP\MCP\Tools` | Tool implementations (9 files) |
| `MCP\Resources\*` | `XC\MCP\MCP\Resources` | Resource implementations |
| `MCP\Prompts\*` | `XC\MCP\MCP\Prompts` | Prompt implementations |

## Request lifecycle (HTTP)

1. `McpController::handle()` checks `mcp_enabled`; handles `DELETE` (session end) and `GET` (405).
2. Symfony `Request` → PSR-7 `ServerRequest`.
3. Auth: default Bearer via `McpAuthenticator`, or OAuth middleware if enabled. Misconfiguration fails closed (503), never open.
4. `RateLimiter::check()` per API key (or per hashed OAuth token).
5. `ServerFactory::createServerForHttp()` sets the `SecurityContext` on the shared holder and builds the server.
6. The SDK runs the JSON-RPC method over `StreamableHttpTransport` (CORS + protocol-version middleware; DNS-rebinding middleware deliberately omitted — Bearer token + nginx host enforcement cover it).
7. PSR-7 response → Symfony response; `Content-Encoding: identity` set to stop upstream gzip from breaking strict clients.

## Capability discovery & gating

`ServerFactory::createServer()` lets the SDK scan `Resources/`, `Tools/`, `Prompts/` via PHP attributes (`#[McpTool]`, `#[McpResource]`, `#[McpPrompt]`). After discovery it prunes the registry so `tools/list` reflects reality:

- Dangerous tools removed unless `dangerous_tools_enabled`.
- Vehicle tools/resources removed unless the `QSL\Make` entity table is queryable.
- Brand tools/resources removed unless the `QSL\ShopByBrand` entity table is queryable.

## Authorization model

Every tool method calls `McpAuthorizer::authorizeTool($name)` first. `SecurityContext` is either **full access** (STDIO local process, or a validated OAuth token) or **scoped** (an API key with optional allow-lists). Dangerous tools are checked against config even for scoped keys.

## Data-access conventions

- Use Doctrine QueryBuilder/DBAL, never hardcode the table prefix. Resolve table names via `TableResolver::resolve(Entity::class)`.
- Category creation goes through `CategoryFactory`, which locks the parent row (`FOR UPDATE`), shifts the nested set, and inserts the row + translation atomically. Top-level categories nest inside the hidden `depth = -1` root.
- Optional modules degrade gracefully: a tool probes its table once and throws a clear `ToolCallException` if absent.

## Adding a supplier mapping

1. Copy `Tools/AsapTools.php` → `Tools/NewTools.php`; change the table name and tool/`#[McpTool]` names.
2. Copy `Resources/AsapResources.php` → `Resources/NewResources.php` similarly.
3. Deploy and `xcst:rebuild`. Services are autowired — no `services.yaml` changes.

## Transports

- **HTTP** (`config/routes.yaml` → `/mcp`): stateful, session via `Mcp-Session-Id`, PSR-16 session store with GC.
- **STDIO** (`bin/mcp-server`): boots the X-Cart kernel (`.env` + `autoload_xcart.php` + `bootstrap.php`), one session per process, full access unless `MCP_API_KEY` is set.
