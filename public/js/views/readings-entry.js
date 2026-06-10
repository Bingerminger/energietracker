// =====================================================================
// Energietracker — Zählerstand-Erfassung (F1004, v1.6.0)
// Zentraler View zur Erfassung aller Zählerstände in einem Durchgang.
// Mobile-First, optimiert für iPhone Safari / Vor-Ort-Ablesungen.
//
// Scope (siehe Utilities::hasAdvancePaymentContracts-ähnliche Logik im
// Backend): nur kumulative Utilities (Gas/Strom/Wasser/Fernwärme).
// Heizöl/Pellets nutzen Lieferungen, kein Zählerstand-Modell.
//
// Architektur:
//   - 1 Initial-Fetch: GET /api/readings-overview (alle Zähler + letzte
//     Ablesungen in einem Roundtrip)
//   - Speichern pro Zeile via POST /api/utility/{u}/readings — robust
//     gegen Teilfehler; eine fehlerhafte Zeile blockiert die anderen nicht
//   - Inline-Validierung: Rückwärts-Zählerstand wird gewarnt (nicht
//     hart blockiert — Zählertausch o. Ä. kann legitim sein)
//   - „Geschätzt"-Toggle setzt is_estimated; mappt auf bestehendes
//     Reading-Schema, keine Datenmodell-Änderung
//
// v2.0.0 (N1007/UX): i18n-Strings über t(); locale-bewusstes fmt;
//   A Verbrauchs-Vorschau bei Eingabe, B globales Ablesedatum,
//   C Fortschrittsanzeige am Speichern-Button, D CTA im Leerzustand.
// =====================================================================
import { api } from '../api.js';
import { getUtilities } from '../state.js';
import { toastOk, toastErr } from '../components/toast.js';
import { t, getLocale } from '../lib/i18n.js';

const fmt = {
  num(n, max = 2) {
    if (n === null || n === undefined || Number.isNaN(n)) return '–';
    return Number(n).toLocaleString(getLocale() === 'en' ? 'en-GB' : 'de-DE', { maximumFractionDigits: max });
  },
  date(s) {
    if (!s || typeof s !== 'string') return '–';
    const m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!m) return s;
    return getLocale() === 'en' ? `${m[3]}/${m[2]}/${m[1]}` : `${m[3]}.${m[2]}.${m[1]}`;
  },
};

function todayISO() {
  const d = new Date();
  const z = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${z(d.getMonth() + 1)}-${z(d.getDate())}`;
}

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[c]));
}

export async function render(container) {
  const today = todayISO();

  container.innerHTML = `
    <section class="view-readings-entry">
      <div class="view-header">
        <div>
          <h1 class="view-header__title">${t('readingsEntry.title')}</h1>
          <p class="view-header__subtitle">${t('readingsEntry.subtitle')}</p>
        </div>
      </div>
      <div class="readings-entry" data-role="list">
        <div class="muted" style="padding:var(--sp-4)">${t('readingsEntry.loading')}</div>
      </div>
      <div class="readings-entry__sticky" data-role="sticky" hidden>
        <button class="btn btn--primary btn--lg" data-action="save-all">
          <span data-role="save-label">${t('readingsEntry.saveAll')}</span>
        </button>
      </div>
    </section>
  `;

  const listEl   = container.querySelector('[data-role="list"]');
  const stickyEl = container.querySelector('[data-role="sticky"]');
  const saveBtn  = container.querySelector('[data-action="save-all"]');
  const saveLbl  = container.querySelector('[data-role="save-label"]');

  let rows = [];
  try {
    const data = await api.readingsOverview();
    rows = Array.isArray(data?.rows) ? data.rows : [];
  } catch (e) {
    listEl.innerHTML = `<div class="empty" style="padding:32px">
      <div class="empty-icon">⚠️</div>
      <h2>${t('readingsEntry.error.title')}</h2>
      <p class="muted">${esc(e.message || t('readingsEntry.error.unknown'))}</p>
    </div>`;
    return;
  }

  if (rows.length === 0) {
    // D — CTA zum Zähler-Anlegen (erste kumulative Verbrauchsart).
    const cumUtil = (await getUtilities().catch(() => [])).find(u => u.reading_kind === 'cumulative');
    const ctaHref = cumUtil ? `#/utility/${cumUtil.key}/meters` : '#/settings';
    listEl.innerHTML = `<div class="empty" style="padding:32px">
      <div class="empty-icon">📋</div>
      <h2>${t('readingsEntry.empty.title')}</h2>
      <p class="muted">${t('readingsEntry.empty.text')}</p>
      <a class="btn btn--primary" href="${ctaHref}">${t('readingsEntry.empty.cta')}</a>
    </div>`;
    stickyEl.hidden = true;
    return;
  }

  // B — globales Ablesedatum oben + alle Zähler-Karten.
  listEl.innerHTML = `
    <div class="readings-entry__toolbar">
      <label class="field field--date">
        <span class="field__label">${t('readingsEntry.globalDateLabel')}</span>
        <input class="input" data-role="global-date" type="date" value="${esc(today)}" />
      </label>
    </div>
    ${rows.map(r => renderRow(r, today)).join('')}
  `;
  rows.forEach((r) => bindRow(listEl.querySelector(`[data-row-index="${r.__seq}"]`), r));

  // B — globales Datum auf alle Karten anwenden + Vorschau neu berechnen.
  const globalDateEl = listEl.querySelector('[data-role="global-date"]');
  globalDateEl?.addEventListener('change', () => {
    const v = globalDateEl.value || today;
    listEl.querySelectorAll('[data-role="date"]').forEach(d => { d.value = v; });
    listEl.querySelectorAll('.reading-card').forEach(card => card.__update?.());
  });

  // C — Fortschrittsanzeige am Speichern-Button.
  const progressText = () => {
    const total = rows.length;
    const done = [...listEl.querySelectorAll('[data-role="counter"]')]
      .filter(el => el.value.trim() !== '').length;
    return done > 0
      ? t('readingsEntry.saveAllProgress', { done, total })
      : t('readingsEntry.saveAll');
  };
  const updateProgress = () => { if (!saveBtn.disabled) saveLbl.textContent = progressText(); };
  listEl.addEventListener('input', updateProgress);
  saveLbl.textContent = progressText();

  stickyEl.hidden = false;
  saveBtn.addEventListener('click', async () => {
    saveBtn.disabled = true;
    saveLbl.textContent = t('readingsEntry.saving');
    let ok = 0, skipped = 0, failed = 0;
    const cards = listEl.querySelectorAll('.reading-card');
    for (let i = 0; i < cards.length; i++) {
      const card = cards[i];
      const r    = rows[i];
      const res  = await trySaveCard(card, r);
      if (res === 'ok')      ok++;
      else if (res === 'skip') skipped++;
      else                   failed++;
    }
    saveBtn.disabled = false;
    if (failed > 0) {
      toastErr(t('readingsEntry.toast.savedFailed', { ok, failed }));
    } else if (ok > 0) {
      toastOk(skipped > 0
        ? t('readingsEntry.toast.savedEmpty', { ok, skipped })
        : t('readingsEntry.toast.saved', { ok }));
      // Letzten Stand in den „Letzter Stand"-Anzeigen aktualisieren,
      // damit ein zweiter Speicher-Klick die frischen Werte sieht.
      await refreshLastReadings(listEl, rows, today);
    } else if (skipped > 0) {
      toastOk(t('readingsEntry.toast.nothing'));
    }
    saveLbl.textContent = progressText();
  });
}

function renderRow(r, today) {
  const last     = r.last_reading;
  const lastStr  = last
    ? `${fmt.num(last.counter, 3)} ${esc(r.consumption_unit)} · ${fmt.date(last.date)}`
    : t('readingsEntry.row.lastNone');
  const lastTag  = last?.is_estimated
    ? ` <span class="reading-card__tag">${t('readingsEntry.row.estimated')}</span>` : '';
  return `
    <article class="reading-card" data-row-index="${escIdx(r)}" data-utility="${esc(r.utility)}" aria-labelledby="rc-name-${escIdx(r)}">
      <div class="reading-card__head">
        <span class="reading-card__icon" aria-hidden="true">${esc(r.utility_icon || r.meter_icon || '•')}</span>
        <div class="reading-card__title">
          <div class="reading-card__name" id="rc-name-${escIdx(r)}">${esc(r.meter_name)}</div>
          <div class="reading-card__sub">${esc(r.utility_label)}${r.meter_notes ? ` · ${esc(r.meter_notes)}` : ''}</div>
        </div>
        <span class="reading-card__status" data-role="status" aria-live="polite"></span>
      </div>

      <div class="reading-card__last">
        ${t('readingsEntry.row.lastLabel')} <strong>${lastStr}</strong>${lastTag}
      </div>

      <div class="reading-card__inputs">
        <label class="field field--counter">
          <span class="field__label">${t('readingsEntry.row.newLabel', { unit: esc(r.consumption_unit) })}</span>
          <input
            class="input input--counter"
            data-role="counter"
            type="number"
            inputmode="decimal"
            step="0.001"
            min="0"
            placeholder="${esc(t('readingsEntry.row.placeholderExample', { value: last ? fmt.num(last.counter + 10, 0) : '0' }))}"
            autocomplete="off"
          />
        </label>
        <label class="field field--date">
          <span class="field__label">${t('readingsEntry.row.dateLabel')}</span>
          <input class="input" data-role="date" type="date" value="${esc(today)}" />
        </label>
      </div>

      <div class="reading-card__preview" data-role="preview" hidden></div>

      <div class="reading-card__extras">
        <label class="toggle">
          <input type="checkbox" data-role="estimated" />
          <span>${t('readingsEntry.row.estimated')}</span>
        </label>
        <button type="button" class="btn btn--ghost btn--sm" data-action="toggle-note"
          aria-expanded="false" aria-controls="rc-note-${escIdx(r)}">
          ${t('readingsEntry.row.addNote')}
        </button>
      </div>

      <div class="reading-card__note" data-role="note-wrap" id="rc-note-${escIdx(r)}" hidden>
        <label class="field">
          <span class="field__label">${t('readingsEntry.row.note')}</span>
          <input class="input" data-role="note" type="text" maxlength="200" placeholder="${t('readingsEntry.row.notePlaceholder')}" />
        </label>
      </div>

      <div class="reading-card__hint" data-role="hint" role="status" aria-live="polite" hidden></div>
    </article>
  `;
}

// Stabiler Index-Schlüssel pro Row (utility+meter), damit DOM-Re-Renders
// und State-Erhalt sauber zusammenpassen.
let __seqCounter = 0;
function escIdx(r) {
  if (typeof r.__seq === 'undefined') r.__seq = __seqCounter++;
  return String(r.__seq);
}

// A — Verbrauchs-Vorschau: Differenz zum letzten Stand + Tage seit der
// letzten Ablesung. Liefert null, wenn kein voriger Stand vorliegt.
function previewText(r, value, dateVal) {
  const last = r.last_reading;
  if (!last || last.counter == null || Number.isNaN(value)) return null;
  const diff = value - Number(last.counter);
  const sign = diff < 0 ? '−' : '+';
  const delta = `${sign}${fmt.num(Math.abs(diff), 3)}`;
  let days = null;
  if (last.date && dateVal) {
    const d = Math.round((new Date(dateVal) - new Date(last.date)) / 86400000);
    if (Number.isFinite(d) && d > 0) days = d;
  }
  return days != null
    ? t('readingsEntry.preview.sinceLastDays', { delta, unit: r.consumption_unit, days })
    : t('readingsEntry.preview.sinceLast', { delta, unit: r.consumption_unit });
}

function bindRow(card, r) {
  if (!card) return;
  const counterEl = card.querySelector('[data-role="counter"]');
  const dateEl    = card.querySelector('[data-role="date"]');
  const hintEl    = card.querySelector('[data-role="hint"]');
  const previewEl = card.querySelector('[data-role="preview"]');
  const noteWrap  = card.querySelector('[data-role="note-wrap"]');

  const noteBtn = card.querySelector('[data-action="toggle-note"]');
  noteBtn?.addEventListener('click', () => {
    const open = noteWrap.hidden; // wird gerade geöffnet
    noteWrap.hidden = !open;
    noteBtn.setAttribute('aria-expanded', String(open));
    noteBtn.textContent = open ? t('readingsEntry.row.hideNote') : t('readingsEntry.row.addNote');
    // Beim Aufklappen den Fokus ins Notizfeld setzen (Tastatur/Screenreader).
    if (open) card.querySelector('[data-role="note"]')?.focus();
  });

  // Gemeinsame Aktualisierung von Hinweis (Rückwärts-Wert) + Vorschau,
  // damit auch ein geändertes globales Datum die Vorschau neu rechnet.
  const update = () => {
    const raw = (counterEl?.value || '').trim();
    const v = parseFloat(raw.replace(',', '.'));
    if (raw === '' || Number.isNaN(v)) {
      hintEl.hidden = true;
      previewEl.hidden = true;
      return;
    }
    const min = r.expected_next_min;
    if (min !== null && min !== undefined && v < min) {
      hintEl.hidden = false;
      hintEl.textContent = t('readingsEntry.hint.lower', { min: fmt.num(min, 3) });
    } else {
      hintEl.hidden = true;
    }
    const prev = previewText(r, v, dateEl?.value);
    if (prev) { previewEl.hidden = false; previewEl.textContent = prev; }
    else      { previewEl.hidden = true; }
  };
  counterEl?.addEventListener('input', update);
  card.__update = update;
}

// A11y (N1009): Der Status ist visuell nur eine Glyphe (✓/✗/…). Da die
// Status-Span eine aria-live-Region ist, würde ein Screenreader sonst „Häkchen"
// vorlesen. Wir trennen sichtbares Symbol (aria-hidden) und angesagten Klartext.
function setCardStatus(statusEl, kind) {
  if (!statusEl) return;
  const map = {
    saving:  { glyph: '…', cls: 'pending', label: t('readingsEntry.status.saving') },
    saved:   { glyph: '✓', cls: 'ok',      label: t('readingsEntry.status.saved') },
    failed:  { glyph: '✗', cls: 'err',     label: t('readingsEntry.status.failed') },
    invalid: { glyph: '✗', cls: 'err',     label: t('readingsEntry.status.invalid') },
  };
  const s = map[kind];
  if (!s) return;
  statusEl.className = 'reading-card__status reading-card__status--' + s.cls;
  statusEl.innerHTML = `<span aria-hidden="true">${s.glyph}</span><span class="sr-only">${esc(s.label)}</span>`;
}

async function trySaveCard(card, r) {
  const counterEl   = card.querySelector('[data-role="counter"]');
  const dateEl      = card.querySelector('[data-role="date"]');
  const estimatedEl = card.querySelector('[data-role="estimated"]');
  const noteEl      = card.querySelector('[data-role="note"]');
  const statusEl    = card.querySelector('[data-role="status"]');

  const raw = (counterEl?.value || '').trim();
  if (raw === '') return 'skip'; // Leer = nichts speichern

  const counter = parseFloat(raw.replace(',', '.'));
  if (Number.isNaN(counter)) {
    setCardStatus(statusEl, 'invalid');
    return 'fail';
  }
  const date = dateEl?.value || todayISO();

  setCardStatus(statusEl, 'saving');

  try {
    await api.createReading(r.utility, {
      meter_id:     r.meter_id,
      date,
      counter,
      note:         noteEl?.value || '',
      is_estimated: !!estimatedEl?.checked,
    });
    setCardStatus(statusEl, 'saved');
    // Eingabe zurücksetzen, damit Doppel-Save nicht doppelt schreibt.
    if (counterEl) counterEl.value = '';
    if (noteEl)    noteEl.value = '';
    if (estimatedEl) estimatedEl.checked = false;
    // Vorschau/Hinweis dieser Karte zurücksetzen.
    card.__update?.();
    return 'ok';
  } catch (e) {
    setCardStatus(statusEl, 'failed');
    statusEl.title = e.message || t('readingsEntry.error.unknown');
    return 'fail';
  }
}

// Nach erfolgreichem Speichern den „letzter Stand"-Anker auf den
// frisch gespeicherten Wert heben, damit weitere Eingaben gegen die
// neue Baseline validieren — ohne erneuten Aggregat-Fetch.
async function refreshLastReadings(listEl, rows, today) {
  try {
    const data = await api.readingsOverview();
    const fresh = Array.isArray(data?.rows) ? data.rows : [];
    const byKey = new Map(fresh.map(f => [f.utility + ':' + f.meter_id, f]));
    rows.forEach((r) => {
      const next = byKey.get(r.utility + ':' + r.meter_id);
      if (!next) return;
      r.last_reading      = next.last_reading;
      r.expected_next_min = next.expected_next_min;
      const card = listEl.querySelector(`[data-row-index="${r.__seq}"]`);
      if (!card) return;
      const lastEl = card.querySelector('.reading-card__last');
      if (lastEl && next.last_reading) {
        lastEl.innerHTML = `${t('readingsEntry.row.lastLabel')} <strong>${fmt.num(next.last_reading.counter, 3)} ${esc(r.consumption_unit)} · ${fmt.date(next.last_reading.date)}</strong>`;
      }
    });
  } catch { /* still */ }
}

export function cleanup() { /* keine globalen Listener */ }
