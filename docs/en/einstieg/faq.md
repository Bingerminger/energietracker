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

### Can I record readings without Wi-Fi in the cellar?

Yes, since v3.1.0. If a reading in “Meter readings” does not reach the server,
it waits in the phone's browser (“Not saved yet”) and is sent as soon as the
connection is back — nothing is doubled. For the app to open without a network
at all, it needs HTTPS and the app on the home screen; otherwise open the page
before going down to the cellar. Details and limits:
[Use on your phone](handy.md#the-not-saved-yet-queue).

### Does the app read the meter value from a photo?

If you run your own text recognition service in the home network (Ollama,
LM Studio), yes: “📷 Photo” on the card, then the app suggests the reading
(“Recognised: … – Use”); nothing is saved until you click. Without such a
service the photo stays a receipt with the reading, and on the iPhone Live
Text (“Scan Text” in the field) takes over the digits. The app deliberately
uses no cloud service —
[Text recognition in the home network](../anleitungen/texterkennung.md).

### Can I try it out first without entering anything?

Yes: on the empty overview **"Try with sample data"**, later under Settings →
Data → "Load demo data". The app saves the current state first; the way back
is Settings → Data → Stored snapshots (↩️).

### I rent my home — does the app help me?

Yes. It handles electricity on your own contract like anyone else’s. For
heating and water that you pay through the service charges, there are building
blocks of their own since v3.1.0: the utility **Heat** for the monthly
consumption information from the metering service (German HeizkostenV § 6a),
hot- and cold-water meters with roles and — after **Settings → Household &
building → “I live”: “in a rented home”** — the page **Tenancy** under Costs &
contracts. It compares the prepayment with the expected costs of the current
billing period, keeps the service charge statements with their PDF and puts
the deadlines for the statement and for objections into the calendar. In
Germany it also works out which part of the CO₂ costs the landlord bears
([Share CO₂ costs](../anleitungen/co2-aufteilung.md)). An estimate, not a
service charge statement and not legal advice. Step by step:
[As a tenant](../anleitungen/mieter.md).

### Can I take over old values from Excel?

Yes, as CSV: readings per meter through **⚙️ Meters → CSV import** — date
(`DD.MM.YYYY`, `DD/MM/YYYY`, `DD-MM-YYYY` or `YYYY-MM-DD`) and reading,
separated by semicolon or comma, note and "estimated" optional. Column names
may be in any language of the app. A preview shows every row before anything
is written, and an example can be downloaded there. An American date with the
month first (`01/15/2026`) is not reinterpreted; the app reports the row.
Temperatures go in under Settings → Weather data as `DD.MM.YYYY;mean;min;max`.
Monthly values for a meter with consumption per period (since v3.1.0) are read
by the same button as `month;consumption`, for example `01.2026;1180`.

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
walks through all of these; the **bill check** recalculates a bill for gas,
electricity, water or district heating section by section.

### Is my annual bill right?

Since v3.1.0 you can check: **Costs & contracts → Check a bill**, choose utility
and meter and enter the bill's figures in the card **“According to the bill”** —
period, consumption, bill amount, advances paid, plus levies and fees under
“Other items”. “Compare” sets your own calculation next to it: **“matches”** if
quantity is within 1 % and amount within 1 % or 2 €, otherwise **“check”** with
possible reasons — such as an estimated reading at the boundary of the period
or a price missing in the contract. If everything is right, **“Book as special
payment”** books the credit or additional payment into the contract. For gas,
electricity, water and district heating — step by step in [Enter and check the
annual bill](../anleitungen/jahresabrechnung.md#7-on-the-bill-enter-compare-book).

### What does the CO₂ price cost me?

In Germany, since 2021 the unit price of gas and heating oil contains a CO₂
price — 55 € in 2025, 60 € per tonne of CO₂ in 2026. Since v3.1.0 the
consumption view of gas, heating oil, district heating and heat shows the card
**“CO₂ price in the fuel”**: how much of it is in your costs, per kWh and for the
year. For 10,000 kWh of gas that was about 119 € including VAT in 2025. It is not
a surcharge but part of what you already pay. What a higher price would cost,
the forecast works out with the field “CO₂ price from 2028”. When you rent, the
landlord bears a share depending on the building
([Share CO₂ costs](../anleitungen/co2-aufteilung.md)). More:
[CO₂ price in fuel](../verstehen/16-co2-preis.md).

### What do I need to switch supplier?

**Costs & contracts → Tariff switch** gives the expected annual consumption for
the comparison portal and the switch date. Since v3.1.0 it also shows, under
**“Have ready for switching”**, the details the new supplier asks for — market
location ID, meter number and last meter reading — to copy. You enter the
market location ID (11 digits, on the bill) once in the meter dialog; the app
rejects a wrong check digit. If your supplier announces a price increase and
you enter it on the contract as a new price row with its date, the app reminds
you of the special right to terminate as of that day (§ 41(5) EnWG). And when
you save a new contract it
points out a minimum term longer than 24 months — invalid for consumers in
Germany (§ 309 no. 9 BGB). These are notes, not legal advice.

### Is a dynamic electricity tariff worth it?

Since v3.1.0 the **dynamic tariff check** works it out: under **Costs &
contracts → Tariff switch** (electricity) the card “Wholesale electricity
prices” loads the monthly wholesale averages from SMARD — only on request — or
reads a file. Then add an offer with the tick “Dynamic tariff (wholesale price
per month)”, with the mark-up from the price sheet. The ranking sets it next to
your contract. An approximation: it calculates with the monthly average, as if
your consumption were spread evenly across the day. Whoever uses a lot in the
evening pays more in reality; whoever puts wall box, heat pump or battery into
cheap hours pays less. A dynamic tariff is only billed with a smart metering
system ([Electricity → Dynamic tariffs](../verstehen/02-strom.md#dynamic-tariffs-v310)).

### I have a meter with peak and off-peak — how do I enter that?

Since v3.1.0 like this: create both registers as meters of their own, combine
them into a group (⚙️ Meters → "Group meters") and create **one** contract for
the group — with one standing charge and, under “Unit price per meter (e.g.
peak/off-peak)”, the price per register. Balance, forecast and switch then
calculate for both together
([Electricity](../verstehen/02-strom.md#peak-and-off-peak-one-contract-for-a-meter-group-v310)).

### How good is my heat pump?

With a heat meter on the heat pump, the app calculates the **seasonal
performance factor** since v3.1.0: heat ÷ electricity over a year. Create the
heat meter under Heat with the role “Heat pump output” and link it to the heat
pump’s electricity meter. For context the card names the Fraunhofer ISE field
test “WP-QS im Bestand” (2025): air/water averaged 3.4, brine/water 4.3.
Without a heat meter it does not work — electricity alone says nothing about
the heat ([Heat §7](../verstehen/15-waerme.md#7-seasonal-performance-factor-of-the-heat-pump-v310)).

### How do I compare myself with others?

With your own **reference values** (since v3.1.0): under Settings → Household
& building → “Own reference values” enter, for example, the kWh the German
electricity benchmark (Stromspiegel) gives for your household and the kWh per
m² from the heating benchmark (Heizspiegel), plus the source. The overview then
shows the card “Benchmark” with the deviation in % for the last full year. The
app ships no tables from these benchmarks — using them requires permission —;
in Germany it links the pages for checking yourself. With PV, a heat pump or a
wall box the general electricity benchmark does not fit; the app therefore
excludes heat pump and wall box.

### Can I take over values from my grid operator’s portal?

Yes, as a file: **⚙️ Meters → “Import time series”** reads quarter-hour,
hourly or daily values from the portals of grid operators, metering operators,
inverters or heat pumps. You say which column holds date and value, whether
they are meter readings or consumption values and in which unit; the app
condenses them into daily values. It does not connect to the portals
([Time series from portals](../anleitungen/daten-aus-portalen.md)).

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
(Germany: GModG, formerly GEG). Elsewhere the card shows kWh/m²·yr and gives the reason.

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
([Country profiles](../verstehen/14-laenderprofile.md)). The ⓘ explanations
name the terms as they appear on your country's bill, and the tariff switch
links the official tariff comparison where there is one.

### Two languages in one household — does that work?

Yes, since v3.1.0. Under Settings → General → Language & country every device
picks its **language on this device**; the browser remembers it, nothing
changes on the server. Next to it is the **default language of the
installation**: it applies to devices without a choice of their own and to
everything produced without a device — PDF annual report, CSV files, messages
to Home Assistant. The print view of the annual report follows the language of
the device.

## Data and operation

### Where is my data, and how do I back it up?

As JSON files in the data directory (`data/`, in the Docker container `/data`).
A complete backup comes from Settings → Data → **Download JSON backup**; before
every import the app creates a snapshot itself.

### What data leaves my server?

Only the requests to Open-Meteo: the daily weather sync with your location
rounded to about 1 km (can be switched off under Settings → Weather data) and
the place search, if you use it. In addition, only if you set one up, the
photos to your own text recognition service — which has to be in the home
network ([Text recognition](../anleitungen/texterkennung.md)) — and, since
v3.1.0 only on request (“Load from SMARD”), the download of wholesale
electricity prices from SMARD, the platform of the German Federal Network
Agency; nothing of yours is sent along. No accounts, no telemetry, no ads.

### Can I use the app on the iPhone?

Yes, in the browser on the home network; as an app on the home screen with
HTTPS. See [Use on your phone](handy.md).

### Can I see deadlines in my calendar?

Yes, since v3.1.0 as a calendar subscription: under **Reminders & tips →
Reminders & maintenance → Subscribe in your calendar**. It holds reminders,
cancellation deadlines, contract ends, price guarantees, price increases and
due readings, and when renting the deadlines of the service charge statement;
the calendar fetches them again every 12 hours. How to set it up
in Apple Calendar, Thunderbird or Google Calendar is described in
[Deadlines in your calendar](../anleitungen/kalender.md).

### How do I print the annual report?

Under **Insights → Annual report** choose the year and **Open print view**, then
**Print / save as PDF**: the browser's print dialog prints on paper or saves a
PDF file; sidebar and buttons do not appear. On the iPhone this works via
**Share → Print** — where the report can also be saved as a PDF or passed on.
The print view is in the language of the device. Below it the ready-made PDF
file in the default language of the installation is still available.

### Which CSV format should I export?

For Excel, LibreOffice or Numbers the **spreadsheet in the default language**
(Settings → Data → Data export, recommended): column names, decimal separator
and dates match the language, and the file opens correctly with a double click.
For scripts **CSV format 1** — it stays byte for byte as it is. Both can be
imported again ([CSV formats](../referenz/api.md#csv-formats-v310)).

### How do I update?

Docker: pull the new image and recreate the container — the data volume stays.
Without Docker: `git pull` (or replace the files). The app upgrades data formats
itself at start-up; a backup beforehand never hurts
([Installation](../betrieb/installation.md)).

### Can I make the app reachable from the internet?

Yes, but only with sign-in (Settings → Access) and behind HTTPS. The checklist
is in [Security & network operation](../betrieb/sicherheit.md).

### What does the Energietracker cost?

Nothing. Since version 3.0.0 it is under the
[GNU AGPL v3.0 or later](../../../LICENSE); versions up to 2.16.0 remain
available under the MIT licence they were published with.

### What does the AGPL mean for me?

For your own installation, nothing: using, changing and sharing it is free.
Whoever offers a modified version to others as a network service must make
its source code available to them too. Under Settings → System a link leads to
the source code of exactly the running version.

### Where do I report a bug or a wish?

In the [GitHub issues](https://github.com/Bingerminger/energietracker/issues).
For a bug the diagnostics under Settings → System (version, schema, write
permissions) help.

---

[← Compendium index](../README.md)
