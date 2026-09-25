# Länderprofile — Sprache, Land, Währung (N1014)

**Deutsch** · [English](../en/verstehen/14-laenderprofile.md)

[← Meter-Topologie](13-meter-topologie.md) · [Kompendium-Index](../README.md)

Bis **v2.6.0** war Energietracker in allem außer der Oberflächensprache
deutsch: Euro, Effizienzklassen nach dem Gebäudeenergiegesetz, der deutsche
Strommix als CO₂-Faktor, eine Heizgrenze von 15 °C, Leipzig als
Wetterstandort und die Zeitzone Europe/Berlin. Seit **v2.7.0** bündelt ein
**Länderprofil** diese Voreinstellungen für neun Länder.

Ein Profil ist ein Vorschlag, keine Bindung: Jeder Wert bleibt einzeln
änderbar. **Bestehende Installationen ändern sich durch das Update nicht** —
die Defaults sind das deutsche Profil, und das deutsche Profil ist genau das
bisherige Verhalten (ein Test hält beides gleich).

---

## 1. Was ein Profil setzt

| Land | Währung | Zeitzone | Heizgrenze | CO₂ Strom | Standort | Effizienzklasse | Brennwert in |
|---|---|---|---|---|---|---|---|
| Deutschland | EUR | Europe/Berlin | 15 °C | je Jahr ¹ | Leipzig | GEG (A+ bis H) | kWh/m³ |
| Österreich | EUR | Europe/Vienna | 15 °C | 103 g/kWh | Wien | – | kWh/m³ |
| Schweiz | CHF | Europe/Zurich | 15 °C | 35 g/kWh | Bern | – | kWh/m³ |
| Frankreich | EUR | Europe/Paris | 18 °C (DJU) | 40 g/kWh | Paris | – | kWh/m³ |
| Italien | EUR | Europe/Rome | 20 °C (gradi giorno) | 281 g/kWh | Roma | – | GJ/Smc |
| Spanien | EUR | Europe/Madrid | 15 °C | 146 g/kWh | Madrid | – | kWh/m³ |
| Portugal | EUR | Europe/Lisbon | 15 °C | 111 g/kWh | Lisboa | – | kWh/m³ |
| Niederlande | EUR | Europe/Amsterdam | 18 °C (graaddagen) | 251 g/kWh | De Bilt | – | MJ/m³ |
| Vereinigtes Königreich | GBP | Europe/London | 15,5 °C | 217 g/kWh | London | – | MJ/m³ |

¹ Deutschland: Strommix des Umweltbundesamts je Jahr 2015–2025
(`co2_strom_years`, seit v2.10.0; 2025: 344 g/kWh), davor 380 g/kWh. Nur das
deutsche Profil hat Jahreswerte — wer das Land wechselt und das Profil
übernimmt, rechnet mit dem einen Wert des Landes. Alle anderen CO₂-Faktoren:
Ember, Stromerzeugungsmix 2024, über *Our World in Data*
(„carbon-intensity-electricity"). Die Heizgrenze folgt der nationalen
Gradtag-Konvention, wo diese eine reine Basistemperatur ist; sonst bleibt es
bei 15 °C.

Die Quelle der Wahrheit ist `src/Config/Countries.php`; die Oberfläche liest
die Profile über `GET /api/countries`.

---

## 2. Wann ein Profil wirkt

**Beim Erststart.** Ist das Datenverzeichnis leer, liest die App die
Sprachwünsche des Browsers (`Accept-Language`): „fr-CH" ergibt Französisch und
die Schweiz, „de-AT" Deutsch und Österreich. Nennt der Browser keine Region,
entscheidet die Sprache (Englisch → Vereinigtes Königreich). Die
Standardzähler heißen dann gleich in der richtigen Sprache („Compteur
principal").

Geschrieben werden **nur die Werte, die vom Default abweichen.** Ein deutscher
Erststart schreibt also nichts fest — korrigiert ein späteres Update einen
Default, greift die Korrektur auch dort. Nach dem Erststart ändert die
Kopfzeile nichts mehr.

**In den Einstellungen.** Die Karte „Sprache & Land" enthält Sprache, Land,
Währung und Zeitzone; alle vier wirken sofort. Beim Wechsel des Landes zeigt
ein Dialog, welche Werte das Profil ändern würde — bisher und neu
nebeneinander, mit der Quelle des CO₂-Faktors:

- **Alle übernehmen** setzt Land und alle abweichenden Profilwerte,
- **Nur Land ändern** setzt nur das Land (Schreibweise, Effizienzskala),
- **Abbrechen** lässt alles, wie es war.

Zähler, Verträge und Ablesungen bleiben in jedem Fall unverändert.

---

## 3. Schreibweise von Zahlen und Daten

Die Sprache bestimmt die Schreibweise; das Land verfeinert sie **nur, wenn
die Sprache dort gesprochen wird**:

| Sprache + Land | Zahl | Betrag | Datum |
|---|---|---|---|
| Deutsch, Deutschland | 1.234,5 | 1.234,56 € | 05.01.2026 |
| Deutsch, Österreich | 1 234,5 | € 1.234,56 | 05.01.2026 |
| Deutsch, Schweiz | 1'234.5 | CHF 1'234.56 | 05.01.2026 |
| Französisch, Schweiz | 1'234,5 | 1'234.56 CHF | 05.01.2026 |
| Englisch, Vereinigtes Königreich | 1,234.5 | £1,234.56 | 05/01/2026 |
| Englisch, Deutschland | 1,234.5 | €1,234.56 | 05/01/2026 |

Die letzte Zeile ist Absicht: Englisch in Deutschland schreibt englisch. Der
Browser (`Intl`) schriebe für „en-DE" deutsche Zahlen — und jede bestehende
englische Installation trägt das Land DE, hätte sie also über Nacht bekommen.

Die Oberfläche formatiert mit `Intl`, der PDF-Jahresbericht und die Texte der
Empfehlungen mit denselben Regeln im Backend (Katalogschlüssel `format.*`,
Länderabweichungen in `Countries::FORMAT_OVERRIDES`).

---

## 4. Währung

Zur Wahl stehen Euro, Schweizer Franken und Pfund Sterling. Die Währung
bestimmt Symbol und Untereinheit in allen Texten — „ct/kWh" wird zu
„Rp./kWh" oder „p/kWh", Spaltenköpfe und Achsen zeigen CHF oder £.

**Beträge werden nicht umgerechnet.** Wer die Währung wechselt, ändert nur
die Beschriftung; die gespeicherten Zahlen bleiben. Die Datenfelder behalten
ihre Namen (`ct_per_kwh`, `eur_per_month`, `amount_eur`) und bedeuten Haupt-
bzw. Untereinheit der gewählten Währung — so bleiben Backup, CSV und die API
unverändert.

---

## 5. Effizienzklasse nur mit Skala

Die Klassen A+ bis H stammen aus dem deutschen Gebäudeenergiegesetz
(Endenergie je m² und Jahr). Andere Länder bewerten anders — Frankreich
(DPE) nach Primärenergie und CO₂, Österreich (HWB) nach Heizwärmebedarf am
Referenzklima. Eine Klasse nach deutschem Recht wäre dort irreführend: Ein
französischer Nutzer liest „E" als DPE-Klasse.

Deshalb gibt es die Klasse nur, wo das Land eine Skala hat — heute nur
Deutschland. Für alle anderen zeigen Dashboard und PDF-Bericht die Kennzahl
kWh/m²·a ohne Klasse und nennen den Grund. Die Empfehlung „schwache
Effizienzklasse" entfällt dort.

---

## 6. Zeitzone

Die Zeitzone bestimmt, welcher Tag „heute" ist (Ablesedatum, Fälligkeiten)
und wo die Tage der Wetterdaten beginnen: Open-Meteo bildet die Tagesmittel
in der übergebenen Zeitzone. Bis v2.6.0 stand dort fest Europe/Berlin — in
Lissabon lag jede Tagesgrenze eine Stunde daneben.

---

## 7. Gas: Brennwert-Einheit und Preis je Kubikmeter

Die Gasrechnung nennt den Brennwert je nach Land in einer anderen Einheit.
Die Einstellungen nehmen ihn in drei Einheiten entgegen; gespeichert wird
immer kWh/m³:

```text
kWh/m³ = MJ/m³ ÷ 3,6
kWh/m³ = GJ/Smc × 1000 ÷ 3,6
```

| Land | Rechnung nennt | Eintrag |
|---|---|---|
| Vereinigtes Königreich | Volume correction 1.02264, Calorific value in MJ/m³ | Zustandszahl 1,02264, Brennwert in MJ/m³ |
| Italien | Coefficiente C, PCS in GJ/Smc | Zustandszahl = Coefficiente C, Brennwert in GJ/Smc |
| Niederlande | Calorische waarde in MJ/m³ | Brennwert in MJ/m³ |

In Italien und den Niederlanden stehen Gaspreise **je m³** auf der Rechnung,
Energietracker rechnet aber in kWh. Unter den Arbeitspreisen eines
Gasvertrags rechnet die Hilfe „Preis je m³ umrechnen" um:

```text
Arbeitspreis [Untereinheit/kWh] = Preis je m³ × 100 ÷ Brennwert [kWh/m³]
```

Maßgeblich ist der Brennwert, der am gewählten Datum gilt. Geteilt wird durch
den **Brennwert**, nicht durch Zustandszahl × Brennwert: Ein Preis je
Normkubikmeter (Smc) bezieht sich auf das bereits korrigierte Volumen. Ist nur
ein direkter Faktor hinterlegt, teilt die Hilfe durch ihn und sagt das dazu.
„Übernehmen" trägt das Ergebnis in die Zeile mit demselben Datum ein, sonst in
die erste leere.

---

## 8. Grenzen

- **Keine Währungsumrechnung** (siehe §4).
- **Gaszähler in Kubikfuß** (ältere britische Zähler, „imperial") werden
  nicht unterstützt; der Zähler muss in m³ zählen.
- **Neun Länder.** Ein weiteres Land ist ein Eintrag in
  `src/Config/Countries.php` plus sein Name in den Sprachkatalogen
  (`countries.XX`); `CountriesTest` prüft die Vollständigkeit.
- **Die Sprache gilt für die ganze Installation**, nicht je Gerät.

→ API: [Länderprofil in der API-Referenz](../referenz/api.md#länderprofil-country-currency-timezone-gas_cv_unit-v270-additiv)
