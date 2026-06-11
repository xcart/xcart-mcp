// LLM provider layer. Three providers behind one streaming interface.
//
//   chat({ provider, apiKey, model, system, messages, tools, signal, onText })
//     → Promise<{ content, tool_calls }>
//
// `messages` is a provider-neutral conversation (system passed separately):
//   { role: 'user',      content }
//   { role: 'assistant', content, tool_calls?: [{ id, name, arguments }] }
//   { role: 'tool',      tool_call_id, name, content }
//
// `tools` is neutral: [{ name, description, parameters /* JSON Schema */ }]
// `onText(delta)` is called with streamed assistant-text chunks as they arrive.
// Returned tool_calls always have `arguments` as a parsed object.

export const PROVIDERS = {
  deepseek: {
    label: 'DeepSeek',
    kind: 'openai',
    endpoint: 'https://api.deepseek.com/v1/chat/completions',
    defaultModel: 'deepseek-chat',
    keyPlaceholder: 'sk-…',
    keyHelp: 'platform.deepseek.com',
  },
  anthropic: {
    label: 'Claude (Anthropic)',
    kind: 'anthropic',
    endpoint: 'https://api.anthropic.com/v1/messages',
    defaultModel: 'claude-opus-4-8',
    maxTokens: 4096,
    keyPlaceholder: 'sk-ant-…',
    keyHelp: 'console.anthropic.com',
  },
  openai: {
    label: 'OpenAI',
    kind: 'openai',
    endpoint: 'https://api.openai.com/v1/chat/completions',
    defaultModel: 'gpt-4o-mini',
    keyPlaceholder: 'sk-…',
    keyHelp: 'platform.openai.com',
  },
};

export function providerMeta(id) {
  return PROVIDERS[id] || PROVIDERS.deepseek;
}

export async function chat(opts) {
  const p = providerMeta(opts.provider);
  return p.kind === 'anthropic' ? anthropicChat(p, opts) : openaiChat(p, opts);
}

// ---- shared SSE line reader ----------------------------------------------

async function* sseEvents(res) {
  const reader = res.body.getReader();
  const decoder = new TextDecoder();
  let buf = '';
  while (true) {
    const { value, done } = await reader.read();
    if (done) break;
    buf += decoder.decode(value, { stream: true });
    let nl;
    while ((nl = buf.indexOf('\n')) >= 0) {
      const line = buf.slice(0, nl).trim();
      buf = buf.slice(nl + 1);
      if (line.startsWith('data:')) yield line.slice(5).trim();
    }
  }
  const tail = buf.trim();
  if (tail.startsWith('data:')) yield tail.slice(5).trim();
}

async function readError(res) {
  let body = '';
  try { body = await res.text(); } catch {}
  try {
    const j = JSON.parse(body);
    return j?.error?.message || j?.message || body || `HTTP ${res.status}`;
  } catch {
    return body || `HTTP ${res.status}`;
  }
}

// ---- OpenAI-compatible (DeepSeek, OpenAI) --------------------------------

function toOpenAIMessages(system, messages) {
  const out = [];
  if (system) out.push({ role: 'system', content: system });
  for (const m of messages) {
    if (m.role === 'tool') {
      out.push({ role: 'tool', tool_call_id: m.tool_call_id, content: m.content });
    } else if (m.role === 'assistant') {
      const msg = { role: 'assistant', content: m.content || '' };
      if (m.tool_calls?.length) {
        msg.tool_calls = m.tool_calls.map(c => ({
          id: c.id,
          type: 'function',
          function: { name: c.name, arguments: JSON.stringify(c.arguments || {}) },
        }));
      }
      out.push(msg);
    } else {
      out.push({ role: 'user', content: m.content || '' });
    }
  }
  return out;
}

async function openaiChat(p, { apiKey, model, system, messages, tools, signal, onText }) {
  const body = {
    model: model || p.defaultModel,
    messages: toOpenAIMessages(system, messages),
    temperature: 0.2,
    stream: true,
  };
  if (tools?.length) {
    body.tools = tools.map(t => ({
      type: 'function',
      function: { name: t.name, description: t.description || '', parameters: t.parameters || { type: 'object', properties: {} } },
    }));
    body.tool_choice = 'auto';
  }

  const res = await fetch(p.endpoint, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${apiKey}` },
    body: JSON.stringify(body),
    signal,
  });
  if (!res.ok || !res.body) throw new Error(`${p.label}: ${await readError(res)}`);

  let content = '';
  const calls = []; // index → { id, name, args(str) }
  for await (const data of sseEvents(res)) {
    if (data === '[DONE]') break;
    let json;
    try { json = JSON.parse(data); } catch { continue; }
    const delta = json.choices?.[0]?.delta;
    if (!delta) continue;
    if (delta.content) { content += delta.content; onText?.(delta.content); }
    for (const tc of delta.tool_calls || []) {
      const i = tc.index ?? 0;
      const slot = (calls[i] ||= { id: tc.id || `call_${i}`, name: '', args: '' });
      if (tc.id) slot.id = tc.id;
      if (tc.function?.name) slot.name = tc.function.name;
      if (tc.function?.arguments) slot.args += tc.function.arguments;
    }
  }

  const tool_calls = calls.filter(Boolean).map(c => ({
    id: c.id, name: c.name, arguments: safeParse(c.args),
  }));
  return { content, tool_calls };
}

// ---- Anthropic Messages API ----------------------------------------------

// Walk the neutral conversation into Anthropic messages, coalescing adjacent
// same-role turns (tool results are delivered as user-role tool_result blocks).
function toAnthropicMessages(messages) {
  const out = [];
  const push = (role, block) => {
    const last = out[out.length - 1];
    if (last && last.role === role) last.content.push(block);
    else out.push({ role, content: [block] });
  };
  for (const m of messages) {
    if (m.role === 'tool') {
      push('user', { type: 'tool_result', tool_use_id: m.tool_call_id, content: m.content || '' });
    } else if (m.role === 'assistant') {
      if (m.content) push('assistant', { type: 'text', text: m.content });
      for (const c of m.tool_calls || []) {
        push('assistant', { type: 'tool_use', id: c.id, name: c.name, input: c.arguments || {} });
      }
    } else {
      push('user', { type: 'text', text: m.content || '' });
    }
  }
  return out;
}

async function anthropicChat(p, { apiKey, model, system, messages, tools, signal, onText }) {
  const body = {
    model: model || p.defaultModel,
    max_tokens: p.maxTokens || 4096,
    messages: toAnthropicMessages(messages),
    stream: true,
  };
  if (system) body.system = system;
  if (tools?.length) {
    body.tools = tools.map(t => ({
      name: t.name,
      description: t.description || '',
      input_schema: t.parameters || { type: 'object', properties: {} },
    }));
  }

  const res = await fetch(p.endpoint, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'x-api-key': apiKey,
      'anthropic-version': '2023-06-01',
      'anthropic-dangerous-direct-browser-access': 'true',
    },
    body: JSON.stringify(body),
    signal,
  });
  if (!res.ok || !res.body) throw new Error(`${p.label}: ${await readError(res)}`);

  let content = '';
  const blocks = {}; // index → { type, id?, name?, json? }
  for await (const data of sseEvents(res)) {
    let ev;
    try { ev = JSON.parse(data); } catch { continue; }
    switch (ev.type) {
      case 'content_block_start':
        blocks[ev.index] = ev.content_block?.type === 'tool_use'
          ? { type: 'tool_use', id: ev.content_block.id, name: ev.content_block.name, json: '' }
          : { type: ev.content_block?.type || 'text' };
        break;
      case 'content_block_delta':
        if (ev.delta?.type === 'text_delta') { content += ev.delta.text; onText?.(ev.delta.text); }
        else if (ev.delta?.type === 'input_json_delta') { blocks[ev.index].json += ev.delta.partial_json || ''; }
        break;
      case 'error':
        throw new Error(`${p.label}: ${ev.error?.message || 'stream error'}`);
    }
  }

  const tool_calls = Object.values(blocks)
    .filter(b => b.type === 'tool_use')
    .map(b => ({ id: b.id, name: b.name, arguments: safeParse(b.json) }));
  return { content, tool_calls };
}

function safeParse(s) {
  if (!s) return {};
  try { return JSON.parse(s); } catch { return {}; }
}
