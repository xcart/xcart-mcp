<div align="center">

<img src="module/config/images/icon.png" alt="X-Cart MCP" width="120" height="120" />

# X-Cart MCP

**Let AI agents manage an X-Cart 5.6 store through the Model Context Protocol.**

[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4.svg)](https://www.php.net/)
[![X-Cart](https://img.shields.io/badge/X--Cart-5.6-FF6600.svg)](https://www.x-cart.com/)
[![MCP](https://img.shields.io/badge/MCP-2025--11--25-6E56CF.svg)](https://modelcontextprotocol.io/)

</div>

---

This repository has two parts:

| Folder | What it is |
|--------|-----------|
| [**`module/`**](module/) | The **X-Cart MCP server** — a PHP module that embeds an MCP server into X-Cart 5.6. Exposes the store (products, orders, categories, search, reports, vehicle fitment, brands, supplier mapping) as **51 tools, 29 resources, 13 prompts** over STDIO and authenticated Streamable HTTP (`/mcp`). |
| [**`extension/`**](extension/) | The **X-Cart MCP Copilot** — a Chrome side-panel extension that chats with your store through the module, using **Claude, DeepSeek, or OpenAI** as the LLM. Streaming replies, live tool-call cards, multi-chat, dark mode. |

```
xcart-mcp/
├── module/        # PHP MCP server module for X-Cart 5.6
│   ├── src/           # Tools, Resources, Prompts, Security, Controller
│   ├── config/        # install.yaml, routes, DI services
│   ├── vendor/        # bundled deps (works without `composer require`)
│   ├── docs/          # ARCHITECTURE, API-REFERENCE, DEPLOYMENT
│   ├── AGENTS.md      # deterministic install/verify guide for AI agents
│   └── README.md
├── extension/     # Chrome side-panel copilot (MV3)
│   ├── background.js  # agentic loop: LLM ↔ MCP, streaming, abort
│   ├── providers.js   # Claude (Messages API) + OpenAI-compatible (DeepSeek/OpenAI)
│   ├── mcp.js         # MCP Streamable HTTP client
│   ├── panel.*        # the chat UI
│   ├── options.*      # settings, provider keys, tool picker
│   ├── tests/         # Playwright e2e + fully-mocked suite
│   └── README.md
├── LICENSE
└── NOTICE
```

## How they fit together

```
┌──────────────────────────┐        Streamable HTTP / STDIO        ┌───────────────────────┐
│  MCP client               │  ───────────────────────────────────▶ │  module/  (X-Cart 5.6) │
│  • extension/ (this repo) │                                        │  MCP server: tools,    │
│  • Claude Desktop / Code  │  ◀─────────────────────────────────── │  resources, prompts    │
│  • Cursor / Codex / …     │            JSON-RPC results            │                        │
└──────────────────────────┘                                        └───────────────────────┘
```

The module is a standard MCP server, so **any** MCP client can drive it — Claude Desktop, Claude Code, Cursor, Codex, IDE plugins. The extension is a purpose-built client that pairs the store with a chat copilot in the browser.

## Quick start

### 1. Install the MCP server (module)

```bash
cp -r module /path/to/xcart/modules/XC/MCP
php service-tool/bin/console xcst:rebuild --enable XC-MCP
```

The install hook bundles its dependencies and generates an API key (Admin → Settings → MCP AI Integration). Full instructions, verification, and troubleshooting: [`module/README.md`](module/README.md) and the agent-oriented [`module/AGENTS.md`](module/AGENTS.md).

### 2. (Optional) Install the Chrome copilot (extension)

1. `chrome://extensions/` → **Developer mode** → **Load unpacked** → select `extension/`.
2. Open **Options**: pick a provider (Claude / DeepSeek / OpenAI), paste its API key, add your MCP endpoint (`https://your-store/mcp` + the key from the module) and select the tools to expose.

Details: [`extension/README.md`](extension/README.md).

## Documentation

- [`module/README.md`](module/README.md) — install, connect a client, transports, security, full tool/resource/prompt reference
- [`module/AGENTS.md`](module/AGENTS.md) — step-by-step install/verify procedure for AI agents
- [`module/docs/ARCHITECTURE.md`](module/docs/ARCHITECTURE.md) · [`API-REFERENCE.md`](module/docs/API-REFERENCE.md) · [`DEPLOYMENT.md`](module/docs/DEPLOYMENT.md)
- [`extension/README.md`](extension/README.md) — the browser copilot, providers, and tests

## License

Licensed under the [Apache License 2.0](LICENSE). "X-Cart" is a trademark of X-Cart Holdings LLC; this is an independent integration project and is not an official X-Cart product.
