// =====================================================================
// v3.13.0 (Paket vertrag, #40) — Ziele der Seite „Prognose“: Zähler und
// Gruppen mit Gruppenvertrag (HT/NT), wie im Tarifvergleich.
//
// Bis v3.12 listete die Seite nur Zähler. Bei einem Doppeltarifzähler stand
// die Prognose so je Zählwerk da, ohne Grundpreis und Abschlag, obwohl beide
// Zählwerke einen Vertrag haben (Beobachtung in #40). Die Gruppe steht jetzt
// direkt vor ihrem ersten Mitglied: Wo vorher ein Zählwerk vorgewählt war,
// ist es die Gruppe. Die Zählwerke bleiben wählbar.
// =====================================================================

/**
 * Zähler und Gruppen mit Gruppenvertrag in der Reihenfolge der Auswahl.
 *
 * @param {Array<{id:string,name?:string,meter_group_id?:string}>} meters
 * @param {Array<{id:string,name?:string}>} groups
 * @param {Array<{meter_group_id?:string,is_shadow?:boolean}>} contracts Verträge der Art (Angebote zählen nicht)
 * @returns {Array<object>} Zähler wie geliefert, Gruppen als {id, name, is_group: true}
 */
export function forecastTargets(meters, groups, contracts) {
  const priced = new Map();
  for (const g of groups || []) {
    if ((contracts || []).some(c => c && c.meter_group_id === g.id && !c.is_shadow)) priced.set(g.id, g);
  }
  const out = [];
  const placed = new Set();
  const group = (g) => ({ id: g.id, name: g.name || g.id, is_group: true });
  for (const m of meters || []) {
    const gid = m?.meter_group_id;
    if (gid && priced.has(gid) && !placed.has(gid)) {
      out.push(group(priced.get(gid)));
      placed.add(gid);
    }
    out.push(m);
  }
  // eine Gruppe, deren Mitglieder hier fehlen, steht am Ende
  for (const [gid, g] of priced) if (!placed.has(gid)) out.push(group(g));
  return out;
}

/** Die Gruppe mit Gruppenvertrag, zu der ein Zählwerk der Auswahl gehört, sonst null. */
export function pricedGroupOf(targets, id) {
  const m = (targets || []).find(x => x.id === id);
  if (!m || m.is_group || !m.meter_group_id) return null;
  return (targets || []).find(x => x.is_group && x.id === m.meter_group_id) || null;
}
