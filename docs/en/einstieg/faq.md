# Questions and answers

[Deutsch](../../einstieg/faq.md) · **English**

[← Compendium index](../README.md)

Short answers to the most common questions. If something does not work, see
[Troubleshooting](../betrieb/fehlersuche.md); terms are explained by the help
inside the app and the [glossary](../verstehen/09-glossar.md).

---

## First steps

### Do I need Home Assistant or a smart meter?

No. Readings can be entered by hand — fastest in the **meter-reading capture**,
which asks for all meters in one pass. Home Assistant is one way to deliver the
readings automatically ([guide](../anleitungen/home-assistant.md)).

### How often do I have to read the meters?

Once a month is enough; less often works too. Consumption comes from the
difference between two readings and is spread over the months to the day; the
denser the readings, the more precise the months. After 45 days without a
reading the overview reminds you under "To do" (adjustable under Settings →
General).

### Can I try it out first without entering anything?

Yes: on the empty overview **"Try with sample data"**, later under Settings →
Data → "Load demo data". The app saves the current state first; the way back
is Settings → Data → Stored snapshots (↩️).

### Can I take over old values from Excel?

Yes, as CSV: readings per meter through **⚙️ Meters → CSV import** — date
(`DD.MM.YYYY` or `YYYY-MM-DD`) and reading, separated by semicolon or comma,
note and "estimated" optional; a preview shows every row before anything is
written, and an example can be downloaded there. Temperatures go in under
Settings → Weather data as `DD.MM.YYYY;mean;min;max`.

## Calculating and display

### Why is there nothing under "Cost"?

Costs come from a contract: without a contract with a unit price for the
period there are no costs. **Costs & contracts → Contracts & payments** shows
which meters have one.

### What do "+" and "−" mean for the balance?

"+" is a **credit** (paid too much, money back), "−" an **additional payment**.
Cards and tiles spell it out: "Credit €120.00". For the PV feed-in "+" is an
open claim against the grid operator.

### Why does the balance differ from my bill?

Usually for one of these reasons:

- The **billing date** is a different one (Settings → Utilities & billing).
- A **refund or back-payment** from the previous year's bill is missing — it
  belongs on the contract as a special payment.
- For gas the bill's **conversion factors** are missing (volume correction
  factor × calorific value).
- The bill uses an **estimated reading** (reading type "E"), the app a real
  one.

The guide [Enter and check the annual bill](../anleitungen/jahresabrechnung.md)
walks through all of these; the **bill check** recalculates a gas bill section
by section.

### Why is my gas consumption in kWh different from the bill?

The gas meter counts cubic metres; kWh only come from volume correction factor ×
calorific value, and both change from year to year. As long as only the default
factor 11.5 is entered, the result is off. The bill's factors belong in the list
under Settings → Utilities & billing, each with its effective date.

### Why are analysis or forecast empty?

Because there is not enough data yet. Weather adjustment needs 12 months with
at least 8 usable points, anomalies 5 months, an efficiency class a whole year
(360 covered days) and the floor area. Every evaluation with degree days also
needs temperatures for your location (Settings → Weather data).

### Why is the efficiency class missing?

Classes exist only for whole years and only in countries with a scale
(Germany: GEG). Elsewhere the card shows kWh/m²·yr and gives the reason.

### What does "estimated" mean?

Between the last reading and today the app knows no reading. For the balance by
calendar it estimates consumption from the heating model or the usual daily
consumption and says from when it is estimated. For heating oil and pellets
everything after the last known fill level is estimated.

### What is a baseline date?

A structural change with a date — new heating, insulation, windows — entered on
the meter. From there all evaluations start afresh, and a before/after figure
shows the effect ([Scenario house](../verstehen/08-szenario-eigenheim.md)).

### I do not live in Germany — does it work?

Yes. The country under Settings → General → Language & country sets currency,
formats, time zone, weather location, heating limit, CO₂ factor and gas units.
Efficiency classes and the special right to cancel remain German
([Country profiles](../verstehen/14-laenderprofile.md)).

## Data and operation

### Where is my data, and how do I back it up?

As JSON files in the data directory (`data/`, in the Docker container `/data`).
A complete backup comes from Settings → Data → **Download JSON backup**; before
every import the app creates a snapshot itself.

### What data leaves my server?

Only the requests to Open-Meteo: the daily weather sync with your location
rounded to about 1 km (can be switched off under Settings → Weather data) and
the place search, if you use it. No accounts, no telemetry, no ads.

### Can I use the app on the iPhone?

Yes, in the browser on the home network; as an app on the home screen with
HTTPS. See [Use on your phone](handy.md).

### How do I update?

Docker: pull the new image and recreate the container — the data volume stays.
Without Docker: `git pull` (or replace the files). The app upgrades data formats
itself at start-up; a backup beforehand never hurts
([Installation](../betrieb/installation.md)).

### Can I make the app reachable from the internet?

Yes, but only with sign-in (Settings → Access) and behind HTTPS. The checklist
is in [Security & network operation](../betrieb/sicherheit.md).

### What does the Energietracker cost?

Nothing. It is under the MIT licence.

### Where do I report a bug or a wish?

In the [GitHub issues](https://github.com/Bingerminger/energietracker/issues).
For a bug the diagnostics under Settings → System (version, schema, write
permissions) help.

---

[← Compendium index](../README.md)
