# Meter-Topologie — Subzähler & Zählergruppen (F1006)

**Deutsch** · [English](../en/verstehen/13-meter-topologie.md)

[← PV](12-pv.md) · [Kompendium-Index](../README.md)

Seit **v1.8.0** (Schema 1.2.0) können Zähler in Beziehung zueinander stehen.
Das löst zwei sehr häufige Alltagssituationen sauber: **„ein Zähler steckt
hinter einem anderen"** und **„mehrere Zähler gehören eigentlich zusammen"**.

| Beziehung | Feld am Zähler | Wirkung |
|---|---|---|
| **Subzähler** (Reihenschaltung) | `parent_meter_id` | zählt **nicht zusätzlich** in die Summe; mit eigenem Vertrag rechnet der Elternvertrag nur den **Rest** (seit v3.9.0) |
| **Gruppe** | `meter_group_id` | Verbräuche werden im Dashboard **zusammengefasst**; seit v3.1.0 auch ein **gemeinsamer Vertrag** |

Beide Felder sind optional (Default `null`) und additiv — bestehende Daten
bleiben unverändert.

---

## 1. Subzähler (Reihenschaltung)

**Situation:** Ein Verbraucher hängt *hinter* dem Hauptzähler und wird von
diesem mitgemessen. Klassiker:

- **Wärmepumpe** hinter dem Haushaltsstrom-Zähler,
- **Wallbox** hinter dem Hausanschluss,
- **Gartenwasser** hinter dem Hauptwasserzähler.

Der Hauptzähler misst hier **brutto** — also inklusive des Subzählers. Würde
man beide einfach addieren, wäre der Subzähler-Anteil doppelt gezählt.

**Lösung:** Setze beim Subzähler den Elternzähler (`parent_meter_id`). Die
Menge der Verbrauchsart zählt dann nur Zähler ohne Elternzähler:

```
Summe der Verbrauchsart (Menge) = nur Zähler OHNE parent_meter_id
```

Im Dashboard erscheint der Subzähler eingerückt unter seinem Elternzähler; in
der Verbrauchsart-Summe taucht er **nicht** zusätzlich auf. Was der Vertrag des
Elternzählers rechnet, hängt davon ab, ob der Subzähler einen eigenen Vertrag hat:

| Subzähler | Vertrag des Elternzählers rechnet | Beispiel |
|---|---|---|
| **ohne eigenen Vertrag** | alles, was er misst (brutto); der Subzähler ist eine **Aufschlüsselung** | private Messung an der Wallbox |
| **mit eigenem Vertrag** *(seit v3.9.0)* | nur den **Rest**: gemessen minus Subzähler | Wärmepumpe mit eigenem Tarif (Modul 2), Kaskade |

> **Beispiel ohne eigenen Vertrag.** Haushaltsstrom misst im Januar 300 kWh
> (brutto). Davon entfallen laut Wärmepumpen-Subzähler 120 kWh auf die
> Wärmepumpe. Die Strom-Gesamtsumme für Januar bleibt **300 kWh** (nicht 420),
> und der Haushaltsvertrag rechnet die 300 kWh. Die 120 kWh sind nur die
> Aufschlüsselung, wie viel davon die Wärmepumpe war.

### Rest-Abrechnung *(v3.9.0)*

Hat der Subzähler einen **eigenen Vertrag** (auch einen Gruppenvertrag seiner
Gruppe, kein Angebot), rechnet der Vertrag des Elternzählers nur noch den Rest.
Das gilt tagesgenau: abgezogen wird nur an Tagen, an denen der Vertrag des
Subzählers läuft. Beginnt er am 16. März, rechnet der Elternvertrag bis zum
15. März alles.

<!-- doc-claim: rest-billing-example (SubmeterRestDocsTest) -->
```text
Elternzähler 500 kWh im Monat, Vertrag 20 ct/kWh
Subzähler    200 kWh im Monat, eigener Vertrag 40 ct/kWh

Elternvertrag   300 kWh = 500 kWh gemessen − 200 kWh Subzähler   × 20 ct =  60 €
Subzählervertrag                        200 kWh                  × 40 ct =  80 €
Summe der Verbrauchsart             500 kWh                               140 €
```

- **Menge:** Die Summe der Verbrauchsart bleibt die Messung des Elternzählers
  (500 kWh, keine Doppelzählung). Die Monatszeile des Elternzählers behält
  `kwh` = Messung und trägt dazu `billed_kwh` (die Menge seiner Verträge).
- **Kosten:** Rest × Elternpreis + Subzähler × eigener Preis. Saldo, Kosten &
  Verträge, Rechnung prüfen, Abrechnungsjahr, Prognose und Prognose-Werkstatt,
  Abschlagsvorschlag, Jahresbericht („Wohin geht mein Geld?“), Tarifvergleich
  (Rückblick und Wechsel: erwarteter Jahresverbrauch = Rest), CSV und PDF
  rechnen so.
- **Hinweis:** Am Elternzähler, an seinem Vertrag und in „Rechnung prüfen“ steht,
  wie gerechnet wird, etwa „300 kWh = 500 kWh gemessen − 200 kWh Wärmepumpe“.
  Die Schnittstelle liefert dazu `rest_of` (`measured`, `submeters`, `rest`).
  Beim Anlegen eines Vertrags an einem Subzähler sagt ein Satz, dass der
  Elternvertrag ab dessen Beginn nur den Rest rechnet.
- **Verschiedene Ablesetage:** Beide Zähler werden über die Tagesverteilung
  angeglichen (zwischen zwei Ablesungen gleichmäßig je Tag). Ist der
  Elternzähler schon weiter abgelesen als der Subzähler, schreibt die App die
  letzte Tagesrate des Subzählers bis zu 92 Tage fort und sagt es
  (`estimated_kwh`). Fehlt ein Stand ganz, zieht nichts ab (`open_days`).
- **Rest unter null:** Misst der Subzähler in einem Monat mehr als der
  Elternzähler, stimmt meist eine Ablesung nicht. Der Elternvertrag rechnet
  diesen Monat nicht negativ ab (0 kWh, 0 € Arbeitspreis), der Hinweis nennt die
  Menge (`short_kwh`) und bittet, die Ablesungen zu prüfen.
- **Nicht bei Wasser:** Dort zieht der Gartenzähler seit v3.1.0 über das
  Schmutzwasser ab (Basis „Trinkwasser minus Abzugszähler“).

### Regeln

- **Maximal eine Ebene.** Ein Subzähler darf nicht selbst Elternzähler eines
  weiteren Subzählers sein (keine mehrstufigen Ketten, keine Zyklen).
- **Löschschutz.** Ein Elternzähler mit zugeordneten Subzählern lässt sich nicht
  löschen, ohne die Zuordnung vorher zu lösen.

### Modul 2: ein eigener Zähler mit eigenem Vertrag *(v3.1.0)*

Nach § 14a EnWG, Modul 2, bekommt eine Wärmepumpe oder Wallbox einen eigenen
Zähler des Netzbetreibers, und ihr Netz-Arbeitspreis sinkt auf 40 %
([Strom → Steuerbare Verbraucher](02-strom.md#steuerbare-verbraucher-v310)).
Abgerechnet werden dann zwei Mengen zu zwei Preisen. So bildest du das ab:

1. Den Zähler der Wärmepumpe bzw. Wallbox als eigenen Stromzähler anlegen, mit
   der Rolle „Wärmepumpe (Heizstrom)“ bzw. „Wallbox“.
2. **Hängt er hinter dem Haushaltszähler** (der Haushaltszähler misst alles),
   den Haushaltszähler als Elternzähler setzen. **Sitzen beide Zähler
   nebeneinander**, bleibt das Feld leer.
3. Dem Zähler einen **eigenen Vertrag** mit dem niedrigeren Arbeitspreis geben.
   Hängt er hinter dem Haushaltszähler, rechnet der Haushaltsvertrag ab dessen
   Beginn nur noch den Rest ([Rest-Abrechnung](#rest-abrechnung-v390)).

Saldo und Kosten stehen dann je Zähler da; die Summe der Verbrauchsart stimmt
in beiden Fällen.

> **Korrektur v3.9.0.** Seit v3.1.0 stand hier, die App ziehe die Menge des
> Subzählers am Haushaltszähler ab und der Haushaltsvertrag rechne nur den Rest.
> Gerechnet hat sie das nicht: Der Haushaltsvertrag rechnete alles, was sein
> Zähler misst, die Menge des Subzählers wurde über beide Verträge bezahlt, und
> die Monatssumme der Kosten enthielt den Subzähler nicht. Seit v3.9.0 rechnet
> die App so, wie es hier steht; ein Test hält die Zahlen des Beispiels oben
> fest. Wer schon einen Vertrag am Subzähler hat, sieht deshalb am
> Haushaltszähler niedrigere Kosten und in der Summe der Verbrauchsart höhere.

Mehrere Zähler hintereinander mit HT/NT und PV (Kaskade) beschreibt der
[Anwendungsfall H](../anleitungen/anwendungsfaelle.md#h--kaskade-mit-wärmepumpe-htnt-und-pv-v390).
Dort misst der vordere Zähler alles, und der Rest ist die Wärmepumpe: Die Regel
gilt in beide Richtungen, der Vertrag des vorderen Zählers kann auch der der
Wärmepumpe sein. Jahresarbeitszahl, Effizienz und „Strom nach Rollen“ nehmen
seit v3.9.0 je Zähler den eigenen Verbrauch (gemessen minus Subzähler); ein
Wärmepumpen-Subzähler hinter dem Haushaltszähler zählt damit auch in der
Effizienz, wie Anwendungsfall C es beschreibt.

---

## 2. Zählergruppen

**Situation:** Mehrere Zähler gehören logisch zusammen und sollen im Dashboard
als *ein* Posten erscheinen:

- **NT + HT Strom** (zwei Zählwerke / zwei Zähler für denselben Anschluss),
- **mehrere Wallboxen** an einem Standort,
- **mehrere Einheiten** desselben Hauses (siehe
  [Use-Cases → Vermieter](../anleitungen/anwendungsfaelle.md)).

**Lösung:** Lege eine Gruppe an und ordne die Zähler zu (`meter_group_id`). Die
Gruppe summiert die Verbräuche fürs Dashboard und ist aufklappbar, sodass die
Einzelzähler weiterhin sichtbar bleiben.

### Zu Gruppe zusammenfassen

Am schnellsten geht das Bündeln über **Verbrauch → *Verbrauchsart* → ⚙️ Zähler →
„Zu Gruppe zusammenfassen"**: mehrere bestehende Zähler auswählen, Gruppenname
vergeben, fertig. Im Hintergrund setzt der Dialog `meter_group_id` bei allen
gewählten Zählern (`POST …/meter-groups/merge`; bis v2.11 hieß er
„Zähler zusammenführen“).

### Gruppenvertrag *(v3.1.0)*

Bis v3.0 fassten Gruppen nur den **Verbrauch fürs Dashboard** zusammen;
Verträge gehörten immer zu einem Zähler. Seit v3.1.0 kann ein Vertrag **einer
Gruppe** gehören ([#17](https://github.com/Bingerminger/energietracker/issues/17))
— für Gas, Strom und Fernwärme, also die Arten mit Abschlagsverträgen. Der
typische Fall ist ein Doppeltarifzähler: zwei Zählwerke (HT und NT), ein
Vertrag mit einem Grundpreis und zwei Arbeitspreisen
([Strom](02-strom.md#hoch--und-niedertarif-ein-vertrag-für-eine-zählergruppe-v310)).

**Anlegen.** Im Vertragsdialog unter **„Zähler“** die Gruppe wählen — sie steht
unter „Zählergruppen (ein Vertrag für alle)“, sobald sie Mitglieder hat. Dann
erscheint **„Arbeitspreis je Zähler (z. B. HT/NT)“**: je Mitglied eine eigene
Preisliste; leer gilt der Arbeitspreis oben. Die Vertragskarte nennt die Gruppe
statt eines Zählers.

**Rechnen.** Jedes Mitglied rechnet seinen Verbrauch zu seinem Arbeitspreis.
Grundpreis, Abschläge und Boni trägt nur das **erste Mitglied** (Reihenfolge
der Zählerliste) — so zählen sie genau einmal, und jede Summe stimmt: die der
Verbrauchsart, die Übersicht, der Jahresbericht, der CSV-Export und die
Effizienzkennzahl.

```text
Beispiel: HT 2.000 kWh × 30 ct + NT 1.000 kWh × 22 ct + 12 × 12 € Grundpreis
        = 600 € + 220 € + 144 € = 964 € im Jahr
```

**Wo du es siehst.**

- **Verbrauchsansicht eines Mitglieds:** Die Saldo-Karte zeigt den Saldo des
  Gruppenvertrags mit dem Hinweis „Dieser Zähler rechnet über den
  Gruppenvertrag „…“. Saldo und Abschläge gelten für die ganze Gruppe.“
- **Wechsel prüfen:** Die Auswahl der Zähler führt „Gruppe: …“ für jede Gruppe
  mit Gruppenvertrag. Prognose und Wechselentscheidung rechnen mit dem
  Verbrauch der ganzen Gruppe; bei zwei Arbeitspreisen mit einem Mischpreis,
  gewichtet mit dem Verbrauch der Zählwerke in den letzten zwölf Monaten. Ein
  Angebot (Schattenvertrag) für die Gruppe legst du dort an.
- **Zu tun, Kalender, Empfehlungen:** Kündigungsstichtag, Vertragsende und
  Preiserhöhung gelten auch für Gruppenverträge.
- **Rechnung prüfen:** In der Oberfläche je Zähler — das erste Mitglied mit den
  festen Kosten, die anderen mit ihrem Verbrauch. Die ganze Gruppe auf einmal
  rechnet `GET …/meter-groups/{id}/bill-check` nach
  ([API](../referenz/api.md#gruppenvertrag-v310)).

**Regeln.**

- Ein Mitglied darf im Zeitraum eines Gruppenvertrags **keinen eigenen echten
  Vertrag** haben, und umgekehrt — sonst zählten Grundpreis und Verbrauch
  doppelt. Die App lehnt das ab („Ein Zähler der Gruppe hat in diesem Zeitraum
  einen eigenen Vertrag …“); den alten Vertrag vorher beenden. Angebote
  (Schattenverträge) sind davon nicht betroffen.
- Eine Gruppe mit Verträgen lässt sich nicht auflösen; erst die
  Gruppenverträge löschen oder einem Zähler zuordnen.
- Wasser kennt keine Gruppenverträge.

---

## 3. Zusammenspiel & Datenmodell

Ein Zähler darf **gleichzeitig** Subzähler *und* Gruppenmitglied sein. Die
Gruppensumme zählt Subzähler nicht, deren Elternzähler selbst Mitglied ist —
so kann nichts doppelt einfließen. Rechnet ein solcher Subzähler nach dem
Gruppenvertrag, zählt sein Geld trotzdem mit, und der Elternzähler rechnet nur
den Rest. In der Kaskade (Zählwerke HT und NT je als eigener Zähler) hängt jedes
Zählwerk des hinteren Zählers am gleichnamigen Zählwerk des vorderen: HT hinter
HT, NT hinter NT.

Der Assistent **Messkonzept einrichten** *(v3.9.0)* legt das nach diesen Regeln
an: Er fragt nach dem Aufbau (ein Zähler für alles, zwei Zähler nebeneinander,
Subzähler dahinter, Kaskade) und legt Subzähler, Gruppen für HT und NT,
Zählwerke und Verträge an; vorhandene Zähler werden zugeordnet
([Use-Case H](../anleitungen/anwendungsfaelle.md#case-cascade),
[API](../referenz/api.md#messkonzept-einrichten-v390-f1032)).

Die technische Sicht (Felder, `meter_groups.json`, Validierung, API-Endpoints)
steht im [Datenmodell](../referenz/datenmodell.md) und in der
[API-Referenz](../referenz/api.md). Die Aliase für die
Home-Assistant-Anbindung (`external_id`) sind unabhängig davon und in
[Home Assistant](../anleitungen/home-assistant.md) beschrieben.

---

[← PV](12-pv.md) · [Kompendium-Index](../README.md)
