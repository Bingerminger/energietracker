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
// =====================================================================
import { api } from '../api.js';
import { toastOk, toastErr } from '../components/toast.js';

const fmt = {
  num(n, max = 2) {
    if (n === null || n === undefined || Number.isNaN(n)) return '–';
    return Number(n).toLocaleString('de-DE', { maximumFractionDigits: max });
  },
  date(s) {
    if (!s || typeof s !== 'string') return '–';
    const m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
    return m ? `${m[3]}.${m[2]}.${m[1]}` : s;
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
  container.innerHTML = `
    <section class="view-readings-entry">
      <div class="view-header">
        <div>
          <h1 class="view-header__title">Zählerstände</h1>
          <p class="view-header__subtitle">Schnelle Vor-Ort-Erfassung — alle Zähler auf einen Blick.</p>
        </div>
      </div>
      <div class="readings-entry" data-role="list">
        <div class="muted" style="padding:var(--sp-4)">Lade Zähler …</div>
      </div>
      <div class="readings-entry__sticky" data-role="sticky" hidden>
        <button class="btn btn--primary btn--lg" data-action="save-all">
          <span data-role="save-label">Alle speichern</span>
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
      <h3>Fehler beim Laden</h3>
      <p class="muted">${esc(e.message || 'Unbekannter Fehler')}</p>
    </div>`;
    return;
  }

  if (rows.length === 0) {
    listEl.innerHTML = `<div class="empty" style="padding:32px">
      <div class="empty-icon">📋</div>
      <h3>Keine Zähler</h3>
      <p class="muted">
        Für die zentrale Erfassung sind nur kumulative Zähler (Gas, Strom,
        Wasser, Fernwärme) eingeplant. Heizöl/Pellets erfassen den
        Verbrauch über Lieferungen, nicht über Zählerstände.
      </p>
    </div>`;
    stickyEl.hidden = true;
    return;
  }

  const today = todayISO();
  listEl.innerHTML = rows.map(r => renderRow(r, today)).join('');
  rows.forEach((r, i) => bindRow(listEl.querySelector(`[data-row-index="${i}"]`), r));

  stickyEl.hidden = false;
  saveBtn.addEventListener('click', async () => {
    saveBtn.disabled = true;
    saveLbl.textContent = 'Speichere …';
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
    saveLbl.textContent = 'Alle speichern';
    if (failed > 0) {
      toastErr(`${ok} gespeichert, ${failed} fehlgeschlagen`);
    } else if (ok > 0) {
      toastOk(`${ok} gespeichert${skipped > 0 ? ` · ${skipped} leer` : ''}`);
      // Letzten Stand in den „Letzter Stand"-Anzeigen aktualisieren,
      // damit ein zweiter Speicher-Klick die frischen Werte sieht.
      await refreshLastReadings(listEl, rows, today);
    } else if (skipped > 0) {
      toastOk('Keine Eingaben — nichts zu speichern');
    }
  });
}

function renderRow(r, today) {
  const last     = r.last_reading;
  const lastStr  = last
    ? `${fmt.num(last.counter, 3)} ${esc(r.consumption_unit)} · ${fmt.date(last.date)}`
    : 'keine Ablesung bisher';
  const lastTag  = last?.is_estimated
    ? ` <span class="reading-card__tag">geschätzt</span>` : '';
  const i        = r.__index = String(r.__seq ?? '');
  return `
    <article class="reading-card" data-row-index="${escIdx(r)}" data-utility="${esc(r.utility)}">
      <div class="reading-card__head">
        <span class="reading-card__icon" aria-hidden="true">${esc(r.utility_icon || r.meter_icon || '•')}</span>
        <div class="reading-card__title">
          <div class="reading-card__name">${esc(r.meter_name)}</div>
          <div class="reading-card__sub">${esc(r.utility_label)}${r.meter_notes ? ` · ${esc(r.meter_notes)}` : ''}</div>
        </div>
        <span class="reading-card__status" data-role="status" aria-live="polite"></span>
      </div>

      <div class="reading-card__last">
        Letzter Stand: <strong>${lastStr}</strong>${lastTag}
      </div>

      <div class="reading-card__inputs">
        <label class="field field--counter">
          <span class="field__label">Neuer Stand (${esc(r.consumption_unit)})</span>
          <input
            class="input input--counter"
            data-role="counter"
            type="number"
            inputmode="decimal"
            step="0.001"
            min="0"
            placeholder="z. B. ${last ? fmt.num(last.counter + 10, 0) : '0'}"
            autocomplete="off"
          />
        </label>
        <label class="field field--date">
          <span class="field__label">Datum</span>
          <input class="input" data-role="date" type="date" value="${esc(today)}" />
        </label>
      </div>

      <div class="reading-card__extras">
        <label class="toggle">
          <input type="checkbox" data-role="estimated" />
          <span>geschätzt</span>
        </label>
        <button type="button" class="btn btn--ghost btn--sm" data-action="toggle-note">
          + Notiz
        </button>
      </div>

      <div class="reading-card__note" data-role="note-wrap" hidden>
        <label class="field">
          <span class="field__label">Notiz</span>
          <input class="input" data-role="note" type="text" maxlength="200" placeholder="optional" />
        </label>
      </div>

      <div class="reading-card__hint" data-role="hint" hidden></div>
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

function bindRow(card, r) {
  if (!card) return;
  const counterEl = card.querySelector('[data-role="counter"]');
  const dateEl    = card.querySelector('[data-role="date"]');
  const hintEl    = card.querySelector('[data-role="hint"]');
  const noteWrap  = card.querySelector('[data-role="note-wrap"]');

  card.querySelector('[data-action="toggle-note"]')?.addEventListener('click', () => {
    noteWrap.hidden = !noteWrap.hidden;
  });

  counterEl?.addEventListener('input', () => {
    const v = parseFloat(counterEl.value.replace(',', '.'));
    if (Number.isNaN(v)) { hintEl.hidden = true; return; }
    const min = r.expected_next_min;
    if (min !== null && min !== undefined && v < min) {
      hintEl.hidden = false;
      hintEl.textContent = `Hinweis: Wert ist niedriger als der letzte Stand (${fmt.num(min, 3)}). Bei Zählertausch o. Ä. ist das ok.`;
    } else {
      hintEl.hidden = true;
    }
  });
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
    statusEl.textContent = '✗';
    statusEl.className = 'reading-card__status reading-card__status--err';
    return 'fail';
  }
  const date = dateEl?.value || todayISO();

  statusEl.textContent = '…';
  statusEl.className = 'reading-card__status reading-card__status--pending';

  try {
    await api.createReading(r.utility, {
      meter_id:     r.meter_id,
      date,
      counter,
      note:         noteEl?.value || '',
      is_estimated: !!estimatedEl?.checked,
    });
    statusEl.textContent = '✓';
    statusEl.className = 'reading-card__status reading-card__status--ok';
    // Eingabe zurücksetzen, damit Doppel-Save nicht doppelt schreibt.
    if (counterEl) counterEl.value = '';
    if (noteEl)    noteEl.value = '';
    if (estimatedEl) estimatedEl.checked = false;
    return 'ok';
  } catch (e) {
    statusEl.textContent = '✗';
    statusEl.className = 'reading-card__status reading-card__status--err';
    statusEl.title = e.message || 'Fehler';
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
    rows.forEach((r, i) => {
      const next = byKey.get(r.utility + ':' + r.meter_id);
      if (!next) return;
      r.last_reading      = next.last_reading;
      r.expected_next_min = next.expected_next_min;
      const card = listEl.querySelector(`[data-row-index="${r.__seq}"]`);
      if (!card) return;
      const lastEl = card.querySelector('.reading-card__last');
      if (lastEl && next.last_reading) {
        lastEl.innerHTML = `Letzter Stand: <strong>${fmt.num(next.last_reading.counter, 3)} ${esc(r.consumption_unit)} · ${fmt.date(next.last_reading.date)}</strong>`;
      }
    });
  } catch { /* still */ }
}

export function cleanup() { /* keine globalen Listener */ }
