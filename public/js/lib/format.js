// =====================================================================
// Formatting helpers — German locale.
// =====================================================================

const NUM = new Intl.NumberFormat('de-DE', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
const NUM_FIXED = (n) => new Intl.NumberFormat('de-DE', { minimumFractionDigits: n, maximumFractionDigits: n });
const EUR = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' });

export const fmt = {
  num:   (v, d=2) => v == null || isNaN(v) ? '–' : NUM_FIXED(d).format(Number(v)),
  int:   (v)      => v == null || isNaN(v) ? '–' : NUM_FIXED(0).format(Number(v)),
  eur:   (v)      => v == null || isNaN(v) ? '–' : EUR.format(Number(v)),
  pct:   (v, d=1) => v == null || isNaN(v) ? '–' : NUM_FIXED(d).format(Number(v) * 100) + ' %',
  date:  (d)      => {
    if (!d) return '–';
    const [y,m,day] = String(d).split('-');
    return day ? `${day}.${m}.${y}` : d;
  },
  month: (ym) => {
    if (!ym) return '–';
    const [y,m] = ym.split('-');
    const names = ['Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'];
    return `${names[Number(m)-1]} ${y}`;
  },
  unit: (v, unit, digits=0) => v == null || isNaN(v) ? '–' : `${NUM_FIXED(digits).format(Number(v))} ${unit}`,
};

export function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, c =>
    ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
}

export function todayIso() {
  return new Date().toISOString().slice(0, 10);
}

export function yearOf(dateStr) {
  return dateStr ? Number(String(dateStr).slice(0, 4)) : null;
}
