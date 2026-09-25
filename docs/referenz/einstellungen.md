# Einstellungen — alle Schlüssel

**Deutsch** · [English](../en/referenz/einstellungen.md)

[← Kompendium-Index](../README.md)

Jede Einstellung mit Schlüssel, Standardwert, Ort in der Oberfläche und Wirkung.
Über die API liest und schreibt `GET`/`PATCH /api/settings` dieselben
Schlüssel ([API-Referenz](api.md)); ungültige Werte lehnt die App mit 400 ab.
Schlüssel bleiben stabil: Entfernt wird erst mit einer neuen Hauptversion und
nach Ankündigung — die veralteten stehen am Ende.

Die Standardwerte gelten für eine neue Installation in Deutschland. Ein anderes
Land setzt beim ersten Start sein Profil (Standort, Währung, Zeitzone,
Heizgrenze, CO₂-Faktoren …) — siehe [Länderprofile](../verstehen/14-laenderprofile.md).
Ein Test prüft, dass diese Seite jeden Schlüssel nennt.

## Einstellungen → Allgemein

### Sprache & Land

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `language` | `de` | Sprache | Sprache der Oberfläche: de, en, fr, it, es, pt, nl. Karte „Sprache & Land“. |
| `country` | `DE` | Land | Land (ISO-Code). Ein Wechsel bietet an, das Länderprofil zu übernehmen — siehe [Länderprofile](../verstehen/14-laenderprofile.md). |
| `currency` | `EUR` | Währung | Währung (ISO-Code) für die Anzeige; es wird nichts umgerechnet. |
| `timezone` | `Europe/Berlin` | Zeitzone | Zeitzone für „heute“, Stichtage und Erinnerungen. |

### Anzeige

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `dashboard_months` | 12 | Monate auf Dashboard | Wie viele Monate der Verlauf auf der Übersicht zeigt (3–36). |
| `forecast_months` | 12 | Prognose-Horizont | Wie viele Monate die Prognose vorausrechnet (1–24). Vorbelegung der Prognose-Ansicht. |
| `alert_days_since_reading` | 45 | Warnung nach | Liegt die letzte Ablesung länger zurück, steht der Zähler unter „Zu tun“ und die Verbrauchsansicht erinnert daran. |

### Erinnerungen

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `contract_remind_days_1` | 90 | Stufe 1 — Vorwarnung | Tage vor dem Kündigungsstichtag (ohne Frist: vor dem Vertragsende) für die erste Erinnerung. |
| `contract_remind_days_2` | 30 | Stufe 2 — Erinnerung | zweite Stufe |
| `contract_remind_days_3` | 1 | Stufe 3 — dringend | dritte Stufe |
| `reminder_warn_days_before` | 14 | Termin „bald fällig“ ab | Ab so vielen Tagen vor dem Termin gilt er als „bald fällig“. |
| `reminder_overdue_days` | 0 | Kulanz bis „überfällig“ | So viele Tage nach dem Termin gilt er noch als fällig, danach als überfällig. 0 = ab dem Tag danach. |

## Einstellungen → Haushalt & Gebäude

### Gebäude & Effizienz

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `wohnflaeche_m2` | 100 | Wohnfläche | Beheizte Fläche — Nenner der Effizienzkennzahl. |
| `gebaeudetyp` | `efh` | Gebäudetyp | Mehrfamilienhaus heißt: ab drei Wohnungen. Werte: `efh` Ein-/Zweifamilienhaus, `rh` Reihenhaus, `mfh` Mehrfamilienhaus, `whg` Wohnung. Bestimmt die Bezugsfläche der energieausweis-nahen Kennzahl. |
| `beheizter_keller` | aus | Beheizter Keller | Ein-/Zweifamilien- oder Reihenhaus mit beheiztem Keller: Gebäudenutzfläche = 1,35 × Wohnfläche (sonst 1,2) — für die Kennzahl nach Energieausweis. |
| `warmwasser_dezentral` | aus | Warmwasser dezentral | Warmwasser über Durchlauferhitzer oder Boiler, nicht über die Heizung: Die Kennzahl nach Energieausweis erhält 20 kWh/m²·a Zuschlag. |

### Wasser-Referenzwerte

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `wasser_personen_anzahl` | 2 | Personen im Haushalt | Für den Wasser-Spar-Index: Liter je Person und Tag im Vergleich zur Referenz. |
| `wasser_personen_referenz` | 122 | Referenz | BDEW 2024: 122 L je Person und Tag. Bestandsinstallationen behalten den früheren Standard 127, bis sie die neuen Werte übernehmen. |
| `wasser_sparindex_gut` | 100 | Spar-Index — gut bis | Index ≤ diesem Wert gilt als unauffällig. |
| `wasser_sparindex_warnung` | 150 | Spar-Index — Warnung ab | Index ≥ diesem Wert zeigt Sparpotenzial an. |

## Einstellungen → Verbrauchsarten & Abrechnung

### Aktive Verbrauchsarten

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `active_utilities` | `gas, strom, wasser` | Aktive Verbrauchsarten | Welche Verbrauchsarten Menü und Auswertungen zeigen. Abgewählte behalten ihre Daten. |

### Abrechnungszyklus

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `billing_cycle_anchor_gas` | `01-01` | Stichtag Gas | Tag der Jahresabrechnung als `MM-TT` (angezeigt `TT-MM`); bis dorthin rechnet die Saldo-Karte die erwartete Abrechnung. |
| `billing_cycle_anchor_strom` | `01-01` | Stichtag Strom | wie Gas |
| `billing_cycle_anchor_wasser` | `01-01` | Stichtag Wasser | wie Gas |
| `billing_cycle_anchor_fernwaerme` | `01-01` | Stichtag Fernwärme | wie Gas |
| `billing_cycle_anchor_pv_einspeisung` | `01-01` | Stichtag PV-Einspeisung | Abrechnungstag der Einspeisevergütung (seit v2.13.0; vorher galt fest der 1. Januar). |

### Physikalische Konstanten

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `gas_conversion_factors` | 11,5 kWh/m³ (undatiert) | Gas-Umrechnungsfaktoren | Ein Eintrag je Brennwertperiode, so wie die Gasrechnung sie ausweist: Gültig ab, Zustandszahl und Brennwert — der Faktor kWh/m³ wird daraus berechnet. Wer keine Aufschlüsselung hat, trägt den Faktor direkt ein. Der Eintrag ohne Datum gilt für alles vor dem ersten Stichtag. Die Verbrauchsrechnung teilt jedes Ableseintervall tagesgenau an den Stichtagen. Liste mit „Gültig ab“, Zustandszahl und Brennwert je Periode; ein Eintrag darf undatiert sein. Standard: undatiert 11,5 kWh/m³. |
| `gas_cv_unit` | `kwh` | Brennwert angeben in | Einheit, in der der Brennwert eingegeben wird: `kwh` (kWh/m³), `mj` (MJ/m³) oder `gj` (GJ/Smc). Gespeichert wird immer kWh/m³. |
| `hdd_base_temp` | 15 | HGT-Basistemperatur | Heizgrenze — Tage darunter zählen als Heiztage. |

### Energieträger (Lieferung)

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `heizoel_kwh_per_l` | 10 | Heizöl Energiegehalt | Energie je Liter Heizöl (Heizwert, üblich 10 kWh/L). Rechnet Liter in kWh um. |
| `pellets_kwh_per_kg` | 4,8 | Pellets Energiegehalt | Energie je kg Pellets (üblich 4,8 kWh/kg). Rechnet kg in kWh um. |
| `tank_warn_pct` | 15 | Tank-Warnung ab | Unter diesem Füllstand wird der Tank gelb, unter der Hälfte davon rot, und „Zu tun“ schlägt eine Lieferung vor. |

### CO₂-Emissionsfaktoren

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `co2_gas` | 182 | CO₂ Gas | BAFA: 201 g/kWh, bezogen auf den Heizwert. Die App zählt Gas nach Brennwert (Zustandszahl × Brennwert), daher × 0,906 = 182. Bestandsinstallationen behalten den früheren Standard 201 (auf den Heizwert bezogen), bis sie die neuen Werte übernehmen. |
| `co2_strom` | 380 | CO₂ Strom | Gilt für Jahre vor dem ersten Jahreswert — ohne Jahreswerte für alle Jahre. |
| `co2_strom_years` | 2015–2025 (344–530) | CO₂ Strom je Jahr | Strommix je Jahr (Umweltbundesamt). Nach dem letzten Jahr gilt dessen Wert, davor „CO₂ Strom“. Strommix je Jahr, 2015–2025 nach Umweltbundesamt (g/kWh). |
| `co2_wasser` | 350 | CO₂ Wasser | Für Aufbereitung und Pumpen je m³ Wasser — ein Schätzwert ohne amtliche Quelle. |
| `co2_fernwaerme` | 280 | CO₂ Fernwärme | BAFA-Pauschale. Genauer ist der Wert Ihres Wärmenetzes — er steht beim Versorger. Früherer Standard 180. |
| `co2_heizoel` | 266 | CO₂ Heizöl | BAFA, bezogen auf den Heizwert — so, wie die App Heizöl rechnet. |
| `co2_pellets` | 36 | CO₂ Pellets | BAFA, CO₂-Äquivalente einschließlich Vorkette. Früherer Standard 26. |

## Einstellungen → Wetterdaten

### Standort und Abgleich

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `location_name` | `Leipzig Zentrum` | Ortsname | Name des Standorts, für die Anzeige. Unter Einstellungen → Wetterdaten per Ortssuche gesetzt. |
| `latitude` | 51,3397 | Breitengrad | Breitengrad für Temperaturen und Klimanormal (an Open-Meteo gerundet auf zwei Nachkommastellen, rund 1 km). |
| `longitude` | 12,3731 | Längengrad | Längengrad, wie Breitengrad. |
| `weather_auto_fill` | an | Wetter automatisch füllen | Holt beim Öffnen der App höchstens einmal täglich Temperaturen von Open-Meteo (Standort auf rund 1 km gerundet). |

## Einstellungen → Zugriff

### Einbetten

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `frame_ancestors` | leer | Erlaubte Einbettungs-Adressen | Adresse mit Schema und Port, z. B. http://homeassistant.local:8123; mehrere durch Leerzeichen trennen. Leer = nur diese Installation selbst. Adressen, die die App einbetten dürfen (Content-Security-Policy), z. B. ein Home-Assistant-Dashboard. |

## Einstellungen → Experte

### Regression & Prognose

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `min_days_period` | 20 | Min. Tage pro Periode | Monate mit weniger erfassten Tagen (Teilmonate) bleiben aus Heizsignatur und Anomalien heraus. |
| `min_hdd_regression` | 5 | Min. HGT für Regression | Monate mit weniger Heizgradtagen bleiben aus der Heizsignatur heraus — im Sommer bestimmt die Grundlast den Verbrauch, nicht das Wetter. |
| `blend_max` | 0,8 | Max. Blend-Gewicht | Höchstes Gewicht des Wettermodells in der Prognose; der Rest kommt aus dem Saisonprofil. 0,8 heißt: mindestens 20 % Saisonprofil. |
| `forecast_model` | `linear` | Standardmodell | Modell für Prognose und Tarifvergleich. Linear passt für die meisten Häuser; die Analyse zeigt, welches deine Daten am besten erklärt. Werte: `linear`, `polynomial`, `robust`, `segmented`, `sigmoid`. |
| `segmented_split_mode` | `auto` | Segment-Knickpunkt | auto = datenbasiert gefittet, fixed = fester HGT-Wert unten. Werte: `auto`, `fixed`. |
| `segmented_fixed_split` | 50 | Fester Knickpunkt | Nur wirksam bei Modus „fixed“. |
| `confidence_band_sigma` | 1,28 | Breite des Prognosebands | In Streuungseinheiten σ: 1,28 heißt, der Verbrauch liegt in 80 % der Jahre im Band; 1,64 wären 90 %. |
| `anomaly_threshold` | 2 | Anomalie-Schwelle | Ab welcher Abweichung (in Streuungseinheiten σ) ein Monat in der Analyse als auffällig gilt. Kleiner heißt empfindlicher. |

### Empfehlungen & Verteilung

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `recommendation_anomaly_sigma` | 2 | Empfehlung Anomalie-Schwelle | Dieselbe Art Schwelle für die Empfehlungen: erst ab dieser Abweichung entsteht ein Hinweis. |
| `recommendation_trend_pct_year` | 3 | Empfehlung Trend-Schwelle | Ab welchem Anstieg je Jahr die Empfehlungen einen steigenden Verbrauch melden. |
| `delivery_baseload_share` | 0,15 | Sockel-Anteil Verteilung | Anteil des Verbrauchs als wetterunabhängige Grundlast (Rest HGT-gewichtet). |

## Nur über die API

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `efficiency_class_thresholds` | A+ … G | — | Obergrenzen der Effizienzklassen in kWh/m²·a (je einschließlich): A+ 30, A 50, B 75, C 100, D 130, E 160, F 200, G 250, darüber H. Nur über die API änderbar. |

## Veraltet — entfallen mit v3.0.0

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `min_temp_days_forecast` | 20 | — | Veraltet (v2.9.0), ohne Wirkung — ersetzt durch die Regel, dass für 90 % der Verbrauchstage eines Monats Temperaturen vorliegen müssen. |
| `baujahr` | leer | — | Veraltet (v2.9.0), ohne Wirkung. |
| `billing_cycle_anchor_heizoel` | `01-01` | — | Veraltet (v2.13.0), ohne Wirkung: Heizöl hat keine Abschläge und damit keinen Saldo bis zum Stichtag. |
| `billing_cycle_anchor_pellets` | `01-01` | — | Veraltet (v2.13.0), ohne Wirkung, wie Heizöl. |

---

[← Kompendium-Index](../README.md)
