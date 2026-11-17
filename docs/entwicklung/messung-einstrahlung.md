# Messung: Welches Normal sagt die Einstrahlung des nächsten Jahres voraus? *(v3.13.0)*

**Frage:** Die PV-Prognose rechnet mit der erwarteten Einstrahlung je Monat. Taugt dafür das flache Mittel der
letzten 30 Jahre, oder liegt das nächste Jahr systematisch daneben?

**Antwort:** **Das flache 30-Jahre-Mittel liegt im Mittel 3,4 % zu niedrig**, weil die Einstrahlung in
Mitteleuropa seit den Achtzigern zunimmt (Aufhellung). **Ein linearer Trend der Jahressummen über dieselben 30 Jahre,
auf das nächste Jahr fortgeschrieben, trifft am besten** (Fehler der Jahressumme 3,2 % statt 4,1 %, kaum noch zu
niedrig). Die App speichert ihn seit v3.13.0 im Klimanormal (`rad_month_trend`, `rad_trend_factor`,
[Datenmodell](../referenz/datenmodell.md)).

## Daten und Verfahren

- **Quelle:** Open-Meteo-Archiv (ERA5), Tagessumme `shortwave_radiation_sum` 1971 bis 2025, Daten unter
  [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/) von [Open-Meteo](https://open-meteo.com/). Rohdaten
  liegen nicht im Repository.
- **Orte:** acht Großstädte, Koordinaten der Stadtmitte: Berlin, Hamburg, München, Köln, Frankfurt am Main, Stuttgart,
  Wien, Zürich.
- **Rollierend:** Für jedes Zieljahr 2001 bis 2025 nur mit den Jahren davor; je Ort und Jahr die zwölf Monatssummen.
  200 Fälle.
- **Fehlermaße:** Jahresfehler (Summe der Schätzung gegen die Jahressumme, Betrag), Bias (mit Vorzeichen), WAPE der
  Monate.

## Ergebnis (Zieljahre 2001 bis 2025)

| Schätzer | Jahresfehler | Bias | WAPE Monate | größter Jahresfehler |
|---|---|---|---|---|
| flaches Mittel der letzten 30 Jahre (bis v3.12) | 4,1 % | −3,4 % | 8,6 % | 14,6 % |
| Mittel der letzten 15 Jahre | 3,3 % | −2,0 % | 8,5 % | 13,0 % |
| Mittel der letzten 10 Jahre | 3,4 % | −1,6 % | 8,6 % | 13,5 % |
| **Trend über 30 Jahre, fortgeschrieben** | **3,2 %** | **−0,5 %** | **8,4 %** | **12,6 %** |
| Trend über 30 Jahre, halb gedämpft | 3,3 % | −1,9 % | 8,4 % | 13,6 % |
| Trend über 15 Jahre | 3,4 % | −0,6 % | 8,6 % | 14,6 % |
| Vorjahr (wie das Saisonprofil nach einem Jahr) | 4,3 % | −0,3 % | 11,7 % | 16,0 % |
| Mittel der zwei Vorjahre | 3,7 % | −0,4 % | 10,1 % | 14,4 % |
| ½ flaches Mittel + ½ zwei Vorjahre | 3,3 % | −1,9 % | 8,9 % | 14,5 % |
| ½ Trend + ½ zwei Vorjahre | 3,3 % | −0,5 % | 8,9 % | 13,5 % |

**In den letzten zehn Zieljahren (2016 bis 2025)** ist der Abstand größer: flach 4,7 % (Bias −4,2 %), Trend 3,5 %
(−1,5 %). Der Trend der Jahressumme 1996 bis 2025 liegt zwischen +0,1 % (Zürich) und +0,5 % (Berlin) je Jahr; die
letzten zehn Jahre lagen 0,8 bis 4,6 % über dem 30-Jahre-Mittel. Der Faktor des Trends lag in allen Fällen zwischen
1,006 und 1,061; die App begrenzt ihn auf ± 10 %.

## Was die App daraus macht

- **Normal mit Trend:** Monatssummen des Normals × Faktor des Trends der Jahressummen, fortgeschrieben auf das Jahr
  nach der Referenzperiode. Unter zehn vollständigen Jahren kein Trend.
- **Keine Mischung mit den gemessenen Jahren, wenn der Trend da ist:** Eine Mischung mit den Vorjahren verbesserte den
  Trend nicht (Jahr 3,3 statt 3,2 %) und verschlechterte die Monate (8,9 statt 8,4 %). Ohne Trend im Normal hilft sie
  dagegen (3,3 statt 4,1 %), darum mischt die PV-Prognose dann die gemessenen Monate zur Hälfte mit.
- **Grenze:** Der Trend ist eine Gerade über 30 Jahre. Kehrt sich die Entwicklung um (wie die „Verdunkelung“ bis in die
  Achtziger), liegt er einige Jahre zu hoch.
