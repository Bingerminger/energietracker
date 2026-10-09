# Country profiles — language, country, currency (N1014)

**English** · [Deutsch](../../verstehen/14-laenderprofile.md)

[← Meter topology](13-meter-topologie.md) · [Compendium index](../README.md)

Up to **v2.6.0** Energietracker was German in everything but the interface
language: euros, efficiency classes under the German Buildings Energy Act
(now the Building Modernisation Act), the German grid mix as CO₂ factor, a heating threshold of 15 °C, Leipzig as
weather location and the time zone Europe/Berlin. Since **v2.7.0** a
**country profile** bundles these defaults for nine countries.

A profile is a proposal, not a commitment: every value stays individually
editable. **Existing installations do not change with the update** — the
defaults are the German profile, and the German profile is exactly the
previous behaviour (a test keeps both equal).

---

## 1. What a profile sets

| Country | Currency | Time zone | Heating threshold | CO₂ electricity | Location | Efficiency class | Calorific value in |
|---|---|---|---|---|---|---|---|
| Germany | EUR | Europe/Berlin | 15 °C | per year ¹ | Leipzig | GModG, formerly GEG (A+ to H) | kWh/m³ |
| Austria | EUR | Europe/Vienna | 15 °C | 103 g/kWh | Wien | – | kWh/m³ |
| Switzerland | CHF | Europe/Zurich | 15 °C | 35 g/kWh | Bern | – | kWh/m³ |
| France | EUR | Europe/Paris | 18 °C (DJU) | 40 g/kWh | Paris | – | kWh/m³ |
| Italy | EUR | Europe/Rome | 20 °C (gradi giorno) | 281 g/kWh | Roma | – | GJ/Smc |
| Spain | EUR | Europe/Madrid | 15 °C | 146 g/kWh | Madrid | – | kWh/m³ |
| Portugal | EUR | Europe/Lisbon | 15 °C | 111 g/kWh | Lisboa | – | kWh/m³ |
| Netherlands | EUR | Europe/Amsterdam | 18 °C (graaddagen) | 251 g/kWh | De Bilt | – | MJ/m³ |
| United Kingdom | GBP | Europe/London | 15.5 °C | 217 g/kWh | London | – | MJ/m³ |

¹ Germany: electricity mix of the German Environment Agency (UBA) per year
2015–2025 (`co2_strom_years`, since v2.10.0; 2025: 344 g/kWh), 380 g/kWh
before that. Only the German profile carries yearly values — whoever switches
the country and applies the profile calculates with that country's single
value. All other CO₂ factors: Ember,
electricity generation mix 2024, via *Our World in Data*
("carbon-intensity-electricity"). The heating threshold follows the national
degree-day convention where it is a plain base temperature; otherwise it
stays at 15 °C.

The source of truth is `src/Config/Countries.php`; the interface reads the
profiles via `GET /api/countries`.

---

## 2. When a profile applies

**On first start.** If the data directory is empty, the app reads the
browser's language preferences (`Accept-Language`): "fr-CH" gives French and
Switzerland, "de-AT" German and Austria. If the browser names no region, the
language decides (English → United Kingdom). The default meters are then named
in the right language straight away ("Compteur principal").

**Only values that differ from the defaults are written.** A German first
start therefore writes nothing — if a later update corrects a default, the
correction applies there as well. After the first start the header changes
nothing.

**In the settings.** The card "Language & country" holds language, country,
currency and time zone; all take effect immediately. Since v3.1.0 the
language appears twice: "Language on this device" (this browser only) and
"Default language of the installation" (devices without a choice of their own,
PDF, CSV, Home Assistant). Country, currency and time zone apply to the whole
installation. When the country
changes, a dialog shows which values the profile would change — current and
new side by side, with the source of the CO₂ factor:

- **Apply all** sets the country and every differing profile value,
- **Change country only** sets just the country (formats, efficiency scale),
- **Cancel** leaves everything as it was.

Meters, contracts and readings stay unchanged in every case.

---

## 3. How numbers and dates are written

The language decides; the country refines it **only if the language is
spoken there**:

| Language + country | Number | Amount | Date |
|---|---|---|---|
| German, Germany | 1.234,5 | 1.234,56 € | 05.01.2026 |
| German, Austria | 1 234,5 | € 1.234,56 | 05.01.2026 |
| German, Switzerland | 1'234.5 | CHF 1'234.56 | 05.01.2026 |
| French, Switzerland | 1'234,5 | 1'234.56 CHF | 05.01.2026 |
| English, United Kingdom | 1,234.5 | £1,234.56 | 05/01/2026 |
| English, Germany | 1,234.5 | €1,234.56 | 05/01/2026 |

The last row is deliberate: English in Germany writes English. The browser
(`Intl`) would write German numbers for "en-DE" — and every existing English
installation carries the country DE, so it would have got them overnight.

The interface and the print view of the annual report format with `Intl`; the
yearly PDF report, the CSV spreadsheets in format "local" and the
recommendation texts follow the same rules in the backend (catalog keys
`format.*`, country deviations in `Countries::FORMAT_OVERRIDES`).

---

## 4. Currency

Euro, Swiss franc and pound sterling are available. The currency sets symbol
and minor unit in every text — "ct/kWh" becomes "Rp./kWh" or "p/kWh", column
headers and axes show CHF or £.

**Amounts are not converted.** Changing the currency changes only the
labels; the stored numbers stay. The data fields keep their names
(`ct_per_kwh`, `eur_per_month`, `amount_eur`) and mean the major or minor unit
of the chosen currency — so backup, CSV and the API stay unchanged.

---

## 5. Efficiency class only with a scale

Classes A+ to H come from the German Building Modernisation Act (GModG,
formerly the Buildings Energy Act, GEG), Annex 10 to § 86 (final energy per m²
and year). Other countries rate differently — France (DPE) by primary
energy and CO₂, Austria (HWB) by heating demand under a reference climate. A
class under German law would mislead there: a French user reads "E" as a DPE
class.

So the class exists only where the country has a scale — today only
Germany. For all others, dashboard and PDF report show the figure kWh/m²·yr
without a class and give the reason. The "weak efficiency class"
recommendation does not appear there.

---

## 6. Time zone

The time zone decides which day is "today" (reading date, due dates) and
where the days of the weather data begin: Open-Meteo builds the daily means
in the time zone it is given. Up to v2.6.0 this was fixed to Europe/Berlin —
in Lisbon every day boundary was an hour off.

---

## 7. Gas: calorific-value unit and price per cubic metre

Depending on the country, the gas bill states the calorific value in a
different unit. The settings accept three units; storage is always kWh/m³:

```text
kWh/m³ = MJ/m³ ÷ 3.6
kWh/m³ = GJ/Smc × 1000 ÷ 3.6
```

| Country | The bill states | Enter |
|---|---|---|
| United Kingdom | volume correction 1.02264, calorific value in MJ/m³ | correction factor 1.02264, calorific value in MJ/m³ |
| Italy | coefficiente C, PCS in GJ/Smc | correction factor = coefficiente C, calorific value in GJ/Smc |
| Netherlands | calorische waarde in MJ/m³ | calorific value in MJ/m³ |

In Italy and the Netherlands gas prices appear **per m³** on the bill, but
Energietracker calculates in kWh. Below the unit prices of a gas contract,
the helper "Convert a price per m³" converts:

```text
unit price [minor unit/kWh] = price per m³ × 100 ÷ calorific value [kWh/m³]
```

The calorific value valid on the chosen date is used. The divisor is the
**calorific value**, not correction factor × calorific value: a price per
standard cubic metre (Smc) refers to the already corrected volume. If only a
direct factor is stored, the helper divides by it and says so. "Apply" writes
the result into the row with the same date, otherwise into the first empty
one.

---

## 8. What your bill calls it

The interface speaks a language, the bill a country. "Advance payment" is what
the English interface calls it — a British bill says "Direct Debit", an
Austrian one "Teilbetrag" where a German one says "Abschlag". Since **v3.1.0**
every country profile knows the words printed on that country's bills. The app
shows them

- in the **ⓘ explanation** and in the **glossary of the help**: "On your bill
  (Austria): “Teilbetrag”",
- in the **tariff switch** as the link "Official tariff comparison (country) ↗",
  where the country has one.

The terms are quoted as on the bill, i.e. in the country's language, whatever
language the interface runs in. Switzerland has three versions (German, French,
Italian); the app takes the one of the interface, and the German one for other
languages.

| Country | Unit price | Standing charge | Advance payment | Correction factor | Calorific value | Balance |
|---|---|---|---|---|---|---|
| Germany | Arbeitspreis / Verbrauchspreis | Grundpreis | Abschlag | Zustandszahl (z-Zahl) | Brennwert | Nachzahlung / Guthaben |
| Austria | Energie-Verbrauchspreis | Energie-Grundpreis | Teilbetrag | – | Verrechnungsbrennwert | Nachzahlung / Guthaben |
| Switzerland (de) | Arbeitspreis („Arbeit“) | Grundpreis / Grundtarif | Akontozahlung | Zustandszahl | Brennwert | – |
| Switzerland (fr) | Prix du kilowattheure | Abonnement | Acompte | – | Pouvoir calorifique supérieur (PCS) | – |
| Switzerland (it) | Prezzo dell’energia | Tassa base | Acconto | – | – | Conguaglio |
| France | Prix du kWh | Abonnement | Mensualité | – | PCS (pouvoir calorifique supérieur) | Régularisation (à payer ou trop-perçu) |
| Italy | Quota consumi (prima: quota energia) | Quota fissa | – | Coefficiente C | Potere calorifico superiore (P) | Ricalcoli / conguaglio |
| Spain | Término de energía / término variable | Término fijo / término de potencia | – | – | PCS (poder calorífico superior) | Regularización |
| Portugal | Preço da energia | Termo fixo / potência contratada | – | – | PCS (poder calorífico superior) | Acerto de faturação |
| Netherlands | Leveringstarief | Vaste leveringskosten (vroeger: vastrecht) | Termijnbedrag | Correctiefactor | Calorische waarde | Jaarafrekening: terugbetaling of bijbetaling |
| United Kingdom | Unit rate | Standing charge | Direct Debit | Correction factor (1.02264) | Calorific value | Account balance (in credit / in debit) |

"–" means: the country's bill has no item with the same meaning, and the app
shows nothing there. That is deliberate:

- The **correction factor** is missing for Austria, France, Spain and
  Portugal. Their bills only state the overall factor (Umrechnungsfaktor,
  *coefficient de conversion*) — correction factor × calorific value in one
  number, not the correction factor alone. Showing it as the correction factor
  would invite a wrong entry.
- The **advance payment** is missing for Italy, Spain and Portugal: bills there
  know no fixed advance payments.

**Official tariff comparisons** (`comparison_portal`):

| Country | Comparison | Responsible |
|---|---|---|
| Austria | <https://www.e-control.at/tarifkalkulator> | E-Control (regulator) |
| France | <https://comparateur-offres.energie-info.fr/> | Médiateur national de l’énergie |
| Italy | <https://www.ilportaleofferte.it/portaleOfferte/> | ARERA (regulator) |
| Spain | <https://comparador.cnmc.gob.es/> | CNMC (competition and regulatory authority) |
| Portugal | <https://simuladorprecos.erse.pt/> | ERSE (regulator) |

The app links no official comparison for Germany (§ 41c EnWG provides for a
trust mark for comparison portals; none has been awarded so far), Switzerland
(households cannot choose their supplier), the Netherlands (the ACM only
certifies private comparison sites) and the United Kingdom (Ofgem runs no
portal of its own). The app links no private comparison portal anywhere.

**Sources** of the terms:

- Germany: § 40 (4) EnWG (standardised terms on the bill).
- Austria: E-Control, sample bills for electricity and gas.
- France: energie-info.fr (Médiateur national de l’énergie).
- Italy: ARERA, glossary of the bill (*Bolletta*); since 1 July 2025 the item
  is called "quota consumi", before "quota energia".
- Spain: CNMC, "Ejemplo de factura de suministro de gas".
- Portugal: ERSE, "Compreender a fatura".
- Netherlands: ACM.
- United Kingdom: GOV.UK, "Gas meter readings and bill calculation" (correction
  factor 1.02264); Ofgem on standing charges.

The data lives in `src/Config/Countries.php` (`bill_terms`,
`comparison_portal`); `GET /api/countries` serves it
([API reference](../referenz/api.md#country-profile-country-currency-timezone-gas_cv_unit-v270-additive)).
**Upkeep:** bills and portals change, links age. CI does not check the
addresses. Whoever finds a dead link or a new term changes `Countries.php`,
with the source in the pull request.

---

## 9. Limits

- **No currency conversion** (see §4).
- **Gas meters counting cubic feet** (older British "imperial" meters) are not
  supported; the meter must count m³.
- **Nine countries.** Another country is one entry in
  `src/Config/Countries.php` plus its name in the language catalogs
  (`countries.XX`); `CountriesTest` checks completeness.
- **Country, currency and time zone apply to the whole installation.** Since
  v3.1.0 every device picks its own language (up to v3.0 that applied to all as
  well).

→ API: [country profile in the API reference](../referenz/api.md#country-profile-country-currency-timezone-gas_cv_unit-v270-additive)
