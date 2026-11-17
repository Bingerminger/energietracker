// Die Seiten als HTML: Startseite und „Über das Projekt und Datenschutz“, je Deutsch und Englisch.
// Kein Framework, keine Abhängigkeit. Texte aus texte.mjs, Grafiken aus icons-app.mjs (die Datei der
// App, unverändert kopiert), Maße der Bilder aus assets/img/quellen.json und den SVG-Dateien.
// Alle Verweise innerhalb der Seite beginnen mit „/“: Die Seite liegt an der Wurzel von
// bingerminger.github.io (und in der Vorschau an der Wurzel von php -S).
import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import { ICONS } from './icons-app.mjs';
import * as X from './texte.mjs';

export const SITE = 'https://bingerminger.github.io/energietracker/';
// Die Seite liegt seit dem Umbau der Projektseiten unter /energietracker/ (an der Wurzel von
// bingerminger.github.io steht die Übersicht aller Projekte). tools/build.mjs setzt BASE vor
// jeden Wurzelpfad der Ausgabe; die Quelltexte bleiben wurzelrelativ.
export const BASE = '/energietracker/';
/** Farben der Verbrauchsarten aus der SSOT der App (src/Config/Utilities.php), wie im Logo. */
const RING = ['#22d3ee', '#10b981', '#3b82f6', '#f43f5e', '#f59e0b'];
/** Wo jede Seite liegt */
export const SEITEN = {
  start: { de: '/', en: '/en/' },
  ueber: { de: '/ueber/', en: '/en/about/' },
};
export const DATEI = (pfad) => (pfad === '/' ? 'index.html' : `${pfad.slice(1)}index.html`);
const SCHMAL = '(max-width: 599.98px)';
const DUNKEL = '(prefers-color-scheme: dark)';
/** Animationen nur ohne „Bewegung reduzieren“; sonst gilt das Rückfallbild */
export const BEWEGT = '(prefers-reduced-motion: no-preference)';
/** Animationen und ihre Rückfallbilder (v3.13.0): tools/build-animations.mjs im Haupt-Repo */
export const ANIM = 'assets/img/anim';

export const esc = (s) => String(s)
  .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

/** Auszeichnung wie rich() in der App: erst maskieren, dann `Code`, [Text](Adresse), **fett**. */
export function rich(s) {
  const codes = [];
  let h = esc(s).replace(/`([^`]+)`/g, (_m, c) => { codes.push(c); return `\u0000${codes.length - 1}\u0000`; });
  h = h.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (_m, t, u) => `<a href="${u}">${t}</a>`);
  h = h.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
  return h.replace(/\u0000(\d+)\u0000/g, (_m, i) => `<code>${codes[Number(i)]}</code>`);
}

export function rende(lang, art, root) {
  const used = new Set();
  const claims = new Set();
  const other = lang === 'de' ? 'en' : 'de';

  /** Text einer Sprache; merkt sich die Belege. Ohne Kennung bricht der Bau ab. */
  const tx = (t) => {
    if (!t || !Array.isArray(t.c) || t.c.length === 0) throw new Error(`Aussage ohne Beleg-Kennung: ${JSON.stringify(t)}`);
    if (typeof t[lang] !== 'string' || !t[lang]) throw new Error(`Text fehlt (${lang}): ${JSON.stringify(t)}`);
    for (const c of t.c) if (c !== '-') claims.add(c);
    return t[lang];
  };
  const dc = (...ts) => {
    const ids = [...new Set(ts.flatMap(t => t.c).filter(c => c !== '-'))];
    return ids.length ? ` data-c="${ids.join(' ')}"` : '';
  };
  const R = (t) => rich(tx(t));
  const E = (t) => esc(tx(t));
  const P = (t, cls = '') => `<p${cls ? ` class="${cls}"` : ''}${dc(t)}>${R(t)}</p>`;
  const ico = (name, { size = 0, cls = '', art: a = '' } = {}) => {
    if (!ICONS[name]) throw new Error(`Grafik fehlt: ${name}`);
    used.add(name);
    const k = ['ico', size ? `ico--${size}` : '', a ? `ico--art art-${a}` : '', cls].filter(Boolean).join(' ');
    return `<svg class="${k}" aria-hidden="true" focusable="false"><use href="#i-${name}"/></svg>`;
  };
  const href = (h) => (typeof h === 'function' ? h(lang) : h);
  const knopf = (url, t, cls = '') => `<a class="knopf${cls ? ` ${cls}` : ''}" href="${esc(url)}"${dc(t)}>${E(t)}</a>`;

  // ── Bilder: Maße und Fassungen hell/dunkel ──────────────────────────
  const manifest = JSON.parse(readFileSync(join(root, 'assets/img/quellen.json'), 'utf8'));
  const dims = (file) => {
    if (file.endsWith('.svg')) {
      const m = readFileSync(join(root, file), 'utf8').slice(0, 300).match(/width="(\d+)" height="(\d+)"/);
      if (!m) throw new Error(`Maße fehlen: ${file}`);
      return [Number(m[1]), Number(m[2])];
    }
    const e = manifest.bilder.find(b => b.datei === file);
    if (!e) throw new Error(`Bild nicht in quellen.json: ${file}`);
    return [e.breite, e.hoehe];
  };
  const da = (file) => existsSync(join(root, file));
  /** Fassungen eines Bildes: Aufnahme (name) oder Schaubild (wegweiser:name) */
  const fassungen = (name) => {
    if (name.startsWith('wegweiser:')) {
      const n = name.slice(10), b = `assets/img/wegweiser/${n}.${lang}`;
      return { hell: `${b}.hell.svg`, dunkel: `${b}.dunkel.svg`, hellSchmal: `${b}.narrow.hell.svg`, dunkelSchmal: `${b}.narrow.dunkel.svg` };
    }
    const hell = `assets/img/screens/${name}.${lang}.webp`;
    const dunkel = `assets/img/screens/${name}-dunkel.${lang}.webp`;
    return { hell, dunkel: da(dunkel) ? dunkel : '' };
  };
  /**
   * <picture>: ohne Skript gilt prefers-color-scheme; mit Wahl im Umschalter schreibt seite.js
   * das media der Quellen mit data-thema um (dunkel erzwingen: data-breite oder „all“, hell: „not all“).
   */
  const picture = (f, alt, { lazy = true, prio = false } = {}) => {
    const src = (file, media, thema, breite = '') => {
      const [w, h] = dims(file);
      return `<source media="${media}" srcset="/${file}" width="${w}" height="${h}"${thema ? ` data-thema="dunkel"${breite ? ` data-breite="${breite}"` : ''}` : ''}>`;
    };
    const [w, h] = dims(f.hell);
    return '<picture>'
      + (f.dunkelSchmal ? src(f.dunkelSchmal, `${SCHMAL} and ${DUNKEL}`, true, SCHMAL) : '')
      + (f.hellSchmal ? src(f.hellSchmal, SCHMAL, false) : '')
      + (f.dunkel ? src(f.dunkel, DUNKEL, true) : '')
      + `<img src="/${f.hell}" width="${w}" height="${h}" alt="${esc(alt)}"${lazy ? ' loading="lazy"' : ''}${prio ? ' fetchpriority="high"' : ''} decoding="async"></picture>`;
  };
  /**
   * Animation einer Szene (v3.13.0): animiertes WebP, nur ohne „Bewegung reduzieren“. Sonst das Rückfallbild
   * (dieselbe Szene, gleiche Maße) aus assets/img/screens/. Kein GIF (Entscheidung 2026-11-16): Wer die
   * WebP-Bilder der Seite zeigt, spielt auch animiertes WebP. Dunkle Quellen tragen data-thema und data-breite wie die übrigen Bilder: Erzwingt der
   * Umschalter Dunkel, gilt für die animierte Quelle nur noch die Bedingung „Bewegung“ (seite.js).
   * Ein Knopf hält die Animation an (seite.js), ohne Skript bleibt er verborgen.
   */
  const animation = (szene, alt) => {
    const a = (v, ext) => `${ANIM}/${szene}${v}.${lang}.${ext}`;
    const hell = `assets/img/screens/${szene}.${lang}.webp`, dunkel = `assets/img/screens/${szene}-dunkel.${lang}.webp`;
    const [w, h] = dims(hell);
    const src = (file, media, type, thema) => `<source media="${media}" srcset="/${file}"${type ? ` type="${type}"` : ''} width="${w}" height="${h}"`
      + `${thema ? ` data-thema="dunkel"${thema === true ? '' : ` data-breite="${thema}"`}` : ''}>`;
    // Der Link öffnet die Animation in voller Größe (am Handy sind die Zahlen sonst zu klein); der Knopf steht daneben
    return `<span class="bewegt"><a class="bewegt__gross" href="/${a('', 'webp')}"><picture>`
      + src(a('-dunkel', 'webp'), `${BEWEGT} and ${DUNKEL}`, 'image/webp', BEWEGT)
      + src(a('', 'webp'), BEWEGT, 'image/webp')
      + src(dunkel, DUNKEL, '', true)
      + `<img src="/${hell}" width="${w}" height="${h}" alt="${esc(alt)}" loading="lazy" decoding="async"></picture>`
      + `<span class="unsichtbar"> ${E(X.UI.gross)}</span></a>`
      + `<button type="button" class="knopf knopf--klein bewegt__knopf" hidden data-bewegt data-an="${E(X.UI.anhalten)}" data-aus="${E(X.UI.abspielen)}">`
      + `${ico('pause', { cls: 'bewegt__pause' })}${ico('abspielen', { cls: 'bewegt__spielen' })}<span>${E(X.UI.anhalten)}</span></button></span>`;
  };
  /** Figur mit Animation: Rahmen, Animation, Bildunterschrift; der Rahmen öffnet die Animation in voller Größe */
  const animFigur = (a) => `<figure class="bild bild--bewegt"${dc(a.alt)}>`
    + `<div class="bild__rahmen">${animation(a.anim, tx(a.alt))}</div>`
    + (a.text ? `<figcaption${dc(a.text)}>${R(a.text)}</figcaption>` : '') + '</figure>';

  const figur = (name, alt, text, { cls = '' } = {}) => {
    const f = fassungen(name);
    return `<figure class="bild${name.startsWith('wegweiser:') ? ' bild--schaubild' : ''}${cls ? ` ${cls}` : ''}"${dc(alt)}>`
      + `<a class="bild__rahmen" href="/${f.hell}">${picture(f, tx(alt))}<span class="unsichtbar"> ${E(X.UI.gross)}</span></a>`
      + (text ? `<figcaption${dc(text)}>${R(text)}</figcaption>` : '') + '</figure>';
  };

  const logo = () => {
    const cx = 12, cy = 14.3, r = 3.55, C = 2 * Math.PI * r, seg = C / RING.length, gap = 0.95;
    const ring = RING.map((c, i) => {
      const rot = -90 + (360 / RING.length) * i + (gap / 2 / C) * 360;
      return `<circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="${c}" stroke-width="2.1" stroke-dasharray="${(seg - gap).toFixed(3)} ${(C - seg + gap).toFixed(3)}" transform="rotate(${rot.toFixed(2)} ${cx} ${cy})"/>`;
    }).join('');
    return `<svg class="logo" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path class="logo__fl" d="M5 9.7 12 3.5l7 6.2v10.8H5z"/><path class="logo__ln" d="M2.8 11.7 12 3.5l9.2 8.2M5 9.7v10.8h14V9.7"/>${ring}</svg>`;
  };

  const hier = SEITEN[art][lang];
  const dort = SEITEN[art][other];
  const start = SEITEN.start[lang];
  const anker = (a) => (art === 'start' ? a : `${start}${a}`);

  // ── Kopf ──────────────────────────────────────────────────────────
  const sprachen = `<div class="sprache" role="group" aria-label="${E(X.UI.sprache)}">`
    + `<a href="${SEITEN[art].de}" hreflang="de" lang="de"${lang === 'de' ? ' aria-current="page"' : ''} data-sprache="de">Deutsch</a>`
    + `<a href="${SEITEN[art].en}" hreflang="en" lang="en"${lang === 'en' ? ' aria-current="page"' : ''} data-sprache="en">English</a></div>`;
  // Umschalter wie in der App: Grafik zeigt, was gilt (Sonne hell, Mond dunkel), „A“ heißt: folgt dem System
  const thema = `<button type="button" class="thema" id="thema" hidden aria-label="${esc(tx(X.UI.thema).replace('{mode}', tx(X.UI.themaSystem)))}"`
    + ` data-label="${E(X.UI.thema)}" data-system="${E(X.UI.themaSystem)}" data-light="${E(X.UI.themaHell)}" data-dark="${E(X.UI.themaDunkel)}"`
    + ` data-meldung-system="${E(X.UI.themaMeldungSystem)}" data-meldung-light="${E(X.UI.themaMeldungHell)}" data-meldung-dark="${E(X.UI.themaMeldungDunkel)}">`
    + `${ico('sonne', { cls: 'thema__sonne' })}${ico('mond', { cls: 'thema__mond' })}<span class="thema__a" aria-hidden="true">${E(X.UI.themaA)}</span></button>`;
  const kopf = `<a class="sprung" href="#inhalt">${E(X.UI.sprung)}</a>
<div class="sprachhinweis" id="sprachhinweis" hidden lang="${other}"><div class="wrap"><div class="infobox infobox--info">${ico('globus', { size: 20 })}<p class="infobox__text">${E(X.UI.hinweis)} <a href="${dort}" data-sprache="${other}">${E(X.UI.hinweisLink)}</a></p><button type="button" class="knopf knopf--klein" data-zu>${E(X.UI.hinweisZu)}</button></div></div></div>
<header class="kopf"><div class="wrap kopf__innen">
<a class="marke" href="${start}">${logo()}<span>Energietracker</span></a>
<nav class="nav" aria-label="${E(X.UI.inhalt)}">${X.NAV.map(n => `<a href="${anker(n.href)}">${E(n.text)}</a>`).join('')}</nav>
<div class="kopf__rechts">${knopf(X.demo(lang), X.UI.demoStarten, 'knopf--haupt knopf--klein kopf__demo')}${sprachen}${thema}</div>
</div></header>`;

  // ── Fuß ────────────────────────────────────────────────────────────
  const F = X.FUSS;
  const fuss = `<footer class="fuss"><div class="wrap fuss__raster">
<div><a class="marke" href="${start}">${logo()}<span>Energietracker</span></a>${P(F.lizenz)}
<p><a href="${SEITEN.ueber[lang]}">${E(X.UI.ueber)}</a></p>
<p><a href="${SEITEN.ueber[lang]}#u-impressum">${E(X.UI.impressum)}</a></p></div>
<div>${P(F.seite)}${P(F.marken)}
<p class="klein"${dc(F.schrift)}>${R(F.schrift)} <a href="/assets/fonts/OFL.txt">OFL</a>. <a href="${X.REPO}/blob/main/TRADEMARKS.md">${E(F.marke)}</a>. <a href="${X.REPO}/blob/main/LICENSE">${E(F.lizenzLink)}</a>.</p></div>
${sprachen}
</div></footer>
<div class="meldung" id="meldung" role="status" aria-live="polite"></div>`;

  const main = art === 'start' ? startseite() : ueberseite();

  function startseite() {
    const H = X.HELD;
    const mac = fassungen('uebersicht'), handy = fassungen('iphone');
    const held = `<section class="held" aria-labelledby="titel"><div class="wrap held__raster">
<div class="held__text">
<p class="abzeichen-reihe"><a class="abzeichen abzeichen--neu" href="#neu"${dc(H.neu)}>${ico('neu', { size: 16 })}<span>${R(H.neu)}</span></a><a class="abzeichen" href="${X.REPO}/blob/main/LICENSE"${dc(H.frei)}>${E(H.frei)}</a></p>
<h1 id="titel"${dc(H.h1)}>${R(H.h1)}</h1>
${P(H.lead, 'lead')}
<p class="knoepfe" id="held-knoepfe">${knopf(X.demo(lang), X.UI.demoStarten, 'knopf--haupt knopf--gross')}${knopf('#installieren', X.UI.installieren, 'knopf--gross')}</p>
${P(H.demoNote, 'klein')}
</div>
<figure class="geraete"${dc(H.bildAlt, H.bildAltHandy)}><div class="geraete__buehne">
<a class="geraete__mac bild__rahmen" href="/${mac.hell}">${picture(mac, tx(H.bildAlt), { lazy: false, prio: true })}<span class="unsichtbar"> ${E(X.UI.gross)}</span></a>
<a class="geraete__handy bild__rahmen" href="/${handy.hell}">${picture(handy, tx(H.bildAltHandy), { lazy: false })}<span class="unsichtbar"> ${E(X.UI.gross)}</span></a></div>
<figcaption${dc(H.bildText)}>${R(H.bildText)}</figcaption></figure>
</div></section>`;

    const S = X.SCHRITTE;
    const schritte = `<section class="abschnitt abschnitt--flach" id="so" aria-labelledby="so-t"><div class="wrap">
<h2 id="so-t">${E(S.h2)}</h2>${P(S.lead, 'abschnitt__lead')}
<ol class="schritte">${S.liste.map(s => `<li${dc(s.text)}><span class="schritte__kopf">${ico(s.icon, { size: 24 })}<b>${E(s.titel)}</b></span><span>${R(s.text)}</span></li>`).join('')}</ol>
</div></section>`;

    const L = X.SLIDER, n = L.folien.length;
    const stand = (i) => tx(X.UI.folie).replace('{n}', String(i + 1)).replace('{m}', String(n));
    const slider = `<section class="abschnitt" id="funktionen" aria-labelledby="funktionen-t"><div class="wrap">
<h2 id="funktionen-t">${E(L.h2)}</h2>${P(L.lead, 'abschnitt__lead')}
<div class="slider" data-slider aria-roledescription="${E(X.UI.karussell)}" aria-labelledby="funktionen-t">
<div class="slider__reiter" role="tablist" aria-label="${E(L.reiter)}">${L.folien.map((f, i) => `<a role="tab" id="reiter-${f.id}" href="#folie-${f.id}" aria-controls="folie-${f.id}" aria-selected="${i === 0}">${E(f.frage)}</a>`).join('')}</div>
<div class="slider__spur" data-spur>${L.folien.map((f, i) => {
      // v3.13.0 — `anim`: Animation mit Rückfallbild statt eines Bildes (Link und Knopf zum Anhalten in animation())
      const medium = f.anim
        ? animation(f.anim, tx(f.alt))
        : f.video
          ? `<video controls preload="none" poster="/${f.video.poster}" src="/${f.video.src}"></video>`
          : `<a href="/${fassungen(f.bild).hell}">${picture(fassungen(f.bild), tx(f.alt))}<span class="unsichtbar"> ${E(X.UI.gross)}</span></a>`;
      return `<figure class="folie${f.handy ? ' folie--handy' : ''}" id="folie-${f.id}" role="tabpanel" aria-labelledby="reiter-${f.id}" aria-roledescription="${E(X.UI.folieRolle)}"${dc(f.alt)}>`
        + `<div class="folie__medium">${medium}</div>`
        + `<figcaption class="folie__text"><span class="folie__zahl">${esc(stand(i))}</span><b class="folie__frage">${E(f.frage)}</b>`
        + `<span${dc(f.text)}>${R(f.text)}</span> <a class="folie__demo" href="${esc(X.demo(lang, f.persona, f.route))}"${dc(L.demo)}>${E(L.demo)}</a></figcaption></figure>`;
    }).join('')}</div>
<div class="slider__steuerung" hidden><button type="button" class="knopf knopf--klein" data-zurueck aria-label="${E(X.UI.zurueck)}">${ico('links')}</button><span class="slider__stand unsichtbar" aria-live="polite" data-stand="${E(X.UI.folie)}">${esc(stand(0))}</span><button type="button" class="knopf knopf--klein" data-weiter aria-label="${E(X.UI.weiter)}">${ico('rechts')}</button></div>
</div>
</div></section>`;

    // v3.13.0 — Neu in 3.13: was dazukam, ehrlich mit dem, was noch aus ist, und zwei Animationen
    const NE = X.NEU;
    const neu = `<section class="abschnitt" id="neu" aria-labelledby="neu-t"><div class="wrap">
<h2 id="neu-t">${E(NE.h2)}</h2>${P(NE.lead, 'abschnitt__lead')}
<div class="neu__bilder">${NE.bilder.map(animFigur).join('')}</div>
<ul class="zeilen zeilen--2">${NE.liste.map(z => `<li${dc(z.text)}>${ico(z.icon, { size: 20 })}<span>${R(z.text)}</span></li>`).join('')}</ul>
<div class="infobox infobox--info"${dc(NE.ehrlich)}>${ico('info', { size: 20 })}<p class="infobox__text">${R(NE.ehrlich)}</p></div>
<p class="knoepfe neu__knoepfe">${knopf(X.demo(lang, NE.demo.persona, NE.demo.route), NE.demo.text)}<a href="${esc(href(NE.changelog.href))}">${E(NE.changelog.text)}</a></p>
</div></section>`;

    const N = X.NUTZEN;
    const nutzen = `<section class="abschnitt abschnitt--flach" id="nutzen" aria-labelledby="nutzen-t"><div class="wrap">
<h2 id="nutzen-t">${E(N.h2)}</h2>${P(N.lead, 'abschnitt__lead')}
<ul class="zeilen zeilen--3">${N.liste.map(z => `<li${dc(z.text)}>${ico(z.icon, { size: 20 })}<span>${R(z.text)}</span></li>`).join('')}</ul>
</div></section>`;

    const A = X.ARTEN;
    const arten = `<section class="abschnitt" id="arten" aria-labelledby="arten-t"><div class="wrap">
<h2 id="arten-t">${E(A.h2)}</h2>${P(A.lead, 'abschnitt__lead')}
<ul class="arten">${A.liste.map(a => `<li${dc(a.name, a.text)}>${ico(`art-${a.key}`, { size: 24, art: a.key })}<b>${E(a.name)}</b><span>${E(a.text)}</span></li>`).join('')}</ul>
</div></section>`;

    const W = X.WEGE;
    const wege = `<section class="abschnitt abschnitt--flach" id="wege" aria-labelledby="wege-t"><div class="wrap">
<h2 id="wege-t">${E(W.h2)}</h2>${P(W.lead, 'abschnitt__lead')}
<ul class="karten">${W.karten.map(k => `<li id="${k.id}" class="karte">
<h3 class="karte__titel">${ico(k.icon, { size: 24, art: k.art || '' })}<span>${E(k.titel)}</span></h3>
${P(k.frage, 'karte__frage')}
<ul class="liste">${k.punkte.map(p => `<li${dc(p)}>${R(p)}</li>`).join('')}</ul>
${k.ehrlich ? `<p class="karte__ehrlich"${dc(k.ehrlich)}>${ico('info', { size: 16 })}<span>${R(k.ehrlich)}</span></p>` : ''}
<p class="karte__links">${knopf(X.demo(lang, k.persona, k.route), W.demo)}${k.anleitung ? `<a href="${esc(href(k.anleitung.href))}">${E(k.anleitung.text)}</a>` : ''}</p>
</li>`).join('')}</ul>
</div></section>`;

    const HA = X.HA;
    const ha = `<section class="abschnitt" id="home-assistant" aria-labelledby="ha-t"><div class="wrap">
<h2 id="ha-t">${E(HA.h2)}</h2>${P(HA.lead, 'abschnitt__lead')}
<div class="ha">
<div class="ha__text">
<ul class="zeilen zeilen--ha">${HA.punkte.map(z => `<li${dc(z.text)}>${ico(z.icon, { size: 20 })}<span>${R(z.text)}</span></li>`).join('')}</ul>
<h3>${E(HA.schritteH3)}</h3>
<ol class="nummern">${HA.schritte.map(s => `<li${dc(s)}>${R(s)}</li>`).join('')}</ol>
<p class="ha__logo"${dc(HA.logo)}>${picture({ hell: 'assets/img/integration-logo.png', dunkel: 'assets/img/integration-logo-dunkel.png' }, tx(HA.logo))}<span>${E(HA.logoText)}</span></p>
<p class="knoepfe">${knopf(X.HA_ADD, HA.app, 'knopf--haupt')}${knopf(X.INTEGRATION_OPEN, HA.integration)}<a href="${esc(X.doc(lang, 'anleitungen/home-assistant.md'))}">${E(HA.anleitung)}</a></p>
<div class="infobox infobox--info"${dc(HA.ehrlich)}>${ico('info', { size: 20 })}<p class="infobox__text">${R(HA.ehrlich)}</p></div>
</div>
${figur('wegweiser:home-assistant', HA.bildAlt, HA.bildText)}
</div>
</div></section>`;

    const V = X.VERTRAUEN;
    const vertrauen = `<section class="abschnitt abschnitt--flach" id="vertrauen" aria-labelledby="vertrauen-t"><div class="wrap">
<h2 id="vertrauen-t">${E(V.h2)}</h2>${P(V.lead, 'abschnitt__lead')}
${figur('wegweiser:together', V.bildAlt, V.bildText, { cls: 'bild--breit' })}
<ul class="zeilen zeilen--2 belege">${V.belege.map(b => `<li${dc(b.text)}>${ico(b.icon, { size: 20 })}<span>${R(b.text)} <a class="beleg" href="${esc(href(b.beleg.href))}">${E(b.beleg.text)}</a></span></li>`).join('')}</ul>
<div class="vertrauen__unten">
<div class="aussen"><h3>${E(V.aussenH3)}</h3>
<table class="tabelle"><thead><tr>${V.aussenKopf.map(k => `<th scope="col">${E(k)}</th>`).join('')}</tr></thead>
<tbody>${V.aussen.map(row => `<tr>${row.map((cell, i) => `<${i ? 'td' : 'th scope="row"'} data-label="${E(V.aussenKopf[i])}"${dc(cell)}>${R(cell)}</${i ? 'td' : 'th'}>`).join('')}</tr>`).join('')}</tbody></table>
${P(V.aussenNote, 'klein')}</div>
<div class="grenzen"><h3>${E(V.grenzenH3)}</h3>
<ul class="liste">${V.grenzen.map(g => `<li${dc(g)}>${R(g)}</li>`).join('')}</ul>
<p>${knopf(href(V.grenzenLink.href), V.grenzenLink.text)}</p></div>
</div>
</div></section>`;

    const Q = X.FRAGEN;
    const fragen = `<section class="abschnitt" id="fragen" aria-labelledby="fragen-t"><div class="wrap">
<h2 id="fragen-t">${E(Q.h2)}</h2>
<div class="fragen">${Q.liste.map(f => `<details${dc(f.antwort)}><summary>${E(f.frage)}</summary><p>${R(f.antwort)}</p></details>`).join('')}</div>
</div></section>`;

    const IN = X.INSTALL;
    const codeBlock = (text) => `<div class="code"><pre tabindex="0"><code>${esc(text)}</code></pre><button type="button" class="knopf knopf--klein kopier" hidden data-fertig="${E(X.UI.kopiert)}">${E(X.UI.kopieren)}</button></div>`;
    const install = `<section class="abschnitt abschnitt--flach" id="installieren" aria-labelledby="installieren-t"><div class="wrap">
<h2 id="installieren-t">${E(IN.h2)}</h2>${P(IN.lead, 'abschnitt__lead')}
<div class="installwege">${IN.wege.map(w => `<section class="install" aria-labelledby="iw-${w.icon}">
<h3 class="install__titel" id="iw-${w.icon}">${ico(w.icon, { size: 24 })}<span>${E(w.titel)}</span></h3>
${P(w.fuer, 'install__fuer')}
<ol>${w.schritte.map((s, i) => `<li${dc(s)}>${R(s)}${w.code && w.code.nach === i ? codeBlock(w.code.text) : ''}</li>`).join('')}</ol>
${w.note ? P(w.note, 'klein') : ''}
<p class="install__fuss">${knopf(href(w.link.href), w.link.text)}</p>
</section>`).join('')}</div>
<div class="infobox infobox--achtung"${dc(IN.achtung)}>${ico('achtung', { size: 20 })}<p class="infobox__text">${R(IN.achtung)} <a href="${esc(href(IN.achtungLink.href))}">${E(IN.achtungLink.text)}</a></p></div>
</div></section>`;

    const O = X.VORHABEN;
    const vorhaben = `<section class="abschnitt" id="vorhaben" aria-labelledby="vorhaben-t"><div class="wrap">
<h2 id="vorhaben-t">${E(O.h2)}</h2>${P(O.lead, 'abschnitt__lead')}
<ul class="repos">${O.repos.map(r => `<li><a href="${r.href}">${ico(r.icon, { size: 24 })}<b>${E(r.name)}</b><code>${esc(r.slug)}</code><span${dc(r.text)}>${R(r.text)}</span></a></li>`).join('')}</ul>
<h3>${E(O.linksH3)}</h3>
<p class="linkreihe">${O.links.map(l => knopf(href(l.href), l.text)).join('')}</p>
</div></section>`;

    const M = X.MITMACHEN;
    const mitmachen = `<section class="abschnitt abschnitt--flach" id="mitmachen" aria-labelledby="mitmachen-t"><div class="wrap">
<h2 id="mitmachen-t">${E(M.h2)}</h2>${P(M.lead, 'abschnitt__lead')}
<ul class="zeilen zeilen--2">${M.liste.map(m => `<li${dc(m.text)}>${ico(m.icon, { size: 20 })}<span>${R(m.text)} <a href="${esc(href(m.href))}">${E(m.linkText)}</a></span></li>`).join('')}</ul>
</div></section>`;

    // Mitlaufender Knopf am Handy: Demo und Installieren immer greifbar
    const leiste = `<div class="leiste" data-leiste>${knopf(X.demo(lang), X.UI.demoStarten, 'knopf--haupt')}${knopf('#installieren', X.UI.installieren)}</div>`;

    return `<main id="inhalt">\n${held}\n${neu}\n${schritte}\n${slider}\n${nutzen}\n${arten}\n${wege}\n${ha}\n${vertrauen}\n${fragen}\n${install}\n${vorhaben}\n${mitmachen}\n</main>\n${leiste}`;
  }

  function ueberseite() {
    const U = X.UEBER;
    return `<main id="inhalt" class="abschnitt"><div class="wrap ueber">
<h1 id="titel"${dc(U.h1)}>${R(U.h1)}</h1>
${P(U.lead, 'lead')}
${U.abschnitte.map(a => `<section aria-labelledby="u-${a.h2.de.toLowerCase().replace(/[^a-zäöüß]+/g, '-')}">
<h2 id="u-${a.h2.de.toLowerCase().replace(/[^a-zäöüß]+/g, '-')}">${E(a.h2)}</h2>
${(a.absaetze || []).map(t => P(t)).join('')}${a.liste ? `<ul class="liste">${a.liste.map(t => `<li${dc(t)}>${R(t)}</li>`).join('')}</ul>` : ''}${a.anschrift ? `<address class="anschrift"${dc(a.anschrift)}>${tx(a.anschrift).split('\n').map(esc).join('<br>')}</address>` : ''}${(a.danach || []).map(t => P(t)).join('')}
</section>`).join('\n')}
<p><a class="knopf" href="${start}">${E(X.UI.start)}</a></p>
</div></main>`;
  }

  // JSON-LD: was die Seite beschreibt, statisch, ohne Zählung
  const ld = art === 'start' ? `<script type="application/ld+json">${JSON.stringify({
    '@context': 'https://schema.org',
    '@type': 'SoftwareApplication',
    name: 'Energietracker',
    url: SITE + hier.slice(1),
    description: tx(X.META.description),
    applicationCategory: 'UtilitiesApplication',
    operatingSystem: 'Docker (amd64, arm64), Home Assistant OS, PHP 8.4',
    softwareVersion: '3.13.0',
    license: 'https://www.gnu.org/licenses/agpl-3.0.html',
    isAccessibleForFree: true,
    offers: { '@type': 'Offer', price: '0', priceCurrency: 'EUR' },
    inLanguage: ['de', 'en', 'fr', 'it', 'es', 'pt', 'nl'],
    downloadUrl: `${X.REPO}/releases`,
    softwareHelp: { '@type': 'CreativeWork', url: X.doc(lang, 'README.md') },
    image: `${SITE}assets/img/vorschau.${lang}.png`,
    author: { '@type': 'Person', name: 'Bingerminger', url: 'https://github.com/Bingerminger' },
    sameAs: [X.REPO, X.DEMO],
  }).replace(/</g, '\\u003c')}</script>\n` : '';
  if (ld) claims.add('json-ld');

  // Sprite zuletzt, damit es nur die benutzten Grafiken enthält
  const body = kopf + '\n' + main + '\n' + fuss;
  const sprite = `<svg class="sprite" aria-hidden="true" focusable="false"><defs>${[...used].sort().map(k => `<symbol id="i-${k}" viewBox="0 0 24 24">${ICONS[k]}</symbol>`).join('')}</defs></svg>`;
  const title = art === 'start' ? tx(X.META.title) : tx(X.META.ueberTitle);
  const desc = art === 'start' ? tx(X.META.description) : tx(X.META.ueberDescription);
  const url = SITE + hier.slice(1);
  const html = `<!doctype html>
<html lang="${lang}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>${esc(title)}</title>
<meta name="description" content="${esc(desc)}">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#111827" media="(prefers-color-scheme: dark)">
<script>(function(){try{var m=localStorage.getItem('projektseite.thema');if(m==='light'||m==='dark'){document.documentElement.setAttribute('data-theme',m);}}catch(e){}})();</script>
<link rel="canonical" href="${url}">
<link rel="alternate" hreflang="de" href="${SITE}${SEITEN[art].de.slice(1)}">
<link rel="alternate" hreflang="en" href="${SITE}${SEITEN[art].en.slice(1)}">
<link rel="alternate" hreflang="x-default" href="${SITE}${SEITEN[art].de.slice(1)}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Energietracker">
<meta property="og:title" content="${esc(title)}">
<meta property="og:description" content="${esc(desc)}">
<meta property="og:url" content="${url}">
<meta property="og:image" content="${SITE}assets/img/vorschau.${lang}.png">
<meta property="og:image:width" content="1280">
<meta property="og:image:height" content="640">
<meta property="og:image:alt" content="${esc(title)}">
<meta property="og:locale" content="${lang === 'de' ? 'de_DE' : 'en_GB'}">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/assets/img/logo.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">
<link rel="preload" href="/assets/fonts/dm-sans.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/assets/stil.css">
<script src="/assets/seite.js" defer></script>
${ld}</head>
<body class="seite--${art}">
${sprite}
${body}
</body>
</html>
`;
  return { html, claims };
}
