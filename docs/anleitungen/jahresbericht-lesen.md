# Jahresbericht lesen

**Deutsch** · [English](../en/anleitungen/jahresbericht-lesen.md)

[← Kompendium-Index](../README.md)

Der Jahresbericht fasst ein Kalenderjahr so zusammen, dass man ihn ohne
Technikkenntnisse lesen kann — für dich, für Angehörige, die den Haushalt
mitbetreuen, oder für den Hausmeister. Vorne steht, was wichtig ist: große
Zahlen, vier Ampeln und die drei wichtigsten Aufgaben. Danach, was sich
gelohnt hat, was zu tun ist, Fristen, Geld, je Verbrauchsart eine Seite und
Themenseiten, die nur erscheinen, wenn es Daten dafür gibt. Seit **v3.4.0**.

PDF und Druckansicht entstehen aus **demselben Datenmodell**: Beide zeigen
dieselben Sätze und Zahlen. Die Druckansicht ist die barrierefreie Fassung
(echte Überschriften, Tabellen, jede Grafik mit ihrer Aussage als Text).

---

## 1. Bericht holen

**Auswertungen → Jahresbericht**, dann einer der drei Knöpfe:

- **Druckansicht öffnen** zeigt den Bericht als Seite in der App. „Drucken /
  als PDF sichern“ bringt ihn auf Papier oder in eine Datei, am iPhone über
  Teilen → Drucken. Daneben lädt **„Jahresbericht herunterladen“** *(v3.6.0)*
  die PDF-Datei mit derselben Auswahl, am iPad der kürzere Weg zur Datei; ein
  angehängtes Szenario gibt es nur in der Druckansicht. Das Häkchen **„Mit
  Anhang ‚Zahlen im Detail‘“** blendet die Monatstabellen ein.
- **Im Browser öffnen** und **Jahresbericht herunterladen** liefern die
  fertige PDF-Datei.

**Der Dialog „Jahresbericht erstellen“ (seit v3.5.0)** fragt vorher:

| Feld | Was es bewirkt |
|---|---|
| **Jahr** | dieses und die sechs Jahre davor; vorgewählt ist das Vorjahr |
| **Sprache des PDF** | eine der Oberflächensprachen, vorgewählt die dieses Geräts — etwa für Angehörige. Die Druckansicht erscheint immer in der Sprache dieses Geräts; der Dialog sagt das statt einer Auswahl |
| **Verbrauchsarten** | Häkchen je *aktive* Verbrauchsart. Alle angehakt ergibt den ganzen Bericht, sonst einen **Teilbericht** nur mit diesen — etwa für die Hausverwaltung. Die Druckansicht nennt ihn oben („Teilbericht: Gas, Wasser“). Ohne Häkchen erscheint „Mindestens eine Verbrauchsart wählen.“ |
| **Mit Anhang „Zahlen im Detail“** | die Monatstabellen je Verbrauchsart |
| **Mit Seite „Flexibilität“ (Ampel, Hebel, Zeitfenster)** | *(v3.6.0)* eine eigene Seite mit denselben Zahlen wie Auswertungen → Flexibilität: große Zahl, Handlungssätze, typischer Tag je Halbjahr, beste Zeitfenster, „Dein Hebel“, Sonnenstrom ([Flexibilität, § 10](flexibilitaet.md#10-im-jahresbericht)). Braucht Börsenpreise je Viertelstunde für das Berichtsjahr, Zeitfenster für Modul 3 oder Erzeugung je Viertelstunde; sonst entfällt die Seite |
| **Szenario anhängen** | *(v3.6.0, nur Druckansicht)* erscheint, wenn in der Prognose-Werkstatt Szenarien auf diesem Gerät gespeichert sind: Das gewählte kommt als eigene Seite dazu — Ergebniskarte, Annahmen, Vergleich, Kurven, Rangliste —, gerechnet wie in der Werkstatt ([Was wäre wenn?](was-waere-wenn.md)). Es gilt nur für diesen Aufruf |

Dieses Gerät merkt sich Verbrauchsarten, Sprache, Anhang und die Seite „Flexibilität“ (im Browser);
das Jahr steht jedes Mal wieder auf dem Vorjahr. Die Knöpfe der Seite sind
Links mit dieser gemerkten Auswahl — ein Mittelklick öffnet sie ohne Dialog.
In der Druckansicht öffnet **„Optionen …“** denselben Dialog, vorbelegt mit
dem, was sie gerade zeigt. In der öffentlichen Demo gibt es den Bericht nur
ganz: Verbrauchsarten und der Anhang im PDF sind dort gesperrt. Der Dialog
erzeugt genau die Zusätze aus [§ 6](#6-teilbericht-und-sprache-per-link).

## 2. Aufbau

| Seite | Inhalt | Erscheint, wenn |
|---|---|---|
| **Das Wichtigste** | Energiekosten, Energieverbrauch (Heizung witterungsbereinigt), CO₂ und bei einer Solaranlage ihr Nutzen — jeweils gegen das Vorjahr, mit einer kleinen Linie über drei Jahre; vier Ampeln; die drei wichtigsten Aufgaben. Die Energiekosten zählen wie die Übersicht: aus den Verträgen und *(seit v3.6.0)* bei Heizöl und Pellets aus den Lieferungen | immer |
| **Was hat sich gelohnt** | Erfolge mit Betrag: weniger geheizt, eine Maßnahme, die wirkt (Zäsur), Solaranlage, Speicher, Sonnenstrom im Auto, Modul 3, gesunkene Kosten; darunter „Gut zu wissen“ | immer; ohne Erfolg steht der Vergleich mit dem Vorjahr |
| **Was ist zu tun** | Aufgaben nach Priorität, je mit Grund, Betrag, Frist und Hinweis | immer; „Nichts zu tun“ nur, wenn auch keine Termine anstehen |
| **Fristen und Termine** | die nächsten zwölf Monate und alles Überfällige; je laufendem Vertrag ein Zeitstrahl | Termine oder Verträge vorhanden |
| **Geld** | Kosten je Verbrauchsart (auch Heizöl und Pellets) und je Monat gegen das Vorjahr, Preis- und Mengeneffekt, Abschläge gegen Kosten je Vertrag, Prognose der nächsten zwölf Monate. *(v3.7.0)* Alle Kosten ohne Bonus — darunter je Bonus ein Satz mit Art, Datum, Betrag und Notiz; der Saldo rechnet ihn ein. Wasser mit Abschlägen hat Saldo und Abschlags-Rat wie Gas und Strom | es gibt Kosten |
| **Je Verbrauchsart** | Jahreswert gegen Vorjahr, Monatsbalken; Heizung: „Kälter oder mehr verbraucht?“ und Effizienzskala; Strom: wofür (Haushalt, Wärmepumpe, Wallbox); Wasser: Liter je Person und Tag; Heizöl und Pellets: Kosten aus den Lieferungen, Bestand und Reichweite | Verbrauch im Jahr |
| **Sonnenstrom** | Energiefluss, Autarkiequote, Eigenverbrauch, Erzeugung je Monat, Amortisation, Speicher | Solaranlage |
| **Wärmepumpe** | Arbeitszahl je Monat gegen das Feldtest-Mittel | Wärmemengenzähler mit verknüpftem Stromzähler |
| **E-Auto laden** | Ladestrom je Monat, davon Sonnenstrom, Kosten laut Ladestrom-Nachweis | Wallbox-Zähler |
| **Günstige Stunden** | Anteil in günstigen Viertelstunden, Profilfaktor, Modul 3, Wert des Speichers | Viertelstundenwerte und Börsenpreise |
| **Flexibilität** | *(v3.6.0)* große Zahl „Dein Hebel“, Handlungssätze, typischer Tag für Winter- und Sommerhalbjahr (Farbe und Legende; im PDF die Sonne als Punkt), beste Zeitfenster, „Dein Hebel“ als Tabelle, Sonnenstrom, Beispielwerte | nur auf Wunsch (Dialog, `flex=1`), mit Börsenpreisen, Zeitfenstern für Modul 3 oder Erzeugung je Viertelstunde |
| **Szenario: {Name}** | *(v3.6.0)* ein gespeichertes Szenario der Prognose-Werkstatt | nur in der Druckansicht, auf Wunsch |
| **Mietwohnung: Nebenkosten** | Vorauszahlung gegen erwartete Kosten, Abrechnung des Jahres, CO₂-Aufteilung mit Frist | Mietverhältnis |
| **Kleines Wörterbuch** | nur die Begriffe, die im Bericht vorkommen, je ein Satz; Quellen | immer |
| **Zahlen im Detail** | je Verbrauchsart eine Monatstabelle | nur auf Wunsch (Anhang) |

Was nicht zum Berichtsjahr gehört — Abrechnung, Fristen, Aufgaben, Tankbestand
—, steht in einem gelben Kasten **„Stand heute (Datum)“**.

## 3. Die vier Ampeln

Jede Ampel trägt ein Symbol **und** ein Wort; die Farbe allein sagt nichts
(auch im Schwarz-Weiß-Druck lesbar).

| Ampel | Grün | Gelb | Rot |
|---|---|---|---|
| **Kosten** gegen Vorjahr | höchstens +3 % | höchstens +10 % | mehr als +10 % |
| **Verbrauch** gegen Vorjahr (Heizung witterungsbereinigt) | nicht gestiegen | höchstens +5 % | mehr als +5 % |
| **Abrechnung** (Stand heute) | Guthaben oder weniger als ein Monatsabschlag Nachzahlung | bis zwei Abschläge | mehr |
| **Fristen** (Stand heute) | nichts in den nächsten 60 Tagen | Frist in höchstens 60 Tagen | höchstens 14 Tage oder überfällig |

Unter der Kosten-Ampel steht immer, wie viel davon an geänderten Preisen und
wie viel an der verbrauchten Menge liegt.

## 4. Aufgaben: Priorität, Betrag, Frist

Jede Aufgabe nennt ihren Grund mit den eigenen Zahlen, was sie bringt oder
vermeidet und bis wann. Die **Priorität**:

- **1** — Frist in höchstens 14 Tagen oder mehr als 200 €
- **2** — Frist in höchstens 60 Tagen oder mehr als 50 €
- **3** — alles andere

Die Regeln dahinter:

| Aufgabe | Wann | Betrag |
|---|---|---|
| Abschlag anpassen | erwartete Nachzahlung oder Guthaben von mindestens 50 € bzw. einem Monatsabschlag | die erwartete Summe zur Abrechnung; dazu ein passender Abschlag — oder, wenn die gezahlten Abschläge schon reichen, nur „kann sinken“ ohne Zahl |
| Kündigung oder Wechsel | Kündigungsstichtag in höchstens 90 Tagen (nicht bei Wasser) | mit gespeichertem Angebot der Unterschied ab dem zweiten Jahr, ohne Bonus; sonst der Jahresverbrauch fürs Vergleichsportal |
| Preiserhöhung | angekündigte höhere Preise | (neuer − alter Arbeitspreis) × erwarteter Verbrauch + Unterschied beim Grundpreis × 12 |
| Heizverbrauch gestiegen | witterungsbereinigt mindestens 5 % mehr als im Vorjahr | Mehrverbrauch × eigener Arbeitspreis |
| Auffälliger Monat | Verbrauch deutlich über dem, was der Monat erwarten ließ (nur Monate des Berichtsjahres, höchstens drei) | Mehrverbrauch × eigener Arbeitspreis |
| Effizienzklasse E bis H | Klasse auf der Skala des Landes | nur als Rechenbeispiel: 10 % weniger Heizenergie |
| Wärmepumpe unter dem Feldtest-Mittel | Jahresarbeitszahl mehr als 0,3 unter dem Mittel für Luft/Wasser | Rechenbeispiel mit dem Mittelwert |
| Mehr Sonnenstrom selbst nutzen | weniger als 30 % des Sonnenstroms selbst genutzt | je 100 kWh: Bezugspreis − Einspeisevergütung |
| Speicherverluste | Wirkungsgrad unter 85 % | Verluste × Bezugspreis |
| Teure Stunden | eine Wärmepumpe oder Wallbox lief eher in teuren Stunden | Mehrpreis gegenüber dem Börsenmittel, nur Börsenanteil |
| Nebenkosten-Vorauszahlung | erwartete Nachzahlung ab 50 € | die erwartete Summe; eine passende Vorauszahlung, mit dem Vermieter zu klären |
| CO₂-Kosten vom Vermieter | Erstattung bei eigener Gastherme | der Anteil des Vermieters, mit Frist |
| Termine, Ablesungen, Fristen der Nebenkostenabrechnung | fällig in höchstens 60 Tagen oder überfällig | — |
| Rechnung prüfen | Versorgerrechnung weicht um mehr als 10 € von der eigenen Rechnung ab | der Unterschied |

**Beträge** kommen ausschließlich aus den eigenen Ablesungen und den eigenen
Vertragspreisen, nie aus Marktannahmen; ohne Preis steht kein Betrag. Sie sind
auf ganze Euro gerundet und tragen „ca.“ sowie ihre Art: **gemessen** (aus dem
Berichtsjahr), **hochgerechnet** (bis zur Abrechnung) oder **Rechenbeispiel**.
Ausgeblendete Empfehlungen bleiben auch im Bericht ausgeblendet.

*(seit v3.7.0)* **Gemessen** heißt auch: aus gemessenen Monaten. Ist ein Monat
des Berichtsjahres oder des Vorjahres nur verteilt (zu lange zwischen zwei
Ablesungen, siehe [Gemessen oder verteilt](../verstehen/00-overview.md#gemessen-oder-verteilt-seit-v370)),
entfallen „Kälter oder mehr verbraucht?“, „Weniger geheizt“ und „Kosten
gesunken“; auf der Seite der Verbrauchsart sagt eine Notiz, warum der Vergleich
fehlt. Die Jahreswerte selbst bleiben, wie sie sind.

*(seit v3.7.0)* Passt das Heizmodell schlecht zu einem Zähler (Gütesperre, siehe
[Anomalien](../verstehen/00-overview.md#9-anomalien)), vergleicht der Bericht diese
Heizart nicht witterungsbereinigt: Kachel und Ampel „Verbrauch“ nehmen den rohen
Verbrauch, „Kälter oder mehr verbraucht?“, „Heizverbrauch gestiegen“ und
„Auffälliger Monat“ entfallen, im Anhang steht „–“; eine Notiz sagt, warum. Ist
eine Saldo-Schätzung unsicher, zeigt die Ampel „Abrechnung“ keinen Haken, sondern
„Schätzung unsicher“ mit der Spanne, und „Wohin geht mein Geld?“ nennt Kosten und
Ergebnis als Spanne.

Einen Rechtshinweis gibt es nur dort, wo die App ihn ohnehin führt: bei einer
Preiserhöhung das Sonderkündigungsrecht nach § 41 Abs. 5 EnWG (nur in
Deutschland).

## 5. Grafiken lesen

- **Grau** ist immer das Vorjahr, **farbig** das Berichtsjahr — in der Farbe der
  Verbrauchsart, für den Druck so abgedunkelt, dass sie sich vom Papier abhebt.
- **Schraffierte** Balken enthalten geschätzte Tage, **gestrichelte** Linien sind
  Prognosen bis zur Abrechnung.
- Beim **Saldo-Verlauf** liegt eine Nachzahlung über, ein Guthaben unter der
  Nulllinie.
- Der **Energiefluss** zeigt Sonne, Netz, Haus und Speicher; je dicker der Pfeil,
  desto mehr Kilowattstunden.
- Unter jeder Grafik steht ihre Aussage als Satz.

## 6. Teilbericht und Sprache per Link

Die Adresse des PDFs nimmt diese Zusätze an (auch kombiniert); der Dialog aus
[§ 1](#1-bericht-holen) setzt sie, und die Druckansicht versteht
`utilities`, `detail` und `flex` ebenso (`#/report/print?year=2025&utilities=wasser`):

| Zusatz | Wirkung |
|---|---|
| `?lang=en` | Sprache des Berichts (`de`, `en`, `fr`, `it`, `es`, `pt`, `nl`) — etwa für Angehörige |
| `?utilities=gas,wasser` | nur diese Verbrauchsarten, etwa für den Hausmeister |
| `?detail=1` | mit Anhang „Zahlen im Detail“ |
| `?flex=1` | *(v3.6.0)* mit Seite „Flexibilität“ |

Nur die Druckansicht kennt dazu `scenario=<Name>` — ein Szenario, das auf
diesem Gerät gespeichert ist.

Beispiel: `…/api.php/api/reports/yearly.pdf?year=2025&utilities=wasser&lang=de`.
Details in der [API-Referenz](../referenz/api.md#get-apireportsyearlypdfyearyyyy).

## 7. Per Home Assistant verschicken

Der Energietracker verschickt selbst nichts. Wer den Bericht jedes Jahr
automatisch bekommen möchte, lässt Home Assistant ihn holen und weiterreichen.

**1. API-Schlüssel** (nur mit eingeschalteter Anmeldung): Einstellungen →
Zugriff → „API-Schlüssel für Skripte“, Bereich **„Lesen“**. Ohne Anmeldung
entfällt die Kopfzeile mit `Authorization`.

**2. Befehl zum Holen** in die `secrets.yaml` — der ganze Befehl, damit der
Schlüssel nicht in der `configuration.yaml` steht:

```yaml
energietracker_report_cmd: "curl -sf -H 'Authorization: Bearer etk_…' -o /config/www/energietracker-jahresbericht.pdf 'http://DEINE-ENERGIETRACKER-IP:8080/api.php/api/reports/yearly.pdf?lang=de'"
```

**3. In die `configuration.yaml`:**

```yaml
shell_command:
  energietracker_jahresbericht: !secret energietracker_report_cmd
```

**4. Automatisierung** — am 15. Januar um 9 Uhr holen und als Dokument über
den Telegram-Bot verschicken (die Integration „Telegram bot“ muss eingerichtet
sein, und `/config/www` muss unter `allowlist_external_dirs` stehen):

```yaml
automation:
  - alias: "Energietracker – Jahresbericht verschicken"
    triggers:
      - trigger: time
        at: "09:00:00"
    conditions:
      - condition: template
        value_template: "{{ now().month == 1 and now().day == 15 }}"
    actions:
      - action: shell_command.energietracker_jahresbericht
      - action: telegram_bot.send_document
        data:
          file: /config/www/energietracker-jahresbericht.pdf
          caption: "Jahresbericht {{ now().year - 1 }}"
```

Ohne Telegram liegt die Datei nach dem Holen unter
`http://DEIN-HOME-ASSISTANT:8123/local/energietracker-jahresbericht.pdf` — ein
Link darauf lässt sich mit jeder Benachrichtigung verschicken. Achtung: Alles
unter `/config/www` ist ohne Anmeldung erreichbar.

---

Siehe auch: [Funktionen](../einstieg/funktionen.md) ·
[Ansichten: Jahresbericht](../referenz/ansichten.md#jahresbericht-seit-v2110-unter-auswertungen) ·
[Home Assistant anbinden](home-assistant.md)
