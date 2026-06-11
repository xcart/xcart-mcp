// Custom multi-chat side panel: streaming replies, live tool-call cards,
// per-chat overrides, Stop, dark mode. Background does the agentic work and
// streams CHAT_EVENT messages; this renders them.
//
// Rendering model: the currently-viewed, running chat is rendered from an
// in-memory "live turn" (driven by CHAT_EVENT) layered on top of the persisted
// messages that existed when the turn started — so there's no flicker from the
// background writing the same content to storage. Everything else renders
// straight from chrome.storage.local.

import { renderMarkdown } from './markdown.js';
import { PROVIDERS } from './providers.js';

const $ = (id) => document.getElementById(id);
const transcriptEl = $('transcript');
const statusEl = $('status');
const listEl = $('chat-list');
const titleLabel = $('chat-title-label');
const inputEl = $('input');

let STATE = { chats: {}, chatOrder: [], currentChatId: null };
let CONFIG = { servers: [], activeProvider: 'deepseek' };
let TOOLS_CACHE = {}; // serverId → tools[]
// Live turn for the currently-viewed chat (null when nothing streaming here).
let live = null; // { chatId, baseCount, items: [...] }
let rafPending = false;

// ---------- helpers ----------

function setStatus(text, isErr) { statusEl.textContent = text; statusEl.className = isErr ? 'err' : ''; }
function escapeHtml(s) { return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

function sendBg(msg) {
  return new Promise((resolve, reject) => {
    chrome.runtime.sendMessage(msg, (res) => {
      if (chrome.runtime.lastError) return reject(new Error(chrome.runtime.lastError.message));
      if (!res?.ok) return reject(new Error(res?.error || 'Unknown error'));
      resolve(res.data);
    });
  });
}

const currentChat = () => STATE.chats[STATE.currentChatId];
const isRunning = (c) => c?.status === 'running';
const liveActive = () => live && live.chatId === STATE.currentChatId && isRunning(currentChat());

function newChat() {
  const id = 'c_' + (crypto.randomUUID?.().slice(0, 8) || Math.random().toString(16).slice(2, 10));
  return { id, title: 'New chat', createdAt: Date.now(), updatedAt: Date.now(), messages: [], status: 'idle', overrides: null };
}

// ---------- storage ----------

async function loadConfig() {
  const s = await chrome.storage.local.get(['servers', 'activeProvider', 'theme']);
  CONFIG.servers = Array.isArray(s.servers) ? s.servers : [];
  CONFIG.activeProvider = s.activeProvider || 'deepseek';
  applyTheme(s.theme || 'auto');
  if (CONFIG.servers.length) {
    const keys = CONFIG.servers.map(x => `toolsCache_${x.id}`);
    const local = await chrome.storage.local.get(keys);
    for (const x of CONFIG.servers) TOOLS_CACHE[x.id] = local[`toolsCache_${x.id}`] || [];
  }
}

async function loadState() {
  const d = await chrome.storage.local.get(['chats', 'chatOrder', 'currentChatId']);
  STATE.chats = d.chats || {};
  STATE.chatOrder = (Array.isArray(d.chatOrder) ? d.chatOrder : []).filter(id => STATE.chats[id]);
  for (const id of Object.keys(STATE.chats)) if (!STATE.chatOrder.includes(id)) STATE.chatOrder.unshift(id);
  STATE.currentChatId = d.currentChatId || null;
  if (!STATE.chatOrder.length) {
    const c = newChat(); STATE.chats[c.id] = c; STATE.chatOrder.unshift(c.id); STATE.currentChatId = c.id;
  }
  if (!STATE.currentChatId || !STATE.chats[STATE.currentChatId]) STATE.currentChatId = STATE.chatOrder[0];
  await saveState();
}

async function saveState() {
  await chrome.storage.local.set({ chats: STATE.chats, chatOrder: STATE.chatOrder, currentChatId: STATE.currentChatId });
}

// ---------- theme ----------

function applyTheme(theme) {
  const root = document.documentElement;
  if (theme === 'light' || theme === 'dark') root.setAttribute('data-theme', theme);
  else root.removeAttribute('data-theme');
  root.dataset.themePref = theme;
}
$('btn-theme').addEventListener('click', async () => {
  const order = ['auto', 'light', 'dark'];
  const next = order[(order.indexOf(document.documentElement.dataset.themePref || 'auto') + 1) % 3];
  applyTheme(next);
  await chrome.storage.local.set({ theme: next });
  setStatus(`Theme: ${next}`);
});

// ---------- sidebar ----------

function renderSidebar() {
  listEl.innerHTML = '';
  for (const id of STATE.chatOrder) {
    const c = STATE.chats[id]; if (!c) continue;
    const item = document.createElement('div');
    item.className = 'chat-item' + (id === STATE.currentChatId ? ' active' : '');
    item.innerHTML = `
      ${isRunning(c) ? '<span class="running-dot" title="Running"></span>' : ''}
      <span class="chat-title"></span>
      <span class="chat-actions">
        <button class="rename" title="Rename">✎</button>
        <button class="delete" title="Delete">×</button>
      </span>`;
    item.querySelector('.chat-title').textContent = c.title || 'Untitled';
    item.addEventListener('click', (e) => { if (!e.target.closest('.chat-actions')) switchChat(id); });
    item.querySelector('.rename').addEventListener('click', async (e) => {
      e.stopPropagation();
      const t = window.prompt('Rename chat:', c.title || '');
      if (t === null) return;
      c.title = t.trim() || 'Untitled'; c.updatedAt = Date.now();
      await saveState(); renderSidebar(); updateHeader();
    });
    item.querySelector('.delete').addEventListener('click', async (e) => {
      e.stopPropagation();
      if (!window.confirm(`Delete "${c.title || 'Untitled'}"?`)) return;
      if (isRunning(c)) sendBg({ type: 'CHAT_STOP', chatId: id }).catch(() => {});
      delete STATE.chats[id];
      STATE.chatOrder = STATE.chatOrder.filter(x => x !== id);
      if (live?.chatId === id) live = null;
      if (STATE.currentChatId === id) {
        STATE.currentChatId = STATE.chatOrder[0] || null;
        if (!STATE.currentChatId) { const c2 = newChat(); STATE.chats[c2.id] = c2; STATE.chatOrder.unshift(c2.id); STATE.currentChatId = c2.id; }
      }
      await saveState(); renderSidebar(); renderTranscript(); updateHeader(); syncComposer();
    });
    listEl.appendChild(item);
  }
}

function updateHeader() {
  const c = currentChat();
  titleLabel.textContent = c ? (c.title || 'Untitled') : 'X-Cart MCP Copilot';
}

// ---------- transcript ----------

function bubble(role, html, streaming) {
  const wrap = document.createElement('div');
  wrap.className = `msg ${role === 'assistant' ? 'ai' : 'user'}`;
  const b = document.createElement('div');
  b.className = 'bubble' + (streaming ? ' streaming' : '');
  b.innerHTML = html;
  wrap.appendChild(b);
  return wrap;
}

function toolCard(entry) {
  const card = document.createElement('details');
  card.className = 'tool-card ' + (entry.pending ? '' : (entry.ok ? 'ok' : 'err'));
  const server = CONFIG.servers.find(s => s.id === entry.server);
  const statusHtml = entry.pending ? '<span class="tc-spin"></span>' : `<span class="tc-status">${entry.ok ? '✓' : '✕'}</span>`;
  card.innerHTML = `
    <summary>
      <span class="tc-icon">🔧</span>
      <span class="tc-name"></span>
      <span class="tc-server"></span>
      ${statusHtml}
    </summary>
    <div class="tool-body">
      <div class="label">Arguments</div>
      <pre class="tc-args"></pre>
      ${entry.pending ? '' : `<div class="label">${entry.ok ? 'Result' : 'Error'}</div><pre class="tc-result"></pre>`}
    </div>`;
  card.querySelector('.tc-name').textContent = entry.name || '(tool)';
  card.querySelector('.tc-server').textContent = server ? server.name : '';
  card.querySelector('.tc-args').textContent = pretty(entry.args);
  if (!entry.pending) card.querySelector('.tc-result').textContent = entry.ok ? pretty(maybeJson(entry.preview)) : (entry.error || '');
  return card;
}

function pretty(v) { try { return JSON.stringify(v ?? {}, null, 2); } catch { return String(v); } }
function maybeJson(s) { try { return JSON.parse(s); } catch { return s; } }

function appendMsg(m) {
  if (m.role === 'user') transcriptEl.appendChild(bubble('user', escapeHtml(m.content).replace(/\n/g, '<br>')));
  else if (m.role === 'assistant') transcriptEl.appendChild(bubble('assistant', renderMarkdown(m.content), m.streaming));
  else if (m.role === 'tool') transcriptEl.appendChild(toolCard(m));
}

function renderTranscript() {
  const chat = currentChat();
  // Sticky-bottom: only auto-scroll if the user is already near the bottom,
  // so streaming text doesn't yank the view down while they read above.
  const stick = transcriptEl.scrollHeight - transcriptEl.scrollTop - transcriptEl.clientHeight < 80;
  const prevTop = transcriptEl.scrollTop;
  transcriptEl.innerHTML = '';
  const msgs = chat?.messages || [];
  const showingLive = liveActive();

  if (!msgs.length && !showingLive) {
    const e = document.createElement('div');
    e.id = 'empty-state';
    e.innerHTML = `<div class="logo">🛒</div><div><strong>X-Cart MCP Copilot</strong></div>
      <div style="margin-top:6px">Ask about products, orders, categories, vehicles… Use ⚙ to override provider, prompt, or tools for this chat.</div>`;
    transcriptEl.appendChild(e);
    return;
  }

  if (showingLive) {
    for (const m of msgs.slice(0, live.baseCount)) appendMsg(m);
    for (const it of live.items) {
      if (it.type === 'assistant') { if (it.text) appendMsg({ role: 'assistant', content: it.text, streaming: it.streaming }); }
      else appendMsg({ role: 'tool', ...it });
    }
  } else {
    for (const m of msgs) appendMsg(m);
  }
  transcriptEl.scrollTop = stick ? transcriptEl.scrollHeight : prevTop;
}

function scheduleRender() {
  if (rafPending) return;
  rafPending = true;
  requestAnimationFrame(() => { rafPending = false; renderTranscript(); });
}

function syncComposer() {
  const running = isRunning(currentChat());
  $('btn-send').hidden = running;
  $('btn-stop').hidden = !running;
  if (statusEl.className !== 'err') setStatus(running ? 'Agent working…' : 'Ready');
}

// ---------- sending ----------

async function send() {
  const text = inputEl.value.trim();
  const chat = currentChat();
  if (!text || !chat || isRunning(chat)) return;

  chat.messages.push({ role: 'user', content: text });
  if (chat.title === 'New chat' || !chat.title) chat.title = text.slice(0, 42).replace(/\s+/g, ' ').trim() || 'Chat';
  chat.status = 'running'; chat.updatedAt = Date.now();
  STATE.chatOrder = [chat.id, ...STATE.chatOrder.filter(x => x !== chat.id)];
  live = { chatId: chat.id, baseCount: chat.messages.length, items: [] };

  inputEl.value = ''; autoGrow();
  await saveState();
  renderSidebar(); updateHeader(); renderTranscript(); syncComposer();

  try {
    await sendBg({ type: 'CHAT_START', chatId: chat.id });
  } catch (e) {
    chat.status = 'idle'; live = null; await saveState();
    setStatus(String(e.message || e), true); syncComposer(); renderSidebar(); renderTranscript();
  }
}

$('btn-send').addEventListener('click', send);
$('btn-stop').addEventListener('click', () => {
  const c = currentChat(); if (c) { setStatus('Stopping…'); sendBg({ type: 'CHAT_STOP', chatId: c.id }).catch(() => {}); }
});

function autoGrow() { inputEl.style.height = 'auto'; inputEl.style.height = Math.min(inputEl.scrollHeight, 140) + 'px'; }
inputEl.addEventListener('input', autoGrow);
inputEl.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } });

// ---------- background events ----------

function liveLastAssistant() {
  const last = live.items[live.items.length - 1];
  if (last && last.type === 'assistant' && last.streaming) return last;
  const a = { type: 'assistant', text: '', streaming: true };
  live.items.push(a);
  return a;
}

chrome.runtime.onMessage.addListener((msg) => {
  if (msg?.type !== 'CHAT_EVENT') return;
  const { chatId, ev } = msg;
  if (chatId !== STATE.currentChatId) return; // other chats reflected via storage.onChanged
  if (!live || live.chatId !== chatId) {
    // We weren't tracking this turn (e.g. switched back to it) — seed from current persisted state.
    const c = STATE.chats[chatId];
    live = { chatId, baseCount: c?.messages?.length || 0, items: [] };
  }

  switch (ev.kind) {
    case 'assistant_delta': liveLastAssistant().text += ev.delta; scheduleRender(); break;
    case 'assistant_commit': { const a = liveLastAssistant(); a.streaming = false; if (!ev.content) live.items.pop(); scheduleRender(); break; }
    case 'tool_call': live.items.push({ type: 'tool', id: ev.id, server: ev.server, name: ev.name, args: ev.args, pending: true }); scheduleRender(); break;
    case 'tool_result': {
      const it = live.items.find(i => i.type === 'tool' && i.id === ev.id);
      if (it) { it.pending = false; it.ok = ev.ok; it.error = ev.error; it.preview = ev.preview; }
      scheduleRender(); break;
    }
    case 'done':
    case 'error':
      live = null;
      setStatus(ev.kind === 'error' ? (ev.error || 'Error') : 'Ready', ev.kind === 'error');
      // Pull the final persisted transcript (the 'done' message may beat storage.onChanged).
      chrome.storage.local.get(['chats'], (d) => {
        if (d.chats) STATE.chats = d.chats;
        renderSidebar(); updateHeader(); syncComposer(); renderTranscript();
      });
      break;
  }
});

// Persisted state changed (background appended messages / flipped status).
chrome.storage.onChanged.addListener((changes, area) => {
  if (area !== 'local') return;
  if (changes.chats) {
    STATE.chats = changes.chats.newValue || {};
    renderSidebar(); updateHeader(); syncComposer();
    if (!liveActive()) scheduleRender(); // when live, events own the transcript
  }
  if (changes.theme) applyTheme(changes.theme.newValue || 'auto');
});

async function switchChat(id) {
  if (id === STATE.currentChatId) return;
  STATE.currentChatId = id;
  if (!live || live.chatId !== id) live = null;
  await saveState();
  renderSidebar(); updateHeader(); renderTranscript(); syncComposer();
}

$('btn-options').addEventListener('click', () => chrome.runtime.openOptionsPage());
$('btn-new-chat').addEventListener('click', async () => {
  const c = newChat();
  STATE.chats[c.id] = c; STATE.chatOrder.unshift(c.id); STATE.currentChatId = c.id;
  live = null;
  await saveState(); renderSidebar(); updateHeader(); renderTranscript(); syncComposer();
  inputEl.focus();
});
$('btn-toggle-sidebar').addEventListener('click', () => $('sidebar').classList.toggle('collapsed'));

// ---------- chat settings modal ----------

const modalBackdrop = $('modal-backdrop');

function openChatSettings() {
  const chat = currentChat(); if (!chat) return;
  const ov = chat.overrides || {};

  const provSel = $('m-provider');
  provSel.innerHTML = `<option value="">Default (${PROVIDERS[CONFIG.activeProvider]?.label || CONFIG.activeProvider})</option>` +
    Object.entries(PROVIDERS).map(([id, p]) => `<option value="${id}">${p.label}</option>`).join('');
  provSel.value = ov.provider || '';
  $('m-model').value = ov.model || '';
  $('m-prompt').value = ov.systemPrompt || '';

  const host = $('m-servers'); host.innerHTML = '';
  const serverIds = ov.serverIds || null;
  const toolOverrides = ov.enabledTools || {};
  if (!CONFIG.servers.length) host.innerHTML = '<div class="muted">No MCP servers configured. Open Options.</div>';

  for (const srv of CONFIG.servers) {
    const tools = TOOLS_CACHE[srv.id] || [];
    const included = serverIds === null ? true : serverIds.includes(srv.id);
    const customTools = toolOverrides[srv.id];
    const enabled = new Set(customTools ?? srv.enabledTools ?? []);
    const block = document.createElement('div');
    block.className = 'server-block'; block.dataset.id = srv.id;
    block.innerHTML = `
      <div class="server-head">
        <input type="checkbox" class="srv-on" ${included ? 'checked' : ''}>
        <span>${escapeHtml(srv.name || srv.url)}</span>
        <span class="muted" style="margin-left:auto;">${tools.length} tools</span>
      </div>
      <label style="font-weight:400;font-size:11px;margin-top:6px;">
        <input type="checkbox" class="srv-custom" ${customTools ? 'checked' : ''}> Override tool selection
      </label>
      <div class="tools-wrap" style="max-height:180px;overflow-y:auto;margin-top:4px;display:${customTools ? 'block' : 'none'};"></div>`;
    const tw = block.querySelector('.tools-wrap');
    tw.innerHTML = tools.map(t => `<label class="tool-opt"><input type="checkbox" data-name="${escapeHtml(t.name)}" ${enabled.has(t.name) ? 'checked' : ''}><code>${escapeHtml(t.name)}</code></label>`).join('');
    block.querySelector('.srv-custom').addEventListener('change', (e) => { tw.style.display = e.target.checked ? 'block' : 'none'; });
    host.appendChild(block);
  }
  modalBackdrop.classList.add('open');
}

function closeChatSettings() { modalBackdrop.classList.remove('open'); }

function readModalIntoOverrides() {
  const ov = {};
  const provider = $('m-provider').value;
  const model = $('m-model').value.trim();
  const prompt = $('m-prompt').value.trim();
  if (provider) ov.provider = provider;
  if (model) ov.model = model;
  if (prompt) ov.systemPrompt = prompt;

  const enabledTools = {}; const serverIds = []; let anyUnchecked = false;
  for (const b of document.querySelectorAll('#m-servers .server-block')) {
    const id = b.dataset.id;
    if (b.querySelector('.srv-on').checked) serverIds.push(id); else anyUnchecked = true;
    if (b.querySelector('.srv-custom').checked) {
      enabledTools[id] = Array.from(b.querySelectorAll('.tools-wrap input[type=checkbox]')).filter(cb => cb.checked).map(cb => cb.dataset.name);
    }
  }
  if (anyUnchecked) ov.serverIds = serverIds;
  if (Object.keys(enabledTools).length) ov.enabledTools = enabledTools;
  return Object.keys(ov).length ? ov : null;
}

$('btn-chat-settings').addEventListener('click', openChatSettings);
$('m-cancel').addEventListener('click', closeChatSettings);
$('m-reset').addEventListener('click', async () => { const c = currentChat(); if (!c) return; c.overrides = null; await saveState(); closeChatSettings(); setStatus('Chat reset to defaults'); });
$('m-save').addEventListener('click', async () => { const c = currentChat(); if (!c) return; c.overrides = readModalIntoOverrides(); await saveState(); closeChatSettings(); setStatus('Chat settings saved'); });
modalBackdrop.addEventListener('click', (e) => { if (e.target === modalBackdrop) closeChatSettings(); });

// ---------- init ----------

(async () => {
  try {
    await loadConfig();
    await loadState();
    renderSidebar(); updateHeader(); renderTranscript(); syncComposer();
    inputEl.focus();
  } catch (e) {
    setStatus(String(e.message || e), true);
  }
})();
