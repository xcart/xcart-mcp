import { test, expect, chromium } from '@playwright/test';
import path from 'node:path';
import os from 'node:os';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const EXT_DIR = path.resolve(__dirname, '..');

// Credentials come from the environment only — never commit real keys.
//   MCP_URL, MCP_KEY, DEEPSEEK_KEY
const MCP_URL = process.env.MCP_URL;
const MCP_KEY = process.env.MCP_KEY;
const DEEPSEEK_KEY = process.env.DEEPSEEK_KEY;

const haveCreds = !!(MCP_URL && MCP_KEY && DEEPSEEK_KEY);
test.skip(!haveCreds, 'Set MCP_URL, MCP_KEY and DEEPSEEK_KEY to run e2e tests.');

async function launchExt() {
  const userDir = fs.mkdtempSync(path.join(os.tmpdir(), 'xcart-mcp-ext-'));
  const ctx = await chromium.launchPersistentContext(userDir, {
    headless: false,
    ignoreHTTPSErrors: true,
    args: [
      `--disable-extensions-except=${EXT_DIR}`,
      `--load-extension=${EXT_DIR}`,
      '--no-first-run', '--no-default-browser-check',
    ],
  });
  let [sw] = ctx.serviceWorkers();
  if (!sw) sw = await ctx.waitForEvent('serviceworker');
  const extId = sw.url().split('/')[2];
  return { ctx, extId };
}

async function setDeepseekKey(page) {
  await page.fill('.provider-card[data-id="deepseek"] .key', DEEPSEEK_KEY);
}

async function configureServer(page, { name, url, key, selectOnly = null }) {
  await page.click('#addServer');
  const card = page.locator('.server-card').last();
  await card.locator('.name-input').fill(name);
  await card.locator('.url').fill(url);
  await card.locator('.key').fill(key);
  await page.click('#save');
  await card.locator('.test').click();
  await expect(card.locator('.server-status')).toContainText(/Connected/, { timeout: 15000 });
  await card.locator('.fetch').click();
  await expect(card.locator('.server-status')).toContainText(/Loaded \d+ tools/, { timeout: 30000 });
  if (selectOnly) {
    await card.locator('.select-none').click();
    for (const n of selectOnly) await card.locator(`.tools-list input[data-name="${n}"]`).check();
  }
  await page.click('#save');
}

test('OPTIONS: add MCP server, fetch tools, store in local', async () => {
  const { ctx, extId } = await launchExt();
  try {
    const page = await ctx.newPage();
    await page.goto(`chrome-extension://${extId}/options.html`);
    await setDeepseekKey(page);
    await configureServer(page, { name: 'Primary XC', url: MCP_URL, key: MCP_KEY });

    const stored = await page.evaluate(() => new Promise(r => chrome.storage.local.get(['servers', 'providers'], r)));
    expect(Array.isArray(stored.servers)).toBe(true);
    expect(stored.servers.length).toBe(1);
    expect(stored.servers[0].name).toBe('Primary XC');
    expect(stored.servers[0].enabledTools.length).toBeGreaterThan(0);
    expect(stored.providers.deepseek.apiKey).toBe(DEEPSEEK_KEY);
  } finally { await ctx.close(); }
});

test('OPTIONS: two servers, each gets its own tool list', async () => {
  const { ctx, extId } = await launchExt();
  try {
    const page = await ctx.newPage();
    await page.goto(`chrome-extension://${extId}/options.html`);
    await setDeepseekKey(page);
    await configureServer(page, { name: 'XC A', url: MCP_URL, key: MCP_KEY });
    await configureServer(page, { name: 'XC B', url: MCP_URL, key: MCP_KEY });

    expect(await page.locator('.server-card').count()).toBe(2);
    const stored = await page.evaluate(() => new Promise(r => chrome.storage.local.get(['servers'], r)));
    expect(stored.servers.length).toBe(2);
  } finally { await ctx.close(); }
});

test('PANEL: multi-chat — create, switch, history persists', async () => {
  const { ctx, extId } = await launchExt();
  try {
    const opts = await ctx.newPage();
    await opts.goto(`chrome-extension://${extId}/options.html`);
    await setDeepseekKey(opts);
    await configureServer(opts, { name: 'XC', url: MCP_URL, key: MCP_KEY, selectOnly: ['product_search'] });
    await opts.close();

    const panel = await ctx.newPage();
    await panel.goto(`chrome-extension://${extId}/panel.html`);
    await panel.waitForSelector('#input', { timeout: 10000 });

    await panel.evaluate(async () => {
      const d = await new Promise(r => chrome.storage.local.get(['chats', 'currentChatId'], r));
      const id = d.currentChatId;
      d.chats[id].title = 'Chat A';
      d.chats[id].messages = [{ role: 'user', content: 'hello A' }, { role: 'assistant', content: 'hi A' }];
      await new Promise(r => chrome.storage.local.set(d, r));
    });
    await panel.reload();
    await panel.waitForSelector('.chat-item.active');
    await expect(panel.locator('.chat-item.active .chat-title')).toHaveText('Chat A');
    await expect(panel.locator('#transcript .msg.user')).toContainText('hello A');

    await panel.click('#btn-new-chat');
    await panel.waitForFunction(() => document.querySelectorAll('.chat-item').length === 2);
    expect(await panel.locator('.chat-item').first().locator('.chat-title').textContent()).toBe('New chat');

    await panel.locator('.chat-item', { hasText: 'Chat A' }).click();
    await panel.waitForFunction(() => document.querySelector('.chat-item.active .chat-title').textContent === 'Chat A');
    await expect(panel.locator('#transcript .msg.ai')).toContainText('hi A');
  } finally { await ctx.close(); }
});

test('OVERRIDES: per-chat system prompt overrides options prompt', async () => {
  const { ctx, extId } = await launchExt();
  try {
    const opts = await ctx.newPage();
    await opts.goto(`chrome-extension://${extId}/options.html`);
    await setDeepseekKey(opts);
    await opts.fill('#systemPrompt', 'Always answer with only the word DEFAULT.');
    await configureServer(opts, { name: 'XC', url: MCP_URL, key: MCP_KEY, selectOnly: ['product_search'] });
    await opts.close();

    const panel = await ctx.newPage();
    await panel.goto(`chrome-extension://${extId}/panel.html`);
    await panel.waitForSelector('#input');

    let result = await panel.evaluate(() => new Promise(r => chrome.runtime.sendMessage({ type: 'CHAT', messages: [{ role: 'user', content: 'hi' }] }, r)));
    expect(result.ok).toBe(true);
    expect(result.data.reply.trim()).toMatch(/DEFAULT/i);

    result = await panel.evaluate(() => new Promise(r => chrome.runtime.sendMessage({
      type: 'CHAT', messages: [{ role: 'user', content: 'hi' }],
      overrides: { systemPrompt: 'Always answer with only the word CUSTOM.' },
    }, r)));
    expect(result.ok).toBe(true);
    expect(result.data.reply.trim()).toMatch(/CUSTOM/i);
  } finally { await ctx.close(); }
});

test('PARALLEL: two chats run concurrently via CHAT_START / CHAT_EVENT done', async () => {
  const { ctx, extId } = await launchExt();
  try {
    const opts = await ctx.newPage();
    await opts.goto(`chrome-extension://${extId}/options.html`);
    await setDeepseekKey(opts);
    await configureServer(opts, { name: 'XC', url: MCP_URL, key: MCP_KEY, selectOnly: ['product_search'] });
    await opts.close();

    const panel = await ctx.newPage();
    await panel.goto(`chrome-extension://${extId}/panel.html`);
    await panel.waitForSelector('#input');

    const ids = await panel.evaluate(async () => {
      const mkId = () => 'c_' + Math.random().toString(16).slice(2, 10);
      const d = await new Promise(r => chrome.storage.local.get(['chats', 'chatOrder'], r));
      const chats = d.chats || {}; const order = d.chatOrder || [];
      const a = mkId(), b = mkId();
      chats[a] = { id: a, title: 'A', createdAt: Date.now(), updatedAt: Date.now(), messages: [{ role: 'user', content: 'Say exactly the word RED and nothing else.' }], status: 'idle' };
      chats[b] = { id: b, title: 'B', createdAt: Date.now(), updatedAt: Date.now(), messages: [{ role: 'user', content: 'Say exactly the word BLUE and nothing else.' }], status: 'idle' };
      order.unshift(a, b);
      await new Promise(r => chrome.storage.local.set({ chats, chatOrder: order }, r));

      const done = new Promise((resolve) => {
        let gotA = false, gotB = false;
        chrome.runtime.onMessage.addListener(function h(msg) {
          if (msg?.type !== 'CHAT_EVENT' || msg.ev?.kind !== 'done') return;
          if (msg.chatId === a) gotA = true;
          if (msg.chatId === b) gotB = true;
          if (gotA && gotB) { chrome.runtime.onMessage.removeListener(h); resolve(); }
        });
      });
      await Promise.all([
        new Promise(r => chrome.runtime.sendMessage({ type: 'CHAT_START', chatId: a }, r)),
        new Promise(r => chrome.runtime.sendMessage({ type: 'CHAT_START', chatId: b }, r)),
      ]);
      await done;
      return { a, b };
    });

    const { chats } = await panel.evaluate(() => new Promise(r => chrome.storage.local.get(['chats'], r)));
    const aReply = chats[ids.a].messages.filter(m => m.role === 'assistant').map(m => m.content).join(' ');
    const bReply = chats[ids.b].messages.filter(m => m.role === 'assistant').map(m => m.content).join(' ');
    expect(aReply).toMatch(/RED/i);
    expect(bReply).toMatch(/BLUE/i);
    expect(chats[ids.a].status).toBe('idle');
    expect(chats[ids.b].status).toBe('idle');
  } finally { await ctx.close(); }
});

test('CHAT: provider calls MCP tool, trace shows the call', async () => {
  const { ctx, extId } = await launchExt();
  try {
    const opts = await ctx.newPage();
    await opts.goto(`chrome-extension://${extId}/options.html`);
    await setDeepseekKey(opts);
    await configureServer(opts, { name: 'XC', url: MCP_URL, key: MCP_KEY, selectOnly: ['product_search'] });
    await opts.close();

    const panel = await ctx.newPage();
    await panel.goto(`chrome-extension://${extId}/panel.html`);
    await panel.waitForSelector('#input');

    const result = await panel.evaluate(() => new Promise(r => chrome.runtime.sendMessage({
      type: 'CHAT', messages: [{ role: 'user', content: 'Search for 2 products. Just list their names briefly.' }],
    }, r)));
    expect(result.ok).toBe(true);
    const calls = result.data.trace.filter(t => t.type === 'call');
    expect(calls.length).toBeGreaterThan(0);
    expect(calls[0].name).toBe('product_search');
    expect(calls[0].server).toMatch(/^srv_/);
  } finally { await ctx.close(); }
});
