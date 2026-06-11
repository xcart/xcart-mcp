// Minimal MCP Streamable HTTP client for browser/service-worker.
// Stateless: caller passes config each call; session is cached in-memory per URL+key.
// Every request is bounded by a timeout (and an optional external AbortSignal).

const SESSIONS = new Map();
const DEFAULT_TIMEOUT_MS = 30_000;

function sessionKey(url, apiKey) {
  return `${url}::${apiKey}`;
}

// Parse a Streamable-HTTP response body that may be JSON or an SSE stream.
// For SSE we collect every `data:` payload and return the JSON-RPC frame that
// matches the request id (falling back to the last frame carrying result/error).
function parseBody(text, contentType, wantId) {
  if (contentType.includes('text/event-stream')) {
    const frames = [];
    for (const line of text.split('\n')) {
      const l = line.trim();
      if (!l.startsWith('data:')) continue;
      try { frames.push(JSON.parse(l.slice(5).trim())); } catch {}
    }
    return frames.find(f => f && f.id === wantId)
      ?? [...frames].reverse().find(f => f && (f.result !== undefined || f.error))
      ?? frames[frames.length - 1]
      ?? null;
  }
  return text ? JSON.parse(text) : null;
}

async function rpc(url, apiKey, sessionId, body, opts = {}) {
  const headers = {
    'Content-Type': 'application/json',
    'Accept': 'application/json, text/event-stream',
    'Authorization': `Bearer ${apiKey}`,
  };
  if (sessionId) headers['Mcp-Session-Id'] = sessionId;

  // Bound the request: external signal + internal timeout.
  const ctrl = new AbortController();
  const onAbort = () => ctrl.abort();
  if (opts.signal) {
    if (opts.signal.aborted) ctrl.abort();
    else opts.signal.addEventListener('abort', onAbort, { once: true });
  }
  const timer = setTimeout(() => ctrl.abort(new DOMException('Timeout', 'TimeoutError')), opts.timeoutMs || DEFAULT_TIMEOUT_MS);

  let res;
  try {
    res = await fetch(url, { method: 'POST', headers, body: JSON.stringify(body), signal: ctrl.signal });
  } catch (e) {
    if (e?.name === 'TimeoutError' || e?.name === 'AbortError') {
      throw new Error(opts.signal?.aborted ? 'MCP: request cancelled' : 'MCP: request timed out');
    }
    throw new Error(`MCP: ${e.message || e}`);
  } finally {
    clearTimeout(timer);
    opts.signal?.removeEventListener('abort', onAbort);
  }

  const newSession = res.headers.get('mcp-session-id') || res.headers.get('Mcp-Session-Id');
  const ct = res.headers.get('content-type') || '';
  const text = await res.text();
  let data = null;
  try { data = parseBody(text, ct, body.id); } catch { /* leave null */ }

  if (!res.ok) throw new Error(`MCP: ${data?.error?.message || `HTTP ${res.status}`}`);
  if (data?.error) throw new Error(`MCP: ${data.error.message || 'error'}`);
  return { result: data?.result, sessionId: newSession };
}

export async function initialize(url, apiKey, opts) {
  const key = sessionKey(url, apiKey);
  const { result, sessionId } = await rpc(url, apiKey, null, {
    jsonrpc: '2.0', id: 1, method: 'initialize',
    params: {
      protocolVersion: '2024-11-05',
      capabilities: {},
      clientInfo: { name: 'xcart-mcp-copilot', version: '0.2.0' },
    },
  }, opts);
  if (sessionId) SESSIONS.set(key, sessionId);
  // Per spec, send the initialized notification.
  await rpc(url, apiKey, sessionId, { jsonrpc: '2.0', method: 'notifications/initialized' }, opts).catch(() => {});
  return result;
}

async function ensureSession(url, apiKey, opts) {
  const key = sessionKey(url, apiKey);
  if (!SESSIONS.get(key)) await initialize(url, apiKey, opts);
  return SESSIONS.get(key);
}

export async function listTools(url, apiKey, opts) {
  const sid = await ensureSession(url, apiKey, opts);
  const { result } = await rpc(url, apiKey, sid, { jsonrpc: '2.0', id: Date.now(), method: 'tools/list' }, opts);
  return result?.tools || [];
}

export async function callTool(url, apiKey, name, args, opts) {
  const sid = await ensureSession(url, apiKey, opts);
  const { result } = await rpc(url, apiKey, sid, {
    jsonrpc: '2.0', id: Date.now(), method: 'tools/call',
    params: { name, arguments: args || {} },
  }, opts);
  return result;
}

export function resetSession(url, apiKey) {
  SESSIONS.delete(sessionKey(url, apiKey));
}
