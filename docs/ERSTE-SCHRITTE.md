# Erste Schritte

[English](en/getting-started.md) · **Deutsch**

[← Kompendium-Index](README.md)

Diese Anleitung führt dich **an einem durchgehenden Beispiel** von der
Installation bis zur ersten Prognose. Wir richten einen Haushalt mit
**Gas-Heizung und Stromanschluss** ein. Wenn du nur ausprobieren willst, kannst
du auch direkt die [Demo-Daten laden](#abkürzung-demo-daten) und bei
[Schritt 6](#6-auswerten) einsteigen.

> **Zeitbedarf:** ~15 Minuten. Du brauchst: einen aktuellen Zählerstand und (für
> die Kostenrechnung) deinen Liefervertrag.

---

## 0. Installieren & öffnen

Energietracker läuft lokal — kein Cloud-Konto, keine Datenbank. Die schnellste
Variante ist Docker:

```bash
docker run -d --name energietracker -p 8080:80 \
  -v "$PWD/data:/data" \
  ghcr.io/bingerminger/energietracker:latest
```

Dann im Browser **http://localhost:8080** öffnen. (Andere Wege — PHP-Server,
Apache, nginx — stehen in [Installation](technical/01-installation.md) und
[Docker](technical/07-docker.md).)

Beim ersten Start legt die App automatisch Standard-Zähler für Gas, Strom und
Wasser an. Du kannst sie behalten, umbenennen oder löschen.

---

## 1. Verbrauchsarten wählen

Öffne **System → Einstellungen** (Zahnrad in der Sidebar) → **Aktive
Verbrauchsarten** und hake an, was du nutzt. Für unser Beispiel genügen **Gas**
und **Strom**. Nicht aktivierte Arten verschwinden aus Sidebar und Dashboard
(die Daten bleiben erhalten, falls du sie später wieder einschaltest).

---

## 2. Ersten Zähler einrichten

Gehe in der Sidebar auf **Gas** und dann auf **Zähler / Verträge**.

1. Den vorhandenen *„Hauptzähler"* anklicken und ggf. umbenennen (z. B.
   *„Gas-Therme Keller"*).
2. Beim Anlegen/Bearbeiten gibst du den **Anfangsstand** und das **Einbaudatum**
   des Geräts an — das ist der Startpunkt der Messung.

> **Zählertausch später?** Kein Problem: Energietracker modelliert jeden Zähler
> als Kette von Geräten. Beim Tausch trägst du Endstand alt + Anfangsstand neu
> ein, der Verbrauch wird über die Tauschgrenze hinweg lückenlos gerechnet.

---

## 3. Zählerstände erfassen

Zwei Wege:

- **Schnell für alle Zähler:** Sidebar → **Erfassung → Zählerstände**. Diese
  Ansicht listet alle aktiven Zähler mit dem jeweils letzten Stand als
  Orientierung — ideal fürs monatliche Ablesen am Handy.
- **Pro Zähler:** in der Gas-Ansicht direkt in die Zählerstand-Tabelle.

Trage mindestens **zwei** Stände mit zeitlichem Abstand ein (z. B. Jahresanfang
und heute) — erst aus der **Differenz** entsteht ein Verbrauch.

> **Viele Altdaten?** Du kannst eine CSV je Zähler importieren
> (`datum;zaehlerstand;notiz;geschaetzt`). Format und Beispiel: in der
> Zähler-Ansicht unter „CSV-Import".

---

## 4. Vertrag hinterlegen (für die Kostenrechnung)

In der Gas-Ansicht → **Zähler / Verträge → Neuer Vertrag**:

- **Anbieter** und **Tarifname**, **Start/Ende**,
- **Arbeitspreis** (ct/kWh) und **Grundpreis** (€/Monat) — beide mit Datum,
  sodass Preiswechsel stichtagsgenau abgebildet werden,
- optional **Abschlag** (€/Monat) für die Saldo-Rechnung und **Boni**.

Ab jetzt rechnet die App nicht nur Verbrauch, sondern auch **Kosten** und den
**Saldo** gegen deine Abschläge (Nachzahlung/Erstattung).

---

## 5. Temperaturen holen (für Heizanalyse & Prognose)

Damit „mehr verbraucht oder nur kälter?" beantwortet werden kann, braucht Gas
(und Fernwärme) Außentemperaturen. Sidebar → **Verbrauch → Temperaturen**:

- **Open-Meteo-Sync** für den in den Einstellungen hinterlegten Standort
  (Default: Leipzig — auf deinen Ort ändern!), **oder**
- eine Temperatur-CSV importieren.

Ohne Temperaturen funktionieren Verbrauch, Kosten und Saldo trotzdem — nur die
wetterbereinigte Analyse und die HGT-Prognose brauchen sie.

---

## 6. Auswerten

Jetzt zahlt sich die Eingabe aus:

- **Übersicht (Dashboard):** 12-Monats-Kennzahlen, Effizienzklasse, fällige
  Termine, kombinierter Verlauf.
- **Gas-Ansicht:** Monatstabelle mit Verbrauch, Kosten, Saldo und gleitenden
  Mitteln; Vertrags-/Saldo-Karte mit Nachzahlungs-Prognose.
- **Analyse → Heizsignatur:** Verbrauch gegen Heizgradtage, mit Regressionsgerade
  und R²-Vergleich von fünf Modellen.
- **Prognose:** 12-Monats-Vorschau (Verbrauch **und** Kosten) als
  R²-gewichteter Blend aus Regression und Saisonprofil.
- **Tarifvergleich:** „Soll ich wechseln?" Der prognostizierte Jahresverbrauch
  steht kopierbar bereit — das ist die Zahl, die Vergleichsportale abfragen.
  Das gefundene Angebot wird als Schattenvertrag eingetragen und über zwölf
  Monate ab dem Wechseltermin gegen den laufenden Vertrag gerechnet, getrennt
  nach erstem Jahr (mit Neukundenbonus) und dauerhaften Kosten. Darunter der
  Rückblick: dieselben Tarife auf die tatsächlich gemessenen Monate gelegt.

---

## 7. Sichern

Sidebar → **Einstellungen → Backup & Wiederherstellung → JSON-Backup
herunterladen**. Das ist ein vollständiger, portabler Snapshot (Format 3.0) für
Umzug oder Sicherung. Im Docker-Betrieb liegen deine Daten ohnehin im
gemounteten `data/`-Volume.

---

## Wie geht es weiter?

- **Haupt- und Unterzähler, Zähler bündeln?** →
  [Meter-Topologie](functional/13-meter-topologie.md)
- **Werte automatisch aus Home Assistant?** → [Home Assistant](HOME-ASSISTANT.md)
- **Mein Fall ist spezieller (WG, PV, Vermieter)?** →
  [Anwendungsbeispiele & Use-Cases](USE-CASES.md)
- **Wie rechnet die Prognose genau?** →
  [Grundlagen & Methodik](functional/00-overview.md)
- **Öl/Pellets statt Gas?** → [Heizöl](functional/05-heizoel.md) ·
  [Pellets](functional/06-pellets.md)

---

## Abkürzung: Demo-Daten

Zum reinen Ausprobieren musst du nichts eintippen: **Einstellungen → Backup &
Wiederherstellung → Demo-Daten laden** spielt einen vollständigen
Beispieldatensatz über alle acht Verbrauchsarten ein (mit Warnung + Auto-Snapshot,
falls schon Daten vorhanden sind). Danach kannst du sofort bei
[Schritt 6](#6-auswerten) weitermachen.

---

[← Kompendium-Index](README.md)
