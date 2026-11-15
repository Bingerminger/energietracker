// =====================================================================
// v3.12.0 (Designsprache) — die eigenen Grafiken, eine Quelle für die ganze App.
//
// Strichzeichnungen auf dem 24er-Raster, 1,5 px, runde Enden: dieselbe Hand wie
// `shapes` in lib/illustrations.js. Kontur `--ico` (Rückfall `--ill-line`),
// getönte Fläche `.f` über `--ico-f` (Rückfall `--ill-fill`), hell und dunkel
// folgen dem Thema. Verbrauchsarten tragen ihre Farbe aus der SSOT (--util-<key>).
//
// Ein Sprite aus <symbol> hängt einmal im Dokument (`mountIconSprite`, app.js),
// der Aufruf steht über <use>. Zier ist aria-hidden; ein Knopf nur aus Grafik
// trägt selbst ein aria-label. Regeln: designsprache.md §2, Test tests/icons.test.mjs.
//
// Das Feld `icon` mit dem Emoji bleibt in API, CSV, Home Assistant und MCP; die
// Oberfläche liest es nicht mehr. Emoji zeigt sie nur noch dort, wo jemand sie
// selbst getippt hat (`meterGlyph`).
// =====================================================================

export const ICONS = {
  'art-gas': '<path class="f" d="M12 21a6.5 6.5 0 0 0 6.5-6.5c0-3.2-2.2-5-3.3-7.5-.5 1.7-1.4 2.7-2.7 3.3C12.7 7.5 11.5 5 9 3c.2 2.8-3.5 5.6-3.5 11.5A6.5 6.5 0 0 0 12 21z"/><path d="M12 21a6.5 6.5 0 0 0 6.5-6.5c0-3.2-2.2-5-3.3-7.5-.5 1.7-1.4 2.7-2.7 3.3C12.7 7.5 11.5 5 9 3c.2 2.8-3.5 5.6-3.5 11.5A6.5 6.5 0 0 0 12 21z"/>',
  'art-strom': '<path class="f" d="M13.5 2.5 5 13.5h6l-1 8 8.5-11h-6z"/><path d="M13.5 2.5 5 13.5h6l-1 8 8.5-11h-6z"/>',
  'art-wasser': '<path class="f" d="M12 3s6.5 6.8 6.5 11.3a6.5 6.5 0 0 1-13 0C5.5 9.8 12 3 12 3z"/><path d="M12 3s6.5 6.8 6.5 11.3a6.5 6.5 0 0 1-13 0C5.5 9.8 12 3 12 3z"/><path d="M9 15a3 3 0 0 0 3 3"/>',
  'art-fernwaerme': '<path class="f" d="M10 15V5a2 2 0 0 1 4 0v10a4 4 0 1 1-4 0z"/><path d="M10 15V5a2 2 0 0 1 4 0v10a4 4 0 1 1-4 0z"/><path d="M12 9v9M17 6h3M17 10h2"/>',
  'art-heizoel': '<path class="f" d="M6 6h9l4 4v10.5H6z"/><path d="M6 6h9l4 4v10.5H6zM9 3.5h5V6H9zM6 12h13"/><path d="M12.5 14.5s1.8 1.8 1.8 3a1.8 1.8 0 0 1-3.6 0c0-1.2 1.8-3 1.8-3z"/>',
  'art-pellets': '<path class="f" d="M5 11h14l1.5 9.5h-17z"/><path d="M5 11h14l1.5 9.5h-17zM8 11c-1-2 0-4 2-4s1.5-2.5 3.5-2.5S16 6 15.5 7.5 17 9.5 16 11"/><path d="M9 15h.01M12 17h.01M15 15h.01"/>',
  'art-pv_einspeisung': '<path class="f" d="M3 13h13l3 7.5H6z"/><path d="M3 13h13l3 7.5H6zM8 13l2.5 7.5M13 13l2 7.5M5 16.5h12.5"/><path d="M19 11V3.5M16 6l3-2.5L22 6"/>',
  'art-pv_erzeugung': '<circle class="f" cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r="4.5"/><path d="M12 2.5V5M12 19v2.5M2.5 12H5M19 12h2.5M5.3 5.3l1.8 1.8M16.9 16.9l1.8 1.8M5.3 18.7l1.8-1.8M16.9 7.1l1.8-1.8"/>',
  'art-waerme': '<path d="M7 20.5c0-3 3-3.5 3-7s-3-4-3-7M12.5 20.5c0-3 3-3.5 3-7s-3-4-3-7M18 20.5c0-3 2.5-3.5 2.5-7s-2.5-4-2.5-7"/>',
  home: '<path class="f" d="M5 10.5 12 4l7 6.5V20.5H5z"/><path d="M3 12l9-8.5 9 8.5M5.5 10.2V20.5h13V10.2M10 20.5v-6h4v6"/>',
  aufgaben: '<rect class="f" x="4" y="4" width="16" height="16" rx="3"/><rect x="4" y="4" width="16" height="16" rx="3"/><path d="M8 12.5l2.8 2.8L16 9.5"/>',
  klemmbrett: '<rect class="f" x="5" y="5" width="14" height="16" rx="2"/><rect x="5" y="5" width="14" height="16" rx="2"/><path d="M9 3.5h6V7H9zM9 11.5h6M9 15.5h4"/>',
  eingang: '<path class="f" d="M4 13.5h4.5l1.5 2.5h4l1.5-2.5H20v6.5H4z"/><path d="M4 13.5h4.5l1.5 2.5h4l1.5-2.5H20M4 13.5V20h16v-6.5M12 3.5v8.5M8.5 8.5 12 12l3.5-3.5"/>',
  verlaufskurve: '<path d="M4 4v16h16"/><path class="f" d="M7.5 16l3.5-4.5 3 2.5 4.5-6.5V20h-11z"/><path d="M7.5 16l3.5-4.5 3 2.5 4.5-6.5"/>',
  geld: '<circle class="f" cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="8.5"/><path d="M15.5 8.8a4 4 0 1 0 0 6.4M7.5 11h6M7.5 13.5h5"/>',
  saeulen: '<path d="M4 20.5h16"/><rect class="f" x="5.5" y="11" width="3.5" height="9.5"/><rect x="5.5" y="11" width="3.5" height="9.5"/><rect x="10.5" y="6" width="3.5" height="14.5"/><rect x="15.5" y="14" width="3.5" height="6.5"/>',
  haushalt: '<path class="f" d="M4.5 12 10 7.2l5.5 4.8v8.5h-11z"/><path d="M2.5 13.5 10 6.8l7.5 6.7M4.5 12v8.5h11V12M8.5 20.5v-5h3v5"/><path d="M20 20.5v-5.5"/><circle cx="20" cy="12.5" r="2.5"/>',
  stecker: '<path class="f" d="M7 9h10v3a5 5 0 0 1-10 0z"/><path d="M7 9h10v3a5 5 0 0 1-10 0zM9.5 9V3.5M14.5 9V3.5M12 17v4"/>',
  zahnrad: '<circle class="f" cx="12" cy="12" r="3"/><circle cx="12" cy="12" r="3"/><path d="M12 3v2.5M12 18.5V21M3 12h2.5M18.5 12H21M5.6 5.6l1.8 1.8M16.6 16.6l1.8 1.8M5.6 18.4l1.8-1.8M16.6 7.4l1.8-1.8"/><circle cx="12" cy="12" r="6.5"/>',
  hilfe: '<circle cx="12" cy="12" r="8.5"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.8 2.1c-.9.6-1.3 1.1-1.3 2.1M12 16.8h.01"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  menue: '<path d="M4 7h16M4 12h16M4 17h16"/>',
  haken: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
  kreuz: '<path d="M6 6l12 12M18 6 6 18"/>',
  stift: '<path d="M4 20l1-4.5L16 4.5l3.5 3.5L8.5 19z"/><path d="M14 6.5 17.5 10"/>',
  eimer: '<path d="M4.5 7h15M9.5 7V4h5v3M6.5 7l1 13.5h9l1-13.5M10 11v6M14 11v6"/>',
  herunter: '<path d="M12 3.5V15M7.5 10.5 12 15l4.5-4.5M4.5 20.5h15"/>',
  hoch: '<path d="M12 15V3.5M7.5 8 12 3.5 16.5 8M4.5 20.5h15"/>',
  extern: '<path d="M14 4.5h5.5V10M19.5 4.5 11 13M10 6H5v13h13v-5"/>',
  zurueck: '<path d="M9 7 4.5 11.5 9 16M4.5 11.5H15a4.5 4.5 0 0 1 0 9h-3"/>',
  sortieren: '<path d="M8 4v16M4.5 7.5 8 4l3.5 3.5M16 20V4M12.5 16.5 16 20l3.5-3.5"/>',
  links: '<path d="M14.5 5.5 8 12l6.5 6.5"/>',
  rechts: '<path d="M9.5 5.5 16 12l-6.5 6.5"/>',
  pause: '<path d="M8.5 5v14M15.5 5v14"/>',
  abspielen: '<path d="M7 4.5v15L19 12z"/>',
  warten: '<path d="M7 3.5h10M7 20.5h10M8 3.5c0 5 8 6 8 8.5s-8 3.5-8 8.5M16 3.5c0 5-8 6-8 8.5s8 3.5 8 8.5"/>',
  suche: '<circle class="f" cx="11" cy="11" r="6.5"/><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/>',
  anhang: '<path d="M19.5 11.5 12 19a4.5 4.5 0 0 1-6.4-6.4l8-8a3 3 0 0 1 4.3 4.3L10 16.8a1.5 1.5 0 0 1-2.1-2.1l7-7"/>',
  kalender: '<rect class="f" x="4" y="5.5" width="16" height="15" rx="2"/><rect x="4" y="5.5" width="16" height="15" rx="2"/><path d="M4 10h16M8.5 3.5V7M15.5 3.5V7"/>',
  stoppuhr: '<circle class="f" cx="12" cy="13.5" r="7"/><circle cx="12" cy="13.5" r="7"/><path d="M12 9.5v4l2.5 2M9.5 3.5h5M12 3.5v3"/>',
  glocke: '<path class="f" d="M6 16.5V11a6 6 0 0 1 12 0v5.5l1.5 2h-15z"/><path d="M6 16.5V11a6 6 0 0 1 12 0v5.5l1.5 2h-15zM10 21h4"/>',
  merker: '<path class="f" d="M9 3.5h6l-1 7 3 3.5H7l3-3.5z"/><path d="M9 3.5h6l-1 7 3 3.5H7l3-3.5zM12 14v6.5"/>',
  schluessel: '<circle class="f" cx="8" cy="15.5" r="4.5"/><circle cx="8" cy="15.5" r="4.5"/><path d="M11.3 12.2 19.5 4M16 7.5l2.5 2.5M13.5 10l2 2"/>',
  schloss: '<rect class="f" x="5" y="10.5" width="14" height="10" rx="2"/><rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3M12 14.5v2"/>',
  schloss_offen: '<rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8 10.5V7.5a4 4 0 0 1 7.5-1.8M12 14.5v2"/>',
  schild: '<path class="f" d="M12 3l7.5 3v5.5c0 4.5-3 8-7.5 9.5-4.5-1.5-7.5-5-7.5-9.5V6z"/><path d="M12 3l7.5 3v5.5c0 4.5-3 8-7.5 9.5-4.5-1.5-7.5-5-7.5-9.5V6z"/><path d="M8.8 12l2.3 2.3 4.2-4.3"/>',
  puls: '<path d="M3 12.5h4l2.5-6 4 11 2.5-5h5"/>',
  info: '<circle class="f" cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="8.5"/><path d="M12 11v5.5M12 7.8h.01"/>',
  achtung: '<path class="f" d="M12 4 21 19.5H3z"/><path d="M12 4 21 19.5H3zM12 10v4.5M12 17.2h.01"/>',
  fehler: '<path class="f" d="M8.5 3.5h7l5 5v7l-5 5h-7l-5-5v-7z"/><path d="M8.5 3.5h7l5 5v7l-5 5h-7l-5-5v-7zM12 8v5M12 16.2h.01"/>',
  erfolg: '<circle class="f" cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="8.5"/><path d="M8 12.5l2.8 2.8L16.2 9.5"/>',
  neutral: '<circle cx="12" cy="12" r="8.5"/><path d="M8 12h8"/>',
  idee: '<path class="f" d="M12 3.5a6 6 0 0 0-3.5 10.9V17h7v-2.6A6 6 0 0 0 12 3.5z"/><path d="M12 3.5a6 6 0 0 0-3.5 10.9V17h7v-2.6A6 6 0 0 0 12 3.5zM9.5 20.5h5"/>',
  neu: '<path d="M12 3.5l1.8 5.2 5.2 1.8-5.2 1.8L12 17.5l-1.8-5.2L5 10.5l5.2-1.8zM18.5 16v4M16.5 18h4"/>',
  tasse: '<path class="f" d="M5 9.5h11.5V15a4.5 4.5 0 0 1-4.5 4.5H9.5A4.5 4.5 0 0 1 5 15z"/><path d="M5 9.5h11.5V15a4.5 4.5 0 0 1-4.5 4.5H9.5A4.5 4.5 0 0 1 5 15zM16.5 11h1.5a2.5 2.5 0 0 1 0 5h-1.5M8.5 3.5v3M12.5 3.5v3"/>',
  // v3.12.0 (M6) — Fußzeile: Stern (Projekt auf GitHub) und Herz (Unterstützen)
  stern: '<path class="f" d="M12 4.3l2.2 5.5 5.9.4-4.6 3.7 1.5 5.8-5-3.2-5 3.2 1.5-5.8-4.6-3.7 5.9-.4z"/><path d="M12 4.3l2.2 5.5 5.9.4-4.6 3.7 1.5 5.8-5-3.2-5 3.2 1.5-5.8-4.6-3.7 5.9-.4z"/>',
  herz: '<path class="f" d="M12 8.2A4.1 4.1 0 0 0 4 9.6c0 4.8 5 8 8 10 3-2 8-5.2 8-10a4.1 4.1 0 0 0-8-1.4z"/><path d="M12 8.2A4.1 4.1 0 0 0 4 9.6c0 4.8 5 8 8 10 3-2 8-5.2 8-10a4.1 4.1 0 0 0-8-1.4z"/>',
  etikett: '<path class="f" d="M4 4.5h8l8.5 8.5-7.5 7.5L4.5 12z"/><path d="M4 4.5h8l8.5 8.5-7.5 7.5L4.5 12zM8.5 8.5h.01"/>',
  globus: '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.8 2.5 4 5.5 4 8.5s-1.2 6-4 8.5c-2.8-2.5-4-5.5-4-8.5s1.2-6 4-8.5z"/>',
  co2: '<path class="f" d="M7 18.5a4 4 0 0 1-.6-8 5.5 5.5 0 0 1 10.6-1 4.5 4.5 0 0 1 .5 9z"/><path d="M7 18.5a4 4 0 0 1-.6-8 5.5 5.5 0 0 1 10.6-1 4.5 4.5 0 0 1 .5 9z"/>',
  bausteine: '<rect class="f" x="4" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/>',
  kolben: '<path class="f" d="M6.5 20.5h11L13.5 12V4h-3v8z"/><path d="M9 3.5h6M10.5 3.5V12L5.5 20.5h13L13.5 12V3.5M8.5 15.5h7"/>',
  lineal: '<path class="f" d="M3 15.5 15.5 3 21 8.5 8.5 21z"/><path d="M3 15.5 15.5 3 21 8.5 8.5 21zM7 11.5l2 2M10 8.5l2 2M13 5.5l2 2"/>',
  medaille: '<circle class="f" cx="12" cy="14.5" r="6"/><circle cx="12" cy="14.5" r="6"/><path d="M8.5 9.5 6 3.5h4l2 4 2-4h4l-2.5 6"/>',
  schaubild: '<rect class="f" x="3.5" y="5" width="17" height="14" rx="2"/><rect x="3.5" y="5" width="17" height="14" rx="2"/><path d="M7 15l3-3.5 2.5 2 4.5-5"/>',
  beleg: '<path class="f" d="M6 3.5h12v17l-2-1.3-2 1.3-2-1.3-2 1.3-2-1.3-2 1.3z"/><path d="M6 3.5h12v17l-2-1.3-2 1.3-2-1.3-2 1.3-2-1.3-2 1.3zM9 8h6M9 12h6"/>',
  dokument: '<path class="f" d="M6 3.5h8l4.5 4.5v12.5H6z"/><path d="M6 3.5h8l4.5 4.5v12.5H6zM14 3.5V8h4.5M9 13h6M9 16.5h4"/>',
  dokumente: '<path d="M8 3.5h7l4 4V17H8zM15 3.5v4h4M5 7v13.5h10"/>',
  ordner: '<path class="f" d="M3.5 6h6l2 2.5h9v11h-17z"/><path d="M3.5 6h6l2 2.5h9v11h-17z"/>',
  diskette: '<path class="f" d="M4 4h13l3 3v13H4z"/><path d="M4 4h13l3 3v13H4zM8 4v5h7V4M8 20v-6.5h8V20"/>',
  kamera: '<path class="f" d="M3.5 8h4L9 5.5h6L16.5 8h4v11.5h-17z"/><path d="M3.5 8h4L9 5.5h6L16.5 8h4v11.5h-17z"/><circle cx="12" cy="13" r="3.5"/>',
  auto: '<path class="f" d="M4 12.5 6 7.5h12l2 5v5H4z"/><path d="M4 12.5 6 7.5h12l2 5v5H4zM4 12.5h16M7 17.5V20M17 17.5V20M7.5 15h.01M16.5 15h.01"/>',
  lieferung: '<path class="f" d="M3 7h11v10.5H3z"/><path d="M3 7h11v10.5H3zM14 10.5h4l3 3.5v3.5h-7z"/><circle cx="7.5" cy="18" r="1.8"/><circle cx="17" cy="18" r="1.8"/>',
  person: '<circle class="f" cx="12" cy="8" r="4"/><circle cx="12" cy="8" r="4"/><path d="M4.5 20.5c.8-4 3.7-6 7.5-6s6.7 2 7.5 6"/>',
  personen: '<circle class="f" cx="9" cy="8.5" r="3.5"/><circle cx="9" cy="8.5" r="3.5"/><path d="M2.5 20c.7-3.5 3.2-5.5 6.5-5.5s5.8 2 6.5 5.5M15.5 5.3a3.5 3.5 0 0 1 0 6.4M18 14.8c1.9.8 3.1 2.6 3.5 5.2"/>',
  brief: '<rect class="f" x="3.5" y="5.5" width="17" height="13" rx="2"/><rect x="3.5" y="5.5" width="17" height="13" rx="2"/><path d="M4 7l8 6 8-6"/>',
  ki: '<rect class="f" x="4" y="7" width="16" height="12" rx="3"/><rect x="4" y="7" width="16" height="12" rx="3"/><path d="M12 7V3.5M9 12.5h.01M15 12.5h.01M9.5 16h5M2 12v3M22 12v3"/>',
  ausgeblendet: '<path d="M3.5 3.5l17 17M10 5.3A10 10 0 0 1 21.5 12c-.6 1.2-1.4 2.3-2.3 3.2M6.5 7.2A11 11 0 0 0 2.5 12c2 3.8 5.5 6 9.5 6 1.3 0 2.5-.2 3.6-.7M10.2 10.2a2.5 2.5 0 0 0 3.6 3.6"/>',
  waage: '<path d="M12 3.5v17M7.5 20.5h9M5 7h14M5 7l-3 7a3.5 3.5 0 0 0 6 0zM19 7l-3 7a3.5 3.5 0 0 0 6 0z"/>',
  bild: '<rect class="f" x="3.5" y="5" width="17" height="14" rx="2"/><rect x="3.5" y="5" width="17" height="14" rx="2"/><path d="M3.5 16l5-5 4 4 2.5-2.5 5.5 5.5M15.5 9.5h.01"/>',
  buch: '<path class="f" d="M12 6.5C10 5 7 4.5 3.5 4.5v14c3.5 0 6.5.5 8.5 2z"/><path d="M12 6.5C10 5 7 4.5 3.5 4.5v14c3.5 0 6.5.5 8.5 2 2-1.5 5-2 8.5-2v-14C17 4.5 14 5 12 6.5zM12 6.5v14"/>',
  sonne: '<circle cx="12" cy="12" r="4"/><path d="M12 3v2M12 19v2M3 12h2M19 12h2M5.6 5.6 7 7M17 17l1.4 1.4M5.6 18.4 7 17M17 7l1.4-1.4"/>',
  mond: '<path class="f" d="M19.5 14.5A8 8 0 0 1 9.5 4.5a8 8 0 1 0 10 10z"/><path d="M19.5 14.5A8 8 0 0 1 9.5 4.5a8 8 0 1 0 10 10z"/>',
  stadt: '<path d="M4 20.5V9l6-3v14.5M10 20.5V4h10v16.5M2.5 20.5h19M13.5 8h3M13.5 12h3M13.5 16h3M6.5 12h1M6.5 16h1"/>',
  block: '<rect class="f" x="5.5" y="3.5" width="13" height="17" rx="1"/><rect x="5.5" y="3.5" width="13" height="17" rx="1"/><path d="M9 7.5h1.5M13.5 7.5H15M9 11.5h1.5M13.5 11.5H15M10.5 20.5v-4.5h3v4.5"/>',
  hahn: '<path d="M4 9h9a4 4 0 0 1 4 4v1.5h-4V13H4zM9 9V5.5M6.5 5.5h5M17 18.5s1.2 1.3 0 2.5c-1.2-1.2 0-2.5 0-2.5z"/>',
  regen: '<path class="f" d="M7 14.5a4 4 0 0 1-.6-8 5.5 5.5 0 0 1 10.6-1 4.5 4.5 0 0 1 .5 9z"/><path d="M7 14.5a4 4 0 0 1-.6-8 5.5 5.5 0 0 1 10.6-1 4.5 4.5 0 0 1 .5 9zM9 17.5 8 20M13 17.5 12 20M17 17.5 16 20"/>',
  bonus: '<rect class="f" x="4" y="9" width="16" height="11.5" rx="1"/><rect x="4" y="9" width="16" height="11.5" rx="1"/><path d="M12 9v11.5M4 13.5h16M12 9c-1.5-4-6-5-6-2.5S9.5 9 12 9zM12 9c1.5-4 6-5 6-2.5S14.5 9 12 9z"/>',
  abschlag: '<rect class="f" x="3.5" y="6.5" width="17" height="12" rx="2"/><rect x="3.5" y="6.5" width="17" height="12" rx="2"/><path d="M3.5 10.5h17M7 15h3"/>',
  stufe1: '<path d="M12 20.5V12"/><path class="f" d="M12 12c0-3.5-2.5-5.5-6.5-5.5C5.5 10 8 12 12 12z"/><path d="M12 12c0-3.5-2.5-5.5-6.5-5.5C5.5 10 8 12 12 12zM5 20.5h14"/>',
  stufe2: '<path d="M12 20.5V8M5 20.5h14"/><path class="f" d="M12 13c0-3-2-5-6-5 0 3.2 2 5 6 5zM12 9.5c0-3 2-5 6-5 0 3.2-2 5-6 5z"/><path d="M12 13c0-3-2-5-6-5 0 3.2 2 5 6 5zM12 9.5c0-3 2-5 6-5 0 3.2-2 5-6 5z"/>',
  stufe3: '<path class="f" d="M12 3a5.5 5.5 0 0 0-5 7.8A4.5 4.5 0 0 0 9 18.5h6a4.5 4.5 0 0 0 2-7.7A5.5 5.5 0 0 0 12 3z"/><path d="M12 3a5.5 5.5 0 0 0-5 7.8A4.5 4.5 0 0 0 9 18.5h6a4.5 4.5 0 0 0 2-7.7A5.5 5.5 0 0 0 12 3zM12 21v-9M12 15l3-3M5 21h14"/>',
  // v3.12.0 (M4) — Trend statt ▲▼: Kurve mit Pfeil, „etwa gleich“ als zwei Wellen
  'trend-hoch': '<path d="M3.5 17.5 9 12l4 3.5 7-8"/><path d="M15 7.5h5v5"/>',
  'trend-runter': '<path d="M3.5 6.5 9 12l4-3.5 7 8"/><path d="M15 16.5h5v-5"/>',
  'trend-gleich': '<path d="M4 9.5c2.7-2.6 5.3-2.6 8 0s5.3 2.6 8 0M4 15.5c2.7-2.6 5.3-2.6 8 0s5.3 2.6 8 0"/>',
};

const SIZES = new Set([16, 18, 20, 22, 24, 48]);

/**
 * Eine Grafik als sicheres HTML.
 * @param {string} name  Schlüssel aus ICONS
 * @param {{size?: number, title?: string, cls?: string}} [opts]
 *        size: 16 Abzeichen und Reiter, 18 Menü und Kartentitel (Standard), 20 Infobox und Listen,
 *        22 Tab-Leiste, 48 leerer Zustand; title: macht die Grafik zum beschrifteten Bild (nur ohne sichtbaren Text)
 */
export function icon(name, { size = 18, title = '', cls = '' } = {}) {
  const key = Object.prototype.hasOwnProperty.call(ICONS, name) ? name : 'neutral';
  const klass = ['ico', SIZES.has(size) && size !== 18 ? `ico--${size}` : '', cls].filter(Boolean).join(' ');
  if (title) {
    return `<svg class="${klass}" role="img" aria-label="${escapeAttr(title)}" focusable="false"><use href="#i-${key}"/></svg>`;
  }
  return `<svg class="${klass}" aria-hidden="true" focusable="false"><use href="#i-${key}"/></svg>`;
}

/** Name der Grafik einer Verbrauchsart: `glyph` aus der SSOT, sonst nach dem Schlüssel. */
export function artGlyph(utility) {
  const key = typeof utility === 'string' ? utility : utility?.key;
  const glyph = typeof utility === 'object' ? utility?.glyph : '';
  if (glyph && Object.prototype.hasOwnProperty.call(ICONS, glyph)) return glyph;
  const byKey = `art-${key}`;
  return Object.prototype.hasOwnProperty.call(ICONS, byKey) ? byKey : 'neutral';
}

/** Grafik einer Verbrauchsart in ihrer Farbe (--util-<key>). @param {object|string} utility */
export function artIcon(utility, { size = 18, title = '', cls = '' } = {}) {
  const key = typeof utility === 'string' ? utility : utility?.key;
  const safe = /^[a-z0-9_-]+$/i.test(String(key || '')) ? key : '';
  return icon(artGlyph(utility), { size, title, cls: ['ico--art', safe ? `art-${safe}` : '', cls].filter(Boolean).join(' ') });
}

function escapeAttr(s) {
  return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/**
 * Zeichen eines Zählers: Was jemand selbst eingetragen hat, bleibt stehen (auch ein Emoji);
 * der Vorgabewert aus der SSOT wird zur Grafik der Art.
 */
export function meterGlyph(meter, utility, opts = {}) {
  const own = String(meter?.icon ?? '').trim();
  if (own && own !== utility?.icon) return `<span class="ico-eigen" aria-hidden="true">${escapeAttr(own)}</span>`;
  return artIcon(utility, opts);
}

/** Sprite einmal ins Dokument hängen. */
export function mountIconSprite(doc = document) {
  if (doc.getElementById('et-icon-sprite')) return;
  const host = doc.createElement('div');
  host.id = 'et-icon-sprite';
  host.setAttribute('aria-hidden', 'true');
  host.className = 'ico-sprite';
  host.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg"><defs>${Object.entries(ICONS)
    .map(([k, v]) => `<symbol id="i-${k}" viewBox="0 0 24 24">${v}</symbol>`).join('')}</defs></svg>`;
  doc.body.prepend(host);
}
