// =====================================================================
// v3.12.0 (Paket wegweiser) — Die Wege im Wegweiser (#/help/setups): für jeden
// Weg das Schaubild und was dazu im Text steht, dazu die Kurzauswahl.
//
// Bausteine statt Stufenleiter: SETUP_IDS hat keine Rangfolge, und nichts hier
// oder im Katalog darf „Grundstufe“ oder „Ausbau“ sagen.
//
// Eine Quelle für App und Doku: Alle Funktionen sind rein. Sie fragen nicht das
// DOM und bekommen die Übersetzung als `tr` (Standard: t). Dasselbe zeichnet
// tools/build-setup-guide.mjs als SVG-Dateien und Markdown für die Doku.
//
// Bilder: Jede Szene zeichnet zwei Fassungen, weil Skalieren am iPhone winzige
// Schrift ergäbe. `wide` (760 breit) liest von links nach rechts, `narrow` (320
// breit, also etwa 1:1 auf 390 px) von oben nach unten. Die Seite zeigt per
// Container-Abfrage die passende. Höhen ergeben sich aus dem umbrochenen Text,
// denn jede Sprache ist anders lang. `detail` ergänzt Ports und Adressen, die
// gehören ins „Im Detail“.
//
// Die Hausgrenze steht in jedem Bild und zeigt immer alles, was hinausgeht: Ein
// Pfeil ist `out`, wenn er sie kreuzt, nur diese sind farbig. Der Wetterabgleich
// läuft auf jedem Weg und steht deshalb in jedem Bild, als kompakter Pfeil ohne
// Beschriftung; was hinausgeht, sagt der Kasten „Wetterdienst“.
// =====================================================================
import { t, getLocale } from './i18n.js';
import { svg, shapes, wrapText } from './illustrations.js';

/** Alle Wege. Die Reihenfolge ist keine Rangfolge. */
export const SETUP_IDS = ['by-hand', 'inbox', 'home-assistant', 'local-ai', 'cloud-ai', 'together'];

const TYPE = {
  wide:   { name: 12.5, sub: 11, flow: 11, port: 10.5, zone: 12, frame: 11, lh: 13.5 },
  narrow: { name: 13.5, sub: 12, flow: 12, port: 11.5, zone: 13, frame: 12, lh: 14.5 },
};
const PAD = 8;
// Breite Fassung: Hausgrenze links, die Spalte „Außerhalb“ rechts
const ZONE = { x: 8, y: 24, w: 614 };
const OUT = { x: 636, w: 116, cx: 694 };

const ICON = {
  meters:    { w: 36, h: 26, draw: (x, y) => shapes.meter(x, y, { w: 16, h: 26, util: 'strom' }) + shapes.meter(x + 20, y, { w: 16, h: 26, util: 'gas' }) },
  phone:     { w: 16, h: 26, draw: (x, y) => shapes.phone(x, y) },
  nas:       { w: 26, h: 26, draw: (x, y) => shapes.nas(x, y) },
  program:   { w: 30, h: 24, draw: (x, y) => shapes.program(x, y) },
  container: { w: 34, h: 24, draw: (x, y) => shapes.container(x, y) },
  cloud:     { w: 36, h: 24, draw: (x, y) => shapes.cloud(x, y) },
  doc:       { w: 22, h: 26, draw: (x, y) => shapes.doc(x, y, { w: 22, h: 26 }) },
  house:     { w: 28, h: 26, draw: (x, y) => shapes.house(x, y, 28, 26) },
  wallbox:   { w: 24, h: 26, draw: (x, y) => shapes.wallbox(x, y, { w: 16, h: 18 }) },
};

/** Zeichenfläche einer Szene: misst und zeichnet Kästen und Beschriftungen. */
function stage(scene, variant, detail, tr) {
  const T = TYPE[variant];
  const nodeText = (id) => {
    const nd = scene.nodes[id];
    return { name: nd.label || tr(nd.name), sub: nd.sub ? tr(nd.sub) : '', port: detail ? nd.port || '' : '' };
  };
  const block = (id, w, stacked) => {
    const { name, sub, port } = nodeText(id);
    const icon = ICON[scene.nodes[id].icon];
    const inner = stacked ? w - 2 * PAD : w - 3 * PAD - icon.w;
    const names = wrapText(name, inner, T.name, { bold: true });
    const subs = sub ? wrapText(sub, inner, T.sub) : [];
    const textH = names.length * (T.name + 2) + subs.length * T.lh + (port ? T.lh : 0);
    return { icon, names, subs, port, textH, h: stacked ? 2 * PAD + icon.h + 5 + textH : 2 * PAD + Math.max(icon.h, textH) };
  };
  const placed = (x, y, width, text, { anchor = 'start' } = {}) => {
    const list = wrapText(text, width, T.flow);
    return { svg: shapes.lines(x, y + T.flow, list, { anchor, size: T.flow, lh: T.lh, cls: 'ill-flow-label' }), h: list.length * T.lh };
  };
  return {
    T, tr,
    text: (id) => tr(scene.flows[id].label),
    height: (id, w, stacked = true) => block(id, w, stacked).h,
    /** Größte Höhe einer Reihe: [[id, Breite], …] */
    row: (list, stacked = true) => Math.max(...list.map(([id, w]) => block(id, w, stacked).h)),
    card(id, x, y, w, h = 0, stacked = true) {
      const b = block(id, w, stacked);
      const H = h || b.h;
      let body = `<rect class="ill-face" x="${x}" y="${y}" width="${w}" height="${H}" rx="6"/>`;
      let tx, ty, anchor;
      if (stacked) {
        const top = y + (H - (b.icon.h + 5 + b.textH)) / 2;
        body += b.icon.draw(x + (w - b.icon.w) / 2, top);
        tx = x + w / 2; ty = top + b.icon.h + 5 + T.name; anchor = 'middle';
      } else {
        body += b.icon.draw(x + PAD, y + (H - b.icon.h) / 2);
        tx = x + 2 * PAD + b.icon.w; ty = y + (H - b.textH) / 2 + T.name; anchor = 'start';
      }
      body += shapes.lines(tx, ty, b.names, { cls: 'ill-name', anchor, size: T.name, lh: T.name + 2 });
      ty += (b.names.length - 1) * (T.name + 2) + T.lh;
      body += shapes.lines(tx, ty, b.subs, { cls: 'ill-sub', anchor, size: T.sub, lh: T.lh });
      ty += b.subs.length * T.lh;
      if (b.port) body += shapes.lines(tx, ty, [b.port], { cls: 'ill-port', anchor, size: T.port, lh: T.lh });
      return { svg: body, x, y, w, h: H, r: x + w, b: y + H, cx: x + w / 2, cy: y + H / 2 };
    },
    /** Beschriftung über einer waagerechten Linie (die letzte Zeile sitzt auf ihr). */
    above(cx, lineY, text, width) {
      const list = wrapText(text, width, T.flow);
      return shapes.lines(cx, lineY - 6 - (list.length - 1) * T.lh, list, { size: T.flow, lh: T.lh, cls: 'ill-flow-label' });
    },
    /** Beschriftung unter einer waagerechten Linie. */
    below(cx, lineY, text, width) {
      return shapes.lines(cx, lineY + 5 + T.flow, wrapText(text, width, T.flow), { size: T.flow, lh: T.lh, cls: 'ill-flow-label' });
    },
    /** Beschriftung an fester Stelle, liefert Bild und Höhe. */
    beside: placed,
    /** Mehrere Richtungen untereinander, jede mit ihrem Pfeilzeichen vorn. */
    dirs(x, y, items, width) {
      let h = 0, body = '';
      for (const [glyph, text] of items) {
        const part = placed(x, y + h, width, `${glyph} ${text}`);
        body += part.svg; h += part.h;
      }
      return { svg: body, h };
    },
    zone: (x, y, w, h) => shapes.boundary(x, y, w, h, { label: tr('setups.zone.home'), size: T.zone }),
    outside: (x, y, anchor = 'start') => shapes.lines(x, y, [tr('setups.zone.outside')], { cls: 'ill-zone-label', anchor, size: T.zone, lh: T.lh }),
    frame: (x, y, w, h, key, labelX = null) => shapes.frame(x, y, w, h, { label: key === 'Docker' ? key : tr(key), size: T.frame, labelX }),
  };
}

/** Pfeil über die Hausgrenze: farbig und gestrichelt. */
const out = (points, label = '') => shapes.flow(points, { kind: 'out', dashed: true, label });
/** Betonter Pfeil: etwas, das du selbst tust. */
const ink = (points, label = '') => shapes.flow(points, { kind: 'ink', label });

// ── Von Hand ─────────────────────────────────────────────────────────

function byHandWide(c) {
  const Y = 56;
  const h = c.row([['meters', 112], ['you', 130], ['et', 148], ['weather', OUT.w]]);
  const m = c.card('meters', 24, Y, 112, h), u = c.card('you', 226, Y, 130, h), e = c.card('et', 462, Y, 148, h), w = c.card('weather', OUT.x, Y, OUT.w, h);
  const cy = Y + h / 2, zb = Y + h + 14;
  const body = c.zone(ZONE.x, ZONE.y, ZONE.w, zb - ZONE.y) + c.outside(OUT.cx, Y - 12, 'middle')
    + shapes.flow([[m.r, cy], [u.x, cy]], { label: c.above((m.r + u.x) / 2, cy, c.text('read'), 84) })
    + shapes.flow([[u.r, cy - 8], [e.x, cy - 8]], { label: c.above((u.r + e.x) / 2, cy - 8, c.text('enter'), 100) })
    + shapes.flow([[e.x, cy + 8], [u.r, cy + 8]], { label: c.below((u.r + e.x) / 2, cy + 8, c.text('results'), 100) })
    + out([[e.r, cy], [w.x, cy]])
    + m.svg + u.svg + e.svg + w.svg;
  return { w: 760, h: zb + 8, body };
}

function byHandNarrow(c) {
  const X = 12, W = 284;
  const m = c.card('meters', X, 34, W, 0, false);
  const read = c.beside(56, m.b + 8, 236, c.text('read'));
  const uY = m.b + read.h + 16;
  const u = c.card('you', X, uY, W, 0, false);
  const pair = c.dirs(58, u.b + 7, [['↓', c.text('enter')], ['↑', c.text('results')]], 232);
  const eY = u.b + pair.h + 14;
  const e = c.card('et', X, eY, W, 0, false);
  const zb = e.b + 12, wY = zb + 26;
  const w = c.card('weather', X, wY, W, 0, false);
  const body = c.zone(4, 4, 312, zb - 4) + c.outside(310, zb + 17, 'end')
    + shapes.flow([[40, m.b], [40, uY]]) + read.svg
    + shapes.flow([[34, u.b], [34, eY]]) + shapes.flow([[48, eY], [48, u.b]]) + pair.svg
    + out([[40, e.b], [40, wY]])
    + m.svg + u.svg + e.svg + w.svg;
  return { w: 320, h: w.b + 6, body };
}

// ── Mit Versorgerpost ────────────────────────────────────────────────
// Ohne Dienst bleibt der Posteingang die Ablage; der Kasten „KI“ ist darum „wenn du willst“.

function inboxWide(c) {
  const Y = 56;
  const h = c.row([['post', 116], ['et', 150], ['ai', 134]]);
  const p = c.card('post', 24, Y, 116, h), e = c.card('et', 232, Y, 150, h), a = c.card('ai', 476, Y, 134, h);
  const cy = Y + h / 2;
  const y2 = Y + h + 34;
  const hu = c.height('you', 116);
  const u = c.card('you', 24, y2, 116, hu);
  const hOut = c.row([['cloud', OUT.w], ['weather', OUT.w]]);
  const cl = c.card('cloud', OUT.x, y2, OUT.w, hOut);
  const w = c.card('weather', OUT.x, cl.b + 10, OUT.w, hOut);
  const zb = Math.max(u.b, w.cy) + 14;
  const body = c.zone(ZONE.x, ZONE.y, ZONE.w, zb - ZONE.y) + c.outside(OUT.cx, y2 - 12, 'middle')
    + shapes.flow([[p.r, cy], [e.x, cy]], { label: c.above((p.r + e.x) / 2, cy, c.text('drop'), 86) })
    + shapes.flow([[e.r, cy - 8], [a.x, cy - 8]], { label: c.above((e.r + a.x) / 2, cy - 8, c.text('text'), 88) })
    + shapes.flow([[a.x, cy + 8], [e.r, cy + 8]], { label: c.below((e.r + a.x) / 2, cy + 8, c.text('proposal'), 88) })
    + ink([[u.r, u.cy], [270, u.cy], [270, e.b]], c.above(205, u.cy, c.text('check'), 124))
    + out([[345, e.b], [345, cl.cy], [cl.x, cl.cy]], c.above(490, cl.cy, c.text('toCloud'), 230))
    + out([[320, e.b], [320, w.cy], [w.x, w.cy]])
    + p.svg + e.svg + a.svg + u.svg + cl.svg + w.svg;
  return { w: 760, h: Math.max(zb, w.b) + 8, body };
}

function inboxNarrow(c) {
  const y1 = 34;
  const h1 = c.row([['post', 134], ['you', 134]]);
  const p = c.card('post', 12, y1, 134, h1), u = c.card('you', 156, y1, 134, h1);
  const l1 = c.beside(42, p.b + 6, 104, c.text('drop')), l2 = c.beside(186, u.b + 6, 104, c.text('check'));
  const eY = p.b + Math.max(l1.h, l2.h) + 14;
  const e = c.card('et', 12, eY, 278, 0, false);
  const pair = c.dirs(58, e.b + 7, [['↓', c.text('text')], ['↑', c.text('proposal')]], 230);
  const aY = e.b + pair.h + 14;
  const a = c.card('ai', 12, aY, 278, 0, false);
  const zb = a.b + 12;
  const label = c.beside(118, zb + 13, 176, c.text('toCloud'));
  const cloudY = zb + 13 + label.h + 8;
  const hOut = c.row([['cloud', 146], ['weather', 150]]);
  const cl = c.card('cloud', 4, cloudY, 146, hOut), w = c.card('weather', 166, cloudY, 150, hOut);
  const body = c.zone(4, 4, 312, zb - 4) + c.outside(12, zb + 17)
    + shapes.flow([[34, p.b], [34, eY]]) + l1.svg
    + ink([[178, u.b], [178, eY]]) + l2.svg
    + shapes.flow([[34, e.b], [34, aY]]) + shapes.flow([[48, aY], [48, e.b]]) + pair.svg
    + out([[e.r, e.cy - 5], [305, e.cy - 5], [305, cloudY]])
    + out([[e.r, e.cy + 5], [297, e.cy + 5], [297, zb + 9], [110, zb + 9], [110, cloudY]]) + label.svg
    + p.svg + u.svg + e.svg + a.svg + cl.svg + w.svg;
  return { w: 320, h: cl.b + 6, body };
}

// ── Mit Home Assistant und smarten Zählern ───────────────────────────
// Der Energietracker liest keine Zähler aus: Das macht Home Assistant, er rechnet.

function haWide(c) {
  const Y = 56;
  const h = c.row([['meters', 112], ['ha', 128], ['et', 158], ['weather', OUT.w]]);
  const m = c.card('meters', 24, Y, 112, h), ha = c.card('ha', 216, Y, 128, h), e = c.card('et', 452, Y, 158, h), w = c.card('weather', OUT.x, Y, OUT.w, h);
  const cy = Y + h / 2;
  const you = c.beside(554, e.b + 6, 74, c.text('browser'), { anchor: 'end' });
  const y2 = e.b + Math.max(you.h + 10, 34);
  const h2 = c.row([['evcc', 128], ['you', 100]]);
  const ev = c.card('evcc', 216, y2, 128, h2), u = c.card('you', 510, y2, 100, h2);
  const zb = ev.b + 14;
  const body = c.zone(ZONE.x, ZONE.y, ZONE.w, zb - ZONE.y) + c.outside(OUT.cx, Y - 12, 'middle')
    + shapes.flow([[m.r, cy], [ha.x, cy]], { label: c.above((m.r + ha.x) / 2, cy, c.text('sense'), 76) })
    + shapes.flow([[ha.r, cy - 8], [e.x, cy - 8]], { label: c.above((ha.r + e.x) / 2, cy - 8, c.text('push'), 104) })
    + shapes.flow([[e.x, cy + 8], [ha.r, cy + 8]], { label: c.below((ha.r + e.x) / 2, cy + 8, c.text('back'), 104) })
    + shapes.flow([[ev.r, ev.cy], [478, ev.cy], [478, e.b]], { label: c.above(411, ev.cy, c.text('charges'), 126) })
    + ink([[560, y2], [560, e.b]]) + you.svg
    + out([[e.r, cy], [w.x, cy]])
    + m.svg + ha.svg + e.svg + ev.svg + u.svg + w.svg;
  return { w: 760, h: zb + 8, body };
}

function haNarrow(c) {
  const X = 12, W = 278;
  const m = c.card('meters', X, 34, W, 0, false);
  const l1 = c.beside(50, m.b + 7, 236, c.text('sense'));
  const y2 = m.b + l1.h + 14;
  const ha = c.card('ha', X, y2, W, 0, false);
  const pair = c.dirs(58, ha.b + 7, [['↓', c.text('push')], ['↑', c.text('back')]], 230);
  const y3 = ha.b + pair.h + 14;
  const e = c.card('et', X, y3, W, 0, false);
  const l2 = c.beside(42, e.b + 6, 104, c.text('charges')), l3 = c.beside(186, e.b + 6, 104, c.text('browser'));
  const y4 = e.b + Math.max(l2.h, l3.h) + 14;
  const h4 = c.row([['evcc', 134], ['you', 134]]);
  const ev = c.card('evcc', X, y4, 134, h4), u = c.card('you', 156, y4, 134, h4);
  const zb = ev.b + 12, wY = zb + 26;
  const w = c.card('weather', 166, wY, 150, 0);
  const body = c.zone(4, 4, 312, zb - 4) + c.outside(12, zb + 17)
    + shapes.flow([[40, m.b], [40, y2]]) + l1.svg
    + shapes.flow([[34, ha.b], [34, y3]]) + shapes.flow([[48, y3], [48, ha.b]]) + pair.svg
    + shapes.flow([[34, y4], [34, e.b]]) + l2.svg
    + ink([[178, y4], [178, e.b]]) + l3.svg
    + out([[e.r, e.cy], [305, e.cy], [305, wY]])
    + m.svg + ha.svg + e.svg + ev.svg + u.svg + w.svg;
  return { w: 320, h: w.b + 6, body };
}

// ── Mit KI lokal ─────────────────────────────────────────────────────
// Open WebUI ist die Drehscheibe: Es fragt das Modell, und es ruft selbst die
// Werkzeuge des Energietrackers auf (das Modell spricht nie mit ihm).

function localAiWide(c) {
  const Y = 84;
  const h = c.row([['you', 128], ['webui', 130], ['ollama', 114], ['models', OUT.w]]);
  const you = c.card('you', 24, Y, 128, h), web = c.card('webui', 258, Y, 130, h), oll = c.card('ollama', 486, Y, 114, h), mod = c.card('models', OUT.x, Y, OUT.w, h);
  const cy = Y + h / 2, dockerB = Y + h + 10, compB = dockerB + 10;
  const ask = c.beside(306, compB + 8, 134, c.text('mcpAsk'), { anchor: 'end' });
  const ans = c.beside(340, compB + 8, 150, c.text('mcpAnswer'));
  const yE = compB + Math.max(ask.h, ans.h) + 18;
  const eh = c.row([['et', 146], ['weather', OUT.w]]);
  const et = c.card('et', 250, yE, 146, eh), w = c.card('weather', OUT.x, yE, OUT.w, eh);
  const zb = et.b + 14;
  const body = c.zone(ZONE.x, ZONE.y, ZONE.w, zb - ZONE.y) + c.outside(OUT.cx, Y - 12, 'middle')
    + c.frame(238, 38, 372, compB - 38, 'setups.node.computer')
    + c.frame(250, 60, 150, dockerB - 60, 'Docker')
    + shapes.flow([[you.r, cy - 8], [web.x, cy - 8]], { label: c.above(195, cy - 8, c.text('ask'), 84) })
    + shapes.flow([[web.x, cy + 8], [you.r, cy + 8]], { label: c.below(195, cy + 8, c.text('answer'), 84) })
    + shapes.flow([[web.r, cy - 8], [oll.x, cy - 8]], { label: c.above(443, cy - 8, c.text('prompt'), 80) })
    + shapes.flow([[oll.x, cy + 8], [web.r, cy + 8]], { label: c.below(443, cy + 8, c.text('reply'), 80) })
    + shapes.flow([[313, web.b], [313, yE]]) + shapes.flow([[333, yE], [333, web.b]]) + ask.svg + ans.svg
    + ink([[you.cx, you.b], [you.cx, et.cy], [et.x, et.cy]], c.above(170, et.cy, c.text('confirm'), 150))
    + out([[mod.x, cy], [oll.r, cy]])
    + out([[et.r, et.cy], [w.x, et.cy]])
    + you.svg + web.svg + oll.svg + mod.svg + et.svg + w.svg;
  return { w: 760, h: zb + 8, body };
}

function localAiNarrow(c) {
  // Oben: Du und der Energietracker; die Bestätigung in der App läuft als Bogen darüber
  const conf = c.beside(160, 30, 280, c.text('confirm'), { anchor: 'middle' });
  const uy = 30 + conf.h + 5, rowY = uy + 15;
  const hRow = c.row([['you', 138], ['et', 128]]);
  const you = c.card('you', 12, rowY, 138, hRow), et = c.card('et', 162, rowY, 128, hRow);
  // Darunter die Drehscheibe, je Spalte zwei Pfeile mit Text daneben
  const la = c.dirs(56, you.b + 6, [['↓', c.text('ask')], ['↑', c.text('answer')]], 94);
  const lb = c.dirs(198, you.b + 6, [['↑', c.text('mcpAsk')], ['↓', c.text('mcpAnswer')]], 92);
  const compTop = you.b + Math.max(la.h, lb.h) + 14, dockerTop = compTop + 20, webY = dockerTop + 21;
  const web = c.card('webui', 26, webY, 250, 0, false);
  const dockerB = web.b + 8;
  const ld = c.dirs(68, dockerB + 6, [['↓', c.text('prompt')], ['↑', c.text('reply')]], 208);
  const ollY = dockerB + 6 + ld.h + 8;
  const oll = c.card('ollama', 26, ollY, 250, 0, false);
  const compB = oll.b + 8, zb = compB + 10;
  // Außerhalb: die beiden Wolken; der Wetterabgleich läuft am rechten Rand hinunter
  const cloudY = zb + 26;
  const hOut = c.row([['models', 146], ['weather', 150]]);
  const mod = c.card('models', 4, cloudY, 146, hOut), w = c.card('weather', 166, cloudY, 150, hOut);
  const body = c.zone(4, 4, 312, zb - 4) + c.outside(12, zb + 17)
    + c.frame(10, compTop, 282, compB - compTop, 'setups.node.computer', 58)
    + c.frame(18, dockerTop, 266, dockerB - dockerTop, 'Docker', 58)
    + conf.svg + ink([[you.cx, rowY], [you.cx, uy], [et.cx, uy], [et.cx, rowY]])
    + shapes.flow([[34, you.b], [34, webY]]) + shapes.flow([[48, webY], [48, you.b]]) + la.svg
    + shapes.flow([[176, webY], [176, et.b]]) + shapes.flow([[190, et.b], [190, webY]]) + lb.svg
    + shapes.flow([[44, web.b], [44, ollY]]) + shapes.flow([[58, ollY], [58, web.b]]) + ld.svg
    + out([[96, cloudY], [96, oll.b]])
    + out([[et.r, et.cy], [305, et.cy], [305, cloudY]])
    + you.svg + et.svg + web.svg + oll.svg + mod.svg + w.svg;
  return { w: 320, h: mod.b + 6, body };
}

// ── Mit KI in der Cloud (experimentell) ──────────────────────────────
// Im Bild steht allgemein „KI-Programm“; Beispiele nur unter „Im Detail“.

function cloudAiWide(c) {
  const Y = 84;
  const h = c.row([['you', 128], ['program', 130], ['provider', OUT.w]]);
  const you = c.card('you', 24, Y, 128, h), pr = c.card('program', 258, Y, 130, h), mod = c.card('provider', OUT.x, Y, OUT.w, h);
  const cy = Y + h / 2, compB = Y + h + 12;
  const ask = c.beside(306, compB + 8, 134, c.text('mcpAsk'), { anchor: 'end' });
  const ans = c.beside(340, compB + 8, 150, c.text('mcpAnswer'));
  const yE = compB + Math.max(ask.h, ans.h) + 18;
  const eh = c.row([['et', 146], ['weather', OUT.w]]);
  const et = c.card('et', 250, yE, 146, eh), w = c.card('weather', OUT.x, yE, OUT.w, eh);
  const zb = et.b + 14;
  const body = c.zone(ZONE.x, ZONE.y, ZONE.w, zb - ZONE.y) + c.outside(OUT.cx, Y - 12, 'middle')
    + c.frame(246, 52, 154, compB - 52, 'setups.node.computer')
    + shapes.flow([[you.r, cy - 8], [pr.x, cy - 8]], { label: c.above(205, cy - 8, c.text('ask'), 84) })
    + shapes.flow([[pr.x, cy + 8], [you.r, cy + 8]], { label: c.below(205, cy + 8, c.text('answer'), 84) })
    + out([[pr.r, cy - 8], [mod.x, cy - 8]], c.above(512, cy - 8, c.text('toProvider'), 226))
    + out([[mod.x, cy + 8], [pr.r, cy + 8]], c.below(512, cy + 8, c.text('fromProvider'), 226))
    + shapes.flow([[313, pr.b], [313, yE]]) + shapes.flow([[333, yE], [333, pr.b]]) + ask.svg + ans.svg
    + ink([[you.cx, you.b], [you.cx, et.cy], [et.x, et.cy]], c.above(170, et.cy, c.text('confirm'), 150))
    + out([[et.r, et.cy], [w.x, et.cy]])
    + you.svg + pr.svg + mod.svg + et.svg + w.svg;
  return { w: 760, h: zb + 8, body };
}

function cloudAiNarrow(c) {
  const conf = c.beside(160, 30, 280, c.text('confirm'), { anchor: 'middle' });
  const uy = 30 + conf.h + 5, rowY = uy + 15;
  const hRow = c.row([['you', 138], ['et', 128]]);
  const you = c.card('you', 12, rowY, 138, hRow), et = c.card('et', 162, rowY, 128, hRow);
  const la = c.dirs(56, you.b + 6, [['↓', c.text('ask')], ['↑', c.text('answer')]], 94);
  const lb = c.dirs(198, you.b + 6, [['↑', c.text('mcpAsk')], ['↓', c.text('mcpAnswer')]], 92);
  const compTop = you.b + Math.max(la.h, lb.h) + 14, prY = compTop + 21;
  const pr = c.card('program', 18, prY, 266, 0, false);
  const compB = pr.b + 8, zb = compB + 10;
  const lc = c.dirs(58, zb + 6, [['↓', c.text('toProvider')], ['↑', c.text('fromProvider')]], 170);
  const cloudY = zb + 6 + lc.h + 10;
  const hOut = c.row([['provider', 146], ['weather', 150]]);
  const mod = c.card('provider', 4, cloudY, 146, hOut), w = c.card('weather', 166, cloudY, 150, hOut);
  const body = c.zone(4, 4, 312, zb - 4) + c.outside(310 - 14, zb + 17, 'end')
    + c.frame(10, compTop, 282, compB - compTop, 'setups.node.computer', 58)
    + conf.svg + ink([[you.cx, rowY], [you.cx, uy], [et.cx, uy], [et.cx, rowY]])
    + shapes.flow([[34, you.b], [34, prY]]) + shapes.flow([[48, prY], [48, you.b]]) + la.svg
    + shapes.flow([[176, prY], [176, et.b]]) + shapes.flow([[190, et.b], [190, prY]]) + lb.svg
    + out([[34, pr.b], [34, cloudY]]) + out([[48, cloudY], [48, pr.b]]) + lc.svg
    + out([[et.r, et.cy], [305, et.cy], [305, cloudY]])
    + you.svg + et.svg + pr.svg + mod.svg + w.svg;
  return { w: 320, h: mod.b + 6, body };
}

// ── Alles zusammen ───────────────────────────────────────────────────
// Der Energietracker in der Mitte, die Bausteine darum; was hinausgeht, unter der rechten Spalte hindurch.

function togetherWide(c) {
  const y1 = 56, L = 130, R = 144;
  const h = c.row([['ha', L], ['post', L], ['ai', R], ['you', R]]);
  const askH = c.beside(0, 0, 66, c.text('ask')).h;
  const y2 = y1 + h + Math.max(26, askH + 10);
  const ha = c.card('ha', 24, y1, L, h), p = c.card('post', 24, y2, L, h), a = c.card('ai', 466, y1, R, h), u = c.card('you', 466, y2, R, h);
  const e = c.card('et', 250, y1, 120, y2 + h - y1);
  const hOut = c.row([['cloud', OUT.w], ['weather', OUT.w]]);
  const clY = u.b + 14;
  const cl = c.card('cloud', OUT.x, clY, OUT.w, hOut), w = c.card('weather', OUT.x, clY + hOut + 10, OUT.w, hOut);
  const zb = w.cy + 14;
  const cy1 = ha.cy, cy2 = p.cy;
  const body = c.zone(ZONE.x, ZONE.y, ZONE.w, zb - ZONE.y) + c.outside(OUT.cx, clY - 12, 'middle')
    + shapes.flow([[ha.r, cy1 - 8], [e.x, cy1 - 8]], { label: c.above(202, cy1 - 8, c.text('push'), 90) })
    + shapes.flow([[e.x, cy1 + 8], [ha.r, cy1 + 8]], { label: c.below(202, cy1 + 8, c.text('back'), 90) })
    + shapes.flow([[p.r, cy2], [e.x, cy2]], { label: c.above(202, cy2, c.text('drop'), 90) })
    + shapes.flow([[a.x, cy1 - 8], [e.r, cy1 - 8]], { label: c.above(418, cy1 - 8, c.text('mcpAsk'), 90) })
    + shapes.flow([[e.r, cy1 + 8], [a.x, cy1 + 8]], { label: c.below(418, cy1 + 8, c.text('mcpAnswer'), 90) })
    + ink([[u.x, cy2], [e.r, cy2]], c.above(418, cy2, c.text('confirm'), 90))
    + shapes.flow([[u.cx, u.y], [u.cx, a.b]])
    + c.beside(u.cx + 6, a.b + 3, 66, c.text('ask')).svg
    + out([[330, e.b], [330, cl.cy], [cl.x, cl.cy]], c.above(480, cl.cy, c.text('toCloud'), 270))
    + out([[295, e.b], [295, w.cy], [w.x, w.cy]])
    + ha.svg + p.svg + a.svg + u.svg + e.svg + cl.svg + w.svg;
  return { w: 760, h: Math.max(zb, w.b) + 8, body };
}

function togetherNarrow(c) {
  const y1 = 34, A = 120, BX = 170;
  const h1 = c.row([['ha', A], ['post', A]]);
  const ha = c.card('ha', 12, y1, A, h1), p = c.card('post', BX, y1, A, h1);
  const l1 = c.dirs(56, ha.b + 6, [['↓', c.text('push')], ['↑', c.text('back')]], 108);
  const l2 = c.beside(198, p.b + 6, 92, c.text('drop'));
  const eY = ha.b + Math.max(l1.h, l2.h) + 14;
  const e = c.card('et', 12, eY, 278, 0, false);
  const l3 = c.dirs(56, e.b + 6, [['↑', c.text('mcpAsk')], ['↓', c.text('mcpAnswer')]], 108);
  const l4 = c.beside(198, e.b + 6, 92, c.text('confirm'));
  const y3 = e.b + Math.max(l3.h, l4.h) + 14;
  const h3 = c.row([['ai', A], ['you', A]]);
  const a = c.card('ai', 12, y3, A, h3), u = c.card('you', BX, y3, A, h3);
  const lAsk = c.beside(151, a.b + 4, 120, c.text('ask'), { anchor: 'middle' });
  const zb = a.b + 4 + lAsk.h + 8;
  const label = c.beside(118, zb + 13, 176, c.text('toCloud'));
  const cloudY = zb + 13 + label.h + 8;
  const hOut = c.row([['cloud', 146], ['weather', 150]]);
  const cl = c.card('cloud', 4, cloudY, 146, hOut), w = c.card('weather', 166, cloudY, 150, hOut);
  const body = c.zone(4, 4, 312, zb - 4) + c.outside(12, zb + 17)
    + shapes.flow([[34, ha.b], [34, eY]]) + shapes.flow([[48, eY], [48, ha.b]]) + l1.svg
    + shapes.flow([[190, p.b], [190, eY]]) + l2.svg
    + shapes.flow([[34, y3], [34, e.b]]) + shapes.flow([[48, e.b], [48, y3]]) + l3.svg
    + ink([[190, y3], [190, e.b]]) + l4.svg
    + shapes.flow([[u.x, u.cy], [a.r, u.cy]]) + lAsk.svg
    + out([[e.r, e.cy - 5], [305, e.cy - 5], [305, cloudY]])
    + out([[e.r, e.cy + 5], [297, e.cy + 5], [297, zb + 9], [110, zb + 9], [110, cloudY]]) + label.svg
    + ha.svg + p.svg + e.svg + a.svg + u.svg + cl.svg + w.svg;
  return { w: 320, h: cl.b + 6, body };
}

const WEATHER = { icon: 'cloud', name: 'setups.node.weather', sub: 'setups.node.weatherSub', port: 'Open-Meteo, HTTPS', outside: true };
const WEATHER_FLOW = { from: 'et', to: 'weather', label: 'setups.flow.weather', out: true };
const YOU = { icon: 'phone', name: 'setups.node.you', sub: 'setups.node.youSub' };

const SCENES = {
  'by-hand': {
    nodes: {
      meters:  { icon: 'meters', name: 'setups.node.meters' },
      you:     YOU,
      et:      { icon: 'nas', label: 'Energietracker', sub: 'setups.node.etAnywhere', port: ':8080' },
      weather: WEATHER,
    },
    flows: {
      read:    { from: 'meters', to: 'you', label: 'setups.flow.read' },
      enter:   { from: 'you', to: 'et', label: 'setups.flow.enter' },
      results: { from: 'et', to: 'you', label: 'setups.flow.results' },
      weather: WEATHER_FLOW,
    },
    wide: byHandWide, narrow: byHandNarrow, thumb: ['meters', 'phone', 'nas'],
  },
  inbox: {
    nodes: {
      post:    { icon: 'doc', name: 'setups.node.post', sub: 'setups.node.postSub' },
      you:     YOU,
      et:      { icon: 'nas', label: 'Energietracker', sub: 'setups.node.etInbox', port: ':8080 /api/inbox' },
      ai:      { icon: 'program', name: 'setups.node.aiHome', sub: 'setups.node.aiHomeSub', port: ':11434' },
      cloud:   { icon: 'cloud', name: 'setups.node.cloud', sub: 'setups.node.cloudSub', port: 'HTTPS', outside: true },
      weather: WEATHER,
    },
    flows: {
      drop:     { from: 'post', to: 'et', label: 'setups.flow.drop' },
      text:     { from: 'et', to: 'ai', label: 'setups.flow.text' },
      proposal: { from: 'ai', to: 'et', label: 'setups.flow.proposal' },
      check:    { from: 'you', to: 'et', label: 'setups.flow.check' },
      toCloud:  { from: 'et', to: 'cloud', label: 'setups.flow.toCloud', out: true },
      weather:  WEATHER_FLOW,
    },
    wide: inboxWide, narrow: inboxNarrow, thumb: ['doc', 'nas', 'program'],
  },
  'home-assistant': {
    nodes: {
      meters:  { icon: 'meters', name: 'setups.node.smartMeters' },
      ha:      { icon: 'house', label: 'Home Assistant', sub: 'setups.node.haSub', port: ':8123' },
      et:      { icon: 'nas', label: 'Energietracker', sub: 'setups.node.etHa', port: ':8080 /api/ingest' },
      evcc:    { icon: 'wallbox', label: 'evcc', sub: 'setups.node.evccSub' },
      you:     YOU,
      weather: WEATHER,
    },
    flows: {
      sense:   { from: 'meters', to: 'ha', label: 'setups.flow.sense' },
      push:    { from: 'ha', to: 'et', label: 'setups.flow.push' },
      back:    { from: 'et', to: 'ha', label: 'setups.flow.back' },
      charges: { from: 'evcc', to: 'et', label: 'setups.flow.charges' },
      browser: { from: 'you', to: 'et', label: 'setups.flow.browser' },
      weather: WEATHER_FLOW,
    },
    wide: haWide, narrow: haNarrow, thumb: ['meters', 'house', 'nas'],
  },
  'local-ai': {
    nodes: {
      you:     YOU,
      webui:   { icon: 'container', label: 'Open WebUI', sub: 'setups.node.webuiSub', port: ':3000 → 8080' },
      ollama:  { icon: 'program', label: 'Ollama', sub: 'setups.node.ollamaSub', port: ':11434' },
      et:      { icon: 'container', label: 'Energietracker', sub: 'setups.node.etLocal', port: ':8080 /api/mcp' },
      models:  { icon: 'cloud', name: 'setups.node.models', sub: 'setups.node.modelsSub', outside: true },
      weather: WEATHER,
    },
    flows: {
      ask:       { from: 'you', to: 'webui', label: 'setups.flow.ask' },
      answer:    { from: 'webui', to: 'you', label: 'setups.flow.answer' },
      prompt:    { from: 'webui', to: 'ollama', label: 'setups.flow.prompt' },
      reply:     { from: 'ollama', to: 'webui', label: 'setups.flow.reply' },
      mcpAsk:    { from: 'webui', to: 'et', label: 'setups.flow.mcpAsk' },
      mcpAnswer: { from: 'et', to: 'webui', label: 'setups.flow.mcpAnswer' },
      confirm:   { from: 'you', to: 'et', label: 'setups.flow.confirm' },
      model:     { from: 'models', to: 'ollama', label: 'setups.flow.model', out: true },
      weather:   WEATHER_FLOW,
    },
    wide: localAiWide, narrow: localAiNarrow, thumb: ['phone', 'container', 'program'],
  },
  'cloud-ai': {
    nodes: {
      you:      YOU,
      program:  { icon: 'program', name: 'setups.node.aiProgram', sub: 'setups.node.aiProgramSub' },
      et:       { icon: 'nas', label: 'Energietracker', sub: 'setups.node.etAnywhere', port: ':8080 /api/mcp' },
      provider: { icon: 'cloud', name: 'setups.node.provider', sub: 'setups.node.providerSub', port: 'HTTPS', outside: true },
      weather:  WEATHER,
    },
    flows: {
      ask:          { from: 'you', to: 'program', label: 'setups.flow.ask' },
      answer:       { from: 'program', to: 'you', label: 'setups.flow.answer' },
      mcpAsk:       { from: 'program', to: 'et', label: 'setups.flow.mcpAsk' },
      mcpAnswer:    { from: 'et', to: 'program', label: 'setups.flow.mcpAnswer' },
      confirm:      { from: 'you', to: 'et', label: 'setups.flow.confirm' },
      toProvider:   { from: 'program', to: 'provider', label: 'setups.flow.toProvider', out: true },
      fromProvider: { from: 'provider', to: 'program', label: 'setups.flow.fromProvider', out: true },
      weather:      WEATHER_FLOW,
    },
    wide: cloudAiWide, narrow: cloudAiNarrow, thumb: ['phone', 'program', 'cloud'],
  },
  together: {
    nodes: {
      ha:      { icon: 'house', label: 'Home Assistant', sub: 'setups.node.haTogether', port: ':8123' },
      post:    { icon: 'doc', name: 'setups.node.post', sub: 'setups.node.postSub' },
      et:      { icon: 'nas', label: 'Energietracker', sub: 'setups.node.etTogether', port: ':8080' },
      ai:      { icon: 'container', name: 'setups.node.aiHome', sub: 'setups.node.aiTogether', port: ':3000, :11434' },
      you:     YOU,
      cloud:   { icon: 'cloud', name: 'setups.node.cloud', sub: 'setups.node.cloudSub', port: 'HTTPS', outside: true },
      weather: WEATHER,
    },
    flows: {
      push:      { from: 'ha', to: 'et', label: 'setups.flow.push' },
      back:      { from: 'et', to: 'ha', label: 'setups.flow.back' },
      drop:      { from: 'post', to: 'et', label: 'setups.flow.drop' },
      mcpAsk:    { from: 'ai', to: 'et', label: 'setups.flow.mcpAsk' },
      mcpAnswer: { from: 'et', to: 'ai', label: 'setups.flow.mcpAnswer' },
      confirm:   { from: 'you', to: 'et', label: 'setups.flow.confirm' },
      ask:       { from: 'you', to: 'ai', label: 'setups.flow.ask' },
      toCloud:   { from: 'et', to: 'cloud', label: 'setups.flow.toCloudAll', out: true },
      weather:   WEATHER_FLOW,
    },
    wide: togetherWide, narrow: togetherNarrow, thumb: ['house', 'nas', 'container'],
  },
};

/**
 * Schaubild eines Wegs (role="img" mit Titel).
 * @param {string} id  aus SETUP_IDS
 * @param {{variant?: 'wide'|'narrow', detail?: boolean, tr?: Function}} [o]
 */
export function sceneSvg(id, { variant = 'wide', detail = false, tr = t } = {}) {
  const scene = SCENES[id];
  if (!scene) return '';
  const { w, h, body } = scene[variant](stage(scene, variant, detail, tr));
  const name = tr(`setups.scene.${SETUPS[id].key}.title`);
  const title = tr(detail ? 'setups.figureDetail' : 'setups.figure', { name });
  return svg(body, { viewBox: `0 0 ${w} ${Math.ceil(h)}`, title, className: `ill--scene ill--scene-${variant}` });
}

/**
 * Derselbe Inhalt als Text: alle Pfeile, getrennt nach „im Haus“ und „über die
 * Hausgrenze“ (Bildschirmleser, Suche).
 */
export function sceneFlows(id, tr = t) {
  const scene = SCENES[id];
  const name = (nodeId) => scene.nodes[nodeId].label || tr(scene.nodes[nodeId].name);
  const all = Object.values(scene?.flows || {}).map(f => ({ from: name(f.from), to: name(f.to), label: tr(f.label), out: !!f.out }));
  return { inside: all.filter(f => !f.out), across: all.filter(f => f.out) };
}

/** Kleines Bild für die Auswahl: nur Geräte und Pfeile, der Text daneben sagt alles (aria-hidden). */
export function sceneThumb(id) {
  const icons = SCENES[id]?.thumb || [];
  let x = 4, body = '';
  icons.forEach((icon, i) => {
    const ic = ICON[icon];
    body += ic.draw(x, (32 - ic.h) / 2);
    x += ic.w;
    if (i < icons.length - 1) { body += shapes.flow([[x + 3, 16], [x + 15, 16]]); x += 18; }
  });
  return svg(body, { viewBox: `0 0 ${Math.ceil(x + 4)} 32`, className: 'ill--thumb' });
}

// ── Kurzauswahl ──────────────────────────────────────────────────────

/** Die drei Fragen. Keine ist Pflicht; „Nichts davon“ ist eine gute Antwort. */
export const QUESTIONS = [
  { id: 'smart', options: ['yes', 'no'] },
  { id: 'post',  options: ['yes', 'no'] },
  { id: 'ai',    options: ['home', 'out', 'no'] },
];

/**
 * Antworten → passende Wege, in der Reihenfolge von SETUP_IDS. Ohne Antwort
 * leer; alles „nein“ führt zu „Von Hand“; zwei oder mehr Bausteine auch zu
 * „Alles zusammen“.
 * @param {{smart?: string, post?: string, ai?: string}} [answers]
 * @returns {string[]}
 */
export function setupsFor(answers = {}) {
  const a = answers || {};
  if (!QUESTIONS.some(q => q.options.includes(a[q.id]))) return [];
  const picks = new Set();
  if (a.smart === 'yes') picks.add('home-assistant');
  if (a.post === 'yes') picks.add('inbox');
  if (a.ai === 'home') picks.add('local-ai');
  if (a.ai === 'out') picks.add('cloud-ai');
  if (!picks.size) return ['by-hand'];
  if (picks.size >= 2) picks.add('together');
  return SETUP_IDS.filter(id => picks.has(id));
}

/** Die Antworten aus den Feldern lesen (Seite und Assistent). */
export function readAnswers(root) {
  const out = {};
  for (const q of QUESTIONS) out[q.id] = root.querySelector(`input[name="guide-${q.id}"]:checked`)?.value || '';
  return out;
}

/** Die drei Fragen als Felder. Vorbelegt ist nichts, jede Frage bleibt offen, bis du sie beantwortest. */
export function questionsHtml(answers = {}, tr = t) {
  const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
  return QUESTIONS.map(q => `<fieldset class="guide-q">
    <legend>${esc(tr(`setups.quick.${q.id}.question`))}</legend>
    <div class="guide-q__options">
      ${q.options.map(o => `<label class="guide-chip"><input type="radio" name="guide-${q.id}" value="${o}"${answers[q.id] === o ? ' checked' : ''}><span>${esc(tr(`setups.quick.${q.id}.${o}`))}</span></label>`).join('')}
    </div>
  </fieldset>`).join('');
}

// ── Was zu jedem Weg gehört ──────────────────────────────────────────

/** Sprungmarke je Sprache: Die Überschriften der Doku heißen auf Deutsch und Englisch verschieden. */
export const anchorFor = (anchor, locale = getLocale()) => (Array.isArray(anchor) ? anchor[locale === 'de' ? 0 : 1] : anchor || '');

/**
 * Bezeichnungen aus der App für die Texte, damit Wegweiser und Oberfläche nie
 * auseinanderlaufen. Jeder Text bekommt sie als Platzhalter.
 */
export const setupLabels = (tr = t) => ({
  settings: tr('nav.settings'),
  connections: tr('nav.connections'),
  homeAssistant: tr('connections.homeassistant.title'),
  reading: tr('connections.reading.title'),
  access: tr('settings.page.access.title'),
  data: tr('settings.page.data.title'),
  weather: tr('nav.weather'),
  inbox: tr('nav.inbox'),
  card: tr('settings.mcp.title'),
  sw: tr('settings.mcp.enabled'),
  read: tr('settings.security.scopeMcp'),
  write: tr('settings.security.scopeMcpWrite'),
  ids: tr('settings.mcp.shareIdentifiers'),
  originals: tr('settings.mcp.shareDocuments'),
  applyInApp: tr('settings.mcp.applyInApp'),
  proposals: tr('nav.todo'),
});

const ET_LINK = { scene: 'by-hand', label: 'setups.scene.byHand.title' };
const CARD_LINK = { app: '#/connections/ai', label: 'settings.mcp.title' };

/**
 * Was zu jedem Weg gehört. Texte stehen unter `setups.scene.<key>.…`:
 * `get1…`, `limit1…`, `step1…`, `note1…`, `err1.symptom`/`.fix`, `row.<id>.label`/`.note`.
 * Ein Link ist `{doc, anchor?, label}`, `{app, label}`, `{url, label}` oder `{scene, label}`;
 * `anchor` ist sprachneutral (Text) oder `[deutsch, englisch]`.
 */
export const SETUPS = {
  'by-hand': {
    key: 'byHand',
    get: 4, limits: 3,
    steps: [
      [{ doc: 'docker', label: 'setups.link.docker' }, { doc: 'install', label: 'setups.link.install' }],
      [{ doc: 'setup', label: 'help.docs.setup' }],
      [{ app: '#/zaehlerstaende', label: 'nav.readings' }],
      [{ app: '#/contracts', label: 'nav.contracts' }],
      [{ app: '#/connections/weather', label: 'nav.weather' }],
      [{ doc: 'phone', label: 'setups.link.phone' }],
    ],
    detail: {
      rows: [
        { id: 'docker', addr: 'http://energietracker.local:8080' },
        { id: 'web', addr: 'http://energietracker.local/energietracker/' },
      ],
      code: 'docker run -d --name energietracker \\\n  --restart unless-stopped -p 8080:80 \\\n  -v "$PWD/data:/data" \\\n  ghcr.io/bingerminger/energietracker:3',
      notes: 4,
      errors: 4,
      sources: [
        { doc: 'docker', label: 'setups.link.docker' },
        { doc: 'install', label: 'setups.link.install' },
        { doc: 'troubleshoot', label: 'help.docs.troubleshoot' },
      ],
    },
  },
  inbox: {
    key: 'inbox',
    get: 4, limits: 4,
    steps: [
      [{ app: '#/inbox', label: 'nav.inbox' }],
      [{ app: '#/connections/reading', label: 'connections.reading.title' }, { doc: 'inbox', anchor: ['auslesen-einrichten', 'setting-up-reading'], label: 'setups.link.reading' }],
      [{ doc: 'inbox', anchor: ['vorschau-und-übernehmen', 'preview-and-taking-over'], label: 'setups.link.review' }],
      [{ doc: 'inbox', anchor: ['andere-wege-in-den-posteingang', 'other-ways-into-the-inbox'], label: 'setups.link.otherWays' }],
    ],
    detail: {
      rows: [
        { id: 'ollama', addr: 'http://mein-rechner.local:11434' },
        { id: 'lmstudio', addr: 'http://mein-rechner.local:1234/v1' },
        { id: 'api', addr: 'http://energietracker.local:8080/api/inbox?name=rechnung.pdf' },
      ],
      code: 'curl -H "Authorization: Bearer etk_…" \\\n  -H "Content-Type: application/pdf" \\\n  --data-binary @rechnung.pdf \\\n  "http://energietracker.local:8080/api/inbox?name=rechnung.pdf"',
      notes: 3,
      errors: 3,
      sources: [
        { doc: 'inbox', label: 'nav.inbox' },
        { doc: 'ocr', anchor: ['ollama-einrichten', 'set-up-ollama'], label: 'setups.link.ollama' },
        { doc: 'inbox', anchor: ['datenschutz-was-wohin-geht', 'privacy-what-goes-where'], label: 'setups.block.data' },
      ],
    },
  },
  'home-assistant': {
    key: 'homeAssistant',
    get: 5, limits: 3,
    steps: [
      [{ doc: 'homeAssistant', anchor: ['als-app-in-home-assistant-os', 'as-an-app-in-home-assistant-os'], label: 'setups.link.haApp' }, { doc: 'docker', label: 'setups.link.docker' }],
      [{ app: '#/connections/homeassistant', label: 'connections.homeassistant.title' }],
      [{ doc: 'homeAssistant', anchor: ['schritt-3--rest-command-in-home-assistant', 'step-3--rest-command-in-home-assistant'], label: 'help.docs.homeAssistant' }],
      [{ doc: 'homeAssistant', anchor: ['hacs-integration-v3120', 'hacs-integration-v3120'], label: 'setups.link.hacs' }],
      [{ doc: 'homeAssistant', anchor: ['schritt-6--benachrichtigungen-an-home-assistant-v390', 'step-6--notifications-to-home-assistant-v390'], label: 'setups.link.notify' }],
      [{ doc: 'evcc', label: 'setups.link.evcc' }],
    ],
    detail: {
      rows: [
        { id: 'ingest', addr: 'http://energietracker.local:8080/api.php/api/ingest' },
        { id: 'summary', addr: 'http://energietracker.local:8080/api/summary' },
        { id: 'app', addr: 'http://homeassistant.local:8095' },
      ],
      code: [
        'rest_command:',
        '  energietracker_push:',
        '    url: "http://energietracker.local:8080/api.php/api/ingest"',
        '    method: POST',
        '    headers:',
        '      Authorization: !secret energietracker_auth',
        '      Content-Type: "application/json"',
      ].join('\n'),
      notes: 3,
      errors: 3,
      sources: [
        { doc: 'homeAssistant', label: 'help.docs.homeAssistant' },
        { doc: 'evcc', label: 'setups.link.evcc' },
        { doc: 'otherSystems', label: 'setups.link.otherSystems' },
      ],
    },
  },
  'local-ai': {
    key: 'localAi',
    get: 4, limits: 5,
    getLinks: [{ doc: 'aiAssistant', anchor: 'mehrwert', label: 'setups.link.aiValue' }, { doc: 'aiAssistant', anchor: 'wer-bestaetigt', label: 'setups.link.aiConfirm' }],
    steps: [
      [ET_LINK],
      [{ doc: 'ocr', anchor: ['ollama-einrichten', 'set-up-ollama'], label: 'setups.link.ollama' }],
      [{ url: 'https://docs.openwebui.com/getting-started/quick-start/', label: 'setups.link.openWebui' }],
      [CARD_LINK, { doc: 'aiAssistant', anchor: ['schritt-2--schlüssel-anlegen', 'step-2--create-a-key'], label: 'help.docs.aiAssistant' }],
      [{ doc: 'aiAssistant', anchor: ['open-webui', 'open-webui'], label: 'help.docs.aiAssistant' }],
      [{ doc: 'aiAssistant', anchor: ['beispiele', 'examples'], label: 'setups.link.examples' }],
    ],
    detail: {
      rows: [
        { id: 'browser', addr: 'http://mein-rechner.local:3000' },
        { id: 'ollama', addr: 'http://host.docker.internal:11434' },
        { id: 'sameHost', addr: 'http://host.docker.internal:8080/api/mcp' },
        { id: 'otherHost', addr: 'http://energietracker.local:8080/api/mcp' },
        { id: 'apache', addr: 'http://energietracker.local/energietracker/api.php/api/mcp' },
      ],
      code: [
        'services:',
        '  energietracker:',
        '    image: ghcr.io/bingerminger/energietracker:3',
        '    restart: unless-stopped',
        '    ports: ["8080:80"]',
        '    volumes: ["./data:/data"]',
        '  open-webui:',
        '    image: ghcr.io/open-webui/open-webui:main',
        '    restart: always',
        '    ports: ["3000:8080"]',
        '    environment:',
        '      WEBUI_SECRET_KEY: "<openssl rand -hex 32>"',
        '    extra_hosts:',
        '      - "host.docker.internal:host-gateway"',
        '    volumes: ["open-webui:/app/backend/data"]',
        'volumes:',
        '  open-webui:',
      ].join('\n'),
      notes: 5,
      errors: 7,
      sources: [
        { doc: 'aiAssistant', label: 'help.docs.aiAssistant' },
        { url: 'https://docs.openwebui.com/features/extensibility/mcp', label: 'setups.link.openWebuiMcp' },
        { url: 'https://ollama.com/blog/ollama-is-now-available-as-an-official-docker-image', label: 'setups.link.ollamaDocker' },
      ],
    },
  },
  'cloud-ai': {
    key: 'cloudAi',
    get: 3, limits: 4,
    getLinks: [{ doc: 'aiAssistant', anchor: 'wer-bestaetigt', label: 'setups.link.aiConfirm' }],
    steps: [
      [ET_LINK],
      [{ doc: 'aiAssistant', anchor: 'programme', label: 'setups.link.programs' }],
      [CARD_LINK, { doc: 'aiAssistant', anchor: ['schritt-2--schlüssel-anlegen', 'step-2--create-a-key'], label: 'help.docs.aiAssistant' }],
      [{ doc: 'aiAssistant', anchor: ['andere-programme-experimentell', 'other-programs-experimental'], label: 'help.docs.aiAssistant' }],
      [{ doc: 'aiAssistant', anchor: ['sicherheit-und-datenschutz', 'security-and-privacy'], label: 'setups.block.data' }],
    ],
    detail: {
      rows: [
        { id: 'program', addr: 'http://energietracker.local:8080/api/mcp' },
        { id: 'public', addr: 'https://energie.example.org/api/mcp' },
      ],
      notes: 3,
      errors: 3,
      sources: [
        { doc: 'aiAssistant', anchor: ['aus-der-cloud-nur-mit-eigenem-https-zugang', 'from-the-cloud-only-with-your-own-https-access'], label: 'setups.link.cloudAccess' },
        { doc: 'aiAssistant', label: 'help.docs.aiAssistant' },
      ],
    },
  },
  together: {
    key: 'together',
    get: 4, limits: 3,
    steps: [
      [ET_LINK],
      [{ scene: 'home-assistant', label: 'setups.scene.homeAssistant.title' }],
      [{ scene: 'inbox', label: 'setups.scene.inbox.title' }],
      [{ scene: 'local-ai', label: 'setups.scene.localAi.title' }, { scene: 'cloud-ai', label: 'setups.scene.cloudAi.title' }],
    ],
    detail: {
      rows: [
        { id: 'et', addr: 'http://energietracker.local:8080' },
        { id: 'webui', addr: 'http://mein-rechner.local:3000' },
        { id: 'ollama', addr: 'http://mein-rechner.local:11434' },
        { id: 'ha', addr: 'http://homeassistant.local:8123' },
      ],
      notes: 3,
      errors: 0,
      sources: [
        { doc: 'useCases', label: 'help.docs.useCases' },
        { doc: 'homeAssistant', label: 'help.docs.homeAssistant' },
        { doc: 'aiAssistant', label: 'help.docs.aiAssistant' },
      ],
    },
  },
};
