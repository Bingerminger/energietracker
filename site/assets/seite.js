// Projektseite: kleine Helfer, die Seite geht auch ohne sie.
// 1. Darstellung hell, dunkel oder wie das System, wie der Umschalter der App (lib/theme.js).
// 2. Sprache des Browsers nur als Vorschlag: ein Hinweis, nie eine Umleitung.
// 3. Bilder-Slider: Reiter, Pfeile, Tastatur, Wischen; ohne Bewegung bei „Bewegung reduzieren“.
// 4. Mitlaufender Knopf am Handy, Knopf „Kopieren“ an den Befehlen.
// Der Speicher im Browser gehört auf github.io allen Seiten des Kontos, auch der Demo (sie räumt
// Schlüssel mit „et-“ und „et.“ ab). Darum ein eigener Präfix „projektseite.“.
(() => {
  const doc = document.documentElement;
  const read = (k) => { try { return localStorage.getItem(k); } catch { return null; } };
  const write = (k, v) => { try { if (v == null) localStorage.removeItem(k); else localStorage.setItem(k, v); } catch { /* ohne Speicher: gilt bis zum Neuladen */ } };
  const ruhig = () => window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // ── 1. Darstellung ────────────────────────────────────────────────
  const THEMA = 'projektseite.thema';
  const MODES = ['system', 'light', 'dark'];
  const mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  const mode = () => { const m = read(THEMA); return m === 'light' || m === 'dark' ? m : 'system'; };
  const wirksam = (m) => (m === 'system' ? (mq && mq.matches ? 'dark' : 'light') : m);
  /** Quellen der Bilder: im System-Modus wie notiert, sonst dunkel erzwingen oder abschalten */
  const bilder = (m) => {
    for (const s of document.querySelectorAll('source[data-thema="dunkel"]')) {
      if (!s.dataset.media) s.dataset.media = s.getAttribute('media');
      s.setAttribute('media', m === 'system' ? s.dataset.media : m === 'dark' ? (s.dataset.breite || 'all') : 'not all');
    }
  };
  const knopf = document.getElementById('thema');
  const beschriften = () => {
    if (!knopf) return;
    const m = mode();
    const label = knopf.dataset.label.replace('{mode}', knopf.dataset[m]);
    knopf.setAttribute('aria-label', label);
    knopf.setAttribute('title', label);
  };
  const meldung = document.getElementById('meldung');
  let meldungUhr = 0;
  const melden = (text) => {
    if (!meldung) return;
    meldung.textContent = text;
    meldung.classList.add('meldung--da');
    clearTimeout(meldungUhr);
    meldungUhr = setTimeout(() => meldung.classList.remove('meldung--da'), 2500);
  };
  const anwenden = (m) => {
    if (m === 'system') doc.removeAttribute('data-theme'); else doc.setAttribute('data-theme', m);
    write(THEMA, m === 'system' ? null : m);
    bilder(m);
    const farbe = wirksam(m) === 'dark' ? '#111827' : '#ffffff';
    for (const t of document.querySelectorAll('meta[name="theme-color"]')) t.setAttribute('content', farbe);
    beschriften();
  };
  if (knopf) {
    knopf.hidden = false;
    knopf.addEventListener('click', () => {
      const next = MODES[(MODES.indexOf(mode()) + 1) % MODES.length];
      anwenden(next);
      melden(knopf.dataset['meldung' + next.charAt(0).toUpperCase() + next.slice(1)]);
    });
  }
  anwenden(mode());
  if (mq) {
    const wechsel = () => { if (mode() === 'system') anwenden('system'); };
    if (mq.addEventListener) mq.addEventListener('change', wechsel); else if (mq.addListener) mq.addListener(wechsel);
  }

  // ── 2. Sprachhinweis ──────────────────────────────────────────────
  const SPRACHE = 'projektseite.sprache';
  const page = (doc.lang || 'de').slice(0, 2);
  const bar = document.getElementById('sprachhinweis');
  if (bar && !read(SPRACHE)) {
    const list = navigator.languages && navigator.languages.length ? navigator.languages : [navigator.language || ''];
    const wants = String(list[0] || '').toLowerCase().slice(0, 2);
    // Deutsche Seite: Wer zuerst keine deutsche Sprache will, bekommt den Hinweis auf Englisch. Englische Seite: wer zuerst Deutsch will.
    if (wants && (page === 'de' ? wants !== 'de' : wants === 'de')) bar.hidden = false;
  }
  bar?.querySelector('[data-zu]')?.addEventListener('click', () => { bar.hidden = true; write(SPRACHE, 'zu'); });
  for (const a of document.querySelectorAll('a[data-sprache]')) a.addEventListener('click', () => write(SPRACHE, a.dataset.sprache));

  // ── 3. Slider ─────────────────────────────────────────────────────
  for (const slider of document.querySelectorAll('[data-slider]')) {
    const tabs = [...slider.querySelectorAll('[role="tab"]')];
    const spur = slider.querySelector('[data-spur]');
    const folien = [...spur.querySelectorAll('[role="tabpanel"]')];
    const steuerung = slider.querySelector('.slider__steuerung');
    const stand = slider.querySelector('.slider__stand');
    let aktiv = 0;
    // Höhe der Spur folgt dem gezeigten Bild, damit unter kurzen Bildern keine Lücke bleibt
    const hoehe = () => { spur.style.height = `${folien[aktiv].offsetHeight}px`; };
    window.addEventListener('resize', hoehe, { passive: true });
    for (const img of spur.querySelectorAll('img')) img.addEventListener('load', hoehe);
    const setzen = (i) => {
      aktiv = i;
      hoehe();
      tabs.forEach((t, k) => { t.setAttribute('aria-selected', String(k === i)); t.tabIndex = k === i ? 0 : -1; });
      folien.forEach((f, k) => { if (k === i) f.removeAttribute('inert'); else f.setAttribute('inert', ''); });
      if (stand) stand.textContent = stand.dataset.stand.replace('{n}', String(i + 1)).replace('{m}', String(folien.length));
      // Reiter in Sicht halten, ohne die Seite zu verschieben
      const t = tabs[i], leiste = t.parentElement;
      if (leiste.scrollWidth > leiste.clientWidth) leiste.scrollTo({ left: t.offsetLeft - leiste.clientWidth / 2 + t.offsetWidth / 2, behavior: ruhig() ? 'auto' : 'smooth' });
    };
    const gehe = (i, fokus = false) => {
      const n = (i + folien.length) % folien.length;
      spur.scrollTo({ left: folien[n].offsetLeft - spur.offsetLeft, behavior: ruhig() ? 'auto' : 'smooth' });
      setzen(n);
      if (fokus) tabs[n].focus();
    };
    tabs.forEach((t, i) => {
      t.addEventListener('click', (e) => { e.preventDefault(); gehe(i); });
      t.addEventListener('keydown', (e) => {
        const ziel = { ArrowRight: aktiv + 1, ArrowLeft: aktiv - 1, Home: 0, End: folien.length - 1 }[e.key];
        if (ziel === undefined) return;
        e.preventDefault();
        gehe(ziel, true);
      });
    });
    slider.querySelector('[data-zurueck]')?.addEventListener('click', () => gehe(aktiv - 1));
    slider.querySelector('[data-weiter]')?.addEventListener('click', () => gehe(aktiv + 1));
    // Wischen: Nach dem Einrasten gilt das Bild, das die Spur zeigt
    let uhr = 0;
    spur.addEventListener('scroll', () => {
      clearTimeout(uhr);
      uhr = setTimeout(() => {
        const i = Math.round(spur.scrollLeft / spur.clientWidth);
        if (i !== aktiv && folien[i]) setzen(i);
      }, 90);
    }, { passive: true });
    if (steuerung) steuerung.hidden = false;
    setzen(0);
  }

  // ── 4. Mitlaufender Knopf, Kopieren ───────────────────────────────
  const leiste = document.querySelector('[data-leiste]');
  const held = document.getElementById('held-knoepfe');
  const fuss = document.querySelector('.fuss');
  if (leiste && 'IntersectionObserver' in window) {
    const sicht = new Set();
    const io = new IntersectionObserver((eintraege) => {
      for (const e of eintraege) { if (e.isIntersecting) sicht.add(e.target); else sicht.delete(e.target); }
      leiste.classList.toggle('leiste--weg', sicht.size > 0);
    });
    if (held) io.observe(held);
    if (fuss) io.observe(fuss);
  }
  if (navigator.clipboard && window.isSecureContext) {
    for (const btn of document.querySelectorAll('.kopier')) {
      const code = btn.parentElement.querySelector('code');
      const label = btn.textContent;
      btn.hidden = false;
      btn.setAttribute('aria-live', 'polite');
      btn.addEventListener('click', async () => {
        try {
          await navigator.clipboard.writeText(code.textContent);
          btn.textContent = btn.dataset.fertig;
          setTimeout(() => { btn.textContent = label; }, 2000);
        } catch { /* Zwischenablage gesperrt: Text bleibt markierbar */ }
      });
    }
  }
})();
