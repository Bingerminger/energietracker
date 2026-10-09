# Einstellungen — alle Schlüssel

**Deutsch** · [English](../en/referenz/einstellungen.md)

[← Kompendium-Index](../README.md)

Jede Einstellung mit Schlüssel, Standardwert, Ort in der Oberfläche und Wirkung.
Über die API liest und schreibt `GET`/`PATCH /api/settings` dieselben
Schlüssel ([API-Referenz](api.md)); ungültige Werte lehnt die App mit 400 ab.
Schlüssel bleiben stabil: Entfernt wird erst mit einer neuen Hauptversion und
nach Ankündigung — die veralteten stehen am Ende.

Seit v3.2.0 kann ein Haushalt mehrere Personen mit Rollen haben
([Benutzer im Haushalt](../anleitungen/benutzer.md)). Drei Schlüssel ändern
dann nur Verwalter, weil die App mit ihnen andere Adressen anspricht oder sich
einbetten lässt: `frame_ancestors`, `ocr_endpoint` und `evcc_endpoint`. Ein
Mitglied, das einen davon mitschickt, bekommt `403` `errors.auth.adminOnly`;
alle anderen Schlüssel dürfen auch Mitglieder ändern. Ohne Anmeldung gilt die
Einschränkung nicht.

Die Standardwerte gelten für eine neue Installation in Deutschland. Ein anderes
Land setzt beim ersten Start sein Profil (Standort, Währung, Zeitzone,
Heizgrenze, CO₂-Faktoren …) — siehe [Länderprofile](../verstehen/14-laenderprofile.md).
Ein Test prüft, dass diese Seite jeden Schlüssel nennt.

## Einstellungen → Allgemein

### Sprache & Land

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `language` | `de` | Standardsprache der Installation | de, en, fr, it, es, pt, nl. Gilt für Geräte ohne eigene Wahl, für Jahresbericht und CSV-Dateien, für Meldungen an Home Assistant und Skripte. Seit v3.1.0 wählt jedes Gerät daneben seine eigene Sprache („Sprache auf diesem Gerät“, im Browser gespeichert); die App schickt sie als `X-ET-Language`, Bezeichnungen und Meldungen der API folgen ihr. |
| `country` | `DE` | Land | Land (ISO-Code). Ein Wechsel bietet an, das Länderprofil zu übernehmen — siehe [Länderprofile](../verstehen/14-laenderprofile.md). |
| `currency` | `EUR` | Währung | Währung (ISO-Code) für die Anzeige; es wird nichts umgerechnet. |
| `timezone` | `Europe/Berlin` | Zeitzone | Zeitzone für „heute“, Stichtage und Erinnerungen. |

### Nutzungsstufe und Einrichtung *(v3.2.0)*

Die Stufe bestimmt, was die Oberfläche zeigt, nicht was die App rechnet:
Berechnungen, API und Export sind in jeder Stufe dieselben. Eine Seite über der
Stufe bleibt erreichbar und zeigt einen Hinweis, mit dem ein Klick die Stufe
hebt. Mehr dazu: [Einrichtung](../einstieg/einrichtung.md).

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `ui_level` | `expert` | Umschalter oben neben Tag/Nacht; Karte „Nutzungsstufe und Einrichtung“ | `beginner` (🌱 Einsteiger), `advanced` (🌿 Erfahren) oder `expert` (🌳 Experte). Einsteiger: Übersicht mit drei Antworten, Zählerstände, einfache Verträge, Jahresbericht. Erfahren: dazu Auswertungen, Prognose, Wechsel, Rechnungsprüfung und Zähler-Aufbau. Experte: alles, mit Schaubildern, Gruppen, Rechenparametern und Zugriff. Der Standard Experte heißt: Eine bestehende Installation sieht nach dem Update alles wie bisher; eine Neuinstallation wählt die Stufe im Einrichtungsassistenten. Mit Anmeldung hat jede Person ihre eigene Stufe (`PATCH /api/session/me`); dieser Schlüssel gilt dann für Personen ohne eigene Wahl. |
| `setup_pending` | `false` | — | `true` heißt: Beim nächsten Öffnen erscheint der Einrichtungsassistent. Gesetzt wird es nur beim allerersten Start einer Neuinstallation, nie nach einem Update; der Assistent setzt es zurück, wenn er fertig ist oder übersprungen wird, ebenso das Laden eines Beispielhaushalts. Erneut starten lässt sich der Assistent jederzeit über „Einrichtungsassistent starten“ auf der Karte „Nutzungsstufe und Einrichtung“ — dafür braucht es diesen Schlüssel nicht. |
| `setup_persona` | leer (`null`) | Einrichtungsassistent, erste Frage | Die zuletzt gewählte Persona: `mieterin`, `etw-fernwaerme`, `eigenheim-klassisch`, `eigenheim-modern` oder `showcase` („Erst einmal alles ansehen“); leer oder `none` = keine. Der Assistent schlägt damit die Antwort vor; das Laden eines Beispielhaushalts setzt die geladene Persona. Rechnet nichts. |

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
| `wohnflaeche_m2` | 100 | Wohnfläche | Beheizte Fläche — Nenner der Effizienzkennzahl. Seit v3.1.0 auch Fläche für die CO₂-Stufe ([CO₂-Preis](../verstehen/16-co2-preis.md)); zur Miete geht die Wohnfläche aus dem Mietverhältnis vor. |
| `gebaeudetyp` | `efh` | Gebäudetyp | Mehrfamilienhaus heißt: ab drei Wohnungen. Werte: `efh` Ein-/Zweifamilienhaus, `rh` Reihenhaus, `mfh` Mehrfamilienhaus, `whg` Wohnung. Bestimmt die Bezugsfläche der energieausweis-nahen Kennzahl. |
| `beheizter_keller` | aus | Beheizter Keller | Wohngebäude mit bis zu zwei Wohnungen (Ein-/Zweifamilienhaus, auch Reihenhaus) mit beheiztem Keller: Gebäudenutzfläche = 1,35 × Wohnfläche (sonst 1,2; § 82 Abs. 2 GModG, bis Juli 2026 GEG) — für die Kennzahl nach Energieausweis. Wirkt nur bei `gebaeudetyp` `efh` oder `rh`. |
| `warmwasser_dezentral` | aus | Warmwasser dezentral | Warmwasser über Durchlauferhitzer oder Boiler, nicht über die Heizung: Die Kennzahl nach Energieausweis erhält 20 kWh/m²·a Zuschlag. |

### Wohnen und Warmwasser *(v3.1.0)*

Wer zur Miete wohnt, bekommt unter Kosten die Seite „Mietverhältnis“; der
Energieträger bestimmt den CO₂-Wert der Heizwärme. Für Mieter Schritt für
Schritt: [Als Mieter](../anleitungen/mieter.md); die Rechnung:
[Heizwärme](../verstehen/15-waerme.md).

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `wohnverhaeltnis` | `eigentum` | Ich wohne | „im Eigentum“ (`eigentum`) oder „zur Miete“ (`miete`). Zur Miete: Unter Kosten & Verträge erscheint die Seite „Mietverhältnis“ (Vorauszahlung, Hilfsrechnung, Nebenkostenabrechnungen), und Agenda und Kalender kennen die beiden Fristen der Nebenkostenabrechnung. In Deutschland teilt die App außerdem die CO₂-Kosten zwischen Mieter und Vermieter auf ([CO₂-Kosten teilen](../anleitungen/co2-aufteilung.md)). Im Eigentum ändert sich nichts. |
| `waerme_energietraeger` | leer | Heizwärme kommt aus | Für den CO₂-Wert der Verbrauchsart Heizwärme – eine Näherung, denn gezählt wird die Wärme, nicht der Brennstoff. Werte: `gas`, `heizoel`, `pellets`, `fernwaerme`, `strom` (Wärmepumpe, Durchlauferhitzer) oder leer („keine Angabe“) — dann ist der CO₂-Wert 0. Gerechnet wird mit dem CO₂-Faktor dieses Energieträgers (Strom je Jahr). |
| `warmwasser_energietraeger` | leer | Warmwasser wird erwärmt mit | Womit das Warmwasser erwärmt wird: dieselben Werte und zusätzlich `waerme` („Heizwärme (zentral)“). Nur zur Information; die Rechnung ändert sich dadurch nicht. |
| `warmwasser_temp_c` | 60 | Warmwassertemperatur | Für die Wärme der Warmwasserzähler nach HeizkostenV § 9 Abs. 2: 2,5 kWh je m³ und Grad über 10 °C (30–90 °C). Unbekannt: 60 °C — dann ist 1 m³ Warmwasser 125 kWh. Wirkt nur auf Wasserzähler mit der Rolle „Warmwasser“. |

### Eigene Vergleichswerte *(v3.1.0)*

Für die Karte „Einordnung {Jahr}“ auf der Übersicht. Die App liefert keine
Tabellen aus Strom- oder Heizspiegel mit — deren Nutzung verlangt eine
Genehmigung —; du trägst die Werte ein, die dir etwa der Stromspiegel für
deinen Haushalt nennt. Leer = keine Einordnung
([API](api.md#einordnung-get-apibenchmarkscomparison-v310)).

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `reference_strom_kwh` | leer (`null`) | Vergleichswert Haushaltsstrom | kWh im Jahr (0–100000), ohne Wärmepumpe und Wallbox. Die App stellt den Haushaltsstrom eines vollen Jahres daneben — alle Stromzähler ohne die Rollen Wärmepumpe und Wallbox — und nennt die Abweichung in %. |
| `reference_heat_kwh_m2` | leer (`null`) | Vergleichswert Heizung | kWh je m² Wohnfläche und Jahr (0–1000). Verglichen wird je Heizart, witterungsbereinigt, wo es das Heizmodell gibt. Der Heizspiegel rechnet mit dem ganzen Gebäude; für eine Wohnung ist das nur ein Anhaltspunkt. |
| `reference_source` | leer | Quelle | Text bis 120 Zeichen, etwa Titel und Jahrgang; steht unter der Einordnung. |
| `warmwasser_elektrisch` | aus | Warmwasser mit Strom | Durchlauferhitzer oder elektrischer Boiler. Der Stromspiegel unterscheidet danach; die App gibt die Angabe mit der Einordnung aus, rechnet aber nicht damit. |

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
| `active_utilities` | `gas, strom, wasser` | Aktive Verbrauchsarten | Welche Verbrauchsarten Menü und Auswertungen zeigen. Abgewählte behalten ihre Daten. Seit v3.1.0 gibt es neun; die neue Heizwärme (`waerme`) ist nicht standardmäßig aktiv — wer sie braucht, wählt sie hier. |

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
| `co2_pv_avoided` | leer (`null`) | CO₂ vermieden durch PV | *(v3.1.0)* Eigener Vermeidungsfaktor in g/kWh (0–2000) für PV-Erzeugung und -Einspeisung. Leer = Strommix wie bei „CO₂ Strom“ (bisheriges Verhalten). Einen Wert schlägt die App nicht vor; einen Anhaltspunkt gibt die Emissionsbilanz erneuerbarer Energieträger des Umweltbundesamts. |

### Photovoltaik *(v3.1.0)*

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `pv_assumed_self_consumption_pct` | leer (`null`) | Eigenverbrauch angenommen | Anteil der Erzeugung in % (0–100), den der Haushalt selbst nutzt — für ein Balkonkraftwerk ohne Einspeisezähler. Wirkt nur, wenn ein Erzeugungszähler als Balkonkraftwerk markiert ist und es keinen Einspeisezähler gibt: Dann ist der Eigenverbrauch Erzeugung × Anteil. Leer = keine Annahme, keine Quote ([PV](../verstehen/12-pv.md#8-balkonkraftwerk-v310)). |

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
| `frame_ancestors` | leer | Erlaubte Einbettungs-Adressen | Adresse mit Schema und Port, z. B. http://homeassistant.local:8123; mehrere durch Leerzeichen trennen. Leer = nur diese Installation selbst. Adressen, die die App einbetten dürfen (Content-Security-Policy), z. B. ein Home-Assistant-Dashboard. Seit v3.2.0 nur Verwalter. |

## Einstellungen → Experte

Oben stehen seit v3.1.0 die Gruppen „Belege“ und „Texterkennung im Heimnetz“,
seit v3.2.0 auch „evcc im Heimnetz“; die Rechenparameter (Regression &
Prognose, Empfehlungen & Verteilung, seit v3.1.0 CO₂-Preis) liegen darunter
eingeklappt hinter „Rechenparameter anzeigen“. Die Seite gehört zur Stufe
Experte.

### Belege *(v3.1.0)*

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `attachments_max_mb` | 500 | Speicher für Belege, höchstens | Obergrenze für alle Belege zusammen (Fotos, PDFs) in MB, 10–100000. Ist sie erreicht, lehnt die App neue Belege ab. Ab 80 % warnt die Backup-Karte unter Einstellungen → Daten. Ein einzelnes Foto darf höchstens 3 MB haben, ein PDF 10 MB. |

### Texterkennung im Heimnetz *(v3.1.0)*

Liest den Zählerstand aus einem Foto über einen eigenen Dienst wie Ollama oder
LM Studio — nur im eigenen Netz. Einrichtung:
[Texterkennung im Heimnetz](../anleitungen/texterkennung.md).

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `ocr_endpoint` | leer | Adresse des Dienstes | Basisadresse mit `http://` oder `https://`, etwa `http://192.168.178.20:11434` (Ollama) oder `http://192.168.178.20:1234/v1` (LM Studio); den Pfad hängt die App an. Leer = aus, die App baut keine Verbindung auf. Jede Adresse, auf die der Name zeigt, muss im eigenen Netz liegen, sonst lehnt die Texterkennung ab. Seit v3.2.0 nur Verwalter. |
| `ocr_api` | `ollama` | Schnittstelle | `ollama` (`/api/chat`) oder `openai` — OpenAI-kompatibel (`/v1/chat/completions`), etwa LM Studio oder LocalAI. |
| `ocr_model` | leer | Modell | Name eines Bildmodells, wie der Dienst es kennt, etwa `qwen2.5vl`, `llama3.2-vision` oder `minicpm-v` (höchstens 200 Zeichen). |
| `ocr_timeout_s` | 30 | Zeitlimit | Sekunden, die der Server auf die Antwort wartet (5–300). Auf einem NAS ohne Grafikkarte brauchen Bildmodelle oft 20 bis 60 Sekunden. Im Docker-Image wartet nginx seit v3.1.0 bis zu 310 Sekunden, das ganze Zeitlimit läuft also durch; hinter einem eigenen Webserver oder Reverse-Proxy gilt dessen Grenze. |

### evcc im Heimnetz *(v3.2.0)*

Holt die Ladevorgänge der Wallbox direkt aus evcc, wenn du in der
Wallbox-Ansicht auf „Von evcc abrufen“ tippst. Die CSV-Datei aus evcc geht
immer, auch ohne diesen Schlüssel. Einrichtung:
[Ladevorgänge aus evcc](../anleitungen/evcc.md).

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `evcc_endpoint` | leer | Adresse von evcc | Basisadresse mit `http://` oder `https://`, unter der du evcc im Browser öffnest, etwa `http://192.168.178.30:7070` oder `http://evcc.local:7070`; die App hängt `/api/sessions` an. Leer = aus, die App baut keine Verbindung auf. Jede Adresse, auf die der Name zeigt, muss im eigenen Netz liegen, sonst lehnt der Abruf ab (wie bei der Texterkennung). Nur Verwalter. |

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

### CO₂-Preis *(v3.1.0)*

Der CO₂-Preis im Brennstoff (BEHG) — Hintergrund und Rechnung unter
[CO₂-Preis im Brennstoff](../verstehen/16-co2-preis.md). Nur in Ländern mit
CO₂-Preis wirksam, heute Deutschland.

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `co2_price_eur_t_years` | leer (`{}`) = Länderprofil | CO₂-Preis je Jahr | Eigene Jahreswerte in € je Tonne als Tabelle Jahr → €/t (Jahre 1990–2100, Werte 0–1000); sie gehen dem Länderprofil vor (Deutschland: 2021 25, 2022 30, 2023 30, 2024 45, 2025 55, 2026 60). Für ein Jahr ohne Wert gilt der letzte bekannte als Annahme — für 2027 also 60, obwohl dafür nach § 4 Abs. 1 Nr. 3 CO2KostAufG der Durchschnitt der Versteigerungen vom 1. Juli bis 30. November 2026 maßgeblich ist; das Umweltbundesamt veröffentlicht ihn spätestens zehn Werktage vor Jahresbeginn, dann hier eintragen. Über die API ein Objekt `{"2027": 65}` oder eine Liste `[{year, eur_t}]`. |
| `co2_price_scenario_eur_t` | leer (`null`) = aus | Szenario: CO₂-Preis | Vorbelegung des Felds „CO₂-Preis ab 2028 (€/t)“ der Prognose (0–1000 €/t): Die Prognose zeigt dann, was dieser Preis mehr kosten würde. Leer = aus. |
| `co2_price_scenario_from` | 2028 | Szenario ab Jahr | Erstes Jahr, für das das Szenario gilt (2021–2100). 2028 soll der europäische Emissionshandel (ETS2) die Festpreise ablösen. |

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
