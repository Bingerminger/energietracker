// =====================================================================
// v3.4.0 (N1019) — Markdown der Doku → HTML, ohne Abhängigkeit.
//
// Die Doku liegt als Markdown unter docs/ — dieselben Dateien wie auf
// GitHub. Der Renderer kann genau die Teilmenge, die dort vorkommt:
// ATX-Überschriften, Absätze, Listen (zwei Ebenen, Nummerierung ab ≠ 1,
// Aufgaben), Zitate, Codeblöcke (auch eingerückt in Listen und Zitaten),
// GFM-Tabellen, Trennlinien, Fett, Kursiv, Inline-Code, Links, Bilder und
// Autolinks. Fußnoten, Referenzlinks, Formeln und Setext-Überschriften gibt
// es in der Doku nicht.
//
// Sicherheit — HTML aus der Quelle wird nie durchgereicht:
//   • jeder Text und jeder Attributwert wird escaped;
//   • `<img>` entsteht neu aus src, alt und width (nur Ziffern), `<a id>`
//     nur mit [a-z0-9-]; jedes andere Tag bleibt sichtbarer Text;
//   • Links laufen durch `opts.link(href)` und danach durch eine eigene
//     Prüfung: nur In-App-Routen (`#/…`), http(s) und mailto;
//   • externe Links öffnen einen neuen Tab mit rel="noopener noreferrer";
//   • Bilder nur vom eigenen Server (relativ oder auf den eigenen Ursprung,
//     passt zu img-src 'self') — fremde fallen weg, ihr Alternativtext bleibt;
//   • IDs der Überschriften tragen das Präfix `d-`, damit sie nicht mit IDs
//     der App kollidieren.
// =====================================================================

const ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
const esc = (s) => String(s).replace(/[&<>"']/g, c => ESC[c]);

/** Präfix aller IDs, die die Doku in die Seite bringt (Überschriften, `<a id>`). */
export const ID_PREFIX = 'd-';

// Erlaubte Linkziele NACH der Auflösung durch opts.link: In-App-Route, Web, E-Mail.
// Kein Leerraum, keine Steuerzeichen, kein Backslash (Browser lesen „\" als „/").
const SAFE_HREF = /^(?:#\/|https?:\/\/|mailto:)[^\s\\\u0000-\u001f]*$/i;
const EXTERNAL = /^(?:https?:|mailto:)/i;
// Bilder: nur relativ — kein Schema (javascript:, data:, http:), nicht „//…" oder „/\…"
const SAFE_SRC = /^(?![a-z][a-z0-9+.-]*:)[a-z0-9._~-][^\s\\"'<>\u0000-\u001f]*$/i;
const ANCHOR_ID = /^[a-z0-9-]+$/;

/**
 * Tags entfernen wie `/<[^>]+>/g` — aber in einem Durchgang ohne Regex: von
 * jedem „<“ bis zum nächsten „>“, wenn dazwischen mindestens ein Zeichen
 * steht. Ein einmaliges Regex-Ersetzen meldet das Code-Scanning als
 * unvollständige Bereinigung; hier gibt es nichts, was sich neu zusammensetzt.
 */
export function stripTags(s) {
  const str = String(s);
  let out = '';
  for (let i = 0; i < str.length;) {
    const j = str[i] === '<' ? str.indexOf('>', i + 1) : -1;
    if (j > i + 1) { i = j + 1; continue; }
    out += str[i++];
  }
  return out;
}

/** Anker einer Überschrift nach GitHubs Regel (wie DocsIntegrityTest::anchors). */
export function slug(text) {
  let t = stripTags(String(text).replace(/\[([^\]]*)\]\([^)]*\)/g, '$1')).replace(/[`*]/g, '');
  t = t.toLowerCase().replace(/[^\p{L}\p{N}\p{M} _-]/gu, '');
  return t.replace(/ /g, '-');
}

function safeLink(href) {
  return typeof href === 'string' && SAFE_HREF.test(href) ? href : null;
}

/** Relativ — oder absolut auf den eigenen Ursprung (dann bildet api.js die Adresse absolut, etwa im Test). */
function safeSrc(src) {
  if (typeof src !== 'string') return null;
  if (SAFE_SRC.test(src)) return src;
  const origin = typeof location !== 'undefined' ? location.origin : '';
  return /^https?:\/\//i.test(origin) && src.startsWith(origin + '/') && !/[\s\\"'<>\u0000-\u001f]/.test(src) ? src : null;
}

function link(href, textHtml, ctx) {
  const r = ctx.opts.link ? ctx.opts.link(href) : null;
  const target = safeLink(r?.href);
  if (!target) return textHtml;
  // extern entscheidet das Ziel selbst, nicht die Auflösung
  const ext = EXTERNAL.test(target) ? ' target="_blank" rel="noopener noreferrer"' : '';
  return `<a href="${esc(target)}"${ext}>${textHtml}</a>`;
}

function image(src, alt, width, ctx) {
  const url = safeSrc(ctx.opts.image ? ctx.opts.image(src) : null);
  if (!url) return esc(alt);
  const w = /^\d{1,4}$/.test(width || '') ? ` width="${width}"` : '';
  return `<img src="${esc(url)}" alt="${esc(alt)}"${w} loading="lazy">`;
}

/** Attribute eines rohen Tags: `name="wert"` oder `name='wert'`. */
function attrs(raw) {
  const out = {};
  for (const m of raw.matchAll(/([a-zA-Z-]+)\s*=\s*(?:"([^"]*)"|'([^']*)')/g)) out[m[1].toLowerCase()] = m[2] ?? m[3];
  return out;
}

const RE_INLINE = {
  escapable: /[\\`*_{}[\]()#+\-.!|<>~]/,
  image: /^!\[([^\]]*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/,
  dest: /^\(([^)\s]+)(?:\s+"[^"]*")?\)/,
  autolink: /^<((?:https?:\/\/|mailto:)[^>\s]+)>/i,
  rawImg: /^<img\s([^<>]*?)\/?>/i,
  rawAnchor: /^<a\s+(?:id|name)\s*=\s*"([^"]*)"\s*>\s*<\/a>/i,
  rawP: /^<\/?p(?:\s[^<>]*)?>/i,
  comment: /^<!--[\s\S]*?-->/,
};

function inline(src, ctx) {
  let out = '';
  let i = 0;
  const n = src.length;
  while (i < n) {
    const c = src[i];
    if (c === '\\' && i + 1 < n && RE_INLINE.escapable.test(src[i + 1])) { out += esc(src[i + 1]); i += 2; continue; }
    if (c === '`') {
      let k = i;
      while (src[k] === '`') k++;
      const ticks = src.slice(i, k);
      const end = src.indexOf(ticks, k);
      if (end >= k) {
        let code = src.slice(k, end).replace(/\n/g, ' ');
        if (/^ .* $/.test(code)) code = code.slice(1, -1);
        out += `<code>${esc(code)}</code>`;
        i = end + ticks.length;
        continue;
      }
      out += esc(ticks); i = k; continue;
    }
    if (c === '!' && src[i + 1] === '[') {
      const m = RE_INLINE.image.exec(src.slice(i));
      if (m) { out += image(m[2], m[1], '', ctx); i += m[0].length; continue; }
    }
    if (c === '[') {
      // Linktext mit verschachtelten Klammern (Code oder ein Bild darin)
      let depth = 0, k = i;
      for (; k < n; k++) {
        if (src[k] === '[') depth++;
        else if (src[k] === ']') { depth--; if (depth === 0) break; }
      }
      const m = k < n ? RE_INLINE.dest.exec(src.slice(k + 1)) : null;
      if (m) {
        out += link(m[1], inline(src.slice(i + 1, k), ctx), ctx);
        i = k + 1 + m[0].length;
        continue;
      }
    }
    if (c === '<') {
      const rest = src.slice(i);
      let m;
      if ((m = RE_INLINE.autolink.exec(rest))) { out += link(m[1], esc(m[1]), ctx); i += m[0].length; continue; }
      if ((m = RE_INLINE.rawImg.exec(rest))) {
        // neu gebaut — nur src, alt und width; alle anderen Attribute fallen weg
        const a = attrs(m[1]);
        if (a.src) out += image(a.src, a.alt || '', a.width, ctx);
        i += m[0].length;
        continue;
      }
      if ((m = RE_INLINE.rawAnchor.exec(rest)) && ANCHOR_ID.test(m[1])) {
        ctx.ids.push(m[1]);
        out += `<a id="${esc(ID_PREFIX + m[1])}"></a>`;
        i += m[0].length;
        continue;
      }
      if ((m = RE_INLINE.rawP.exec(rest)) || (m = RE_INLINE.comment.exec(rest))) { i += m[0].length; continue; }
    }
    if (c === '*' || c === '_') {
      const strong = src.startsWith(c + c, i);
      const mark = strong ? c + c : c;
      const prev = src[i - 1] || ' ';
      if (src[i + mark.length] && !/\s/.test(src[i + mark.length]) && !(c === '_' && /[\p{L}\p{N}]/u.test(prev))) {
        let end = i + mark.length;
        while ((end = src.indexOf(mark, end + 1)) > 0) {
          if (strong || src[end + 1] !== c) {
            if (!/\s/.test(src[end - 1]) && !(c === '_' && /[\p{L}\p{N}]/u.test(src[end + mark.length] || ' '))) break;
          } else end++;
        }
        if (end > 0) {
          const tag = strong ? 'strong' : 'em';
          out += `<${tag}>${inline(src.slice(i + mark.length, end), ctx)}</${tag}>`;
          i = end + mark.length;
          continue;
        }
      }
    }
    out += esc(c);
    i++;
  }
  return out;
}

const RE = {
  fence: /^(\s*)(`{3,}|~{3,})\s*([\w-]*)/,
  heading: /^(#{1,6})[ \t]+(.*)$/,
  hr: /^\s{0,3}([-*_])(\s*\1){2,}\s*$/,
  quote: /^\s{0,3}>\s?/,
  list: /^(\s*)([-*+]|\d+[.)])\s+(.*)$/,
  tableSep: /^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/,
  comment: /^\s*<!--/,
};

/** Text einer ATX-Überschrift ohne schließende Rauten — linear, ohne Rückverfolgung. */
function headingText(raw) {
  let s = raw.trimEnd();
  let j = s.length;
  while (j > 0 && s[j - 1] === '#') j--;
  if (j < s.length && (j === 0 || /\s/.test(s[j - 1]))) s = s.slice(0, j).trimEnd();
  return s;
}

function splitRow(line) {
  let s = line.trim();
  if (s.startsWith('|')) s = s.slice(1);
  if (s.endsWith('|') && !s.endsWith('\\|')) s = s.slice(0, -1);
  const cells = [];
  let cur = '';
  let inCode = false;
  for (let i = 0; i < s.length; i++) {
    if (s[i] === '\\' && s[i + 1] === '|') { cur += '|'; i++; continue; }
    if (s[i] === '`') inCode = !inCode;
    if (s[i] === '|' && !inCode) { cells.push(cur.trim()); cur = ''; continue; }
    cur += s[i];
  }
  cells.push(cur.trim());
  return cells;
}

function isBlockStart(line, next) {
  return RE.fence.test(line) || RE.heading.test(line) || RE.hr.test(line) || RE.quote.test(line)
    || RE.list.test(line) || RE.comment.test(line) || (line.includes('|') && RE.tableSep.test(next || ''));
}

const indentOf = (l) => l.length - l.trimStart().length;

function blocks(lines, ctx) {
  let html = '';
  let i = 0;
  while (i < lines.length) {
    const line = lines[i];
    if (!line.trim()) { i++; continue; }
    let m;
    if ((m = RE.fence.exec(line))) {
      const ind = m[1].length, fence = m[2], lang = m[3];
      const body = [];
      i++;
      while (i < lines.length && !lines[i].trim().startsWith(fence)) {
        body.push(lines[i].slice(Math.min(ind, indentOf(lines[i]))));
        i++;
      }
      i++;
      html += `<pre class="doc-code"${lang ? ` data-lang="${esc(lang)}"` : ''}><code>${esc(body.join('\n'))}</code></pre>\n`;
      continue;
    }
    if (RE.comment.test(line)) {
      while (i < lines.length && !lines[i].includes('-->')) i++;
      i++;
      continue;
    }
    if ((m = RE.heading.exec(line))) {
      const level = m[1].length;
      const text = headingText(m[2]);
      let id = slug(text);
      const k = ctx.seen.get(id) || 0;
      ctx.seen.set(id, k + 1);
      if (k) id = `${id}-${k}`;
      ctx.ids.push(id);
      const inner = inline(text, ctx);
      ctx.headings.push({ level, id: ID_PREFIX + id, html: stripTags(inner) });
      html += `<h${level} id="${esc(ID_PREFIX + id)}">${inner}</h${level}>\n`;
      i++;
      continue;
    }
    if (RE.hr.test(line)) { html += '<hr>\n'; i++; continue; }
    if (RE.quote.test(line)) {
      const body = [];
      while (i < lines.length && lines[i].trim() && RE.quote.test(lines[i])) { body.push(lines[i].replace(RE.quote, '')); i++; }
      html += `<blockquote>${blocks(body, ctx)}</blockquote>\n`;
      continue;
    }
    if (line.includes('|') && RE.tableSep.test(lines[i + 1] || '')) {
      const head = splitRow(line);
      i += 2;
      const rows = [];
      while (i < lines.length && lines[i].trim() && lines[i].includes('|')) { rows.push(splitRow(lines[i])); i++; }
      // eigener Scroll-Rahmen: breite Tabellen scrollen in sich, nicht die Seite
      html += `<div class="doc-table"><table><thead><tr>${head.map(c => `<th>${inline(c, ctx)}</th>`).join('')}</tr></thead><tbody>`
        + rows.map(r => `<tr>${head.map((_, j) => `<td>${inline(r[j] || '', ctx)}</td>`).join('')}</tr>`).join('')
        + '</tbody></table></div>\n';
      continue;
    }
    if ((m = RE.list.exec(line))) {
      const base = m[1].length;
      const ordered = /\d/.test(m[2]);
      const start = ordered ? parseInt(m[2], 10) : 1;
      const items = [];
      while (i < lines.length) {
        const lm = RE.list.exec(lines[i]);
        if (!lm || lm[1].length !== base || /\d/.test(lm[2]) !== ordered) break;
        const contentIndent = lm[1].length + lm[2].length + 1;
        const body = [lm[3]];
        i++;
        while (i < lines.length) {
          const l = lines[i];
          if (!l.trim()) {
            // Leerzeile: gehört dazu, wenn danach eingerückt weitergeht
            let j = i;
            while (j < lines.length && !lines[j].trim()) j++;
            if (j < lines.length && indentOf(lines[j]) >= Math.min(contentIndent, base + 2)) {
              while (i < j) { body.push(''); i++; }
              continue;
            }
            break;
          }
          const ind = indentOf(l);
          if (ind >= Math.min(contentIndent, base + 2)) { body.push(l.slice(Math.min(ind, contentIndent))); i++; continue; }
          if (RE.list.test(l) || isBlockStart(l, lines[i + 1])) break;
          body.push(l.trimStart());   // faule Fortsetzung
          i++;
        }
        let task = '';
        const tm = /^\[([ xX])\]\s+/.exec(body[0]);
        if (tm) {
          task = `<input type="checkbox" disabled${tm[1] !== ' ' ? ' checked' : ''}> `;
          body[0] = body[0].slice(tm[0].length);
        }
        let inner = blocks(body, ctx);
        const single = inner.match(/^<p>([\s\S]*)<\/p>\n$/);
        if (single && !single[1].includes('<p>')) inner = single[1];
        else inner = inner.replace(/^<p>([\s\S]*?)<\/p>\n/, '$1\n');
        items.push(`<li>${task}${inner}</li>`);
        // Leerzeilen zwischen Punkten derselben Liste
        while (i < lines.length && !lines[i].trim()) {
          let j = i;
          while (j < lines.length && !lines[j].trim()) j++;
          const nm = RE.list.exec(lines[j] || '');
          if (nm && nm[1].length === base) i = j;
          else break;
        }
      }
      const tag = ordered ? 'ol' : 'ul';
      html += `<${tag}${ordered && start !== 1 ? ` start="${start}"` : ''}>${items.join('')}</${tag}>\n`;
      continue;
    }
    // Absatz
    const para = [line.trim()];
    i++;
    while (i < lines.length && lines[i].trim() && !isBlockStart(lines[i], lines[i + 1])) { para.push(lines[i].trim()); i++; }
    const text = para.join('\n');
    const inner = inline(text, ctx);
    if (/^(?:<a id="[^"]*"><\/a>\s*)+$/.test(inner)) { html += inner + '\n'; continue; }   // nur Sprungmarken
    html += /^<p>.*<\/p>$/i.test(text) ? `<p class="doc-figures">${inner}</p>\n` : `<p>${inner}</p>\n`;
  }
  return html;
}

/**
 * @param {string} md  Markdown einer Doku-Seite
 * @param {{link?: (href: string) => ({href: string}|null), image?: (src: string) => (string|null)}} [opts]
 *        link: Linkziel → `{href}` (In-App-Route, http(s), mailto) oder null (nur Text);
 *        image: Bildquelle → relative URL oder null (Bild fällt weg)
 * @returns {{html: string, ids: string[], headings: Array<{level: number, id: string, html: string}>, title: string}}
 *        ids: Anker ohne Präfix (für Tests), headings: Überschriften mit Präfix-ID und
 *        escaptem Text (für das Inhaltsverzeichnis), title: Text der ersten Überschrift 1
 */
export function renderMarkdown(md, opts = {}) {
  const ctx = { opts, seen: new Map(), ids: [], headings: [] };
  const html = blocks(String(md ?? '').replace(/\r\n?/g, '\n').split('\n'), ctx);
  const title = ctx.headings.find(h => h.level === 1)?.html || '';
  return { html, ids: ctx.ids, headings: ctx.headings, title };
}
