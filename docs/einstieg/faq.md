# Fragen und Antworten

**Deutsch** · [English](../en/einstieg/faq.md)

[← Kompendium-Index](../README.md)

Kurze Antworten auf die häufigsten Fragen. Wenn etwas nicht funktioniert, hilft
die [Fehlersuche](../betrieb/fehlersuche.md); Begriffe erklärt die Hilfe in der
App und das [Glossar](../verstehen/09-glossar.md).

---

## Erste Schritte

### Brauche ich Home Assistant oder einen Smart Meter?

Nein. Zählerstände lassen sich von Hand eintragen — am schnellsten in der
**Zählerstand-Erfassung**, die alle Zähler in einem Durchgang abfragt. Home
Assistant ist eine Möglichkeit, die Stände automatisch zu liefern
([Anleitung](../anleitungen/home-assistant.md)).

### Wie oft muss ich ablesen?

Einmal im Monat genügt, seltener geht auch. Der Verbrauch entsteht aus der
Differenz zweier Stände und wird tagesgenau auf die Monate verteilt; je dichter
die Stände, desto genauer die Monate. Nach 45 Tagen ohne Ablesung erinnert die
Übersicht unter „Zu tun“ (einstellbar unter Einstellungen → Allgemein).

### Kann ich erst einmal ausprobieren, ohne etwas einzutragen?

Ja: Auf der leeren Übersicht **„Mit Beispieldaten ausprobieren“**, später unter
Einstellungen → Daten → „Demo-Daten laden“. Vorher sichert die App den jetzigen
Stand; zurück geht es über Einstellungen → Daten → Gespeicherte Snapshots (↩️).

### Kann ich alte Werte aus Excel übernehmen?

Ja, als CSV: Zählerstände je Zähler über **⚙️ Zähler → CSV-Import** — Datum
(`TT.MM.JJJJ` oder `JJJJ-MM-TT`) und Stand, getrennt durch Semikolon oder Komma,
Notiz und „geschätzt“ optional; eine Vorschau zeigt jede Zeile, bevor etwas
geschrieben wird, und ein Beispiel lässt sich dort herunterladen. Temperaturen
kommen unter Einstellungen → Wetterdaten als `TT.MM.JJJJ;Mittel;Min;Max`.

## Rechnen und Anzeigen

### Warum steht bei „Kosten“ nichts?

Kosten entstehen aus einem Vertrag: ohne Vertrag mit Arbeitspreis für den
Zeitraum keine Kosten. Unter **Kosten & Verträge → Verträge & Abschläge** sieht
man, welche Zähler einen haben.

### Was bedeuten „+“ und „−“ beim Saldo?

„+“ ist ein **Guthaben** (zu viel gezahlt, Geld zurück), „−“ eine
**Nachzahlung**. Karten und Kacheln schreiben es aus: „Guthaben 120,00 €“.
Bei der PV-Einspeisung ist „+“ ein offener Anspruch gegenüber dem
Netzbetreiber.

### Warum weicht der Saldo von meiner Abrechnung ab?

Meist aus einem dieser Gründe:

- Der **Abrechnungsstichtag** ist ein anderer (Einstellungen → Verbrauchsarten
  & Abrechnung).
- Eine **Rückzahlung oder Nachzahlung** der Vorjahresabrechnung fehlt — sie
  gehört als Sonderzahlung an den Vertrag.
- Bei Gas fehlen die **Umrechnungsfaktoren** der Rechnung (Zustandszahl ×
  Brennwert).
- Die Rechnung rechnet mit einem **geschätzten Stand** (Ableseart „E“), die App
  mit einem abgelesenen.

Die Anleitung [Jahresabrechnung eintragen und prüfen](../anleitungen/jahresabrechnung.md)
geht alle Punkte durch; die **Rechnungsprüfung** rechnet eine Gasrechnung
Abschnitt für Abschnitt nach.

### Warum ist mein Gasverbrauch in kWh anders als auf der Rechnung?

Der Gaszähler zählt Kubikmeter; kWh entstehen erst über Zustandszahl ×
Brennwert, und beide ändern sich von Jahr zu Jahr. Solange nur der
Standardfaktor 11,5 eingetragen ist, weicht das Ergebnis ab. Die Faktoren der
Rechnung gehören mit ihrem Stichtag in die Liste unter Einstellungen →
Verbrauchsarten & Abrechnung.

### Warum sind Analyse oder Prognose leer?

Weil noch zu wenig Daten da sind. Die Wetterbereinigung braucht 12 Monate mit
mindestens 8 verwertbaren Punkten, Anomalien 5 Monate, eine Effizienzklasse ein
ganzes Jahr (360 abgedeckte Tage) und die Wohnfläche. Außerdem braucht jede
Auswertung mit Heizgradtagen Temperaturen für den eigenen Standort (Einstellungen
→ Wetterdaten).

### Warum fehlt die Effizienzklasse?

Klassen gibt es nur für ganze Jahre und nur in Ländern mit einer Skala
(Deutschland: GEG). Anderswo zeigt die Karte kWh/m²·a und nennt den Grund.

### Was heißt „geschätzt“?

Zwischen der letzten Ablesung und heute kennt die App keinen Stand. Für den
Saldo nach Kalender schätzt sie den Verbrauch aus dem Heizmodell bzw. dem
üblichen Tagesverbrauch und sagt, ab wann geschätzt ist. Bei Heizöl und Pellets
ist alles nach der letzten bekannten Füllmenge geschätzt.

### Was ist eine Zäsur?

Eine bauliche Änderung mit Datum — neue Heizung, Dämmung, Fenster —, die am
Zähler eingetragen wird. Ab dort rechnen alle Auswertungen neu, und eine
Vorher-nachher-Kennzahl zeigt die Wirkung ([Szenario Eigenheim](../verstehen/08-szenario-eigenheim.md)).

### Ich wohne nicht in Deutschland — geht das?

Ja. Das Land unter Einstellungen → Allgemein → Sprache & Land setzt Währung,
Formate, Zeitzone, Wetterstandort, Heizgrenze, CO₂-Faktor und Gaseinheiten.
Deutsch geprägt bleiben Effizienzklassen und Sonderkündigungsrecht
([Länderprofile](../verstehen/14-laenderprofile.md)).

## Daten und Betrieb

### Wo liegen meine Daten, und wie sichere ich sie?

Als JSON-Dateien im Datenverzeichnis (`data/`, im Docker-Container `/data`).
Ein vollständiges Backup lädt Einstellungen → Daten → **JSON-Backup
herunterladen**; vor jedem Import legt die App selbst einen Snapshot an.

### Welche Daten verlassen meinen Server?

Nur die Anfragen an Open-Meteo: der tägliche Wetterabgleich mit dem auf rund
1 km gerundeten Standort (abschaltbar unter Einstellungen → Wetterdaten) und
die Ortssuche, wenn du sie benutzt. Keine Konten, keine Telemetrie, keine
Werbung.

### Kann ich die App auf dem iPhone nutzen?

Ja, im Browser im Heimnetz; als App auf dem Home-Bildschirm mit HTTPS. Siehe
[Auf dem Handy nutzen](handy.md).

### Wie aktualisiere ich?

Docker: das neue Image holen und den Container neu anlegen — das Datenvolume
bleibt. Ohne Docker: `git pull` (oder die Dateien ersetzen). Datenformate hebt
die App beim Start selbst an; ein Backup vorher schadet nie
([Installation](../betrieb/installation.md)).

### Kann ich die App aus dem Internet erreichbar machen?

Ja, aber nur mit Anmeldung (Einstellungen → Zugriff) und hinter HTTPS. Die
Checkliste steht unter [Sicherheit & Netzbetrieb](../betrieb/sicherheit.md).

### Was kostet der Energietracker?

Nichts. Er steht unter der MIT-Lizenz.

### Wo melde ich einen Fehler oder einen Wunsch?

In den [GitHub-Issues](https://github.com/Bingerminger/energietracker/issues).
Bei einem Fehler hilft die Diagnose unter Einstellungen → System (Version,
Schema, Schreibrechte).

---

[← Kompendium-Index](../README.md)
