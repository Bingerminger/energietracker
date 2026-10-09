# CO₂-Kosten mit dem Vermieter teilen

**Deutsch** · [English](../en/anleitungen/co2-aufteilung.md)

[← Kompendium-Index](../README.md)

In deutschen Mietwohnungen tragen Mieter und Vermieter die CO₂-Kosten fürs
Heizen gemeinsam — nach dem **Kohlendioxidkostenaufteilungsgesetz
(CO2KostAufG)**, für Abrechnungszeiträume ab 2023. Je mehr CO₂ das Gebäude je
Quadratmeter ausstößt, desto größer ist der Anteil des Vermieters. Seit
**v3.1.0** rechnet der Energietracker diesen Anteil aus: Mit eigener
Gastherme fordert er ihn für dich an, bei einer Zentralheizung prüft er die
Heizkostenabrechnung nach.

Seit dem 29.07.2026 regelt das Gesetz außerdem neu eingebaute Gas- und
Ölheizungen nach § 43 des Gebäudemodernisierungsgesetzes (GModG; so heißt das
GEG seit Juli 2026): Dort tragen Mieter und Vermieter ab 2028 die Gasnetzentgelte und die
CO₂-Kosten und ab 2029 die Kosten der vorgeschriebenen Brennstoffanteile (etwa
Biomethan) je zur Hälfte, mit einer Härtefallregel (§§ 5a–5d CO2KostAufG,
Stand 09.10.2026). Das rechnet die App nicht. Die zehn Stufen unten gelten
unverändert.

> **Eine Hilfsrechnung, keine Rechtsberatung.** Die App rechnet mit deinen
> Daten und den Regeln des Gesetzes, beurteilt aber nicht, ob und wie sie im
> Einzelfall gelten. Bei Fragen helfen Mietervereine oder eine Rechtsberatung.

Was der CO₂-Preis selbst ist und wie die App ihn rechnet:
[CO₂-Preis im Brennstoff](../verstehen/16-co2-preis.md).

---

## 1. Was du brauchst

- **Einstellungen → Haushalt & Gebäude → „Ich wohne“: „zur Miete“** und als
  Land Deutschland. Sonst fehlt die Karte; die API antwortet mit
  `supported: false`.
- Ein **Mietverhältnis** unter Kosten & Verträge → Mietverhältnis
  ([Als Mieter](mieter.md#5-mietverhältnis-anlegen)), das im Abrechnungsjahr
  gilt, mit der **Wohnfläche**.
- Für die eigene Gastherme: den **Gasverbrauch** des Jahres (Zählerstände und
  Vertrag) oder die **Heizöl-Lieferungen** — am besten dazu die
  **Gasrechnung** mit ihren CO₂-Angaben.
- Für die Zentralheizung: die **Heizkostenabrechnung** des Vermieters mit
  ihren CO₂-Angaben.

## 2. Welcher Fall bist du?

| Deine Heizung | Wer rechnet | Was die App tut |
|---|---|---|
| **Etagenheizung** — Gastherme in der Wohnung mit eigenem Gasvertrag, oder Heizöl, das du selbst kaufst | Du zahlst den CO₂-Preis über deinen Lieferanten und forderst den Anteil des Vermieters selbst ein (§ 6) | rechnet den Anteil aus, schreibt ein Anschreiben als PDF und erinnert an die Frist |
| **Zentralheizung** oder Fernwärme über den Vermieter | Der Vermieter teilt die Kosten in der Heizkostenabrechnung auf (§ 7) | rechnet seine Angaben nach und meldet Abweichungen |

Die App wählt den Fall selbst: Trägt eine Nebenkostenabrechnung, deren
Zeitraum im Jahr endet, **CO₂-Angaben**, gilt Zentralheizung. Sonst rechnet sie
mit deinen **Gas- und Heizöldaten** als Etagenheizung.

## 3. Die Wohnfläche

Im Dialog **„Mietverhältnis bearbeiten“** steht das Feld **„Wohnfläche laut
Mietvertrag (m²)“**. Leer gilt die Wohnfläche aus Einstellungen → Haushalt &
Gebäude. Ohne Fläche gibt es keine Stufe; die Karte sagt dann: „Ohne
Wohnfläche keine Stufe – bitte im Mietverhältnis oder unter Haushalt
eintragen.“ Gibt es im Jahr mehrere Mietverhältnisse, zählt das jüngste.

## 4. Die zehn Stufen

Maßstab ist der CO₂-Ausstoß je Quadratmeter Wohnfläche und Jahr, auf eine
Nachkommastelle gerundet (Anlage zum CO2KostAufG):

| Stufe | kg CO₂ je m² und Jahr | Anteil Vermieter | Anteil Mieter |
|---|---|---|---|
| 1 | unter 12 | 0 % | 100 % |
| 2 | 12 bis unter 17 | 10 % | 90 % |
| 3 | 17 bis unter 22 | 20 % | 80 % |
| 4 | 22 bis unter 27 | 30 % | 70 % |
| 5 | 27 bis unter 32 | 40 % | 60 % |
| 6 | 32 bis unter 37 | 50 % | 50 % |
| 7 | 37 bis unter 42 | 60 % | 40 % |
| 8 | 42 bis unter 47 | 70 % | 30 % |
| 9 | 47 bis unter 52 | 80 % | 20 % |
| 10 | ab 52 | 95 % | 5 % |

Umfasst eine Heizkostenabrechnung weniger als ein Jahr, kürzt die App die
Grenzen im selben Verhältnis (§ 5 Abs. 1).

## 5. Etagenheizung: selbst ausrechnen und einfordern

**1. Verbrauch erfassen.** Gas wie gewohnt mit Zählerständen und Vertrag,
Heizöl über die Lieferungen. Die App rechnet daraus die Emissionen des
Kalenderjahres mit dem Standardfaktor des BEHG und dem CO₂-Preis des Jahres.

**2. Besser: die Gasrechnung erfassen.** Unter **Kosten & Verträge → Rechnung
prüfen**, Karte **„Laut Rechnung“**
([Jahresabrechnung](jahresabrechnung.md#7-laut-rechnung-erfassen-vergleichen-buchen)):
dort **„Emissionen (kg CO₂)“** und **„CO₂-Kosten laut Rechnung“** so, wie sie
auf der Rechnung stehen — der Lieferant weist den Betrag mit Umsatzsteuer aus
(§ 3 Abs. 3) —, und das **Rechnungsdatum**. Dann rechnet die App mit den Werten des Lieferanten, und
aus dem Rechnungsdatum entsteht die Frist.

**3. Kürzungen eintragen.** Im Dialog „Mietverhältnis bearbeiten“, Feldgruppe
**„CO₂-Kosten (CO2KostAufG)“**:

| Feld | Wirkung |
|---|---|
| „Gas auch für eigene Geräte (z. B. Gasherd) – Erstattung −5 %“ | Die Erstattung sinkt um 5 % (§ 6 Abs. 3 Satz 2). |
| „Öffentlich-rechtliche Vorgaben (§ 9)“: keine | keine Kürzung |
| … „gegen Sanierung oder Heizungstausch (Anteil halbiert)“ | Eine Vorgabe wie Denkmalschutz verhindert eines davon: Anteil halbiert. |
| … „gegen beides (keine Aufteilung)“ | Der Vermieter trägt nichts. |

Auf eine Vorgabe nach § 9 kann sich der Vermieter nur berufen, wenn er sie dir
nachweist (§ 9 Abs. 3).

**4. Ablesen.** Die Karte **„CO₂-Kosten teilen {Jahr}“** auf der Seite
Mietverhältnis zeigt das Vorjahr: den Fall in einem Satz, **CO₂ je m² und
Jahr**, **Stufe** (… / 10), **Anteil Vermieter** und **Erstattung**, darunter
die Kürzungen und „Hilfsrechnung nach dem CO2KostAufG, keine Rechtsberatung.“

```text
kg je m²      = Emissionen des Jahres / Wohnfläche      (eine Nachkommastelle)
Stufe, Anteil = Tabelle in Abschnitt 4
Erstattung    = CO₂-Kosten mit Umsatzsteuer × Anteil × Kürzung
Kürzung       = × 0,95 mit eigenen Geräten; × 0,5 bei einer Vorgabe; 0 bei beiden
```

**Beispiel** (erfundene Zahlen, CO₂-Preis 60 €/t): 12.000 kWh Gas, 80 m².

```text
Emissionen    12.000 kWh × 0,18139 kg/kWh     = 2.176,7 kg
je m²         2.176,7 kg / 80 m²              =    27,2 kg   → Stufe 5, 40 %
CO₂-Kosten    2,1767 t × 60 €/t = 130,60 € netto, × 1,19 = 155,41 €
Erstattung    155,41 € × 40 %                 =    62,16 €
mit Gasherd   62,16 € × 0,95                  =    59,06 €
```

**5. Anschreiben.** **„Anschreiben (PDF)“** öffnet ein Schreiben „Erstattung
des Vermieteranteils an den CO₂-Kosten (CO2KostAufG)“ mit Abrechnungsjahr,
Bezeichnung der Wohnung, Emissionen, Wohnfläche, CO₂ je m², Stufe, Anteil, den
CO₂-Kosten des Jahres mit Umsatzsteuer, den Kürzungen und dem
Erstattungsbetrag, dazu den Satz „Nach § 6 Abs. 2 CO2KostAufG trägt der
Vermieter den genannten Anteil. Bitte den Betrag mit der nächsten
Betriebskostenabrechnung verrechnen oder auszahlen. Die Rechnung des
Lieferanten liegt bei.“ Name, Anschrift, Datum und Unterschrift ergänzt du
selbst, die Rechnung des Lieferanten legst du bei. Das PDF steht in der
Standardsprache der Installation.

**6. Frist beachten.** Der Anspruch muss binnen **zwölf Monaten, nachdem der
Lieferant abgerechnet hat,** in Textform beim Vermieter geltend gemacht werden
(§ 6 Abs. 2 Satz 3) — eine E-Mail genügt der Textform. Die App nimmt das
**Rechnungsdatum** der Gasrechnung als Tag der Abrechnung, ohne Datum den Tag
nach dem Abrechnungszeitraum, und trägt die Frist in Agenda und Kalender ein
([Abschnitt 7](#7-frist-im-kalender)).

## 6. Zentralheizung: die Abrechnung prüfen

Bei einer Zentralheizung hat der Vermieter seinen Anteil schon abgezogen. Die
App prüft, ob er stimmt.

1. **Nebenkostenabrechnung erfassen** ([Als Mieter](mieter.md#7-abrechnung-erfassen-und-preise-übernehmen))
   und in der Feldgruppe **„CO₂-Angaben der Heizkostenabrechnung“** eintragen,
   was dort steht: **Emissionen (kg CO₂)**, **CO₂-Kosten**, **Stufe laut
   Abrechnung**, **Anteil Vermieter laut Abrechnung (%)** und **Betrag
   Vermieter laut Abrechnung**. Die ersten beiden braucht die Rechnung, die
   übrigen dienen dem Vergleich.
2. Die App rechnet aus Emissionen und Wohnfläche die Stufe nach, den Anteil aus
   der Tabelle und den Betrag als CO₂-Kosten × Anteil. Die Karte zeigt das
   Ergebnis und darunter, was abweicht:

| Hinweis | Wann |
|---|---|
| „Die Stufe in der Abrechnung weicht von der nachgerechneten ab.“ | andere Stufe |
| „Der Anteil des Vermieters in der Abrechnung weicht von der Tabelle ab.“ | anderer Prozentsatz |
| „Der Betrag des Vermieters in der Abrechnung weicht vom nachgerechneten ab.“ | mehr als 0,50 € Unterschied |
| „In der Abrechnung fehlen Emissionen oder Fläche – so lässt sich nichts nachrechnen.“ | Emissionen oder Wohnfläche fehlen |

**Beispiel:** 2.000 kg CO₂ bei 70 m² sind 28,6 kg je m² — Stufe 5, 40 %. Bei
300 € CO₂-Kosten trägt der Vermieter 120 €. Nennt die Abrechnung Stufe 4 und
90 €, erscheinen die Hinweise zu Stufe, Anteil und Betrag.

„Anschreiben (PDF)“ liefert hier ein Prüfergebnis: dieselbe Tabelle, der Satz
„Nachrechnung der CO₂-Angaben der Heizkostenabrechnung nach §§ 5 und 7
CO2KostAufG.“ und die Abweichungen. Damit kannst du beim Vermieter nachfragen.

## 7. Frist im Kalender

Mit eigener Gastherme, „zur Miete“ und einem Erstattungsbetrag über 0 € kennt
die Agenda eine weitere Frist:

| Eintrag | Datum | Wann er erscheint |
|---|---|---|
| „CO₂-Kosten {Jahr}: Vermieteranteil von {Betrag} bis heute einfordern“ | Rechnungsdatum der Gasrechnung des Jahres + 12 Monate | im [Kalender-Abo](kalender.md) bis zu 365 Tage vorher, unter „Zu tun“ ab 30 Tagen vorher |

Ohne erfasste Gasrechnung des Jahres gibt es keine Frist — die App kennt dann
das Abrechnungsdatum nicht. Ein Klick führt zur Seite Mietverhältnis.

## 8. Grenzen

- **Der Fall wird geraten.** Kochst du mit Gas, heizt aber über eine
  Zentralheizung, und die Abrechnung trägt keine CO₂-Angaben, hält die App dich
  für eine Etagenheizung und rechnet mit deinem Gasverbrauch. Trag dann die
  CO₂-Angaben der Heizkostenabrechnung ein.
- **Standardfaktor und Kalenderjahr.** Ohne Gasrechnung rechnet die App mit
  dem Standardfaktor und dem Verbrauch des Kalenderjahres. Maßgeblich ist die
  Abrechnung des Lieferanten — erfasse sie, wenn es um Geld geht.
- **Heizöl** fließt in die Rechnung ein, eine Frist entsteht aber nur aus einer
  Gasrechnung.
- **Fernwärme und Heizwärme** rechnet die App im eigenen Fall nicht; dort
  rechnet der Vermieter.
- **Nur Deutschland, nur zur Miete.** Eigentümer und andere Länder sehen die
  Karte nicht.
- **Keine Rechtsberatung.** Ob Kürzungen greifen, ob eine Frist läuft und wie
  der Vermieter verrechnet, beurteilt die App nicht.

Technische Einzelheiten:
[API-Referenz](../referenz/api.md#co₂-preis-und-aufteilung-v310) ·
[Datenmodell](../referenz/datenmodell.md#mietverhältnis-und-nebenkostenabrechnung-v310) ·
[Ansichten](../referenz/ansichten.md#mietverhältnis-v310).

---

[← Kompendium-Index](../README.md) · [Als Mieter](mieter.md)
