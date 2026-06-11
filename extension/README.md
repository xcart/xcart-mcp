# X-Cart MCP Copilot — Chrome Extension

A Chrome side-panel that chats with your X-Cart store through the MCP module.
Bring your own LLM — **Claude (Anthropic)**, **DeepSeek**, or **OpenAI** — and the
agent calls MCP tools to look things up and make changes, with every tool call
visible in the chat.

## Features

- **Side panel chat UI** — purpose-built, no heavy web-component dependency, no build step.
- **Streaming replies** — tokens render as they arrive.
- **Live tool-call cards** — every MCP `tools/call` shows up inline with its arguments,
  result, and status. Nothing is hidden.
- **Stop button** — cancel a running agent mid-flight (aborts the in-flight LLM/MCP request).
- **Multiple providers** — Claude (`claude-opus-4-8`), DeepSeek (`deepseek-chat`), OpenAI.
  Store keys for several and switch the active one (or override per chat).
- **Multiple MCP servers** — connect more than one X-Cart store; tools are namespaced per server.
- **Per-chat overrides** — provider, model, system prompt, which servers, and which tools.
- **Parallel chats** — each chat runs in the background; start several at once.
- **Tool picker** — fetch the MCP tool list and enable only what you need (fewer tools = cheaper, sharper).
- **Dark mode** — auto / light / dark.
- **Local-only secrets** — API keys live in `chrome.storage.local` (not synced across devices).

## Install (unpacked)

1. Open `chrome://extensions/`, toggle **Developer mode**.
2. **Load unpacked** → select this folder (`chrome-extension/`).
3. Click the toolbar icon → opens the side panel. Open **Options** to configure.

## Configure (Options page)

- **LLM provider** — pick the active provider, paste its API key, optionally set a model:
  - Claude — key from console.anthropic.com, default `claude-opus-4-8`
  - DeepSeek — key from platform.deepseek.com, default `deepseek-chat`
  - OpenAI — key from platform.openai.com, default `gpt-4o-mini`
- **System prompt** — steer the agent globally.
- **MCP servers** — add one or more:
  - **MCP endpoint URL** — e.g. `https://your-xcart-domain/mcp`
  - **MCP API key** — `mcp_…`, from the XC/MCP module (Admin → Settings → MCP AI Integration)
  - **Test connection**, then **Fetch tools**, then check the tools to expose.

Per chat, use the **⚙** button in the panel header to override provider, model,
prompt, or the active servers/tools for that conversation only.

## How it works

```
panel.js ──CHAT_START──▶ background.js ──▶ providers.js ──▶ Claude / DeepSeek / OpenAI
   ▲                          │                                   │ (tool calls)
   └────CHAT_EVENT (stream)───┘                                   ▼
        text · tool_call · tool_result · done            mcp.js ──▶  /mcp  (Streamable HTTP)
```

`background.js` runs the agentic loop (LLM → tool calls → results → repeat),
streaming events back to the panel and persisting the transcript to
`chrome.storage.local` so chats survive panel close and run in parallel.

## Files

| File | Purpose |
|------|---------|
| `manifest.json` | MV3 manifest — side panel + options |
| `background.js` | Service worker: agentic loop, streaming, abort, persistence, config migration |
| `providers.js` | Unified streaming client for Claude (Messages API) + OpenAI-compatible (DeepSeek/OpenAI) |
| `mcp.js` | JSON-RPC client for MCP Streamable HTTP (timeout + abort + robust SSE) |
| `markdown.js` | Tiny XSS-safe Markdown → HTML renderer |
| `panel.html` / `panel.css` / `panel.js` | The chat side panel |
| `options.html` / `options.css` / `options.js` | Settings, provider keys, tool picker |

## Tests

End-to-end tests (Playwright) drive the unpacked extension against a real MCP
server and provider. Credentials come from the environment — **no keys are
committed**. Tests skip automatically if the env vars are missing.

```bash
npm install
MCP_URL="https://your-xcart/mcp" MCP_KEY="mcp_…" DEEPSEEK_KEY="sk-…" npm test
```

## Notes on providers

- **Claude** uses the Messages API (`/v1/messages`) directly via `fetch`, with
  `anthropic-dangerous-direct-browser-access: true`. On Opus 4.8 the request omits
  `temperature`/`budget_tokens` (those return 400 there).
- **DeepSeek / OpenAI** use the OpenAI-compatible chat-completions endpoint.
- All three stream over SSE and support function/tool calling, normalized into one
  internal format in `providers.js`.
