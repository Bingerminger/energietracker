# Messung: Welches Normal sagt die Heizgradtage des nächsten Jahres voraus? *(v3.13.0)*

**Frage:** Die Prognose der Wärmepumpe nach dem Wetter rechnet mit den erwarteten Heizgradtagen je Monat. Taugt dafür
das flache Mittel der letzten 30 Jahre, oder liegt das nächste Jahr systematisch daneben?

**Antwort:** **Das flache 30-Jahre-Mittel liegt im Mittel 9 bis 12 % zu hoch** (zu kalt), weil es in Deutschland seit
Jahrzehnten wärmer wird. **Ein linearer Trend der Jahresmitteltemperatur über dieselben 30 Jahre, auf das nächste Jahr
fortgeschrieben, trifft am besten** (Bias + 1,6 bis + 2,6 % je nach Heizgrenze): Ein um ΔT wärmeres Jahr hat die Heizgradtage einer um ΔT
niedrigeren Heizgrenze. Die App speichert den Trend seit v3.13.0 im Klimanormal (`temp_trend`, `temp_trend_slope`,
`temp_trend_center`, [Datenmodell](../referenz/datenmodell.md)); **nur die Wärmepumpe rechnet damit**, Gas und alle
anderen Arten bleiben beim flachen Mittel.

## Daten und Verfahren

- **Quelle:** Deutscher Wetterdienst (DWD), Climate Data Center, Tageswerte (KL), Tagesmittel der Lufttemperatur, bis
  Ende 2025. Datenbasis: Deutscher Wetterdienst, Nutzung nach den
  [Nutzungsbedingungen des CDC](https://opendata.dwd.de/climate_environment/CDC/Nutzungsbedingungen_German.pdf)
  ([CC BY 4.0](https://creativecommons.org/licenses/by/4.0/)). Rohdaten
  liegen nicht im Repository.
- **Orte:** zehn Stationen: Berlin-Tempelhof, Dresden-Klotzsche, Frankfurt am Main, Hamburg-Fuhlsbüttel, Hannover,
  Köln/Bonn, Leipzig/Halle, München-Stadt, Nürnberg, Stuttgart-Echterdingen.
- **Rollierend:** Für jedes Zieljahr 2001 bis 2025 nur mit den 30 Jahren davor; je Ort und Jahr die zwölf Monatssummen
  der Heizgradtage (Summe von max(0, Grenze − Tagesmittel)). 249 Fälle (ein Stationsjahr unvollständig).
- **Fehlermaße:** Jahresfehler (Summe der Schätzung gegen die Jahressumme, Betrag), Bias (mit Vorzeichen, positiv = zu
  viele Gradtage), WAPE der Monate.

## Ergebnis (Zieljahre 2001 bis 2025)

| Schätzer | Grenze 15 °C: Jahresfehler | Bias | WAPE Monate | Grenze 12 °C: Jahresfehler | Bias |
|---|---|---|---|---|---|
| flaches Mittel der letzten 30 Jahre (bis v3.12) | 10,8 % | + 9,0 % | 19,3 % | 14,1 % | + 11,6 % |
| Mittel der letzten 15 Jahre | 8,7 % | + 5,4 % | 18,6 % | 11,8 % | + 7,2 % |
| Mittel der letzten 10 Jahre | 7,9 % | + 4,1 % | 18,4 % | 10,8 % | + 5,6 % |
| Versatz = Mittel der letzten 10 Jahre − Mittel der 30 | 7,9 % | + 4,2 % | 18,1 % | 10,8 % | + 5,7 % |
| Trend der Gradtage selbst, als Faktor | 7,5 % | + 1,4 % | 18,1 % | 10,5 % | + 2,4 % |
| **Trend der Temperatur, fortgeschrieben** | **7,4 %** | **+ 1,6 %** | **17,8 %** | **10,2 %** | **+ 2,6 %** |
| Trend der Temperatur, halb gedämpft | 8,3 % | + 5,2 % | 18,3 % | 11,3 % | + 7,0 % |

**In den letzten zehn Zieljahren (2016 bis 2025)** ist der Abstand größer: zur Grenze 15 °C flach 12,1 % (Bias
+ 11,6 %), Trend 7,5 % (+ 4,0 %). Der fortgeschriebene Trend lag in allen Fällen zwischen rund 0,4 und 1,1 K; die App
begrenzt ihn auf ± 2 K. Zur Grenze 13 °C: flach 12,7 % (+ 10,5 %), Trend 9,1 % (+ 2,1 %).

## Was die App daraus macht

- **Normal mit Trend, je Jahr:** Heizgradtage der Heizgrenze − Steigung × (Jahr − Mitte der 30 Jahre), für jeden
  Prognosemonat und jeden geschätzten Tag der Saldo-Karte. Unter zehn vollständigen Jahren kein Trend, ebenso nicht auf
  einem Normal, dessen Periode kürzer als zehn Jahre ist. Gemessen ist der Versatz für das Jahr nach der Periode; für
  das übernächste schreibt die App die Gerade ein Jahr weiter fort.
- **Nicht über das Raster hinaus:** Die Heizgrenze rückt nie unter 6 °C, wo das Normal endet.
- **Mischung mit dem Saisonprofil bleibt** wie beim Gas: nach dem Bestimmtheitsmaß des Heizmodells, höchstens 0,8.
- **Grenze:** Der Trend ist eine Gerade über 30 Jahre. Auch mit ihm lag das Folgejahr zuletzt noch zu kalt
  (2016 bis 2025 Bias + 4 %); das Wetter eines einzelnen Jahres bleibt (Jahresfehler im Median rund 6 %).
