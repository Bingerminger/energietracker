# Einstellungen — alle Schlüssel

**Deutsch** · [English](../en/referenz/einstellungen.md)

[← Kompendium-Index](../README.md)

Jede Einstellung mit Schlüssel, Standardwert, Ort in der Oberfläche und Wirkung.
Über die API liest und schreibt `GET`/`PATCH /api/settings` dieselben
Schlüssel ([API-Referenz](api.md)); ungültige Werte lehnt die App mit 400 ab.
Schlüssel bleiben stabil: Entfernt wird erst mit einer neuen Hauptversion und
nach Ankündigung — die veralteten stehen am Ende.

Seit v3.2.0 kann ein Haushalt mehrere Personen mit Rollen haben
([Benutzer im Haushalt](../anleitungen/benutzer.md)). Zehn Schlüssel ändern
dann nur Verwalter, weil die App mit ihnen andere Adressen anspricht, sich
einbetten lässt, Namen sperrt, den Verlauf kürzt oder Dokumente in die Cloud
schickt: `frame_ancestors`, `ocr_endpoint`, `evcc_endpoint`, (seit v3.4.0)
`allowed_hosts` und `activity_keep_months` sowie (seit v3.7.0) `inbox_provider`,
wohin Versorgerpost zum Auslesen geht, und `inbox_cloud_enabled`,
`inbox_cloud_provider`, `inbox_cloud_endpoint` und `inbox_cloud_model`. Ein
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
| `ui_level` | `expert` | Umschalter oben neben Tag/Nacht; Karte „Nutzungsstufe und Einrichtung“ | `beginner` (🌱 Einsteiger), `advanced` (🌿 Erfahren) oder `expert` (🌳 Experte). Einsteiger: sehen, was man verbraucht, was es kostet und ob der Abschlag reicht; Stände erfassen, Versorgerpost auslesen lassen, Termine erledigen. Erfahren: dazu vergleichen und prüfen (Tarife, Rechnungen, Analysen, Zähler-Aufbau, Mietverhältnis, Home Assistant). Experte: alles sehen und anbinden (Viertelstunden, Rechenparameter, Zugriff, System). Jede Funktion: [Was kann ich auf welcher Stufe?](stufen.md). Der Standard Experte heißt: Eine bestehende Installation sieht nach dem Update alles wie bisher; eine Neuinstallation wählt die Stufe im Einrichtungsassistenten. Mit Anmeldung hat jede Person ihre eigene Stufe (`PATCH /api/session/me`); dieser Schlüssel gilt dann für Personen ohne eigene Wahl. |
| `setup_pending` | `false` | — | `true` heißt: Beim nächsten Öffnen erscheint der Einrichtungsassistent. Gesetzt wird es nur beim allerersten Start einer Neuinstallation, nie nach einem Update; der Assistent setzt es zurück, wenn er fertig ist oder übersprungen wird, ebenso das Laden eines Beispielhaushalts. Erneut starten lässt sich der Assistent jederzeit über „Einrichtungsassistent starten“ auf der Karte „Nutzungsstufe und Einrichtung“ — dafür braucht es diesen Schlüssel nicht. |
| `setup_persona` | leer (`null`) | Einrichtungsassistent, erste Frage | Die zuletzt gewählte Persona: `mieterin`, `etw-fernwaerme`, `eigenheim-klassisch`, `eigenheim-modern` oder `showcase` („Erst einmal alles ansehen“); leer oder `none` = keine. Der Assistent schlägt damit die Antwort vor; das Laden eines Beispielhaushalts setzt die geladene Persona. Rechnet nichts. |

### Anzeige

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `dashboard_months` | 12 | Monate auf Dashboard | Wie viele Monate der Verlauf auf der Übersicht zeigt (3–36). |
| `forecast_months` | 12 | Prognose-Horizont | Wie viele Monate die Prognose vorausrechnet (1–24). Vorbelegung der Prognose-Ansicht. |
| `alert_days_since_reading` | 45 | Warnung nach | Liegt die letzte Ablesung länger zurück, steht der Zähler unter „Zu tun“ und die Verbrauchsansicht erinnert daran. |

### Neue Versionen *(v3.6.0)*

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `update_check` | aus | Auf neue Versionen hinweisen | Einmal am Tag fragt der Server bei GitHub die neueste veröffentlichte Version ab (`GET /api/update-check`); ist sie neuer, erscheint ein Hinweis mit Link zu den Release-Notes, bis du ihn für diese Version schließt. GitHub sieht nur die Adresse des Servers. Ausgeschaltet geht keine Anfrage hinaus. Die Einrichtung schlägt den Hinweis vor; bestehende Installationen bleiben aus, bis jemand ihn einschaltet. |

Unabhängig davon zeigt jedes Gerät nach einem Update einmal „Aktualisiert auf …“ mit Link zu den Release-Notes — ohne Anfrage nach außen.

### Umgebung *(v3.7.1)*

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `env_name` | leer | Umgebungsname | Text bis 12 Zeichen; der Server entfernt Tags und Steuerzeichen, fasst Leerraum zusammen und kürzt. Erscheint oben neben dem Logo als Abzeichen im Design von „DEMO“ — etwa um eine Test-Installation von der eigentlichen zu unterscheiden. Leer: kein Abzeichen. In der öffentlichen Demo bleibt „DEMO“. |

### Erinnerungen

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `contract_remind_days_1` | 90 | Stufe 1 — Vorwarnung | Tage vor dem Kündigungsstichtag (ohne Frist: vor dem Vertragsende) für die erste Erinnerung. |
| `contract_remind_days_2` | 30 | Stufe 2 — Erinnerung | zweite Stufe |
| `contract_remind_days_3` | 1 | Stufe 3 — dringend | dritte Stufe |
| `reminder_warn_days_before` | 14 | Termin „bald fällig“ ab | Ab so vielen Tagen vor dem Termin gilt er als „bald fällig“. |
| `reminder_overdue_days` | 0 | Kulanz bis „überfällig“ | So viele Tage nach dem Termin gilt er noch als fällig, danach als überfällig. 0 = ab dem Tag danach. |

### Verlauf erledigter Hinweise *(v3.4.0)*

Nicht unter Einstellungen, sondern unter **Hinweise → Erledigt → „Verlauf
verwalten“** — dort, wo der Verlauf steht
([Hinweise erledigen](../anleitungen/hinweise-erledigen.md)).

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `activity_keep_months` | 60 | Aufbewahren (Monate, 0 = unbegrenzt) | Einträge im Verlauf, die länger her sind (Tag des Erledigens), fallen beim nächsten Eintrag heraus; ihre Belege werden frei. 0–1200, `0` = unbegrenzt. Nur Verwalter. |

## Einstellungen → Haushalt & Gebäude

### Gebäude & Effizienz

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `wohnflaeche_m2` | 100 | Wohnfläche | Beheizte Fläche — Nenner der Effizienzkennzahl. Seit v3.1.0 auch Fläche für die CO₂-Stufe ([CO₂-Preis](../verstehen/16-co2-preis.md)); zur Miete geht die Wohnfläche aus dem Mietverhältnis vor. Seit v3.6.0 ordnet die Karte „Einordnung“ die Heizung erst ein, wenn die Fläche eingetragen ist (s. u.). |
| `gebaeudetyp` | `efh` | Gebäudetyp | Mehrfamilienhaus heißt: ab drei Wohnungen. Werte: `efh` Ein-/Zweifamilienhaus, `rh` Reihenhaus, `mfh` Mehrfamilienhaus, `whg` Wohnung. Bestimmt die Bezugsfläche der energieausweis-nahen Kennzahl. |
| `beheizter_keller` | aus | Beheizter Keller | Wohngebäude mit bis zu zwei Wohnungen (Ein-/Zweifamilienhaus, auch Reihenhaus) mit beheiztem Keller: Gebäudenutzfläche = 1,35 × Wohnfläche (sonst 1,2; § 82 Abs. 2 GModG, bis Juli 2026 GEG) — für die Kennzahl nach Energieausweis. Wirkt nur bei `gebaeudetyp` `efh` oder `rh`. |
| `warmwasser_dezentral` | aus | Warmwasser dezentral | Warmwasser über Durchlauferhitzer oder Boiler, nicht über die Heizung: Die Kennzahl nach Energieausweis erhält einen Zuschlag von 20 kWh/m²·a, ab 2027 von 16 kWh/m²·a (§ 82 GModG). |

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
([API](api.md#einordnung-get-apibenchmarkscomparison-v310)). Seit v3.6.0
führt die Einladung auf der Karte („Vergleichswerte eintragen“) direkt hierher,
mit dem Feld im Fokus.

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `reference_strom_kwh` | leer (`null`) | Vergleichswert Haushaltsstrom | kWh im Jahr (0–100000), ohne Wärmepumpe und Wallbox. Die App stellt den Haushaltsstrom eines vollen Jahres daneben — alle Stromzähler ohne die Rollen Wärmepumpe und Wallbox — und nennt die Abweichung in %. |
| `reference_heat_kwh_m2` | leer (`null`) | Vergleichswert Heizung | kWh je m² Wohnfläche und Jahr (0–1000). Verglichen wird je Heizart, witterungsbereinigt, wo es das Heizmodell gibt. Der Heizspiegel rechnet mit dem ganzen Gebäude; für eine Wohnung ist das nur ein Anhaltspunkt. |
| `reference_source` | leer | Quelle | Text bis 120 Zeichen, etwa Titel und Jahrgang; steht unter der Einordnung. |
| `warmwasser_elektrisch` | aus | Warmwasser mit Strom | Durchlauferhitzer oder elektrischer Boiler. Der Stromspiegel unterscheidet danach; die App gibt die Angabe mit der Einordnung aus, rechnet aber nicht damit. |

### Wasser-Referenzwerte

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `wasser_personen_anzahl` | 2 | Personen im Haushalt | Für den Wasser-Spar-Index: Liter je Person und Tag im Vergleich zur Referenz. Seit v3.6.0 ordnet die Karte „Einordnung“ das Wasser erst ein, wenn die Zahl eingetragen ist (s. u.). |
| `wasser_personen_referenz` | 122 | Referenz | BDEW 2024: 122 L je Person und Tag. Bestandsinstallationen behalten den früheren Standard 127, bis sie die neuen Werte übernehmen. |
| `wasser_sparindex_gut` | 100 | Spar-Index — gut bis | Index ≤ diesem Wert gilt als unauffällig. |
| `wasser_sparindex_warnung` | 150 | Spar-Index — Warnung ab | Index ≥ diesem Wert zeigt Sparpotenzial an. |

### Wohnfläche und Personen: eingetragen oder angenommen *(v3.6.0)*

Der Standard (100 m², 2 Personen) ist nur eine Annahme. Solange niemand die
Angabe eingetragen hat, steht das Feld **leer**, mit dem Standard als
Platzhalter und dem Satz „Noch nicht eingetragen …“; die Karte „Einordnung“
lädt dann zum Feld ein, statt mit dem Standard einzuordnen. Eingetragen ist
die Angabe, sobald sie gespeichert ist — auch wenn sie dem Standard gleicht
(trag 100 ein, wenn deine Wohnung 100 m² hat). Ein leer gespeichertes Feld
nimmt die Angabe zurück. Werte aus einem Backup oder einem Beispielhaushalt
gelten als eingetragen; wer die Seite schon einmal mit dem Wert gespeichert
hat, behält ihn. Effizienzklasse, CO₂-Stufe, Wasser-Spar-Index und
Jahresbericht rechnen bis zum Eintrag weiter mit dem Standard. Wo eine
angenommene Fläche einfließt — Effizienzklasse, CO₂-Kostenaufteilung für Mieter
(dort zählt zuerst die Fläche im Mietverhältnis) —, steht **„Angenommene
Wohnfläche 100 m² – bitte eintragen“** mit einem Link direkt zum Feld, im
Jahresbericht ein Satz dazu. Die API meldet
die offenen Angaben unter `assumed_keys` ([API](api.md#angenommene-angaben-assumed_keys-v360)).

## Einstellungen → Verbrauchsarten & Abrechnung

### Aktive Verbrauchsarten

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `active_utilities` | `gas, strom, wasser` | Aktive Verbrauchsarten | Welche Verbrauchsarten Menü und Auswertungen zeigen. Abgewählte behalten ihre Daten. Seit v3.1.0 gibt es neun; die neue Heizwärme (`waerme`) ist nicht standardmäßig aktiv — wer sie braucht, wählt sie hier. |

### Abrechnungszyklus

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `billing_cycle_anchor_gas` | `01-01` | Stichtag Gas: erster Tag der Abrechnungsperiode | Der **erste Tag** der Abrechnungsperiode als `MM-TT` (angezeigt `TT-MM`): `09-01` heißt 1.9. bis 31.8., `01-01` das Kalenderjahr. Die Saldo-Karte rechnet die erwartete Abrechnung bis zum Tag davor, dem letzten Tag der Periode. Seit v3.6.0 schneidet er auch die **Abrechnungsjahre** (Umschalter „Kalenderjahr · Abrechnungsjahr“ in Verbrauchsart, Übersicht und Analyse, Anhang im Jahresbericht, CSV je Periode). Erfasste Versorgerrechnungen gehen vor: Ihr Zeitraum gilt genau wie auf der Rechnung, der Stichtag füllt nur die Lücken davor, dazwischen und danach. Endet die letzte Rechnung nicht am Tag vor dem Stichtag, schlägt die Verbrauchsart den passenden Tag vor; ohne Rechnung *(v3.7.0)* den Beginn des Vertrags nach dem jüngsten Lieferantenwechsel, sonst den Tag, an dem mehrere Verträge beginnen. Schneidet ein Lieferantenwechsel kurz vor oder nach dem Stichtag weniger als 14 Tage ab, gehen sie in die Nachbarperiode auf *(v3.7.0)*. Ein 29.02. wirkt als 28.02. |
| `billing_cycle_anchor_strom` | `01-01` | Stichtag Strom: erster Tag der Abrechnungsperiode | wie Gas |
| `billing_cycle_anchor_wasser` | `01-01` | Stichtag Wasser: erster Tag der Abrechnungsperiode | wie Gas |
| `billing_cycle_anchor_fernwaerme` | `01-01` | Stichtag Fernwärme: erster Tag der Abrechnungsperiode | wie Gas |
| `billing_cycle_anchor_pv_einspeisung` | `01-01` | Stichtag PV-Einspeisung: erster Tag der Abrechnungsperiode | Erster Tag der Abrechnungsperiode der Einspeisevergütung (seit v2.13.0; vorher galt fest der 1. Januar). |

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

Seit v3.6.0 steht die lange Liste in der Kachel zugeklappt („Faktoren je
Verbrauchsart und Strom je Jahr“ — derselbe Aufklappbereich wie „Prognose im
Detail“; ob offen, merkt sich jedes Gerät). Bei einem Fehler in einem der
Felder und nach dem Speichern einer Änderung darin klappt sie auf.

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

### Flexibilität *(v3.7.0)*

Womit „Dein Hebel“ auf der Seite Flexibilität rechnet, wo nichts gemessen ist.
Ein leeres Feld heißt **Beispielwert** (der Standard); was du einträgst — auch
derselbe Wert —, nennen Seite und Jahresbericht **eigener Wert**. Leeren nimmt
die Angabe zurück. Die Felder bleiben leer mit dem Beispielwert als Platzhalter,
solange nichts eingetragen ist (`example_keys` in `GET /api/settings`).

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `flex_heat_pump_share_pct` | 30 | Wärmepumpe: verschiebbarer Anteil | Anteil des Tagesverbrauchs der Wärmepumpe in % (0–100), den Gebäude und Pufferspeicher in andere Stunden verschieben lassen. |
| `flex_washer_cycles_per_week` | 4 | Waschmaschine: Gänge je Woche | 0–21; 0 = kein solches Gerät, der Hebel entfällt. |
| `flex_washer_kwh_per_cycle` | 0,9 | Waschmaschine: kWh je Gang | 0–5 kWh. |
| `flex_dishwasher_cycles_per_week` | 5 | Spülmaschine: Gänge je Woche | 0–21; 0 = kein solches Gerät. |
| `flex_dishwasher_kwh_per_cycle` | 1,0 | Spülmaschine: kWh je Gang | 0–5 kWh. |
| `flex_ev_plugged_from` | leer (`null`) | Auto ohne evcc: angesteckt ab | Volle Stunde 0–23. Zusammen mit `flex_ev_plugged_until` die Steckzeit des Autos, wenn es keine Ladevorgänge aus evcc gibt: Verschoben wird nur in dieser Zeit. Leer = am Ladetag den ganzen Tag bereit (Beispielwert). Mit Ladevorgängen aus evcc zählen deren Steckzeiten. |
| `flex_ev_plugged_until` | leer (`null`) | Auto ohne evcc: angesteckt bis | Volle Stunde 0–23; liegt sie vor „ab“, geht die Zeit über Mitternacht (18 bis 7 = abends bis morgens). Gleich „ab“ = kein Fenster. |

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

### Hostnamen *(v3.4.0)*

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `allowed_hosts` | leer | Erlaubte Hostnamen | Kommaliste ohne Schema und Port, `*.domain` als Platzhalter, höchstens 20 Einträge; gespeichert als `a.example, *.b.example`. Leer = alle Namen. Sonst beantwortet die App andere Namen mit `421` (gegen DNS-Rebinding); IP-Adressen und `localhost` gelten immer. Gilt zusammen mit `ET_ALLOWED_HOSTS`. Beim Speichern muss der Name dabei sein, unter dem die Anfrage kommt, sonst `400` `errors.settings.hostNotListed`. Nur Verwalter — [Sicherheit § 8](../betrieb/sicherheit.md#8-hostnamen-und-einbetten). |

## Einstellungen → Experte

Oben stehen seit v3.7.0 die Karte „Auslesen“ (wer die Post im Posteingang
ausliest, Lokal | Cloud), seit v3.1.0 die Gruppen „Belege“ und „Texterkennung im
Heimnetz“, seit v3.2.0 auch „evcc im Heimnetz“; die Rechenparameter (Regression &
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
[Texterkennung im Heimnetz](../anleitungen/texterkennung.md). Adresse und
Schnittstelle teilt die Texterkennung mit dem Posteingang; seit v3.7.0 stehen
sie deshalb nur einmal, in der Karte „Auslesen“ unter „Lokal“. Die Karte
„Texterkennung im Heimnetz“ zeigt Bildmodell und Zeitlimit.

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `ocr_endpoint` | leer | Auslesen → Lokal: Adresse des Dienstes | Basisadresse mit `http://` oder `https://`, etwa `http://192.168.178.20:11434` (Ollama) oder `http://192.168.178.20:1234/v1` (LM Studio); den Pfad hängt die App an. Leer = aus, die App baut keine Verbindung auf. Jede Adresse, auf die der Name zeigt, muss im eigenen Netz liegen, sonst lehnt die Texterkennung ab. Seit v3.2.0 nur Verwalter. |
| `ocr_api` | `ollama` | Auslesen → Lokal: Schnittstelle | `ollama` (`/api/chat`) oder `openai` — OpenAI-kompatibel (`/v1/chat/completions`), etwa LM Studio oder LocalAI. |
| `ocr_model` | leer | Modell | Name eines Bildmodells, wie der Dienst es kennt, etwa `qwen2.5vl`, `llama3.2-vision` oder `minicpm-v` (höchstens 200 Zeichen). |
| `ocr_timeout_s` | 30 | Zeitlimit | Sekunden, die der Server auf die Antwort wartet (5–300). Auf einem NAS ohne Grafikkarte brauchen Bildmodelle oft 20 bis 60 Sekunden. Im Docker-Image wartet nginx seit v3.1.0 bis zu 310 Sekunden, das ganze Zeitlimit läuft also durch; hinter einem eigenen Webserver oder Reverse-Proxy gilt dessen Grenze. |

### Posteingang *(v3.7.0)*

Liest Versorgerpost im Posteingang aus — lokal über den Dienst der
Texterkennung (Adresse und Schnittstelle wie oben), mit einem Textmodell. Alles
dazu steht in **einer** Karte, „Auslesen“ (Einstellungen → Experte), mit dem
Umschalter **Lokal | Cloud** und einer eigenen Taste „Speichern“; „Lokal“ zeigt
Adresse, Schnittstelle, Textmodell, Zeitlimit und „Verbindung prüfen“
(`GET /api/inbox/local/check`, nur Verwalter). Einrichtung:
[Posteingang → Auslesen einrichten](../anleitungen/posteingang.md#auslesen-einrichten).

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `inbox_provider` | `local` | Auslesen → Lokal \| Cloud | Wer ausliest — die eine Stelle dafür ist der Umschalter der Karte „Auslesen“: `local` = der Dienst der Texterkennung im Heimnetz, `anthropic` oder `openai` = ein Cloud-Dienst ([Auslesen in der Cloud](#posteingang-auslesen-in-der-cloud-v370)). Einen Cloud-Dienst nimmt der Server nur an, solange das Auslesen in der Cloud für genau ihn eingeschaltet ist und ein Schlüssel da ist. Speichern auf „Cloud“ setzt den Wert auf den Dienst, sobald er bereit ist, Speichern auf „Lokal“ auf `local`; Ausschalten stellt zurück auf `local`. Vor jedem Auslesen in der Cloud fragt die App, ob das Dokument dorthin darf. Nur Verwalter. |
| `inbox_model` | `qwen3.8` | Auslesen → Lokal: Textmodell | Name eines Textmodells, wie der Dienst es kennt (höchstens 200 Zeichen; leer = `qwen3.8`). Empfohlen: `qwen3.8`, braucht rund 18 GB Arbeitsspeicher. |
| `inbox_timeout_s` | 300 | Auslesen → Lokal: Zeitlimit je Schritt | Sekunden je Schritt (Art erkennen, Felder lesen), 30–300. Jeder Schritt ist eine eigene Anfrage und bleibt so unter den 310 Sekunden, die nginx im Docker-Image wartet. |

### evcc im Heimnetz *(v3.2.0)*

Holt die Ladevorgänge der Wallbox direkt aus evcc, wenn du in der
Wallbox-Ansicht auf „Von evcc abrufen“ tippst. Die CSV-Datei aus evcc geht
immer, auch ohne diesen Schlüssel. Einrichtung:
[Ladevorgänge aus evcc](../anleitungen/evcc.md).

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `evcc_endpoint` | leer | Adresse von evcc | Basisadresse mit `http://` oder `https://`, unter der du evcc im Browser öffnest, etwa `http://192.168.178.30:7070` oder `http://evcc.local:7070`; die App hängt `/api/sessions` an. Leer = aus, die App baut keine Verbindung auf. Jede Adresse, auf die der Name zeigt, muss im eigenen Netz liegen, sonst lehnt der Abruf ab (wie bei der Texterkennung). Nur Verwalter. |

### Posteingang: Auslesen in der Cloud *(v3.7.0)*

Ein Opt-in für den Posteingang: Ein Cloud-Dienst liest die Post vom Versorger
aus, wenn im Haus kein starker Rechner steht. Aus, bis ein Verwalter es
einschaltet; vor jedem Auslesen nennt die App den Dienst. **Experimentell:**
Alle Cloud-Wege, auch Claude, sind noch nicht mit echten Rückmeldungen geprüft;
Karte und Rückfrage sagen das. Die Cloud-Seite der Karte „Auslesen“
(Einstellungen → Experte) speichert über `PATCH /api/inbox/cloud`
([API](api.md#get-apiinboxcloud--patch-apiinboxcloud-v370)).
Einrichtung: [In der Cloud auslesen](../anleitungen/posteingang.md#in-der-cloud-auslesen).

**Voreinstellungen** *(Karte „Auslesen“)*: Claude (Anthropic), OpenAI
(`https://api.openai.com/v1`), Mistral (`https://api.mistral.ai/v1`), OpenRouter
(`https://openrouter.ai/api/v1`), Google Gemini
(`https://generativelanguage.googleapis.com/v1beta/openai`) und „Eigene
Adresse“. Sie sind keine eigene Einstellung: Sie füllen nur
`inbox_cloud_provider` und `inbox_cloud_endpoint`; welche gilt, folgt aus diesen
beiden. Eine andere Adresse ist „Eigene Adresse“. Die **Modellliste** lädt die
Karte nach dem Speichern des Schlüssels über `GET /api/inbox/cloud/models` (nur
Verwalter; der Schlüssel bleibt auf dem Server); schlägt das fehl, bleibt das
Feld frei.

Der **API-Schlüssel ist kein Einstellungsschlüssel**: Er steht in einer eigenen
Datei auf dem Server (`inbox_cloud_key.json`, nur für den Webserver lesbar),
nie in `settings.json`, nie in `GET /api/settings` und nie im Backup. Beim
OpenAI-kompatiblen Weg gehört er zum Host der Adresse: Wer die Voreinstellung
wechselt, schickt den bisherigen Schlüssel nicht an den neuen Dienst; beim
Zurückstellen ist er noch da.

Wer ausliest, bestimmt `inbox_provider` ([Posteingang](#posteingang-v370)), also
der Umschalter der Karte; die Schlüssel hier richten den Cloud-Dienst ein.

| Schlüssel | Standard | In der App | Wirkung |
|---|---|---|---|
| `inbox_cloud_enabled` | aus | Auslesen → Cloud: Eingeschaltet | Der Schalter des Opt-ins. Aus = kein Dokument verlässt das Heimnetz. Einschalten geht über die Karte nur mit Schlüssel (bei OpenAI-kompatibel auch mit Adresse und Modell). Nur Verwalter. |
| `inbox_cloud_provider` | `anthropic` | Auslesen → Cloud: Anbieter | `anthropic` — Claude (Anthropic), das PDF geht mit, die Belegstelle kommt samt Seite vom Dienst — oder `openai`, ein OpenAI-kompatibler Dienst (OpenAI, Mistral, OpenRouter, Google Gemini oder eigene Adresse), der nur den Text des Dokuments bekommt. Nur Verwalter. |
| `inbox_cloud_endpoint` | leer | Auslesen → Cloud: Anbieter bzw. Adresse | Nur bei `openai`: Basisadresse mit `https://` bis zur Versionsstufe, etwa `https://api.example.com/v1`; die App hängt `/chat/completions` an (ohne Versionsstufe wie bisher `/v1/chat/completions`). Die Voreinstellungen tragen ihre Adresse ein. Nie eine Adresse im Heimnetz (Gegenstück zu `ocr_endpoint`); geprüft beim Speichern und bei jedem Aufruf nach der Namensauflösung. Nur Verwalter. |
| `inbox_cloud_model` | leer | Auslesen → Cloud: Modell | Aus der Liste des Dienstes oder von Hand. Leer = Standard des Anbieters (`claude-opus-5-5` bei Claude, der einzige fest eingebaute Name); bei `openai` Pflicht. Buchstaben, Ziffern und `. _ : / @ + -`, höchstens 200 Zeichen. Wechselt der Anbieter oder der Dienst hinter der Adresse, wird das Modell geleert. Nur Verwalter. |
| `inbox_cloud_timeout_s` | 120 | Auslesen → Cloud: Zeitlimit | Sekunden, die der Server auf den Cloud-Dienst wartet (10–600). Hinter einem eigenen Webserver oder Reverse-Proxy gilt dessen Grenze; im Docker-Image wartet nginx beim Erkennen und Auslesen bis zu 660 Sekunden *(v3.7.0)*. |

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
| `co2_price_scenario_from` | 2028 | Szenario ab Jahr | Erstes Jahr, für das das Szenario gilt (2021–2100). 2028 startet der europäische Emissionshandel für Gebäude und Straßenverkehr (ETS2) und löst den nationalen Preis ab (Art. 2 VO (EU) 2026/667). |

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
