# Rechtsgrundlagen

**Deutsch** · [English](../en/referenz/rechtsgrundlagen.md)

[← Kompendium-Index](../README.md)

Auf diese Vorschriften und Quellen stützen sich die Hinweise unter
[Deine Rechte als Kunde](../verstehen/17-rechte.md). Jede Zeile nennt die
Aussage, die Norm mit einem Link zur Quelle, den Tag, an dem die Quelle zuletzt
gelesen wurde, und wie sicher die Aussage ist. Die App hält dieselben Angaben an
einer Stelle im Code (`src/Config/Rights.php`). Ein Test prüft, dass diese Seite
und der Code übereinstimmen: jede Regel hier, keine Zeile ohne Regel, gleiche
Norm, gleiche Quelle, gleicher Stand.

> **Hinweis, keine Rechtsberatung.** Diese Seite fasst Gesetze und Quellen
> zusammen, so wie sie am angegebenen Tag zu lesen waren. Sie ersetzt keine
> Beratung. Hilfe bekommst du bei der Verbraucherzentrale und, bei Strom und
> Gas, bei der Schlichtungsstelle Energie.

Die Regeln gelten nur für Deutschland. In einem anderen Land zeigt die App
keine Hinweise zu Rechten und keine Briefe.

## Status

| Status | Bedeutung | Was die App damit macht |
|---|---|---|
| **W** | Wortlaut der amtlichen Quelle gelesen und zusammengefasst | darf in einem Hinweis stehen |
| **S** | Sekundärquelle: Verbraucherzentrale, vzbv, Bundesnetzagentur, Fachportal | darf in einem Hinweis stehen, die Quelle wird genannt |
| **G** | zu prüfen: aus Kenntnis, nicht abgerufen, oder ein Detail ist offen | darf in einem Hinweis stehen; diese Seite kennzeichnet die Aussage mit „Zu prüfen“ |
| **U** | unsicher: Rechtslage offen oder nicht belegt | steht nie in einem Hinweis; diese Seite nennt sie nur, damit sichtbar ist, was offen ist |

Eine Regel mit dem Zusatz „Nicht in App-Texten“ dient nur der Gestaltung. Die
Spalte **Nr.** ist die Zeile im Prüfbericht zu v3.11.0; mehrere Regeln können
eine Zeile teilen, etwa StromGVV und GasGVV.

**Gegenlesen:** Vor jedem Release liest ein Mensch jede Zeile gegen den dann
geltenden Wortlaut und setzt den Stand neu. Für v3.11.0 steht das noch aus.

## Fristen

Diese Fristen rechnet die App. Ein Test hält die Zahlen an den Werten im Code.

| Frist | Dauer | Grundlage |
|---|---|---|
| Rechnung für Strom und Gas <!-- doc-claim: rights-deadline bill --> | 42 Tage (sechs Wochen) nach Ende des Abrechnungszeitraums bzw. der Belieferung | § 40c Abs. 2 EnWG |
| Ankündigung einer Preisänderung im Sondervertrag <!-- doc-claim: rights-deadline announce_special_months --> | 1 Monat vor der Änderung | § 41 Abs. 5 Satz 1 bis 3 EnWG |
| Ankündigung einer Preisänderung in der Grundversorgung <!-- doc-claim: rights-deadline announce_basic_days --> | 42 Tage (sechs Wochen) vor der Änderung, die zum Monatsbeginn gilt | § 5 Abs. 2 StromGVV, § 5 Abs. 2 GasGVV |
| Bekanntgabe eines Bescheids per Post <!-- doc-claim: rights-deadline notice_postal_days --> | am 4. Tag nach Aufgabe zur Post | § 41 Abs. 2 Satz 1 VwVfG, § 122 Abs. 2 Nr. 1 AO |
| Widerspruch oder Klage gegen einen Bescheid <!-- doc-claim: rights-deadline notice_months --> | 1 Monat nach Bekanntgabe | § 70 Abs. 1 VwGO; Fristende nach §§ 187 Abs. 1, 188 Abs. 2, 3 BGB (zu prüfen); am Samstag, Sonntag oder Feiertag der nächste Werktag (§ 57 Abs. 2 VwGO, § 222 Abs. 2 ZPO, § 31 Abs. 3 VwVfG) |

So rechnet die App die Termine:

- **Sonderkündigung:** Die Kündigung muss vor dem Tag der Preisänderung
  zugehen; der Termin ist der Tag davor. Nennt das Schreiben ein früheres Datum,
  steht das frühere als Termin, und der Hinweis nennt beide.
- **Gebührenbescheid:** Bescheiddatum plus vier Tage, mit Zustellungsurkunde das
  Datum der Zustellung; dann ein Monat. Fehlt der Tag im Monat, gilt der
  Monatsletzte. Fällt dieser Tag auf einen Samstag, Sonntag oder einen Feiertag,
  der in ganz Deutschland gilt, ist der Termin der nächste Werktag (Nr. 60, 61);
  Feiertage nur einzelner Länder kennt die App nicht, der Hinweis sagt das.
  Termin, „Zu tun“, Kalender und Brief nennen diesen Tag; „abgelaufen“ ist die
  Frist erst am Tag danach *(v3.11.0, Nachprüfung; vorher galt der Monatstag)*.
- Beide Termine stehen 14 Tage vorher unter „Zu tun“. Gerechnet wird in
  Kalendertagen, ohne Uhrzeit.

## Preisobergrenzen im Messstellenbetrieb

Brutto je Jahr und Zählpunkt, Anteil des Anschlussnutzers, für den
grundzuständigen Messstellenbetreiber. Die App prüft mit dieser Tabelle ab
2026-01-01 (Messstellenbetriebsgesetz in der Fassung vom 23.12.2025).
Jedes Merkmal einer Zeile genügt allein; passen mehrere Zeilen, gilt die
höchste Grenze. Passt beim intelligenten Messsystem keine Gruppe, gilt der
optionale Einbau.

| Fall | Jahresverbrauch | Anlage | § 14a EnWG | Obergrenze je Jahr | Norm |
|---|---|---|---|---|---|
| Moderne Messeinrichtung <!-- doc-claim: rights-metering-cap mme --> | – | – | – | 25 € | [§ 32 Abs. 1 MsbG](https://www.gesetze-im-internet.de/messbg/__32.html) |
| Intelligentes Messsystem, optionaler Einbau <!-- doc-claim: rights-metering-cap imsys_optional --> | – | – | – | 30 € | [§ 30 Abs. 1, 3 MsbG](https://www.gesetze-im-internet.de/messbg/__30.html) |
| Intelligentes Messsystem <!-- doc-claim: rights-metering-cap imsys_6000 --> | über 6.000 bis 10.000 kWh | – | – | 40 € | [§ 30 Abs. 1, 3 MsbG](https://www.gesetze-im-internet.de/messbg/__30.html) |
| Intelligentes Messsystem <!-- doc-claim: rights-metering-cap imsys_10000 --> | über 10.000 bis 20.000 kWh | über 7 bis 15 kW | ja | 50 € | [§ 30 Abs. 1 Nr. 4 MsbG](https://www.gesetze-im-internet.de/messbg/__30.html) |
| Intelligentes Messsystem <!-- doc-claim: rights-metering-cap imsys_20000 --> | über 20.000 bis 50.000 kWh | über 15 bis 25 kW | – | 110 € | [§ 30 Abs. 1, 3 MsbG](https://www.gesetze-im-internet.de/messbg/__30.html) |
| Intelligentes Messsystem <!-- doc-claim: rights-metering-cap imsys_50000 --> | über 50.000 bis 100.000 kWh | über 25 bis 100 kW | – | 140 € | [§ 30 Abs. 1, 3 MsbG](https://www.gesetze-im-internet.de/messbg/__30.html) |
| Intelligentes Messsystem <!-- doc-claim: rights-metering-cap imsys_100000 --> | über 100.000 kWh | über 100 kW | – | angemessenes Entgelt, kein fester Betrag | [§ 30 Abs. 1, 3 MsbG](https://www.gesetze-im-internet.de/messbg/__30.html) |

Den Jahresverbrauch nimmt die App als Mittel der letzten drei vollen Jahre. Für
konventionelle Zähler und für Gas prüft sie nichts.

## Aussagen und Quellen

### Abrechnung und Rechnung

| Nr. | Aussage | Norm und Quelle | Stand | Status |
|---|---|---|---|---|
| 1 <!-- doc-claim: rights-rule de.enwg.40c.2 --> | Die Rechnung kommt spätestens sechs Wochen nach Ende des Abrechnungszeitraums, die Schlussrechnung sechs Wochen nach Ende der Belieferung; bei monatlicher Abrechnung drei Wochen. | [§ 40c Abs. 2 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__40c.html) | 2026-11-03 | W |
| 2 <!-- doc-claim: rights-rule de.enwg.40c.3 --> | Ein Guthaben wird binnen zwei Wochen ausgezahlt oder vollständig mit dem nächsten Abschlag verrechnet; aus einer Schlussrechnung wird es binnen zwei Wochen ausgezahlt. | [§ 40c Abs. 3 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__40c.html) | 2026-11-03 | W |
| 3 <!-- doc-claim: rights-rule de.enwg.40c.1 --> | Eine Zahlung ist frühestens zwei Wochen nach Zugang der Zahlungsaufforderung fällig. | [§ 40c Abs. 1 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__40c.html) | 2026-11-03 | W |
| 4 <!-- doc-claim: rights-rule de.enwg.40b.1 --> | Der Abrechnungszeitraum ist höchstens ein Jahr; der Lieferant bietet auch monatliche, vierteljährliche und halbjährliche Abrechnung an. | [§ 40b Abs. 1 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__40b.html) | 2026-11-03 | W |
| 5 <!-- doc-claim: rights-rule de.enwg.40.2.6 --> | Die Rechnung nennt Anfangs- und Endstand und wie sie ermittelt wurden. | [§ 40 Abs. 2 Satz 1 Nr. 6 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__40.html) | 2026-11-03 | W |
| 6 <!-- doc-claim: rights-rule de.enwg.40.2.13 --> | Die Rechnung nennt, ob du in der Grundversorgung bist. | [§ 40 Abs. 2 Satz 1 Nr. 13 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__40.html) | 2026-11-03 | W |
| 7 <!-- doc-claim: rights-rule de.enwg.40.3.4 --> | Entgelte für den Messstellenbetrieb stehen gesondert auf der Rechnung, soweit sie Teil der Kalkulation sind. | [§ 40 Abs. 3 Nr. 4 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__40.html) | 2026-11-03 | W |
| 33 <!-- doc-claim: rights-rule de.avbfernwaermev.24.1 --> | *Unsicher:* Für Fernwärme gibt es keine gesetzliche Frist für die Rechnung, abgerechnet wird mindestens jährlich; ob eine Neufassung der AVBFernwärmeV in Kraft ist, ist offen. Die App prüft hier nichts. | [§ 24 Abs. 1 AVBFernwärmeV](https://www.gesetze-im-internet.de/avbfernw_rmev/__24.html) | 2026-11-03 | U |
| 34 <!-- doc-claim: rights-rule de.avbwasserv.24.1 --> | Wasser (privatrechtlich) wird in Zeitabschnitten abgerechnet, die zwölf Monate nicht wesentlich überschreiten. | [§ 24 Abs. 1 AVBWasserV](https://www.gesetze-im-internet.de/avbwasserv/__24.html) | 2026-11-03 | W |

### Verbrauch ermitteln und schätzen

| Nr. | Aussage | Norm und Quelle | Stand | Status |
|---|---|---|---|---|
| 8 <!-- doc-claim: rights-rule de.enwg.40a.2.s1 --> | Schätzen darf der Lieferant nur, wenn trotz Pflicht keine Ablesedaten übermittelt wurden oder er den Verbrauch aus Gründen, die er nicht zu vertreten hat, nicht ermitteln kann; Grundlage sind das Vorjahr oder vergleichbare Kunden. | [§ 40a Abs. 2 Satz 1, 2 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__40a.html) | 2026-11-03 | W |
| 9 <!-- doc-claim: rights-rule de.enwg.40a.2.s3 --> | Eine Schätzung steht hervorgehoben auf der Rechnung, mit Grund und Faktoren; auf Wunsch erläutert der Lieferant sie unentgeltlich in Textform. | [§ 40a Abs. 2 Satz 3 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__40a.html) | 2026-11-03 | W |
| 10 <!-- doc-claim: rights-rule de.enwg.40a.1 --> | Bei intelligenten Messsystemen und Lastgangmessung gehen die Werte des Messstellen- oder Netzbetreibers vor; der Lieferant gibt an, wie der Stand ermittelt wurde. | [§ 40a Abs. 1 Satz 4, 5 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__40a.html) | 2026-11-03 | W |
| 11 <!-- doc-claim: rights-rule de.stromgvv.11 --> | Grundversorgung Strom: Der Verbrauch wird nach § 40a EnWG ermittelt. | [§ 11 Abs. 1 StromGVV](https://www.gesetze-im-internet.de/stromgvv/__11.html) | 2026-11-03 | W |
| 11 <!-- doc-claim: rights-rule de.gasgvv.11 --> | *Zu prüfen:* Grundversorgung Gas: ebenso. | [§ 11 Abs. 1 GasGVV](https://www.gesetze-im-internet.de/gasgvv/__11.html) | 2026-11-03 | G |
| 27 <!-- doc-claim: rights-rule de.stromgvv.17 --> | Grundversorgung Strom: Einwände gegen eine Rechnung berechtigen nur zum Zahlungsaufschub, wenn ein offensichtlicher Fehler ernsthaft möglich ist oder der Verbrauch mehr als doppelt so hoch ist und nachgeprüft wird. Die App rät nie, Zahlungen zurückzuhalten. | [§ 17 Abs. 1 StromGVV](https://www.gesetze-im-internet.de/stromgvv/__17.html) | 2026-11-03 | W |
| 27 <!-- doc-claim: rights-rule de.gasgvv.17 --> | *Zu prüfen:* Grundversorgung Gas: ebenso. | [§ 17 Abs. 1 GasGVV](https://www.gesetze-im-internet.de/gasgvv/__17.html) | 2026-11-03 | G |
| 28 <!-- doc-claim: rights-rule de.stromgvv.18 --> | Grundversorgung Strom: Bei einem Fehler im Rechnungsbetrag wird zu viel Gezahltes erstattet und zu wenig Gezahltes nachgefordert, in der Regel für den Ablesezeitraum vor der Feststellung, höchstens für drei Jahre. | [§ 18 StromGVV](https://www.gesetze-im-internet.de/stromgvv/__18.html) | 2026-11-03 | W |
| 28 <!-- doc-claim: rights-rule de.gasgvv.18 --> | *Zu prüfen:* Grundversorgung Gas: ebenso. | [§ 18 GasGVV](https://www.gesetze-im-internet.de/gasgvv/__18.html) | 2026-11-03 | G |
| 35 <!-- doc-claim: rights-rule de.avbwasserv.20.2 --> | Wasser: Der Versorger darf schätzen, solange sein Beauftragter die Räume nicht zur Ablesung betreten kann; Grundlage ist die letzte Ablesung. | [§ 20 Abs. 2 AVBWasserV](https://www.gesetze-im-internet.de/avbwasserv/__20.html) | 2026-11-03 | W |
| 35 <!-- doc-claim: rights-rule de.avbfernwaermev.20.2 --> | Fernwärme: ebenso. | [§ 20 Abs. 2 AVBFernwärmeV](https://www.gesetze-im-internet.de/avbfernw_rmev/__20.html) | 2026-11-03 | W |
| 57 <!-- doc-claim: rights-rule de.bnetza.bills --> | Eine Schätzung ist auf der Rechnung erkennbar, mit Grund und Faktoren; bei Problemen zuerst Beschwerde beim Versorger, dann Verbraucherservice der Bundesnetzagentur und Schlichtungsstelle. | [Bundesnetzagentur: Rechnungen](https://www.bundesnetzagentur.de/DE/Vportal/Energie/RechnungenSperrungen/Rechnungen-table.html) | 2026-11-03 | S |

### Abschläge

| Nr. | Aussage | Norm und Quelle | Stand | Status |
|---|---|---|---|---|
| 12 <!-- doc-claim: rights-rule de.stromgvv.13 --> | Grundversorgung Strom: Der Abschlag richtet sich anteilig nach dem Verbrauch im zuletzt abgerechneten Zeitraum; macht der Kunde einen erheblich geringeren Verbrauch glaubhaft, ist das angemessen zu berücksichtigen. Bei einer Preisänderung kann der Abschlag um denselben Prozentsatz angepasst werden; zu viel gezahlte Abschläge werden unverzüglich erstattet. | [§ 13 StromGVV](https://www.gesetze-im-internet.de/stromgvv/__13.html) | 2026-11-03 | W |
| 12 <!-- doc-claim: rights-rule de.gasgvv.13 --> | Grundversorgung Gas: ebenso. | [§ 13 GasGVV](https://www.gesetze-im-internet.de/gasgvv/__13.html) | 2026-11-03 | W |
| 13 <!-- doc-claim: rights-rule de.enwg.41b.3 --> | Sondervertrag mit Haushaltskunden: Voraus- oder Abschlagszahlungen richten sich nach dem Verbrauch des vorhergehenden Abrechnungszeitraums oder vergleichbarer Kunden; ein glaubhaft erheblich geringerer Verbrauch ist angemessen zu berücksichtigen; vor Lieferbeginn sind sie nicht fällig. | [§ 41b Abs. 3 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__41b.html) | 2026-11-03 | W |
| 14 <!-- doc-claim: rights-rule de.avbfernwaermev.25 --> | Fernwärme: Abschläge wie in der Grundversorgung (Verbrauch im zuletzt abgerechneten Zeitraum, glaubhaft erheblich geringerer Verbrauch, Anpassung bei Preisänderung, Erstattung). | [§ 25 AVBFernwärmeV](https://www.gesetze-im-internet.de/avbfernw_rmev/__25.html) | 2026-11-03 | W |
| 15 <!-- doc-claim: rights-rule de.avbwasserv.25 --> | Wasser (privatrechtlich): Abschläge wie in der Grundversorgung. | [§ 25 AVBWasserV](https://www.gesetze-im-internet.de/avbwasserv/__25.html) | 2026-11-03 | W |
| 16 <!-- doc-claim: rights-rule de.lg_ffm.3_06_o_13_23 --> | Einzelfall: Abschläge zu erhöhen, obwohl eine Berechnung nach dem Verbrauch eine Senkung ergäbe, verstößt gegen das EnWG (laut vzbv rechtskräftig). | [LG Frankfurt a. M., 30.05.2023, 3-06 O 13/23](https://www.vzbv.de/urteile/gaspreise-vzbv-stoppt-ueberhoehte-abschlaege) | 2026-11-03 | S |
| 17 <!-- doc-claim: rights-rule de.vz.advance --> | Abschläge orientieren sich am Vorjahresverbrauch; eigenmächtig kürzen solltest du sie nicht. | [Verbraucherzentrale Niedersachsen: Abschlagszahlungen](https://www.verbraucherzentrale-niedersachsen.de/wissen/energie/preise-tarife-anbieterwechsel/abschlagszahlungen-fuer-strom-und-gas-richtig-berechnen-120062) | 2026-11-03 | S |

### Preisänderung und Kündigung

| Nr. | Aussage | Norm und Quelle | Stand | Status |
|---|---|---|---|---|
| 18 <!-- doc-claim: rights-rule de.enwg.41.5.s1 --> | Sondervertrag: Über eine Preisänderung unterrichtet der Lieferant Haushaltskunden spätestens einen Monat vorher, einfach und verständlich, mit Anlass, Voraussetzungen und Umfang. | [§ 41 Abs. 5 Satz 1 bis 3 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__41.html) | 2026-11-03 | W |
| 19 <!-- doc-claim: rights-rule de.enwg.41.5.s4 --> | Sondervertrag: Du kannst ohne Kündigungsfrist zum Wirksamwerden der Preisänderung kündigen, ohne gesondertes Entgelt. | [§ 41 Abs. 5 Satz 4 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__41.html) | 2026-11-03 | W |
| 20 <!-- doc-claim: rights-rule de.enwg.41.6 --> | Sondervertrag: Kein Unterrichtungsgebot und kein Kündigungsrecht, wenn der Lieferant eine gesetzliche Änderung der Umsatzsteuer unverändert weitergibt oder eine Absenkung der Kalkulationsbestandteile nach § 40 Abs. 3 Nr. 1 bis 5 EnWG. | [§ 41 Abs. 6 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__41.html) | 2026-11-03 | W |
| 21 <!-- doc-claim: rights-rule de.vz.special_termination --> | Die Kündigung muss zum Wirksamwerden beim Anbieter vorliegen; der Anbieter darf dafür keine eigene Frist setzen; eine Mindestlaufzeit schließt das Recht nicht aus. | [Verbraucherzentrale Niedersachsen: Sonderkündigungsrecht (Stand 24.04.2026)](https://www.verbraucherzentrale-niedersachsen.de/themen/energie-wohnen/energiepreise-anbieterwechsel/preiserhoehung-wenn-energieanbieter-das-sonderkuendigungsrecht-verweigern) | 2026-11-03 | S |
| 22 <!-- doc-claim: rights-rule de.enwg.41b.1 --> | Sondervertrag: Der Lieferant bestätigt eine Kündigung binnen einer Woche nach Zugang in Textform, mit dem Vertragsende. | [§ 41b Abs. 1 Satz 2 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__41b.html) | 2026-11-03 | W |
| 24 <!-- doc-claim: rights-rule de.stromgvv.5.2 --> | Grundversorgung Strom: Preisänderungen gelten zum Monatsbeginn, mit öffentlicher Bekanntgabe mindestens sechs Wochen vorher und zeitgleich einem Brief mit Umfang, Anlass und Voraussetzungen. | [§ 5 Abs. 2 StromGVV](https://www.gesetze-im-internet.de/stromgvv/__5.html) | 2026-11-03 | W |
| 24 <!-- doc-claim: rights-rule de.gasgvv.5.2 --> | Grundversorgung Gas: ebenso. | [§ 5 Abs. 2 GasGVV](https://www.gesetze-im-internet.de/gasgvv/__5.html) | 2026-11-03 | W |
| 25 <!-- doc-claim: rights-rule de.stromgvv.5.3 --> | Grundversorgung Strom: Du kannst ohne Frist zum Wirksamwerden kündigen; die Änderung wirkt nicht, wenn du binnen eines Monats nach Zugang deiner Kündigung einen eingeleiteten Versorgerwechsel nachweist. | [§ 5 Abs. 3 StromGVV](https://www.gesetze-im-internet.de/stromgvv/__5.html) | 2026-11-03 | W |
| 25 <!-- doc-claim: rights-rule de.gasgvv.5.3 --> | Grundversorgung Gas: ebenso. | [§ 5 Abs. 3 GasGVV](https://www.gesetze-im-internet.de/gasgvv/__5.html) | 2026-11-03 | W |
| 26 <!-- doc-claim: rights-rule de.stromgvv.20 --> | Grundversorgung Strom: Kündigung jederzeit mit zwei Wochen Frist, in Textform; der Grundversorger bestätigt unverzüglich, ohne Entgelt. | [§ 20 StromGVV](https://www.gesetze-im-internet.de/stromgvv/__20.html) | 2026-11-03 | W |
| 26 <!-- doc-claim: rights-rule de.gasgvv.20 --> | *Zu prüfen:* Grundversorgung Gas: ebenso. | [§ 20 GasGVV](https://www.gesetze-im-internet.de/gasgvv/__20.html) | 2026-11-03 | G |
| 29 <!-- doc-claim: rights-rule de.avbfernwaermev.24.4 --> | Fernwärme: Preise ändern sich nach der Preisänderungsklausel mit Kosten- und Marktelement; die Berechnungsfaktoren sind auszuweisen. Ein Sonderkündigungsrecht gibt es nur im Sonderfall einer Gaspreiserhöhung nach dem Energiesicherungsgesetz. | [§ 24 Abs. 4 bis 7 AVBFernwärmeV](https://www.gesetze-im-internet.de/avbfernw_rmev/__24.html) | 2026-11-03 | W |
| 58 <!-- doc-claim: rights-rule de.enwg.2026 --> | *Unsicher:* Das EnWG wurde 2026 geändert (BGBl. 2026 I Nr. 226); ob das §§ 40 bis 41b betrifft, ist nicht geprüft. | [EnWG, Änderung durch BGBl. 2026 I Nr. 226](https://www.buzer.de/41_EnWG.htm) | 2026-11-03 | U |

### Laufzeit

| Nr. | Aussage | Norm und Quelle | Stand | Status |
|---|---|---|---|---|
| 30 <!-- doc-claim: rights-rule de.avbfernwaermev.32 --> | Fernwärme: Laufzeit höchstens zehn Jahre; ohne Kündigung neun Monate vor Ablauf verlängert sie sich um fünf Jahre; gekündigt wird schriftlich. | [§ 32 AVBFernwärmeV](https://www.gesetze-im-internet.de/avbfernw_rmev/__32.html) | 2026-11-03 | W |
| 31 <!-- doc-claim: rights-rule de.bgb.310.2 --> | §§ 308, 309 BGB gelten nicht für Sonderabnehmerverträge der Strom-, Gas-, Fernwärme- und Wasserversorger, soweit diese nicht zum Nachteil der Kunden von den Versorgungsbedingungen der Verordnungen abweichen; für Abwasser entsprechend. | [§ 310 Abs. 2 BGB](https://www.gesetze-im-internet.de/bgb/__310.html) | 2026-11-03 | W |
| 32 <!-- doc-claim: rights-rule de.bgb.309.9 --> | *Zu prüfen:* Eine erste Laufzeit über 24 Monate ist in den AGB eines Verbrauchervertrags unwirksam. Darauf stützt sich die Warnung der App bei Strom und Gas. | [§ 309 Nr. 9 BGB](https://www.gesetze-im-internet.de/bgb/__309.html) | 2026-11-03 | G |
| 59 <!-- doc-claim: rights-rule de.bgb.309.9.b --> | *Unsicher:* Eine stillschweigende Verlängerung ist in AGB nur wirksam, wenn der Vertrag danach auf unbestimmte Zeit läuft und jederzeit mit höchstens einem Monat Frist kündbar ist. Ob das für einen verlängerten Strom- oder Gasvertrag gilt, hängt von § 310 Abs. 2 BGB (Nr. 31) und vom Datum des Vertragsschlusses ab. Die App nennt deshalb keine Frist und rechnet kein frühestes Ende; sie verweist auf den Vertrag. | [§ 309 Nr. 9 Buchst. b BGB](https://www.gesetze-im-internet.de/bgb/__309.html) | 2026-11-03 | U |

### Bonus

| Nr. | Aussage | Norm und Quelle | Stand | Status |
|---|---|---|---|---|
| 23 <!-- doc-claim: rights-rule de.enwg.41.4 --> | Die Vertragszusammenfassung nennt Bonusvereinbarungen und Mindestlaufzeiten; der Vertrag nennt Sonderangebote und Preisnachlässe. | [§ 41 Abs. 4 Satz 2 Nr. 6, Abs. 1 Satz 2 Nr. 5 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__41.html) | 2026-11-03 | W |
| 37 <!-- doc-claim: rights-rule de.vz.bonus --> | Die Verbraucherzentrale wendet die Zwei-Wochen-Regel für Guthaben auch auf Boni an: auszahlen oder mit dem nächsten Abschlag verrechnen. | [§ 40c Abs. 3 EnWG (Auslegung der Verbraucherzentrale)](https://www.verbraucherzentrale.de/wissen/vertraege-reklamation/kundenrechte/wenn-energieanbieter-guthaben-oder-bonus-nicht-auszahlen-10913) | 2026-11-03 | S |
| 38 <!-- doc-claim: rights-rule de.bgh.ix_zr_267_20 --> | Einzelfall (Insolvenz): Ein als verbrauchsabhängiger Nachlass gestalteter Neukundenbonus mindert die Endabrechnung. Nur ein Beispiel, keine allgemeine Regel. | [BGH, 27.07.2023, IX ZR 267/20](https://rewis.io/urteile/urteil/f7q-27-07-2023-ix-zr-26720/) | 2026-11-03 | S |
| 39 <!-- doc-claim: rights-rule de.bgb.195 --> | *Zu prüfen:* Ansprüche verjähren regelmäßig in drei Jahren ab dem Ende des Jahres, in dem sie entstanden sind. | [§§ 195, 199 Abs. 1 BGB](https://www.gesetze-im-internet.de/bgb/__195.html) | 2026-11-03 | G |

### Messstellenbetrieb

| Nr. | Aussage | Norm und Quelle | Stand | Status |
|---|---|---|---|---|
| 40 <!-- doc-claim: rights-rule de.msbg.30 --> | Intelligentes Messsystem: Obergrenzen je Fallgruppe für den Anschlussnutzer 40, 50, 110 und 140 € im Jahr, über 100.000 kWh ein angemessenes Entgelt, beim optionalen Einbau 30 €; brutto je Jahr und Zählpunkt, für den grundzuständigen Messstellenbetreiber (Fassung vom 23.12.2025). Die Beträge älterer Fassungen sind nicht belegt; die App prüft erst ab 2026-01-01. | [§ 30 Abs. 1, 3 MsbG](https://www.gesetze-im-internet.de/messbg/__30.html) | 2026-11-03 | W |
| 41 <!-- doc-claim: rights-rule de.msbg.30.1.4 --> | Mit einer Vereinbarung nach § 14a EnWG oder einer Anlage über 7 bis 15 kW gilt die Gruppe mit 50 €. | [§ 30 Abs. 1 Nr. 4 MsbG](https://www.gesetze-im-internet.de/messbg/__30.html) | 2026-11-03 | W |
| 42 <!-- doc-claim: rights-rule de.msbg.30.4 --> | Jahresverbrauch ist das Mittel der letzten drei Jahreswerte, sonst die Prognose des Netzbetreibers; passen mehrere Gruppen, gilt die höchste Grenze; eine Festlegung der Bundesnetzagentur ginge vor. Ob es sie gibt, ist offen (Stand 2026-11). | [§ 30 Abs. 4 bis 6 MsbG](https://www.gesetze-im-internet.de/messbg/__30.html) | 2026-11-03 | W |
| 43 <!-- doc-claim: rights-rule de.msbg.32.1 --> | Moderne Messeinrichtung: höchstens 25 € brutto im Jahr (seit 2025, vorher 20 €; das Datum der Erhöhung ist noch zu prüfen). | [§ 32 Abs. 1 MsbG](https://www.gesetze-im-internet.de/messbg/__32.html) | 2026-11-03 | W |
| 44 <!-- doc-claim: rights-rule de.msbg.35.1 --> | Zusatzleistung vorzeitige Ausstattung auf Verlangen: einmalig bis 100 €, im optionalen Fall bis 30 € je Jahr; Einbau auf Verlangen binnen vier Monaten. | [§ 35 Abs. 1 Nr. 1 MsbG](https://www.gesetze-im-internet.de/messbg/__35.html) | 2026-11-03 | W |
| 45 <!-- doc-claim: rights-rule de.msbg.29 --> | Pflichteinbau eines intelligenten Messsystems über 6.000 kWh, mit einer Vereinbarung nach § 14a EnWG und bei Anlagen über 7 kW; sonst optional; moderne Messeinrichtung bis Ende 2032. | [§ 29 MsbG](https://www.gesetze-im-internet.de/messbg/__29.html) | 2026-11-03 | W |
| 46 <!-- doc-claim: rights-rule de.bnetza.msb_costs --> | Die Preisobergrenzen gelten für den grundzuständigen Messstellenbetreiber; ein frei gewählter ist daran nicht gebunden. | [Bundesnetzagentur: Kosten des Messstellenbetriebs](https://www.bundesnetzagentur.de/DE/Vportal/Energie/Metering/_faq/Kosten_table.html) | 2026-11-03 | S |

### Gebührenbescheid (Wasser, Abwasser)

| Nr. | Aussage | Norm und Quelle | Stand | Status |
|---|---|---|---|---|
| 36 <!-- doc-claim: rights-rule de.avbwasserv.30 --> | *Zu prüfen:* Wasser (privatrechtlich): Einwände gegen eine Rechnung berechtigen nur bei offensichtlichen Fehlern zum Zahlungsaufschub. | [§ 30 AVBWasserV](https://www.gesetze-im-internet.de/avbwasserv/__30.html) | 2026-11-03 | G |
| 47 <!-- doc-claim: rights-rule de.vwgo.70.1 --> | Widerspruch binnen eines Monats nach Bekanntgabe, schriftlich, elektronisch nach § 3a Abs. 2 VwVfG oder zur Niederschrift bei der Behörde, die den Bescheid erlassen hat. | [§ 70 Abs. 1 VwGO](https://www.gesetze-im-internet.de/vwgo/__70.html) | 2026-11-03 | W |
| 48 <!-- doc-claim: rights-rule de.vwgo.58.2 --> | Ohne oder mit unrichtiger Rechtsbehelfsbelehrung gilt eine Frist von einem Jahr. | [§ 58 Abs. 2 VwGO](https://www.gesetze-im-internet.de/vwgo/__58.html) | 2026-11-03 | W |
| 49 <!-- doc-claim: rights-rule de.vwgo.68.1 --> | *Zu prüfen:* Landesrecht kann das Widerspruchsverfahren ausschließen; dann ist binnen eines Monats Klage zu erheben. | [§ 68 Abs. 1 Satz 2, § 74 Abs. 1 VwGO](https://www.gesetze-im-internet.de/vwgo/__68.html) | 2026-11-03 | G |
| 50 <!-- doc-claim: rights-rule de.vwvfg.41.2 --> | Ein Bescheid, der im Inland per Post übermittelt wird, gilt am vierten Tag nach Aufgabe zur Post als bekannt gegeben. | [§ 41 Abs. 2 Satz 1 VwVfG](https://www.gesetze-im-internet.de/vwvfg/__41.html) | 2026-11-03 | W |
| 50 <!-- doc-claim: rights-rule de.ao.122.2 --> | Für Abgaben ebenso nach der Abgabenordnung; der Verweis im Kommunalabgabengesetz ist je Land noch zu prüfen. | [§ 122 Abs. 2 Nr. 1 AO](https://www.gesetze-im-internet.de/ao_1977/__122.html) | 2026-11-03 | W |
| 51 <!-- doc-claim: rights-rule de.vwgo.80 --> | Widerspruch und Klage gegen öffentliche Abgaben schieben die Zahlung nicht auf; die Vollziehung soll ausgesetzt werden, wenn ernstliche Zweifel bestehen oder sie eine unbillige Härte wäre. | [§ 80 Abs. 2 Satz 1 Nr. 1, Abs. 4 Satz 3 VwGO](https://www.gesetze-im-internet.de/vwgo/__80.html) | 2026-11-03 | W |
| 52 <!-- doc-claim: rights-rule de.bgb.188 --> | *Zu prüfen:* Eine Monatsfrist endet mit dem Tag des Folgemonats mit derselben Zahl; fehlt er, mit dem Monatsletzten (das BGB gilt nach § 222 Abs. 1 ZPO und § 31 Abs. 1 VwVfG). Zum Ende am Wochenende oder Feiertag Nr. 60. | [§§ 187 Abs. 1, 188 Abs. 2, 3 BGB](https://www.gesetze-im-internet.de/bgb/__188.html) | 2026-11-03 | G |
| 60 <!-- doc-claim: rights-rule de.vwgo.57.2 --> | Fällt das Ende einer Frist auf einen Sonntag, einen allgemeinen Feiertag oder einen Sonnabend, endet sie mit Ablauf des nächsten Werktags; das gilt nach § 57 Abs. 2 VwGO für die Fristen der VwGO. | [§ 57 Abs. 2 VwGO, § 222 Abs. 2 ZPO](https://www.gesetze-im-internet.de/vwgo/__57.html) | 2026-11-03 | W |
| 60 <!-- doc-claim: rights-rule de.vwvfg.31.3 --> | Im Verwaltungsverfahren ebenso: Fristende am Sonntag, gesetzlichen Feiertag oder Sonnabend → Ablauf des nächstfolgenden Werktags (nicht, wenn die Behörde unter Hinweis auf die Vorschrift einen bestimmten Tag als Ende mitgeteilt hat). | [§ 31 Abs. 3 VwVfG](https://www.gesetze-im-internet.de/vwvfg/__31.html) | 2026-11-03 | W |
| 60 <!-- doc-claim: rights-rule de.ao.108.3 --> | Für Abgaben nach der Abgabenordnung ebenso. | [§ 108 Abs. 3 AO](https://www.gesetze-im-internet.de/ao_1977/__108.html) | 2026-11-03 | W |
| 61 <!-- doc-claim: rights-rule de.feiertage --> | *Zu prüfen:* In ganz Deutschland gesetzliche Feiertage sind Neujahr, Karfreitag, Ostermontag, 1. Mai, Christi Himmelfahrt, Pfingstmontag, 3. Oktober, 25. und 26. Dezember; der 3. Oktober nach dem Einigungsvertrag (gelesen), die übrigen nach den Feiertagsgesetzen der Länder (nicht einzeln abgerufen). Weitere Feiertage einzelner Länder kennt die App nicht. | [Art. 2 Abs. 2 Einigungsvertrag, Feiertagsgesetze der Länder](https://www.gesetze-im-internet.de/einigvtr/art_2.html) | 2026-11-03 | G |
| 53 <!-- doc-claim: rights-rule de.land.vorverfahren --> | *Unsicher:* Die Länder regeln das Widerspruchsverfahren unterschiedlich (Beispiele 2021: Nordrhein-Westfalen, Bayern). Nicht in App-Texten. | [§ 110 JustG NRW, Art. 12 AGVwGO (Bayern)](https://steuerzahler.de/aktuelles/detail/rechtsschutz-gegenueber-behoerden-das-widerspruchsverfahren/) | 2026-11-03 | U |

### Beschwerde, Begriffe, Gestaltung

| Nr. | Aussage | Norm und Quelle | Stand | Status |
|---|---|---|---|---|
| 54 <!-- doc-claim: rights-rule de.enwg.111a --> | Verbraucherbeschwerde: Energieversorgungsunternehmen, Messstellenbetreiber und Messdienstleister antworten binnen vier Wochen; lehnen sie ab, nennen sie die Gründe in Textform und weisen auf die Schlichtungsstelle hin. | [§ 111a EnWG](https://www.gesetze-im-internet.de/enwg_2005/__111a.html) | 2026-11-03 | W |
| 55 <!-- doc-claim: rights-rule de.enwg.3.31 --> | „Energie“ im Sinne des EnWG sind Elektrizität, Gas und Wasserstoff, nicht Fernwärme; dort steht auch, wer Haushaltskunde ist. | [§ 3 Nr. 31, Nr. 57 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__3.html) | 2026-11-03 | W |
| 56 <!-- doc-claim: rights-rule de.bgh.i_zr_113_20 --> | Ein Programm, das Dokumente aus Vorlagen erstellt, ist keine Rechtsdienstleistung (wie ein Formularhandbuch). Grundlage für die Gestaltung der Briefe, nicht in App-Texten. | [BGH, 09.09.2021, I ZR 113/20](https://www.lto.de/recht/juristen/b/bgh-urteil-izr11320-vertragsgenerator-smartlaw-keine-unzulaessige-rechtsdienstleistung-rak-hamburg-rdg-legal-tech) | 2026-11-03 | S |

## Was offen ist

- **Gegenlesen** durch einen Menschen vor dem Release (alle Zeilen).
- **Zu prüfen (G):** GasGVV §§ 11, 17, 18, 20 im Wortlaut; § 309 Nr. 9 BGB als
  Grundlage der Laufzeitwarnung; Verjährung (§§ 195, 199 BGB); Ausschluss des
  Widerspruchsverfahrens (§ 68, § 74 VwGO); Fristende (§§ 187, 188 BGB); die
  bundesweiten Feiertage nach den Feiertagsgesetzen der Länder (Nr. 61); § 30
  AVBWasserV; der Verweis der Kommunalabgabengesetze auf die Abgabenordnung je
  Land; das Datum, seit dem 25 € für die moderne Messeinrichtung gelten.
- **Unsicher (U):** Neufassung der AVBFernwärmeV; Änderung des EnWG durch
  BGBl. 2026 I Nr. 226; Widerspruchsverfahren in den Ländern; Beträge der
  MsbG-Fassungen 2023 und 02/2025; eine Festlegung der Bundesnetzagentur zu den
  Preisobergrenzen; die Kündigungsfrist eines stillschweigend verlängerten
  Strom- oder Gasvertrags (Nr. 59).
