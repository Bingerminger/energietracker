// =====================================================================
// Abmelden der Listener beim Verlassen einer Einstellungsseite.
// v3.12.0 — aus views/settings.js ausgelagert, Verhalten unverändert.
// =====================================================================

// v2.6.0 — Die Ansicht rendert sich nach Sprachwechsel, Import oder
// Anmeldeänderung selbst neu. Bisher blieben dabei die Listener der vorigen
// Runde am Container und am Fenster hängen: Nach einem Import meldete ein
// veralteter beforeunload-Handler „ungespeicherte Änderungen", und die
// input-Listener liefen in anderen Ansichten weiter (derselbe Container).
let _unlisten = [];

export function unlistenAll() {
  _unlisten.forEach(off => off());
  _unlisten = [];
}

export function listen(target, type, handler) {
  target.addEventListener(type, handler);
  _unlisten.push(() => target.removeEventListener(type, handler));
}
