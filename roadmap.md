# Energietracker — Roadmap

Was als Nächstes kommt, in einfachen Worten. Woran gerade gearbeitet wird, zeigt das
[Projektboard](https://github.com/users/Bingerminger/projects/1), den Stand jedes Releases die
[Milestones](https://github.com/Bingerminger/energietracker/milestones). Eigene Wünsche
kannst du jederzeit unter [Discussions → Ideas](https://github.com/Bingerminger/energietracker/discussions/categories/ideas)
einreichen.

*In English:* [roadmap.en.md](roadmap.en.md). Für Mitwirkende: die [Planung](roadmap-planung.md) mit
allen offenen Punkten und Regeln, frühere Konzepte im [Archiv](roadmap-archiv.md).

**Stand:** 28.10.2026 · **Aktuelle Version:** v3.9.0

---

## Jetzt in Arbeit: v3.10.0 — Versprochen und abgesichert

- **Was wir euch zugesagt haben:**
  - Auslesen mit neueren OpenAI-Modellen ([#41](https://github.com/Bingerminger/energietracker/issues/41)).
  - Vorjahresvergleich und richtige Beschriftung im Abrechnungsjahr, kein Sprung nach oben beim
    Umschalten ([#36](https://github.com/Bingerminger/energietracker/issues/36)).
  - Der Abschlagsvorschlag kurz vor der Abrechnung, klarer erklärt ([#38](https://github.com/Bingerminger/energietracker/issues/38)).
  - Benachrichtigungen an Home Assistant auch mit eigenem Zertifikat im Heimnetz ([#36](https://github.com/Bingerminger/energietracker/issues/36)).
  - Messkonzept als Kaskade je Zählwerk und mit Einspeisung an jedem Zähler ([#40](https://github.com/Bingerminger/energietracker/issues/40)).
  - Zwei Salden auf einer Seite, die sich nicht mehr widersprechen ([#49](https://github.com/Bingerminger/energietracker/issues/49), aus [Discussion #42](https://github.com/Bingerminger/energietracker/discussions/42)).
- **Kleinere Fehler:** Folgevertrag ab Stichtag, Anomalie am Hauptzähler mit Subzählern, Rechnungskorrekturen
  im Posteingang, Anfangsstand einer Rechnung.
- **Sicherheit:** ein schlankeres Docker-Image, signiert und mit Stückliste der Pakete; klare Fristen in
  [SECURITY.md](SECURITY.md).
- **Mitmachen:** Wünsche über Discussions, sichtbarer Stand je Release, ein Dank an alle, die das Projekt
  unterstützen.

---

## Als Nächstes

Die Reihenfolge steht, die Termine werden je Release angekündigt. Ein Release hat ein Thema und
erscheint, wenn es fertig und geprüft ist, in der Regel nach ein bis drei Wochen.

| Version | Thema | Worum es geht |
|---|---|---|
| **v3.11.0** | **Deine Rechte als Kunde** | Der Energietracker prüft, ob Fristen und Regeln eingehalten werden: Kommt die Rechnung zu spät? Ist der Abschlag zu hoch? Darfst du nach einer Preiserhöhung kündigen, und bis wann? Fehlt ein Bonus? Wurde geschätzt, obwohl du abgelesen hast? Ist das Entgelt für den Messstellenbetrieb zu hoch? Läuft die Frist für einen Widerspruch gegen den Gebührenbescheid? Dazu der passende Brief als Vorlage. |
| **v3.12.0** | **Energietracker für deine KI** | Wer eine KI nutzt, schließt sie über MCP an den Energietracker an: „Hier ist meine Gasrechnung, trag sie ein und sag mir, ob sie stimmt.“ Der Energietracker bleibt die Akte und rechnet, jede Zahl mit Herkunft. Dazu die Home-Assistant-Integration über HACS. |
| **v3.13.0** | **Lange Verläufe und Umzug** | Viele Jahre Daten bleiben schnell, Verbrauch zwischen zwei Ablesungen wird nach dem Wetter verteilt, und wer aus einer anderen App kommt, bringt seine Historie mit. |
| **v3.14.0** | **Für alle verständlich** | Anleitungen auf Französisch, Italienisch, Spanisch, Portugiesisch und Niederländisch, Länderprofile für Österreich und die Schweiz. |

---

## Später

- **v4.0.0 — Mehrere Objekte:** mehrere Immobilien und kleine Verwaltungen in einer Installation,
  Nebenkosten- und Heizkostenabrechnung als Vermieter, Aufteilung des CO₂-Preises. Vorher fragen wir in
  Discussions, was ihr dafür braucht.
- **Speicher mit Netz- und Solarstrom (MiSpeL),** sobald die Regeln dafür feststehen, spätestens 2027.
- **Jedes Jahr:** CO₂-Preis und Strompreispauschale, sobald sie veröffentlicht sind.

## Bewusst nicht geplant

- Eine eigene Auslesung von Smart Metern: Das macht Home Assistant, der Energietracker übernimmt die Werte.
- Ein Cloud-Dienst mit Konten: Deine Daten bleiben auf deinem Gerät.
- Eine eigene Steuerung von Speicher und Auto: Das machen evcc und Home Assistant.

---

## Wünsche und Fehler melden

- **Ideen** unter [Discussions → Ideas](https://github.com/Bingerminger/energietracker/discussions/categories/ideas).
  Gib bestehenden Ideen ein 👍, so sehen wir, was vielen wichtig ist. Vor jedem Release werden die Ideen
  bewertet: Nutzen für viele, Nähe zu Rechnung und Verständnis, Aufwand, Datenschutz. Angenommene Ideen
  bekommen einen Milestone, abgelehnte eine kurze Begründung.
- **Fehler** als [Issue](https://github.com/Bingerminger/energietracker/issues/new/choose), am besten mit
  Version, Schritten und einem erfundenen Beispiel statt echter Rechnungen.
- **Sicherheitslücken** bitte nicht öffentlich, sondern wie in [SECURITY.md](SECURITY.md) beschrieben.

---

## Zuletzt erschienen

| Version | Datum | Thema |
|---|---|---|
| v3.9.0 | 27.10.2026 | Jeder Vertrag zahlt nur seinen Teil: Messkonzepte, Home Assistant, der Vertrag aus der Rechnung |
| v3.7.0 | 22.10.2026 | Post vom Versorger: Dokumente automatisch einlesen und verwalten |
| v3.6.0 | 20.10.2026 | Was wäre wenn, und wie auf der Rechnung |
| v3.5.0 | 14.10.2026 | Wer, wann, wohin |
| v3.4.0 | 13.10.2026 | Klar und sicher |
| v3.3.0 | 12.10.2026 | Was Flexibilität wert ist |
| v3.2.0 | 09.10.2026 | Leicht für jeden |
| v3.1.0 | 07.10.2026 | Für jeden Haushalt |
| v3.0.0 | 07.10.2026 | Frei zum Ausprobieren |

Alle Änderungen im Detail: [CHANGELOG](CHANGELOG.md).
