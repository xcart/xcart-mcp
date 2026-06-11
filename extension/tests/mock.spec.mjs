// Fully-mocked e2e: no real MCP server or LLM key needed. Stubs the MCP
// endpoint and the LLM provider (OpenAI-compatible or Anthropic Messages), then
// drives the real extension UI through a range of scenarios.

import { test, expect, chromium } from '@playwright/test';
import path from 'node:path';
import os from 'node:os';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const EXT_DIR = path.resolve(__dirname, '..');

const MCP_URL = 'https://mock.local/mcp';
const SERVER_ID = 'srv_test';
const TOOL = `${SERVER_ID}__product_search`;

// ---- SSE builders --------------------------------------------------------

const done = 'data: [DONE]\n\n';
const oa = (...frames) => frames.map(f => `data: ${JSON.stringify(f)}\n\n`).join('') + done;
const oaText = (t) => ({ choices: [{ delta: { content: t } }] });
const oaTool = (name, args, id = 'call_1') => ({ choices: [{ delta: { tool_calls: [{ index: 0, id, type: 'function', function: { name, arguments: args } }] } }] });

const an = (...frames) => frames.map(f => `data: ${JSON.stringify(f)}\n\n`).join('');
const anText = (t) => [
  { type: 'content_block_start', index: 0, content_block: { type: 'text' } },
  { type: 'content_block_delta', index: 0, delta: { type: 'text_delta', text: t } },
  { type: 'content_block_stop', index: 0 },
];
const anTool = (name) => [
  { type: 'content_block_start', index: 1, content_block: { type: 'tool_use', id: 'toolu_1', name } },
  { type: 'content_block_delta', index: 1, delta: { type: 'input_json_delta', partial_json: '{"query":""}' } },
  { type: 'content_block_stop', index: 1 },
];

// ---- launch + routing ----------------------------------------------------

async function launchExt({ llm, onMcpCall } = {}) {
  const userDir = fs.mkdtempSync(path.join(os.tmpdir(), 'xcart-mcp-mock-'));
  const ctx = await chromium.launchPersistentContext(userDir, {
    headless: false,
    args: [`--disable-extensions-except=${EXT_DIR}`, `--load-extension=${EXT_DIR}`, '--no-first-run', '--no-default-browser-check'],
  });
  const hits = [];

  await ctx.route(MCP_URL, async (route) => {
    let body = {}; try { body = JSON.parse(route.request().postData() || '{}'); } catch {}
    const reply = (extra) => route.fulfill({ status: 200, contentType: 'application/json', headers: { 'mcp-session-id': 'mock' }, body: JSON.stringify({ jsonrpc: '2.0', id: body.id, ...extra }) });
    if (body.method === 'initialize') return reply({ result: { protocolVersion: '2024-11-05', capabilities: {}, serverInfo: { name: 'Mock XC' } } });
    if (body.method === 'tools/list') return reply({ result: { tools: [{ name: 'product_search', description: 'Search products', inputSchema: { type: 'object', properties: { query: { type: 'string' } } } }] } });
    if (body.method === 'tools/call') return reply(onMcpCall ? onMcpCall() : { result: { content: [{ type: 'text', text: JSON.stringify([{ name: 'Widget' }, { name: 'Gadget' }]) }] } });
    return route.fulfill({ status: 200, contentType: 'application/json', body: '{}' });
  });

  const llmHandler = llm || defaultLLM;
  await ctx.route(/api\.(deepseek|openai|anthropic)\.com/, async (route) => {
    const req = route.request();
    hits.push(req.url());
    if (!llmHandler) return route.abort();
    const { body, contentType = 'text/event-stream' } = await llmHandler(req) || {};
    if (body == null) return; // intentionally hang
    await route.fulfill({ status: 200, contentType, body });
  });

  let [sw] = ctx.serviceWorkers();
  if (!sw) sw = await ctx.waitForEvent('serviceworker');
  const extId = sw.url().split('/')[2];
  return { ctx, extId, hits };
}

// Default LLM: turn 1 = intro + tool call, turn 2 (sees tool result) = final text.
function defaultLLM(req) {
  const post = req.postData() || '';
  const second = post.includes('"role":"tool"');
  return { body: second ? oa(oaText('Нашёл такие товары: Widget и Gadget.')) : oa(oaText('Давайте посмотрим, какие товары есть в магазине.'), oaTool(TOOL, '{"query":""}')) };
}

const toolMsgCount = (req) => ((req.postData() || '').match(/"role":"tool"/g) || []).length;

async function seedConfig(page, { activeProvider = 'deepseek', chat, maxIterations } = {}) {
  await page.evaluate(({ MCP_URL, SERVER_ID, activeProvider, chat, maxIterations }) => new Promise(r => {
    const data = {
      configVersion: 2,
      activeProvider,
      providers: { deepseek: { apiKey: 'dk', model: 'deepseek-chat' }, anthropic: { apiKey: 'ak', model: 'claude-opus-4-8' }, openai: { apiKey: '', model: 'gpt-4o-mini' } },
      servers: [{ id: SERVER_ID, name: 'Mock XC', url: MCP_URL, apiKey: 'mcp_test', enabledTools: ['product_search'] }],
      [`toolsCache_${SERVER_ID}`]: [{ name: 'product_search', description: 'Search products', inputSchema: { type: 'object', properties: { query: { type: 'string' } } } }],
      systemPrompt: 'You are a test assistant.',
      theme: 'dark',
    };
    if (maxIterations) data.maxIterations = maxIterations;
    if (chat) { data.chats = { [chat.id]: chat }; data.chatOrder = [chat.id]; data.currentChatId = chat.id; }
    chrome.storage.local.set(data, r);
  }), { MCP_URL, SERVER_ID, activeProvider, chat, maxIterations });
}

async function openPanel(ctx, extId, seedOpts) {
  const panel = await ctx.newPage();
  await panel.goto(`chrome-extension://${extId}/panel.html`);
  await panel.waitForSelector('#input');
  await seedConfig(panel, seedOpts);
  await panel.reload();
  await panel.waitForSelector('#input');
  return panel;
}

async function ask(panel, text) {
  await panel.fill('#input', text);
  await panel.press('#input', 'Enter');
}

// ========================================================================

test('final answer renders after a tool call, and persists across reload', async () => {
  const { ctx, extId } = await launchExt();
  try {
    const panel = await openPanel(ctx, extId);
    await ask(panel, 'Какие товары видишь?');

    await expect(panel.locator('.tool-card .tc-name')).toHaveText('product_search', { timeout: 10000 });
    await expect(panel.locator('.tool-card.ok')).toBeVisible({ timeout: 10000 });
    await expect(panel.locator('#transcript .msg.ai').last()).toContainText('Widget и Gadget', { timeout: 10000 });
    await expect(panel.locator('#status')).toHaveText('Ready', { timeout: 10000 });
    expect(await panel.locator('#transcript .msg.ai').count()).toBe(2);

    await panel.screenshot({ path: 'test-results/mock-final.png' });

    await panel.reload();
    await panel.waitForSelector('#transcript .msg.ai');
    await expect(panel.locator('#transcript .msg.ai').last()).toContainText('Widget и Gadget');
    expect(await panel.locator('.tool-card').count()).toBe(1);
  } finally { await ctx.close(); }
});

test('multi-step: two sequential tool calls, then a final answer', async () => {
  const llm = (req) => {
    const n = toolMsgCount(req);
    if (n === 0) return { body: oa(oaText('Step 1.'), oaTool(TOOL, '{"query":"a"}')) };
    if (n === 1) return { body: oa(oaText('Step 2.'), oaTool(TOOL, '{"query":"b"}', 'call_2')) };
    return { body: oa(oaText('Done after 2 lookups.')) };
  };
  const { ctx, extId } = await launchExt({ llm });
  try {
    const panel = await openPanel(ctx, extId);
    await ask(panel, 'search twice');
    await expect(panel.locator('#transcript .msg.ai').last()).toContainText('Done after 2 lookups', { timeout: 10000 });
    expect(await panel.locator('.tool-card').count()).toBe(2);
    expect(await panel.locator('.tool-card.ok').count()).toBe(2);
  } finally { await ctx.close(); }
});

test('tool error renders a red error card and the agent still replies', async () => {
  const onMcpCall = () => ({ error: { message: 'Inventory service unavailable' } });
  const { ctx, extId } = await launchExt({ onMcpCall });
  try {
    const panel = await openPanel(ctx, extId);
    await ask(panel, 'find stuff');
    await expect(panel.locator('.tool-card.err')).toBeVisible({ timeout: 10000 });
    await panel.locator('.tool-card.err').click(); // expand
    await expect(panel.locator('.tool-card.err .tc-result')).toContainText('Inventory service unavailable');
    await expect(panel.locator('#transcript .msg.ai').last()).toContainText('Widget и Gadget', { timeout: 10000 });
  } finally { await ctx.close(); }
});

test('markdown in the reply renders as HTML and is XSS-safe', async () => {
  const md = 'Here: **bold**, `code`, a [link](https://example.com) and <script>alert(1)</script>.';
  const llm = () => ({ body: oa(oaText(md)) });
  const { ctx, extId } = await launchExt({ llm });
  try {
    const panel = await openPanel(ctx, extId);
    await ask(panel, 'format please');
    const ai = panel.locator('#transcript .msg.ai .bubble').last();
    await expect(ai.locator('strong')).toHaveText('bold', { timeout: 10000 });
    await expect(ai.locator('code')).toHaveText('code');
    await expect(ai.locator('a')).toHaveAttribute('href', 'https://example.com');
    expect(await ai.locator('script').count()).toBe(0); // not executed
    expect(await ai.innerHTML()).toContain('&lt;script&gt;'); // escaped, shown as text
  } finally { await ctx.close(); }
});

test('markdown table renders as a real table, not raw pipes', async () => {
  const md = 'Корневые категории SEMA:\n\n| # | Категория |\n|---:|-----------|\n| 1 | Accessories |\n| 2 | Air and Fuel Delivery |\n| 5 | Brake |\n\nи ещё ниже.';
  const llm = () => ({ body: oa(oaText(md)) });
  const { ctx, extId } = await launchExt({ llm });
  try {
    const panel = await openPanel(ctx, extId);
    await ask(panel, 'покажи таблицу');
    const ai = panel.locator('#transcript .msg.ai .bubble').last();
    await expect(ai.locator('table.md-table')).toBeVisible({ timeout: 10000 });
    await expect(ai.locator('table.md-table thead th')).toHaveCount(2);
    await expect(ai.locator('table.md-table tbody tr')).toHaveCount(3);
    await expect(ai.locator('table.md-table tbody tr').first()).toContainText('Accessories');
    expect(await ai.innerText()).not.toContain('|---'); // delimiter row not shown literally
    expect(await ai.innerText()).not.toContain('| 1 |');
  } finally { await ctx.close(); }
});

test('Claude (Anthropic) provider: streaming tool_use end to end', async () => {
  const llm = (req) => {
    const second = (req.postData() || '').includes('tool_result');
    const body = second
      ? an(...anText('Final via Claude: Widget, Gadget.'), { type: 'message_stop' })
      : an(...anText('Looking via Claude…'), ...anTool(TOOL), { type: 'message_stop' });
    return { body };
  };
  const { ctx, extId, hits } = await launchExt({ llm });
  try {
    const panel = await openPanel(ctx, extId, { activeProvider: 'anthropic' });
    await ask(panel, 'через claude');
    await expect(panel.locator('.tool-card.ok')).toBeVisible({ timeout: 10000 });
    await expect(panel.locator('#transcript .msg.ai').last()).toContainText('Final via Claude', { timeout: 10000 });
    expect(hits.some(u => u.includes('anthropic.com'))).toBe(true);
    expect(hits.some(u => u.includes('deepseek.com'))).toBe(false);
  } finally { await ctx.close(); }
});

test('per-chat override switches provider to Anthropic while default stays DeepSeek', async () => {
  const llm = (req) => {
    if (req.url().includes('anthropic')) {
      const second = (req.postData() || '').includes('tool_result');
      return { body: second ? an(...anText('Claude override final.'), { type: 'message_stop' }) : an(...anText('Claude override go.'), ...anTool(TOOL), { type: 'message_stop' }) };
    }
    return { body: oa(oaText('deepseek should not be used')) };
  };
  const chat = { id: 'c_ov', title: 'override', createdAt: 1, updatedAt: 1, status: 'idle', messages: [{ role: 'user', content: 'go' }], overrides: { provider: 'anthropic' } };
  const { ctx, extId, hits } = await launchExt({ llm });
  try {
    const panel = await openPanel(ctx, extId, { activeProvider: 'deepseek', chat });
    await panel.evaluate(() => new Promise(r => chrome.runtime.sendMessage({ type: 'CHAT_START', chatId: 'c_ov' }, r)));
    await expect(panel.locator('#transcript .msg.ai').last()).toContainText('Claude override final', { timeout: 10000 });
    expect(hits.every(u => u.includes('anthropic.com'))).toBe(true);
  } finally { await ctx.close(); }
});

test('two chats run in parallel and both finish with the right answer', async () => {
  const llm = (req) => {
    const post = req.postData() || '';
    if (post.includes('"role":"tool"')) return { body: oa(oaText(post.includes('RED') ? 'answer RED' : 'answer BLUE')) };
    const wantRed = post.includes('RED');
    return { body: oa(oaText('checking'), oaTool(TOOL, '{"query":""}', wantRed ? 'r' : 'b')) };
  };
  const { ctx, extId } = await launchExt({ llm });
  try {
    const panel = await openPanel(ctx, extId);
    const ids = await panel.evaluate(async () => {
      const mk = () => 'c_' + Math.random().toString(16).slice(2, 8);
      const a = mk(), b = mk();
      const d = await new Promise(r => chrome.storage.local.get(['chats', 'chatOrder'], r));
      const chats = d.chats || {}, order = d.chatOrder || [];
      chats[a] = { id: a, title: 'A', createdAt: 1, updatedAt: 1, status: 'idle', messages: [{ role: 'user', content: 'say RED' }] };
      chats[b] = { id: b, title: 'B', createdAt: 1, updatedAt: 1, status: 'idle', messages: [{ role: 'user', content: 'say BLUE' }] };
      order.unshift(a, b);
      await new Promise(r => chrome.storage.local.set({ chats, chatOrder: order }, r));
      const wait = new Promise(res => { let ga, gb; chrome.runtime.onMessage.addListener(function h(m) { if (m?.type === 'CHAT_EVENT' && m.ev?.kind === 'done') { if (m.chatId === a) ga = 1; if (m.chatId === b) gb = 1; if (ga && gb) { chrome.runtime.onMessage.removeListener(h); res(); } } }); });
      await Promise.all([new Promise(r => chrome.runtime.sendMessage({ type: 'CHAT_START', chatId: a }, r)), new Promise(r => chrome.runtime.sendMessage({ type: 'CHAT_START', chatId: b }, r))]);
      await wait;
      return { a, b };
    });
    const { chats } = await panel.evaluate(() => new Promise(r => chrome.storage.local.get(['chats'], r)));
    const reply = (id) => chats[id].messages.filter(m => m.role === 'assistant').map(m => m.content).join(' ');
    expect(reply(ids.a)).toContain('RED');
    expect(reply(ids.b)).toContain('BLUE');
    expect(chats[ids.a].status).toBe('idle');
    expect(chats[ids.b].status).toBe('idle');
  } finally { await ctx.close(); }
});

test('missing API key surfaces an error in the status bar', async () => {
  const { ctx, extId } = await launchExt();
  try {
    const panel = await ctx.newPage();
    await panel.goto(`chrome-extension://${extId}/panel.html`);
    await panel.waitForSelector('#input');
    // Config with an empty key for the active provider.
    await panel.evaluate(({ MCP_URL, SERVER_ID }) => new Promise(r => chrome.storage.local.set({
      configVersion: 2, activeProvider: 'deepseek',
      providers: { deepseek: { apiKey: '', model: 'deepseek-chat' } },
      servers: [{ id: SERVER_ID, name: 'Mock XC', url: MCP_URL, apiKey: 'mcp_test', enabledTools: ['product_search'] }],
      systemPrompt: 'x',
    }, r)), { MCP_URL, SERVER_ID });
    await panel.reload();
    await panel.waitForSelector('#input');
    await ask(panel, 'hi');
    await expect(panel.locator('#status')).toHaveText(/API key/i, { timeout: 10000 });
    await expect(panel.locator('#btn-send')).toBeVisible();
  } finally { await ctx.close(); }
});

test('configurable iteration limit stops gracefully with a visible note', async () => {
  // Always return a tool call → background caps at the configured limit and emits the note.
  const llm = () => ({ body: oa(oaText('looping'), oaTool(TOOL, '{"query":""}')) });
  const { ctx, extId } = await launchExt({ llm });
  try {
    const panel = await openPanel(ctx, extId, { maxIterations: 3 });
    await ask(panel, 'loop forever');
    await expect(panel.locator('#transcript .msg.ai').last()).toContainText('Stopped after 3 tool steps', { timeout: 15000 });
    await expect(panel.locator('#status')).toHaveText('Ready', { timeout: 5000 });
    expect(await panel.locator('.tool-card').count()).toBe(3);
  } finally { await ctx.close(); }
});

test('Stop button cancels a running chat', async () => {
  const { ctx, extId } = await launchExt({ llm: () => ({ body: null }) }); // hang forever
  try {
    const panel = await openPanel(ctx, extId);
    await ask(panel, 'hang please');
    await expect(panel.locator('#btn-stop')).toBeVisible({ timeout: 5000 });
    await panel.click('#btn-stop');
    await expect(panel.locator('#btn-send')).toBeVisible({ timeout: 10000 });
    await expect(panel.locator('.chat-item.active .running-dot')).toHaveCount(0);
  } finally { await ctx.close(); }
});

test('theme toggle cycles and persists', async () => {
  const { ctx, extId } = await launchExt();
  try {
    const panel = await openPanel(ctx, extId); // seeded theme: dark
    await expect(panel.locator('html')).toHaveAttribute('data-theme', 'dark');
    await panel.click('#btn-theme'); // dark → auto
    const stored = await panel.evaluate(() => new Promise(r => chrome.storage.local.get(['theme'], r)));
    expect(stored.theme).toBe('auto');
  } finally { await ctx.close(); }
});

test('DeepSeek wire format: role-first delta, split tool-call args, reasoning_content ignored', async () => {
  // Reproduce DeepSeek's real streaming shape: a role-only first chunk, an
  // interleaved reasoning_content chunk (must NOT leak into the answer), tool-call
  // arguments dribbled across multiple chunks, and a finish_reason terminator.
  const c = (delta, finish = null) => ({ choices: [{ index: 0, delta, finish_reason: finish }] });
  const llm = (req) => {
    if ((req.postData() || '').includes('"role":"tool"')) {
      return { body: oa(c({ role: 'assistant', content: '' }), c({ content: 'Готово: Widget, Gadget.' }, 'stop')) };
    }
    return {
      body: oa(
        c({ role: 'assistant', content: '' }),
        c({ reasoning_content: 'Хм, дай-ка я подумаю про товары…' }), // DeepSeek thinking — must be ignored
        c({ content: 'Ищу ' }),
        c({ content: 'товары.' }),
        c({ tool_calls: [{ index: 0, id: 'call_abc', type: 'function', function: { name: TOOL, arguments: '' } }] }),
        c({ tool_calls: [{ index: 0, function: { arguments: '{"qu' } }] }),
        c({ tool_calls: [{ index: 0, function: { arguments: 'ery":"shoes"}' } }] }),
        c({}, 'tool_calls'),
      ),
    };
  };
  const { ctx, extId } = await launchExt({ llm });
  try {
    const panel = await openPanel(ctx, extId);
    await ask(panel, 'найди обувь');

    await expect(panel.locator('.tool-card .tc-name')).toHaveText('product_search', { timeout: 10000 });
    await panel.locator('.tool-card').first().click(); // expand args
    // The arguments must be reassembled from the dribbled chunks.
    await expect(panel.locator('.tool-card .tc-args')).toContainText('"query": "shoes"');
    await expect(panel.locator('#transcript .msg.ai').last()).toContainText('Готово: Widget, Gadget', { timeout: 10000 });
    // reasoning_content must not appear anywhere in the conversation.
    await expect(panel.locator('#transcript')).not.toContainText('подумаю');
    // intro text streamed from content chunks is preserved
    await expect(panel.locator('#transcript')).toContainText('Ищу товары.');
  } finally { await ctx.close(); }
});

test('new chat: title is taken from the first message', async () => {
  const { ctx, extId } = await launchExt();
  try {
    const panel = await openPanel(ctx, extId);
    await ask(panel, 'Сколько заказов сегодня?');
    await expect(panel.locator('.chat-item.active .chat-title')).toContainText('Сколько заказов', { timeout: 10000 });
  } finally { await ctx.close(); }
});
