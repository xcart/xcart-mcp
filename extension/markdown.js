// Tiny, dependency-free, XSS-safe Markdown → HTML renderer.
// Supports: fenced/inline code, bold, italic, headings, links, unordered &
// ordered lists, blockquotes, hr, GFM tables, paragraphs. Everything is
// HTML-escaped first, so no raw markup from the model can reach the DOM.

export function renderMarkdown(src) {
  if (!src) return '';
  const text = String(src).replace(/\r\n?/g, '\n');

  // Pull out fenced code blocks first so their contents aren't processed.
  const fences = [];
  const withoutFences = text.replace(/```([^\n]*)\n([\s\S]*?)```/g, (_, lang, code) => {
    const i = fences.length;
    fences.push(`<pre class="md-pre"><code>${esc(code.replace(/\n$/, ''))}</code></pre>`);
    return ` FENCE${i} `;
  });

  const lines = withoutFences.split('\n');
  const out = [];
  let para = [];
  let list = null; // { type: 'ul'|'ol', items: [] }
  let quote = [];

  const flushPara = () => { if (para.length) { out.push(`<p>${inline(para.join(' '))}</p>`); para = []; } };
  const flushList = () => { if (list) { out.push(`<${list.type}>${list.items.map(li => `<li>${inline(li)}</li>`).join('')}</${list.type}>`); list = null; } };
  const flushQuote = () => { if (quote.length) { out.push(`<blockquote>${inline(quote.join(' '))}</blockquote>`); quote = []; } };
  const flushAll = () => { flushPara(); flushList(); flushQuote(); };

  for (let i = 0; i < lines.length; i++) {
    const line = lines[i].replace(/\s+$/, '');

    const fence = line.match(/^ FENCE(\d+) $/);
    if (fence) { flushAll(); out.push(fences[+fence[1]]); continue; }
    if (!line.trim()) { flushAll(); continue; }

    // GFM table: a pipe row immediately followed by a delimiter row.
    if (isTableRow(line) && i + 1 < lines.length && isDelimiterRow(lines[i + 1])) {
      flushAll();
      const aligns = splitRow(lines[i + 1]).map(cellAlign);
      const header = splitRow(line);
      const body = [];
      let j = i + 2;
      for (; j < lines.length && isTableRow(lines[j]) && lines[j].trim(); j++) body.push(splitRow(lines[j]));
      out.push(renderTable(header, aligns, body));
      i = j - 1;
      continue;
    }

    let m;
    if ((m = line.match(/^(#{1,6})\s+(.*)$/))) { flushAll(); out.push(`<h${m[1].length}>${inline(m[2])}</h${m[1].length}>`); continue; }
    if (/^(-{3,}|\*{3,}|_{3,})$/.test(line)) { flushAll(); out.push('<hr>'); continue; }
    if ((m = line.match(/^>\s?(.*)$/))) { flushPara(); flushList(); quote.push(m[1]); continue; }
    if ((m = line.match(/^[-*+]\s+(.*)$/))) {
      flushPara(); flushQuote();
      if (!list || list.type !== 'ul') { flushList(); list = { type: 'ul', items: [] }; }
      list.items.push(m[1]); continue;
    }
    if ((m = line.match(/^\d+[.)]\s+(.*)$/))) {
      flushPara(); flushQuote();
      if (!list || list.type !== 'ol') { flushList(); list = { type: 'ol', items: [] }; }
      list.items.push(m[1]); continue;
    }
    flushList(); flushQuote();
    para.push(line.trim());
  }
  flushAll();
  return out.join('\n');
}

// ---- tables --------------------------------------------------------------

function isTableRow(line) { return line.includes('|') && /\S/.test(line.replace(/\|/g, '')); }
function isDelimiterRow(line) {
  const cells = splitRow(line);
  return cells.length > 0 && cells.every(c => /^:?-{1,}:?$/.test(c.replace(/\s/g, '')));
}
function splitRow(line) {
  let s = line.trim();
  if (s.startsWith('|')) s = s.slice(1);
  if (s.endsWith('|')) s = s.slice(0, -1);
  return s.split('|').map(c => c.trim());
}
function cellAlign(c) {
  const s = c.replace(/\s/g, '');
  const l = s.startsWith(':'), r = s.endsWith(':');
  if (l && r) return 'center';
  if (r) return 'right';
  if (l) return 'left';
  return '';
}
function renderTable(header, aligns, body) {
  const th = header.map((c, i) => `<th${alignAttr(aligns[i])}>${inline(c)}</th>`).join('');
  const rows = body.map(cells => `<tr>${cells.map((c, i) => `<td${alignAttr(aligns[i])}>${inline(c)}</td>`).join('')}</tr>`).join('');
  return `<table class="md-table"><thead><tr>${th}</tr></thead><tbody>${rows}</tbody></table>`;
}
function alignAttr(a) { return a ? ` style="text-align:${a}"` : ''; }

// ---- inline --------------------------------------------------------------

const NUL = String.fromCharCode(0);

function inline(s) {
  let t = esc(s);
  // Sentinels never collide with real text and survive the other rules.
  const codes = [];
  t = t.replace(/`([^`]+)`/g, (_, c) => { codes.push(c); return NUL + (codes.length - 1) + NUL; });
  t = t.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+|mailto:[^\s)]+)\)/g,
    (_, txt, url) => `<a href="${url}" target="_blank" rel="noopener noreferrer">${txt}</a>`);
  t = t.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
  t = t.replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>');
  t = t.replace(/(^|[^_])_([^_\n]+)_/g, '$1<em>$2</em>');
  t = t.replace(new RegExp(NUL + '(\\d+)' + NUL, 'g'), (_, i) => `<code>${codes[+i]}</code>`);
  return t;
}

function esc(s) {
  return String(s).replace(/[&<>"']/g, c =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
