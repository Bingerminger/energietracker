# UI reference — all views

**English** · [Deutsch](../../ui/01-views.md)

[← Compendium index](../README.md)

> **Real screenshots.** The following images are **actual screen captures** of the
> running app with the bundled [demo dataset](../../../demo-data/) (light theme, as
> of v1.9.2). The app's interface is in German; the captures are shared with the
> German compendium. To regenerate them yourself: load the demo data and capture
> the views one by one — the app needs no build step for this.

The app is a single-page application with a fixed **topbar** (logo, theme toggle)
and a **sidebar** that is built dynamically from the *active* utilities
(Settings → Active utilities).

---

## 1. Overview (dashboard)

The entry point. 12-month figures per utility, efficiency class **per heat
source**, tank levels (oil/pellets), **electricity balance & self-sufficiency** for
PV, the combined consumption history and due appointments.

![Dashboard](../../ui/screenshots/dashboard.png)

---

## 2. Meter-reading capture (F1004)

The central, mobile-friendly input mask: all active cumulative meters
(gas/electricity/water/district heating/PV) each with the last reading for
orientation — ideal for the monthly reading on the phone.

![Meter readings](../../ui/screenshots/zaehlerstaende.png)

---

## 3. Consumption view — cumulative utilities (gas/electricity/water/district heating)

Identical structure per utility: year selection, meter selection, KPI bar
(consumption, costs, balance today, expected balance), contract/balance card, a
consumption chart with a temperature overlay, and a monthly table with moving
averages (MA-3/MA-6) and weather adjustment.

![Gas view](../../ui/screenshots/gas-view.png)

---

## 4. Consumption view — delivery-based utilities (heating oil/pellets)

Instead of meter readings: the tank stock curve (modelled, calibrated) and a
delivery table (date, quantity, price, total, supplier). No contract area — the tank
invoice is the cost basis.

![Heating oil view](../../ui/screenshots/heizoel-view.png)

---

## 5. Analysis (heating signature)

An HDD correlation scatter plot with a regression line, an R² comparison of **all
five** models (linear, polynomial, robust, segmented, sigmoid), anomalies.

![Analysis](../../ui/screenshots/analyse.png)

---

## 6. Forecast

Model selection (all five), a 12-month forecast as an R²-weighted blend of
regression and seasonal profile, a cost forecast with the balance of open contracts.

![Forecast](../../ui/screenshots/prognose.png)

---

## 7. Tariff comparison

Real **and** shadow contracts computed on the actual consumption — "what would tariff
X have cost?", without changing the balance/forecast.

![Tariff comparison](../../ui/screenshots/tarifvergleich.png)

---

## 8. Recommendations

Seven statistical rule families (over-consumption trend, summer base, anomaly, tank
level, contract end, efficiency, …), sorted by urgency, individually hideable.
Purely data-driven, no advertising.

![Recommendations](../../ui/screenshots/empfehlungen.png)

---

## 9. Appointments & maintenance

Recurring appointments (heating service, chimney sweep, calibration deadlines).
Due/overdue ones appear on the dashboard; on completion the next appointment is
rolled forward according to the recurrence.

![Appointments](../../ui/screenshots/termine.png)

---

## 10. Temperatures

CSV import (drag & drop), Open-Meteo sync for the stored location, a monthly chart
min/avg/max. The basis of every HDD evaluation.

![Temperatures](../../ui/screenshots/temperaturen.png)

---

## 11. Settings

All 40 keys grouped: conversion & HDD, **billing cycle (DD-MM)**, building &
efficiency, calorific values, forecast model, active utilities, CSV export of all
utilities, backup & migration, demo-data import (F1007) and the **🏠 Home Assistant
integration (F1009)** — manage the API token, maintain meter aliases, copy the
ready-made HA YAML.

![Settings](../../ui/screenshots/einstellungen.png)

---

## 12. Meters & contracts — incl. topology (F1006)

Meter/device management incl. meter swap (the device chain) and contract maintenance
(working/base price history, advances, bonuses). For oil/pellets only the tank/store
management is relevant here. When creating or editing a tank, **tank capacity** and **initial stock** are captured (instead of a cumulative meter reading).

**Meter topology:** submeters are shown indented under their parent meter, groups as
an expandable collective entry; a **merge wizard** combines several existing meters
into a group. Per meter, the HA alias (`external_id`) can also be set here.

![Meters & contracts](../../ui/screenshots/zaehler-vertraege.png)

---

## 13. PV — feed-in & generation (F1005)

A dedicated view for photovoltaics: the feed-in meter (remuneration as revenue), the
generation meter, the **electricity balance** (grid import − feed-in) and the
**self-sufficiency rate/self-consumption**. PV utilities have no default meter —
anyone without a system sees no phantom meters.

![PV](../../ui/screenshots/pv.png)

---

[← Compendium index](../README.md)
