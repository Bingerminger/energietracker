# Country profiles — language, country, currency (N1014)

**English** · [Deutsch](../../functional/14-laenderprofile.md)

[← Meter topology](13-meter-topologie.md) · [Compendium index](../README.md)

Up to **v2.6.0** Energietracker was German in everything but the interface
language: euros, efficiency classes under the German Buildings Energy Act,
the German grid mix as CO₂ factor, a heating threshold of 15 °C, Leipzig as
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
| Germany | EUR | Europe/Berlin | 15 °C | 380 g/kWh ¹ | Leipzig | GEG (A+ to H) | kWh/m³ |
| Austria | EUR | Europe/Vienna | 15 °C | 103 g/kWh | Wien | – | kWh/m³ |
| Switzerland | CHF | Europe/Zurich | 15 °C | 35 g/kWh | Bern | – | kWh/m³ |
| France | EUR | Europe/Paris | 18 °C (DJU) | 40 g/kWh | Paris | – | kWh/m³ |
| Italy | EUR | Europe/Rome | 20 °C (gradi giorno) | 281 g/kWh | Roma | – | GJ/Smc |
| Spain | EUR | Europe/Madrid | 15 °C | 146 g/kWh | Madrid | – | kWh/m³ |
| Portugal | EUR | Europe/Lisbon | 15 °C | 111 g/kWh | Lisboa | – | kWh/m³ |
| Netherlands | EUR | Europe/Amsterdam | 18 °C (graaddagen) | 251 g/kWh | De Bilt | – | MJ/m³ |
| United Kingdom | GBP | Europe/London | 15.5 °C | 217 g/kWh | London | – | MJ/m³ |

¹ Germany keeps the previous default. All other CO₂ factors: Ember,
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
currency and time zone; all four take effect immediately. When the country
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

The interface formats with `Intl`; the yearly PDF report and the
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

Classes A+ to H come from the German Buildings Energy Act (final energy per
m² and year). Other countries rate differently — France (DPE) by primary
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

## 8. Limits

- **No currency conversion** (see §4).
- **Gas meters counting cubic feet** (older British "imperial" meters) are not
  supported; the meter must count m³.
- **Nine countries.** Another country is one entry in
  `src/Config/Countries.php` plus its name in the language catalogs
  (`countries.XX`); `CountriesTest` checks completeness.
- **The language applies to the whole installation**, not per device.

→ API: [country profile in the API reference](../technical/03-api-reference.md#country-profile-country-currency-timezone-gas_cv_unit-v270-additive)
