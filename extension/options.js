import { PROVIDERS, providerMeta } from './providers.js';

const $ = (id) => document.getElementById(id);
const DEFAULT_PROMPT = 'You manage an X-Cart store through MCP tools. To do anything — search, create, update, map, or delete — you MUST call the relevant tool; calling the tool is the only thing that actually changes the store. Never describe, plan, narrate, or claim you did something without calling the tool to actually do it. For bulk tasks, keep calling tools (one action per call) until the work is finished, then give a short summary. Be concise.';
const LEGACY_PROMPTS = ['You are an assistant that manages an X-Cart store via MCP tools. Be concise. Use tools when the user asks to look up or modify data.'];
const isDefaultPrompt = (p) => !p || LEGACY_PROMPTS.includes(p);

// Working copy; persisted on Save (storage.local).
let SERVERS = [];                       // [{id,name,url,apiKey,enabledTools}]
let PROVIDER_CFG = {};                  // { id: {apiKey, model} }
let ACTIVE = 'deepseek';
const TOOLS = {};                       // serverId → tools[]

function emptyProviders() {
  const o = {};
  for (const id of Object.keys(PROVIDERS)) o[id] = { apiKey: '', model: PROVIDERS[id].defaultModel };
  return o;
}

function setGlobalStatus(text, cls = '') { const el = $('global-status'); el.textContent = text; el.className = cls; }

function sendBg(msg) {
  return new Promise((resolve, reject) => {
    chrome.runtime.sendMessage(msg, (res) => {
      if (chrome.runtime.lastError) return reject(new Error(chrome.runtime.lastError.message));
      if (!res?.ok) return reject(new Error(res?.error || 'Unknown error'));
      resolve(res.data);
    });
  });
}

const newId = () => 'srv_' + (crypto.randomUUID?.().slice(0, 8) || Math.random().toString(16).slice(2, 10));
function groupOf(name) { const i = name.indexOf('_'); return i > 0 ? name.slice(0, i) : 'misc'; }
function escapeHtml(s) { return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

// ---------- theme ----------
function applyTheme(theme) {
  const root = document.documentElement;
  if (theme === 'light' || theme === 'dark') root.setAttribute('data-theme', theme);
  else root.removeAttribute('data-theme');
}
$('theme').addEventListener('change', (e) => { applyTheme(e.target.value); chrome.storage.local.set({ theme: e.target.value }); });

// ---------- providers ----------
function renderProviders() {
  const host = $('providers'); host.innerHTML = '';
  const tpl = $('provider-tpl');
  for (const [id, meta] of Object.entries(PROVIDERS)) {
    const card = tpl.content.firstElementChild.cloneNode(true);
    card.dataset.id = id;
    card.querySelector('.name').textContent = meta.label;
    card.querySelector('.keyhelp').textContent = 'Key from ' + (meta.keyHelp || '');
    const key = card.querySelector('.key'); key.placeholder = meta.keyPlaceholder || 'API key'; key.value = PROVIDER_CFG[id]?.apiKey || '';
    const model = card.querySelector('.model'); model.placeholder = meta.defaultModel; model.value = PROVIDER_CFG[id]?.model || '';
    const radio = card.querySelector('.active-radio'); radio.checked = id === ACTIVE;
    const badge = card.querySelector('.badge');
    const refresh = () => {
      host.querySelectorAll('.provider-card').forEach(c => { const a = c.dataset.id === ACTIVE; c.classList.toggle('active', a); c.querySelector('.badge').hidden = !a; });
    };
    radio.addEventListener('change', () => { if (radio.checked) { ACTIVE = id; refresh(); } });
    key.addEventListener('input', () => { (PROVIDER_CFG[id] ||= {}).apiKey = key.value.trim(); });
    model.addEventListener('input', () => { (PROVIDER_CFG[id] ||= {}).model = model.value.trim(); });
    host.appendChild(card);
    if (id === ACTIVE) { card.classList.add('active'); badge.hidden = false; }
  }
}

// ---------- MCP servers ----------
function renderTools(card, serverId) {
  const host = card.querySelector('.tools-list');
  const tools = TOOLS[serverId];
  if (!tools || !tools.length) { host.innerHTML = '<em class="muted">Click “Fetch tools” to load.</em>'; return; }
  const srv = SERVERS.find(s => s.id === serverId);
  const enabled = new Set(srv?.enabledTools || []);
  const groups = {};
  for (const t of tools) (groups[groupOf(t.name)] ||= []).push(t);
  host.innerHTML = Object.keys(groups).sort().map(g => {
    const items = groups[g].map(t => `
      <label class="tool">
        <input type="checkbox" data-name="${escapeHtml(t.name)}" ${enabled.has(t.name) ? 'checked' : ''}>
        <div><code>${escapeHtml(t.name)}</code><div class="tool-desc">${escapeHtml(t.description || '')}</div></div>
      </label>`).join('');
    return `<div class="group"><div class="group-title">${escapeHtml(g)} (${groups[g].length})</div>${items}</div>`;
  }).join('');
}

const collectEnabled = (card) => Array.from(card.querySelectorAll('.tools-list input[type=checkbox]')).filter(cb => cb.checked).map(cb => cb.dataset.name);
function setCardStatus(card, text, cls = '') { const el = card.querySelector('.server-status'); el.textContent = text; el.className = 'server-status ' + cls; }

function createServerCard(srv) {
  const card = $('server-tpl').content.firstElementChild.cloneNode(true);
  card.dataset.id = srv.id;
  card.querySelector('.name-input').value = srv.name || '';
  card.querySelector('.url').value = srv.url || '';
  card.querySelector('.key').value = srv.apiKey || '';

  card.querySelector('.delete').addEventListener('click', () => {
    if (!confirm(`Delete "${srv.name || 'this server'}"?`)) return;
    SERVERS = SERVERS.filter(s => s.id !== srv.id); delete TOOLS[srv.id]; card.remove();
  });

  const syncFromInputs = () => {
    srv.name = card.querySelector('.name-input').value.trim();
    srv.url = card.querySelector('.url').value.trim();
    srv.apiKey = card.querySelector('.key').value.trim();
    srv.enabledTools = collectEnabled(card);
  };
  card.addEventListener('input', syncFromInputs);
  card.addEventListener('change', syncFromInputs);

  card.querySelector('.test').addEventListener('click', async () => {
    syncFromInputs(); await persist(); setCardStatus(card, 'Testing…');
    try { const info = await sendBg({ type: 'TEST_MCP', serverId: srv.id }); setCardStatus(card, `Connected to ${info?.serverInfo?.name || 'server'}.`, 'ok'); }
    catch (e) { setCardStatus(card, String(e.message || e), 'err'); }
  });
  card.querySelector('.fetch').addEventListener('click', async () => {
    syncFromInputs(); await persist(); setCardStatus(card, 'Fetching tools…');
    try {
      const tools = await sendBg({ type: 'LIST_TOOLS', serverId: srv.id });
      TOOLS[srv.id] = tools;
      if (!srv.enabledTools?.length) srv.enabledTools = tools.map(t => t.name);
      renderTools(card, srv.id); setCardStatus(card, `Loaded ${tools.length} tools.`, 'ok');
    } catch (e) { setCardStatus(card, String(e.message || e), 'err'); }
  });
  card.querySelector('.select-all').addEventListener('click', () => { card.querySelectorAll('.tools-list input[type=checkbox]').forEach(cb => cb.checked = true); syncFromInputs(); });
  card.querySelector('.select-none').addEventListener('click', () => { card.querySelectorAll('.tools-list input[type=checkbox]').forEach(cb => cb.checked = false); syncFromInputs(); });

  return card;
}

function renderServers() {
  const host = $('servers'); host.innerHTML = '';
  for (const srv of SERVERS) { const card = createServerCard(srv); host.appendChild(card); renderTools(card, srv.id); }
}

// ---------- persistence ----------
async function persist() {
  await chrome.storage.local.set({
    configVersion: 2,
    servers: SERVERS,
    providers: PROVIDER_CFG,
    activeProvider: ACTIVE,
    systemPrompt: $('systemPrompt').value.trim() || DEFAULT_PROMPT,
    maxIterations: Math.min(Math.max(1, Number($('maxIterations').value) || 40), 200),
  });
}

async function loadAll() {
  const local = await chrome.storage.local.get(['servers', 'providers', 'activeProvider', 'systemPrompt', 'theme', 'configVersion', 'maxIterations']);
  let { servers, providers, activeProvider, systemPrompt, theme } = local;

  // Migrate legacy config the first time options runs after the upgrade.
  if (!providers || !servers?.length) {
    const sync = await chrome.storage.sync.get(['servers', 'deepseekKey', 'model', 'systemPrompt', 'mcpUrl', 'mcpKey', 'enabledTools']);
    providers = providers || emptyProviders();
    if (sync.deepseekKey) { providers.deepseek.apiKey = sync.deepseekKey; if (sync.model) providers.deepseek.model = sync.model; }
    if (!servers?.length) {
      servers = Array.isArray(sync.servers) && sync.servers.length ? sync.servers
        : (sync.mcpUrl && sync.mcpKey ? [{ id: 'srv_legacy', name: 'X-Cart', url: sync.mcpUrl, apiKey: sync.mcpKey, enabledTools: sync.enabledTools || [] }] : []);
    }
    systemPrompt = systemPrompt || sync.systemPrompt;
  }

  PROVIDER_CFG = { ...emptyProviders(), ...(providers || {}) };
  ACTIVE = activeProvider || 'deepseek';
  SERVERS = Array.isArray(servers) ? servers : [];
  $('systemPrompt').value = isDefaultPrompt(systemPrompt) ? DEFAULT_PROMPT : systemPrompt;
  $('maxIterations').value = Number(local.maxIterations) || 40;
  $('theme').value = theme || 'auto'; applyTheme(theme || 'auto');

  const keys = SERVERS.map(s => `toolsCache_${s.id}`);
  if (keys.length) { const cache = await chrome.storage.local.get(keys); for (const s of SERVERS) TOOLS[s.id] = cache[`toolsCache_${s.id}`] || []; }

  renderProviders(); renderServers();
}

$('addServer').addEventListener('click', () => {
  const srv = { id: newId(), name: '', url: '', apiKey: '', enabledTools: [] };
  SERVERS.push(srv);
  const card = createServerCard(srv); $('servers').appendChild(card); renderTools(card, srv.id);
});

$('save').addEventListener('click', async () => {
  try { await persist(); setGlobalStatus('Saved.', 'ok'); }
  catch (e) { setGlobalStatus(String(e.message || e), 'err'); }
});

loadAll();
