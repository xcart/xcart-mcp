// LIVE DeepSeek integration: real DeepSeek API (key from env), mocked MCP server.
// Proves providers.js handles DeepSeek's actual streaming + tool-call wire format
// end to end. Skips unless DEEPSEEK_KEY is set. No key is ever written to disk.

import { test, expect, chromium } from '@playwright/test';
import path from 'node:path';
import os from 'node:os';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const EXT_DIR = path.resolve(__dirname, '..');

const KEY = process.env.DEEPSEEK_KEY;
test.skip(!KEY, 'Set DEEPSEEK_KEY to run the live DeepSeek test.');
test.setTimeout(90_000);

const MCP_URL = 'https://mock.local/mcp';
const SERVER_ID = 'srv_test';

test('LIVE DeepSeek calls the MCP tool and answers about the products', async () => {
  const userDir = fs.mkdtempSync(path.join(os.tmpdir(), 'xcart-live-'));
  const ctx = await chromium.launchPersistentContext(userDir, {
    headless: false,
    args: [`--disable-extensions-except=${EXT_DIR}`, `--load-extension=${EXT_DIR}`, '--no-first-run', '--no-default-browser-check'],
  });
  try {
    // Mock ONLY the MCP endpoint — api.deepseek.com goes to the real network.
    let toolCalled = false;
    await ctx.route(MCP_URL, async (route) => {
      let body = {}; try { body = JSON.parse(route.request().postData() || '{}'); } catch {}
      const reply = (result) => route.fulfill({ status: 200, contentType: 'application/json', headers: { 'mcp-session-id': 'mock' }, body: JSON.stringify({ jsonrpc: '2.0', id: body.id, result }) });
      if (body.method === 'initialize') return reply({ protocolVersion: '2024-11-05', capabilities: {}, serverInfo: { name: 'Mock XC' } });
      if (body.method === 'tools/list') return reply({ tools: [{ name: 'product_search', description: 'Search the store catalog for products by keyword.', inputSchema: { type: 'object', properties: { query: { type: 'string', description: 'search keyword' } }, required: ['query'] } }] });
      if (body.method === 'tools/call') { toolCalled = true; return reply({ content: [{ type: 'text', text: JSON.stringify([{ name: 'Acme Widget', sku: 'AW-1' }, { name: 'Super Gadget', sku: 'SG-2' }]) }] }); }
      return route.fulfill({ status: 200, contentType: 'application/json', body: '{}' });
    });

    let [sw] = ctx.serviceWorkers();
    if (!sw) sw = await ctx.waitForEvent('serviceworker');
    const extId = sw.url().split('/')[2];

    const panel = await ctx.newPage();
    await panel.goto(`chrome-extension://${extId}/panel.html`);
    await panel.waitForSelector('#input');
    await panel.evaluate(({ MCP_URL, SERVER_ID, KEY }) => new Promise(r => chrome.storage.local.set({
      configVersion: 2, activeProvider: 'deepseek',
      providers: { deepseek: { apiKey: KEY, model: 'deepseek-chat' } },
      servers: [{ id: SERVER_ID, name: 'Mock XC', url: MCP_URL, apiKey: 'mcp_test', enabledTools: ['product_search'] }],
      [`toolsCache_${SERVER_ID}`]: [{ name: 'product_search', description: 'Search the store catalog for products by keyword.', inputSchema: { type: 'object', properties: { query: { type: 'string' } }, required: ['query'] } }],
      systemPrompt: 'You manage a store. To answer questions about products you MUST call the product_search tool. Then answer concisely listing the product names.',
      theme: 'dark',
    }, r)), { MCP_URL, SERVER_ID, KEY });
    await panel.reload();
    await panel.waitForSelector('#input');

    await panel.fill('#input', 'What products are in the store? List their names.');
    await panel.press('#input', 'Enter');

    // Real DeepSeek should call the tool, then answer mentioning the mocked products.
    await expect(panel.locator('.tool-card .tc-name')).toHaveText('product_search', { timeout: 60_000 });
    await expect(panel.locator('.tool-card.ok')).toBeVisible({ timeout: 60_000 });
    await expect(panel.locator('#transcript .msg.ai').last()).toContainText(/Widget|Gadget/i, { timeout: 60_000 });
    await expect(panel.locator('#status')).toHaveText('Ready', { timeout: 60_000 });
    expect(toolCalled).toBe(true);

    await panel.screenshot({ path: 'test-results/live-deepseek.png' });
  } finally {
    await ctx.close();
  }
});
