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

### Geht das Erfassen auch ohne WLAN im Keller?

Ja, seit v3.1.0. Kommt ein Stand in „Zählerstände“ nicht beim Server an, wartet
er im Browser des Handys („Noch nicht gespeichert“) und wird nachgesendet,
sobald die Verbindung wieder da ist — doppelt wird dabei nichts. Damit die App
ohne Netz überhaupt öffnet, braucht es HTTPS und die App auf dem
Home-Bildschirm; sonst die Seite vor dem Gang in den Keller öffnen. Einzelheiten
und Grenzen: [Auf dem Handy nutzen](handy.md#die-warteschlange-noch-nicht-gespeichert).

### Liest die App den Zählerstand vom Foto?

Wenn du einen eigenen Texterkennungsdienst im Heimnetz betreibst (Ollama,
LM Studio), ja: „📷 Foto“ an der Karte, dann schlägt die App den Stand vor
(„Erkannt: … – Übernehmen“); gespeichert wird erst mit deinem Klick. Ohne
solchen Dienst bleibt das Foto ein Beleg am Stand, und am iPhone übernimmt
Live Text („Text scannen“ im Feld) die Ziffern. Einen Cloud-Dienst nutzt die
App bewusst nicht — [Texterkennung im Heimnetz](../anleitungen/texterkennung.md).

### Kann ich erst einmal ausprobieren, ohne etwas einzutragen?

Ja: Auf der leeren Übersicht **„Mit Beispieldaten ausprobieren“**, später unter
Einstellungen → Daten → „Demo-Daten laden“. Vorher sichert die App den jetzigen
Stand; zurück geht es über Einstellungen → Daten → Gespeicherte Snapshots (↩️).

### Ich wohne zur Miete — hilft mir die App?

Ja. Den Strom mit eigenem Vertrag rechnet sie wie bei jedem anderen. Für
Heizung und Wasser, die du über die Nebenkosten zahlst, gibt es seit v3.1.0
eigene Bausteine: die Verbrauchsart **Heizwärme** für die monatliche
Verbrauchsinfo des Messdienstes (HeizkostenV § 6a), Warm- und
Kaltwasserzähler mit Rollen und — nach **Einstellungen → Haushalt & Gebäude →
„Ich wohne“: „zur Miete“** — die Seite **Mietverhältnis** unter Kosten &
Verträge. Sie stellt die Vorauszahlung den erwarteten Kosten des laufenden
Abrechnungszeitraums gegenüber, hebt die Nebenkostenabrechnungen mit PDF auf
und trägt die Fristen für Abrechnung und Einwände in den Kalender ein. In
Deutschland rechnet sie außerdem aus, welchen Teil der CO₂-Kosten der Vermieter
trägt ([CO₂-Kosten teilen](../anleitungen/co2-aufteilung.md)). Eine
Hilfsrechnung, keine Nebenkostenabrechnung und keine Rechtsberatung. Schritt
für Schritt: [Als Mieter](../anleitungen/mieter.md).

### Kann ich alte Werte aus Excel übernehmen?

Ja, als CSV: Zählerstände je Zähler über **⚙️ Zähler → CSV-Import** — Datum
(`TT.MM.JJJJ`, `TT/MM/JJJJ`, `TT-MM-JJJJ` oder `JJJJ-MM-TT`) und Stand,
getrennt durch Semikolon oder Komma, Notiz und „geschätzt“ optional. Die
Spaltennamen dürfen in jeder Sprache der App stehen. Eine Vorschau zeigt jede
Zeile, bevor etwas geschrieben wird, und ein Beispiel lässt sich dort
herunterladen. Ein amerikanisches Datum mit dem Monat vorn (`01/15/2026`)
deutet die App nicht um, sondern meldet die Zeile. Temperaturen kommen unter
Einstellungen → Wetterdaten als `TT.MM.JJJJ;Mittel;Min;Max`. Monatswerte für
einen Zähler mit Verbrauch je Zeitraum (seit v3.1.0) liest derselbe Knopf als
`Monat;Verbrauch`, etwa `01.2026;1180`.

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
- Die Rechnung rechnet mit einem **geschätzten Stand** (Ableseart „S“), die App
  mit einem abgelesenen.

Die Anleitung [Jahresabrechnung eintragen und prüfen](../anleitungen/jahresabrechnung.md)
geht alle Punkte durch; die **Rechnungsprüfung** rechnet eine Rechnung für Gas,
Strom, Wasser oder Fernwärme Abschnitt für Abschnitt nach.

### Stimmt meine Jahresabrechnung?

Seit v3.1.0 lässt sich das prüfen: **Kosten & Verträge → Rechnung prüfen**,
Verbrauchsart und Zähler wählen und in der Karte **„Laut Rechnung“** die Werte
der Rechnung eintragen — Zeitraum, Verbrauch, Rechnungsbetrag, gezahlte
Abschläge, dazu Umlagen und Gebühren unter „Weitere Posten“. „Vergleichen“
stellt die eigene Rechnung daneben: **„passt“**, wenn Menge auf 1 % und Betrag
auf 1 % oder 2 € genau stimmen, sonst **„prüfen“** mit möglichen Gründen —
etwa ein geschätzter Stand an der Grenze des Zeitraums oder ein Preis, der im
Vertrag fehlt. Stimmt alles, bucht **„Als Sonderzahlung buchen“** das Guthaben
bzw. die Nachzahlung in den Vertrag. Für Gas, Strom, Wasser und Fernwärme —
Schritt für Schritt in [Jahresabrechnung eintragen und
prüfen](../anleitungen/jahresabrechnung.md#7-laut-rechnung-erfassen-vergleichen-buchen).

### Was kostet mich der CO₂-Preis?

In Deutschland steckt seit 2021 ein CO₂-Preis im Arbeitspreis für Gas und
Heizöl — 2025 55 €, 2026 60 € je Tonne CO₂. Seit v3.1.0 zeigt die
Verbrauchsansicht von Gas, Heizöl, Fernwärme und Heizwärme die Karte
**„CO₂-Preis im Brennstoff“**: wie viel davon in deinen Kosten steckt, je kWh
und für das Jahr. Bei 10.000 kWh Gas waren das 2025 rund 119 € mit
Umsatzsteuer. Das ist kein Aufschlag, sondern ein Teil dessen, was du schon
zahlst. Was ein höherer Preis kosten würde, rechnet die Prognose mit dem Feld
„CO₂-Preis ab 2028“ aus. Zur Miete trägt der Vermieter je nach Gebäude einen
Teil ([CO₂-Kosten teilen](../anleitungen/co2-aufteilung.md)). Mehr:
[CO₂-Preis im Brennstoff](../verstehen/16-co2-preis.md).

### Was brauche ich für den Anbieterwechsel?

**Kosten & Verträge → Wechsel prüfen** nennt den erwarteten Jahresverbrauch
fürs Vergleichsportal und den Wechseltermin. Seit v3.1.0 stehen dort unter
**„Für den Wechsel bereithalten“** die Angaben, die der neue Anbieter abfragt —
Marktlokations-ID, Zählernummer und letzter Zählerstand — zum Kopieren. Die
Marktlokations-ID (11 Ziffern, auf der Rechnung) trägst du einmal im
Zählerdialog ein; eine falsche Prüfziffer lehnt die App ab. Kündigt dein
Anbieter eine Preiserhöhung an und du trägst sie als neue Preiszeile mit ihrem
Datum am Vertrag ein, erinnert die App an das Sonderkündigungsrecht zu diesem
Tag (§ 41 Abs. 5 EnWG). Und beim
Speichern eines neuen Vertrags weist sie darauf hin, wenn die Mindestlaufzeit
länger als 24 Monate ist — für Verbraucher in Deutschland unwirksam (§ 309
Nr. 9 BGB). Das sind Hinweise, keine Rechtsberatung.

### Lohnt sich ein dynamischer Stromtarif?

Das rechnet seit v3.1.0 der **Dynamik-Check** nach: Unter **Kosten & Verträge →
Wechsel prüfen** (Strom) lädt die Karte „Börsenstrompreise“ die Monatsmittel der
Börse von SMARD — nur auf Knopfdruck — oder liest eine Datei. Dann ein Angebot
mit dem Häkchen „Dynamischer Tarif (Börsenpreis je Monat)“ anlegen, mit dem
Aufschlag aus dem Preisblatt. Die Rangliste stellt es neben deinen Vertrag.
Eine Näherung: Gerechnet wird mit dem Monatsmittel, als verteile sich dein
Verbrauch gleichmäßig über den Tag. Wer viel abends verbraucht, zahlt real
mehr; wer Wallbox, Wärmepumpe oder Speicher in günstige Stunden legt, weniger.
Abgerechnet wird ein dynamischer Tarif nur mit einem intelligenten Messsystem
([Strom → Dynamische Tarife](../verstehen/02-strom.md#dynamische-tarife-v310)).

### Ich habe einen Zähler mit Hoch- und Niedertarif — wie trage ich das ein?

Seit v3.1.0 so: beide Zählwerke als eigene Zähler anlegen, zu einer Gruppe
zusammenfassen (⚙️ Zähler → „Zu Gruppe zusammenfassen“) und **einen** Vertrag
für die Gruppe anlegen — mit einem Grundpreis und unter „Arbeitspreis je
Zähler (z. B. HT/NT)“ dem Preis je Zählwerk. Saldo, Prognose und Wechsel
rechnen dann für beide zusammen
([Strom](../verstehen/02-strom.md#hoch--und-niedertarif-ein-vertrag-für-eine-zählergruppe-v310)).

### Wie gut ist meine Wärmepumpe?

Mit einem Wärmemengenzähler an der Wärmepumpe rechnet die App seit v3.1.0 die
**Jahresarbeitszahl** aus: Wärme ÷ Strom über ein Jahr. Den Wärmezähler legst
du unter Heizwärme mit der Rolle „Wärmemenge der Wärmepumpe“ an und verknüpfst
ihn mit dem Stromzähler der Wärmepumpe. Zur Einordnung nennt die Karte den
Feldtest „WP-QS im Bestand“ des Fraunhofer ISE (2025): Luft/Wasser im Mittel
3,4, Sole/Wasser 4,3. Ohne Wärmezähler geht es nicht — der Strom allein sagt
nichts über die Wärme
([Heizwärme §7](../verstehen/15-waerme.md#7-jahresarbeitszahl-der-wärmepumpe-v310)).

### Wie vergleiche ich mich mit anderen?

Mit eigenen **Vergleichswerten** (seit v3.1.0): unter Einstellungen → Haushalt
& Gebäude → „Eigene Vergleichswerte“ etwa die kWh, die der Stromspiegel für
deinen Haushalt nennt, und die kWh je m² aus dem Heizspiegel, dazu die Quelle.
Die Übersicht zeigt dann die Karte „Einordnung“ mit der Abweichung in % für
das letzte volle Jahr. Tabellen aus Strom- und Heizspiegel liefert die App
nicht mit — ihre Nutzung verlangt eine Genehmigung —, in Deutschland verlinkt
sie die Seiten zur Selbstprüfung. Mit PV, Wärmepumpe oder Wallbox passt der
allgemeine Stromspiegel nicht; Wärmepumpe und Wallbox rechnet die App deshalb
heraus.

### Kann ich Werte aus dem Portal meines Netzbetreibers übernehmen?

Ja, als Datei: **⚙️ Zähler → „Zeitreihe importieren“** liest
Viertelstunden-, Stunden- oder Tageswerte aus Portalen von Netzbetreiber,
Messstellenbetreiber, Wechselrichter oder Wärmepumpe. Du sagst, in welcher
Spalte Datum und Wert stehen, ob es Zählerstände oder Verbrauchswerte sind und
in welcher Einheit; die App verdichtet zu Tageswerten. Eine Verbindung zu den
Portalen baut sie nicht auf
([Zeitreihen aus Portalen](../anleitungen/daten-aus-portalen.md)).

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
(Deutschland: GModG, bis Juli 2026 GEG). Anderswo zeigt die Karte kWh/m²·a und nennt den Grund.

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
([Länderprofile](../verstehen/14-laenderprofile.md)). Die ⓘ-Erklärungen nennen
die Begriffe so, wie sie auf der Rechnung deines Landes stehen, und der
Tarifwechsel verlinkt den amtlichen Tarifvergleich, wo es einen gibt.

### Zwei Sprachen im Haushalt — geht das?

Ja, seit v3.1.0. Unter Einstellungen → Allgemein → Sprache & Land wählt jedes
Gerät seine **Sprache auf diesem Gerät**; der Browser merkt sie sich, am
Server ändert sich nichts. Daneben steht die **Standardsprache der
Installation**: Sie gilt für Geräte ohne eigene Wahl und für alles, was ohne
Gerät entsteht — PDF-Jahresbericht, CSV-Dateien, Meldungen an Home Assistant.
Die Druckansicht des Jahresberichts folgt der Sprache des Geräts.

## Daten und Betrieb

### Wo liegen meine Daten, und wie sichere ich sie?

Als JSON-Dateien im Datenverzeichnis (`data/`, im Docker-Container `/data`).
Ein vollständiges Backup lädt Einstellungen → Daten → **JSON-Backup
herunterladen**; vor jedem Import legt die App selbst einen Snapshot an.

### Welche Daten verlassen meinen Server?

Nur die Anfragen an Open-Meteo: der tägliche Wetterabgleich mit dem auf rund
1 km gerundeten Standort (abschaltbar unter Einstellungen → Wetterdaten) und
die Ortssuche, wenn du sie benutzt. Dazu, nur wenn du ihn einträgst, die Fotos
an deinen eigenen Texterkennungsdienst — und der muss im Heimnetz stehen
([Texterkennung](../anleitungen/texterkennung.md)) — und, seit v3.1.0 nur auf
Knopfdruck („Von SMARD laden“), der Abruf der Börsenstrompreise von SMARD, der
Plattform der Bundesnetzagentur; dabei geht nichts von dir mit. Keine Konten,
keine Telemetrie, keine Werbung.

### Kann ich die App auf dem iPhone nutzen?

Ja, im Browser im Heimnetz; als App auf dem Home-Bildschirm mit HTTPS. Siehe
[Auf dem Handy nutzen](handy.md).

### Kann ich Fristen in meinem Kalender sehen?

Ja, seit v3.1.0 als Kalender-Abo: unter **Hinweise → Termine & Wartung → Im
Kalender abonnieren**. Darin stehen Termine, Kündigungsstichtage,
Vertragsenden, Preisgarantien, Preiserhöhungen und fällige Ablesungen, zur
Miete auch die Fristen der Nebenkostenabrechnung; der Kalender holt sie alle
12 Stunden neu. Wie das in Apple Kalender, Thunderbird
oder Google Kalender geht, steht unter [Fristen im Kalender](../anleitungen/kalender.md).

### Wie drucke ich den Jahresbericht?

Unter **Auswertungen → Jahresbericht** das Jahr wählen und **Druckansicht
öffnen**, dann **Drucken / als PDF sichern**: Der Druckdialog des Browsers
druckt auf Papier oder sichert eine PDF-Datei; Seitenleiste und Knöpfe
erscheinen dabei nicht. Am iPhone geht das über **Teilen → Drucken** — dort
lässt sich der Bericht auch als PDF sichern oder weitergeben. Die Druckansicht
steht in der Sprache des Geräts. Darunter gibt es weiterhin die fertige
PDF-Datei in der Standardsprache der Installation.

### Welches CSV-Format soll ich exportieren?

Für Excel, LibreOffice oder Numbers die **Tabelle in der Standardsprache**
(Einstellungen → Daten → Datenexport, empfohlen): Spaltennamen, Dezimalzeichen
und Datum passen zur Sprache, die Datei öffnet sich mit einem Doppelklick
richtig. Für Skripte **CSV-Format 1** — es bleibt Byte für Byte, wie es ist.
Beide lassen sich wieder importieren ([CSV-Formate](../referenz/api.md#csv-formate-v310)).

### Wie aktualisiere ich?

Docker: das neue Image holen und den Container neu anlegen — das Datenvolume
bleibt. Ohne Docker: `git pull` (oder die Dateien ersetzen). Datenformate hebt
die App beim Start selbst an; ein Backup vorher schadet nie
([Installation](../betrieb/installation.md)).

### Kann ich die App aus dem Internet erreichbar machen?

Ja, aber nur mit Anmeldung (Einstellungen → Zugriff) und hinter HTTPS. Die
Checkliste steht unter [Sicherheit & Netzbetrieb](../betrieb/sicherheit.md).

### Was kostet der Energietracker?

Nichts. Seit Version 3.0.0 steht er unter der
[GNU AGPL v3.0 oder neuer](../../LICENSE); Versionen bis 2.16.0 bleiben unter
der MIT-Lizenz verfügbar, unter der sie erschienen sind.

### Was bedeutet die AGPL für mich?

Für die eigene Installation nichts: nutzen, ändern und weitergeben ist frei.
Wer eine veränderte Fassung anderen als Netzdienst anbietet, muss ihnen auch
deren Quellcode zugänglich machen. Unter Einstellungen → System führt ein Link
zum Quellcode genau der laufenden Version.

### Wo melde ich einen Fehler oder einen Wunsch?

In den [GitHub-Issues](https://github.com/Bingerminger/energietracker/issues).
Bei einem Fehler hilft die Diagnose unter Einstellungen → System (Version,
Schema, Schreibrechte).

---

[← Kompendium-Index](../README.md)
