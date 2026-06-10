# Getting started

**English** · [Deutsch](../ERSTE-SCHRITTE.md)

[← Compendium index](README.md)

This guide takes you **through one continuous example**, from installation to the
first forecast. We set up a household with **gas heating and an electricity
connection**. If you just want to try it out, you can also
[load the demo data](#shortcut-demo-data) directly and jump in at
[step 6](#6-evaluate).

> **Time needed:** ~15 minutes. You need: a current meter reading and (for the
> cost calculation) your supply contract.

---

## 0. Install & open

Energietracker runs locally — no cloud account, no database. The fastest option
is Docker:

```bash
docker run -d --name energietracker -p 8080:80 \
  -v "$PWD/data:/data" \
  ghcr.io/bingerminger/energietracker:latest
```

Then open **http://localhost:8080** in the browser. (Other routes — PHP server,
Apache, nginx — are in [Installation](technical/01-installation.md) and
[Docker](technical/07-docker.md).)

On the first start the app automatically creates default meters for gas,
electricity and water. You can keep, rename or delete them.

---

## 1. Choose utilities

Open **System → Settings** (gear icon in the sidebar) → **Active utilities** and
tick what you use. For our example, **gas** and **electricity** are enough.
Utilities that are not active disappear from the sidebar and dashboard (the data
is kept in case you switch them back on later).

---

## 2. Set up the first meter

In the sidebar go to **Gas** and then to **Meters / contracts**.

1. Click the existing *"main meter"* and rename it if you like (e.g. *"gas boiler
   basement"*).
2. When creating/editing, you enter the device's **initial reading** and
   **installation date** — that is the starting point of the measurement.

> **Meter swap later?** No problem: Energietracker models every meter as a chain
> of devices. On a swap you enter the old final reading + new initial reading,
> and consumption is computed seamlessly across the swap boundary.

---

## 3. Record meter readings

Two ways:

- **Quick, for all meters:** sidebar → **Entry → Meter readings**. This view
  lists all active meters with their respective last reading for orientation —
  ideal for the monthly reading on your phone.
- **Per meter:** directly in the meter-reading table in the gas view.

Enter at least **two** readings with a time gap (e.g. start of the year and
today) — consumption only arises from the **difference**.

> **Lots of historical data?** You can import a CSV per meter
> (`date;reading;note;estimated`). Format and example: in the meter view under
> "CSV import".

---

## 4. Add a contract (for the cost calculation)

In the gas view → **Meters / contracts → New contract**:

- **Provider** and **tariff name**, **start/end**,
- **working price** (ct/kWh) and **base price** (€/month) — both with a date, so
  that price changes are mapped to the exact effective day,
- optionally an **advance** (€/month) for the balance calculation and **bonuses**.

From now on the app computes not only consumption but also **costs** and the
**balance** against your advances (surcharge/refund).

---

## 5. Fetch temperatures (for heating analysis & forecast)

So that "consumed more, or just colder?" can be answered, gas (and district
heating) need outdoor temperatures. Sidebar → **Consumption → Temperatures**:

- **Open-Meteo sync** for the location stored in the settings (default: Leipzig —
  change it to your location!), **or**
- import a temperature CSV.

Without temperatures, consumption, costs and balance still work — only the
weather-adjusted analysis and the HDD forecast need them.

---

## 6. Evaluate

Now the input pays off:

- **Overview (dashboard):** 12-month metrics, efficiency class, due reminders,
  combined trend.
- **Gas view:** monthly table with consumption, costs, balance and moving
  averages; contract/balance card with surcharge forecast.
- **Analysis → heating signature:** consumption against heating degree days, with
  a regression line and R² comparison of five models.
- **Forecast:** 12-month preview (consumption **and** costs) as an R²-weighted
  blend of regression and seasonal profile.
- **Tariff comparison:** "what would tariff X have cost?" via shadow contracts.

---

## 7. Back up

Sidebar → **Settings → Backup & restore → Download JSON backup**. That is a
complete, portable snapshot (format 3.0) for moving or backing up. In Docker
operation your data is in the mounted `data/` volume anyway.

---

## What next?

- **Main and sub-meters, bundling meters?** →
  [Meter topology](functional/13-meter-topologie.md)
- **Values automatically from Home Assistant?** → [Home Assistant](HOME-ASSISTANT.md)
- **My case is more specific (shared flat, PV, landlord)?** →
  [Use cases](USE-CASES.md)
- **How exactly does the forecast compute?** →
  [Fundamentals & methodology](functional/00-overview.md)
- **Oil/pellets instead of gas?** → [Heating oil](functional/05-heizoel.md) ·
  [Pellets](functional/06-pellets.md)

---

## Shortcut: demo data

To just try it out you don't have to type anything: **Settings → Backup &
restore → Load demo data** imports a complete example dataset across all eight
utilities (with a warning + auto-snapshot if data already exists). After that you
can continue right at [step 6](#6-evaluate).

---

[← Compendium index](README.md)
