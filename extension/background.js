import * as MCP from './mcp.js';
import { chat, PROVIDERS, providerMeta } from './providers.js';

chrome.action.onClicked.addListener(async (tab) => {
  try { await chrome.sidePanel.open({ tabId: tab.id }); }
  catch { chrome.runtime.openOptionsPage(); }
});

const SEP = '__';
const encodeToolName = (sid, name) => `${sid}${SEP}${name}`;
function decodeToolName(encoded) {
  const i = encoded.indexOf(SEP);
  return i < 0 ? { serverId: null, name: encoded } : { serverId: encoded.slice(0, i), name: encoded.slice(i + SEP.length) };
}

const DEFAULT_PROMPT = 'You manage an X-Cart store through MCP tools. To do anything — search, create, update, map, or delete — you MUST call the relevant tool; calling the tool is the only thing that actually changes the store. Never describe, plan, narrate, or claim you did something without calling the tool to actually do it. For bulk tasks, keep calling tools (one action per call) until the work is finished, then give a short summary. Be concise.';
// Prompts shipped by earlier versions — auto-upgraded to DEFAULT_PROMPT for users who never customized it.
const LEGACY_PROMPTS = ['You are an assistant that manages an X-Cart store via MCP tools. Be concise. Use tools when the user asks to look up or modify data.'];
const isDefaultPrompt = (p) => !p || LEGACY_PROMPTS.includes(p);
const DEFAULT_MAX_ITERS = 40; // tool steps per message; bulk ops (mass create) need many
const HARD_MAX_ITERS = 200;
const MAX_TOOL_RESULT_CHARS = 12_000;

// -------- config (all persisted in chrome.storage.local) --------
//
//   providers:      { deepseek:{apiKey,model}, anthropic:{...}, openai:{...} }
//   activeProvider: 'deepseek' | 'anthropic' | 'openai'
//   servers:        [{ id, name, url, apiKey, enabledTools }]
//   systemPrompt, theme

function emptyProviders() {
  const o = {};
  for (const id of Object.keys(PROVIDERS)) o[id] = { apiKey: '', model: PROVIDERS[id].defaultModel };
  return o;
}

// One-time migration: pull legacy values out of storage.sync / old local keys.
async function migrateConfig() {
  const local = await chrome.storage.local.get(['configVersion', 'providers', 'servers', 'systemPrompt', 'activeProvider', 'theme']);
  if (local.configVersion >= 2 && local.providers) return;

  const sync = await chrome.storage.sync.get(['servers', 'deepseekKey', 'model', 'systemPrompt', 'mcpUrl', 'mcpKey', 'enabledTools']);

  const providers = local.providers || emptyProviders();
  if (sync.deepseekKey) { providers.deepseek.apiKey = sync.deepseekKey; if (sync.model) providers.deepseek.model = sync.model; }

  let servers = Array.isArray(local.servers) && local.servers.length ? local.servers
    : (Array.isArray(sync.servers) ? sync.servers : []);
  if (!servers.length && sync.mcpUrl && sync.mcpKey) {
    servers = [{ id: 'srv_legacy', name: 'X-Cart', url: sync.mcpUrl, apiKey: sync.mcpKey, enabledTools: sync.enabledTools || [] }];
  }

  await chrome.storage.local.set({
    configVersion: 2,
    providers,
    activeProvider: local.activeProvider || 'deepseek',
    servers,
    systemPrompt: local.systemPrompt || sync.systemPrompt || DEFAULT_PROMPT,
    theme: local.theme || 'auto',
  });
}

async function getConfig() {
  await migrateConfig();
  const s = await chrome.storage.local.get(['providers', 'activeProvider', 'servers', 'systemPrompt', 'maxIterations']);
  const maxIters = Number(s.maxIterations) || DEFAULT_MAX_ITERS;
  return {
    providers: s.providers || emptyProviders(),
    activeProvider: s.activeProvider || 'deepseek',
    servers: Array.isArray(s.servers) ? s.servers : [],
    systemPrompt: isDefaultPrompt(s.systemPrompt) ? DEFAULT_PROMPT : s.systemPrompt,
    maxIterations: Math.min(Math.max(1, maxIters), HARD_MAX_ITERS),
  };
}

// overrides = { serverIds?, enabledTools?, systemPrompt?, provider?, model? }
function resolveChatServers(allServers, overrides) {
  const ov = overrides || {};
  const serverIds = Array.isArray(ov.serverIds) ? ov.serverIds : null;
  const toolOverrides = ov.enabledTools || null;
  const subset = serverIds ? allServers.filter(s => serverIds.includes(s.id)) : allServers;
  return subset.map(s => ({ ...s, enabledTools: toolOverrides?.[s.id] ?? s.enabledTools ?? [] }));
}

async function collectEnabledTools(servers, signal) {
  const tools = []; const routing = {};
  for (const srv of servers) {
    if (!srv.enabledTools?.length) continue;
    const cacheKey = `toolsCache_${srv.id}`;
    let cache = (await chrome.storage.local.get([cacheKey]))[cacheKey];
    // Self-heal: refetch if the cache is missing OR stale (doesn't contain an
    // enabled tool). Otherwise that tool is silently never sent to the model.
    const hasAll = (c) => Array.isArray(c) && srv.enabledTools.every(n => c.some(t => t.name === n));
    if (!hasAll(cache)) {
      cache = await MCP.listTools(srv.url, srv.apiKey, { signal });
      await chrome.storage.local.set({ [cacheKey]: cache });
    }
    const enabled = new Set(srv.enabledTools);
    for (const t of cache) {
      if (!enabled.has(t.name)) continue;
      const enc = encodeToolName(srv.id, t.name);
      tools.push({
        name: enc,
        description: `[${srv.name}] ${t.description || ''}`,
        parameters: t.inputSchema || { type: 'object', properties: {} },
      });
      routing[enc] = { serverId: srv.id, name: t.name };
    }
  }
  return { tools, routing };
}

// Build the run context from config + per-chat overrides.
async function buildContext(overrides, signal) {
  const cfg = await getConfig();
  const providerId = overrides?.provider || cfg.activeProvider;
  const pcfg = cfg.providers[providerId] || {};
  const apiKey = pcfg.apiKey;
  if (!apiKey) throw new Error(`Set the ${providerMeta(providerId).label} API key in options.`);

  const servers = resolveChatServers(cfg.servers, overrides);
  const { tools, routing } = await collectEnabledTools(servers, signal);
  const serverById = Object.fromEntries(servers.map(s => [s.id, s]));

  return {
    provider: providerId,
    apiKey,
    model: overrides?.model || pcfg.model || providerMeta(providerId).defaultModel,
    system: overrides?.systemPrompt || cfg.systemPrompt,
    maxIterations: cfg.maxIterations,
    tools, routing, serverById,
  };
}

// Convert a stored display transcript into a provider-neutral history.
function historyToNeutral(messages) {
  const out = [];
  for (const m of messages) {
    if (m.role === 'user') out.push({ role: 'user', content: m.content || '' });
    else if (m.role === 'assistant') out.push({ role: 'assistant', content: m.content || '' });
    // 'tool' display entries are dropped — each turn re-runs tools fresh.
  }
  return out;
}

// Core agentic loop. Streams events via `emit`; returns { reply, trace }.
async function executeChat(ctx, neutral, { signal, emit }) {
  const trace = [];
  let reply = '';
  const maxIters = ctx.maxIterations || DEFAULT_MAX_ITERS;

  for (let i = 0; i < maxIters; i++) {
    const msg = await chat({
      provider: ctx.provider, apiKey: ctx.apiKey, model: ctx.model,
      system: ctx.system, messages: neutral, tools: ctx.tools, signal,
      onText: (delta) => emit({ kind: 'assistant_delta', delta }),
    });
    neutral.push({ role: 'assistant', content: msg.content, tool_calls: msg.tool_calls });
    if (msg.content) reply = msg.content;
    emit({ kind: 'assistant_commit', content: msg.content || '' });

    const calls = msg.tool_calls || [];
    if (!calls.length) return { reply, trace };

    for (const call of calls) {
      if (signal?.aborted) throw new DOMException('aborted', 'AbortError');
      const route = ctx.routing[call.name] || decodeToolName(call.name);
      const args = call.arguments || {};
      emit({ kind: 'tool_call', id: call.id, server: route.serverId, name: route.name, args });
      trace.push({ type: 'call', server: route.serverId, name: route.name, args });

      let content, ok = true, errMsg = null;
      try {
        const srv = ctx.serverById[route.serverId];
        if (!srv) throw new Error(`Unknown server id: ${route.serverId}`);
        const res = await MCP.callTool(srv.url, srv.apiKey, route.name, args, { signal });
        content = JSON.stringify(res);
        if (content.length > MAX_TOOL_RESULT_CHARS) content = content.slice(0, MAX_TOOL_RESULT_CHARS) + '…(truncated)';
      } catch (e) {
        ok = false; errMsg = String(e.message || e);
        content = JSON.stringify({ error: errMsg });
      }
      neutral.push({ role: 'tool', tool_call_id: call.id, name: route.name, content });
      trace.push({ type: 'result', server: route.serverId, name: route.name, ok, error: errMsg });
      emit({ kind: 'tool_result', id: call.id, server: route.serverId, name: route.name, args, ok, error: errMsg, preview: content.slice(0, 400) });
    }
  }
  const limit = `_(Stopped after ${maxIters} tool steps. Send “continue” to keep going, or raise the limit in Options.)_`;
  emit({ kind: 'assistant_commit', content: limit });
  return { reply: reply || limit, trace };
}

// -------- ad-hoc options helpers --------

async function resolveServer({ serverId, url, apiKey }) {
  if (url && apiKey) return { url, apiKey };
  const { servers } = await getConfig();
  const srv = servers.find(s => s.id === serverId);
  if (!srv) throw new Error(`Server not found: ${serverId}`);
  return { url: srv.url, apiKey: srv.apiKey };
}

async function handleListTools(msg) {
  const { url, apiKey } = await resolveServer(msg);
  MCP.resetSession(url, apiKey);
  const tools = await MCP.listTools(url, apiKey);
  if (msg.serverId) await chrome.storage.local.set({ [`toolsCache_${msg.serverId}`]: tools });
  return tools;
}

async function handleTestMcp(msg) {
  const { url, apiKey } = await resolveServer(msg);
  MCP.resetSession(url, apiKey);
  return MCP.initialize(url, apiKey);
}

// -------- async chat lifecycle --------

async function loadChat(chatId) {
  const d = await chrome.storage.local.get(['chats']);
  const chats = d.chats || {};
  return { chats, chat: chats[chatId] || null };
}
async function saveChats(chats) { await chrome.storage.local.set({ chats }); }

// A persistence failure (e.g. storage quota) during a run must not be silently
// swallowed — abort the run and surface it so the chat doesn't freeze.
function onWriteError(chatId, e) {
  const c = CONTROLLERS.get(chatId);
  if (c) c.abort();
  broadcast(chatId, { kind: 'error', error: `Storage error: ${e?.message || e}. Try deleting old chats.` });
}

// Serialize all read-modify-write operations on the `chats` key so concurrent
// appends and status flips can't clobber each other (lost-write race).
let writeQueue = Promise.resolve();
function enqueue(fn) {
  const p = writeQueue.then(fn, fn);
  writeQueue = p.then(() => {}, () => {});
  return p;
}

function appendMessage(chatId, entry) {
  return enqueue(async () => {
    const { chats, chat } = await loadChat(chatId);
    if (!chat) return;
    chat.messages.push(entry);
    chat.updatedAt = Date.now();
    chats[chatId] = chat;
    try { await saveChats(chats); } catch (e) { onWriteError(chatId, e); }
  });
}

function setChatStatus(chatId, status) {
  return enqueue(async () => {
    const { chats, chat } = await loadChat(chatId);
    if (!chat) return;
    chat.status = status; chat.updatedAt = Date.now();
    chats[chatId] = chat;
    try { await saveChats(chats); } catch (e) { onWriteError(chatId, e); }
  });
}

const CONTROLLERS = new Map();

// Keep the MV3 service worker alive while runs are in flight. Long bulk
// operations (many sequential tool calls) can otherwise outlive the worker,
// which would kill the fire-and-forget run and freeze the chat at "running".
function updateKeepalive() {
  if (CONTROLLERS.size) chrome.alarms.create('keepalive', { periodInMinutes: 0.4 });
  else chrome.alarms.clear('keepalive');
}
chrome.alarms?.onAlarm.addListener((a) => {
  if (a.name === 'keepalive' && CONTROLLERS.size) chrome.storage.local.get('configVersion'); // touch an API to reset the idle timer
  else if (a.name === 'keepalive') chrome.alarms.clear('keepalive');
});

function broadcast(chatId, ev) {
  chrome.runtime.sendMessage({ type: 'CHAT_EVENT', chatId, ev }).catch(() => {});
}

async function startChat({ chatId }) {
  if (!chatId) throw new Error('chatId required');
  if (CONTROLLERS.has(chatId)) throw new Error('Chat is already running.');
  const { chat } = await loadChat(chatId);
  if (!chat) throw new Error(`Chat not found: ${chatId}`);
  if (!chat.messages?.length) throw new Error('No messages in chat.');

  const controller = new AbortController();
  CONTROLLERS.set(chatId, controller);
  updateKeepalive();
  await setChatStatus(chatId, 'running');
  broadcast(chatId, { kind: 'status', status: 'running' });

  // Persist assistant/tool entries as they stream; relay everything to the UI.
  const emit = (ev) => {
    broadcast(chatId, ev);
    if (ev.kind === 'assistant_commit' && ev.content) appendMessage(chatId, { role: 'assistant', content: ev.content });
    else if (ev.kind === 'tool_result') {
      appendMessage(chatId, { role: 'tool', id: ev.id, server: ev.server, name: ev.name, args: ev.args, ok: ev.ok, error: ev.error, preview: ev.preview });
    }
  };

  (async () => {
    let error = null;
    try {
      const ctx = await buildContext(chat.overrides || null, controller.signal);
      const neutral = historyToNeutral(chat.messages);
      await executeChat(ctx, neutral, { signal: controller.signal, emit });
    } catch (e) {
      error = controller.signal.aborted ? 'Stopped.' : String(e.message || e);
    }
    CONTROLLERS.delete(chatId);
    updateKeepalive();
    await setChatStatus(chatId, 'idle');
    broadcast(chatId, error ? { kind: 'error', error } : { kind: 'done' });
  })();

  return { started: true };
}

function stopChat({ chatId }) {
  const c = CONTROLLERS.get(chatId);
  if (c) c.abort();
  return { stopped: !!c };
}

// Synchronous one-shot (used by tests): runs without persistence, returns trace.
async function runChatSync({ messages, overrides }) {
  const ctx = await buildContext(overrides || null, null);
  const neutral = messages.map(m => ({ role: m.role === 'tool' ? 'user' : m.role, content: m.content }));
  return executeChat(ctx, neutral, { signal: null, emit: () => {} });
}

chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
  (async () => {
    try {
      if (msg?.type === 'LIST_TOOLS') sendResponse({ ok: true, data: await handleListTools(msg) });
      else if (msg?.type === 'TEST_MCP') sendResponse({ ok: true, data: await handleTestMcp(msg) });
      else if (msg?.type === 'CHAT_START') sendResponse({ ok: true, data: await startChat(msg) });
      else if (msg?.type === 'CHAT_STOP') sendResponse({ ok: true, data: stopChat(msg) });
      else if (msg?.type === 'CHAT') sendResponse({ ok: true, data: await runChatSync(msg) });
      else sendResponse({ ok: false, error: 'Unknown message type' });
    } catch (e) {
      sendResponse({ ok: false, error: String(e.message || e) });
    }
  })();
  return true;
});
