# Data flow & algorithms

[Deutsch](../../entwicklung/datenfluss.md) · **English**

[← Compendium index](../README.md)

How meter readings become monthly consumption, cost and balance, and how the
forecast calculates — the path through `ConsumptionService` and
`ForecastService`. Layers, services and directories are described in the
[architecture](architektur.md), every setting in the
[settings reference](../referenz/einstellungen.md), the formulas in their
functional context in [Basics & methodology](../verstehen/00-overview.md).

> Up to v2.13 this page was `docs/ARCHITECTURE.md` ("short version", as of
> v1.4.4). Module map, layers and settings inventory duplicated the architecture
> page and are gone; what remains is what only existed here.

---

## 1. Data flow: reading → monthly consumption → balance

### Step 1 — reading collection

`ConsumptionService::forMeter($utility, $meter)` starts with all readings of the
meter, filters out those with `is_future: true` or a date in the future, and sorts
them chronologically. At least two readings must be present — otherwise an empty
result.

### Step 2 — interval-to-month distribution

For each pair of consecutive readings `(prev, curr)`:

1. Days = `(curr.date − prev.date).days`
2. The counter delta is computed via `consumptionBetween()` — on a device swap
   between `prev` and `curr` it is bridged like this:
   ```
   consumption = (old_device.final_counter − prev.counter)
               + (curr.counter − new_device.initial_counter)
   ```
3. Counter delta × `unit_to_kwh` factor (for gas: 11.5 kWh/m³ as default).
4. **Linear distribution over the months**: daily, the kWh and the proportional days
   are pushed into the respective `YYYY-MM` bucket.

### Step 3 — weather enrichment

Each month is assigned, from `temperatures.json`:

- `avg_temp` = the mean of all daily temperatures in the month
- `min_temp` = the minimum
- `max_temp` = the maximum
- **`hdd`** (heating degree days) = Σ max(0, base − avg_day) over all days, base =
  `settings.hdd_base_temp` (default 15 °C)

### Step 4 — utility fields

- `kwh_per_day = kwh / days`
- `m3 = kwh / unit_to_kwh_factor` (gas only)
- `co2_kg = kwh × factor / 1000` — factor via `SettingsService::co2Factor(key, year)`; electricity per year from `co2_strom_years` (since v2.10.0)

### Step 5 — contract application (day-exact since v2.9.0)

`ContractService::segmentsBetween(...)` splits every month at every contract
start and end and at every effective date of the working prices, base prices
and advances. Which contract applies on a given day is decided by
`resolveForDate(...)`: on overlap, the one that started later; after a contract
end without a successor, the most recently ended contract as an assumption
(`assumed`), as long as it is not marked as cancelled (`auto_renews: false`).
Per segment:

```
Working price = consumption on the segment's days (from step 2)
                × price on the segment's day (valueOnDate; after the end
                  the price on the last contract day, priceDate)
Base price    = monthly amount × segment days / days in month
Advance       = monthly amount on the first contract day of the month
                × contract days / days in month
Bonus         = per contract; not for assumed segments after the end
```

The month row:

- `contract_id` = the contract with the most days, `contract_assumed`
- `working_price_ct` = the volume-weighted working price of the month
- `kwh_cost`, `base_price_eur`, `advance_eur`, `bonus_eur` = sums of the
  segments
- `cost = kwh_cost + base_price_eur − bonus_eur`
- `monthly_balance = cost − advance_eur` (positive = underpaid)
- `cumulative_balance` = the running balance per contract ID
- `contract_parts[]` — only with more than one contract in the month: per
  contract `days`, `kwh`, `kwh_cost`, `base_price_eur`, `advance_eur`,
  `bonus_eur`, `cost`, `assumed`

Up to v2.8 the contract valid on the first of the month applied to the whole
month (`findActiveForDate(first of month)`). **Water** still calculates this
way: the three-component model has no advances, and its tariffs change at the
turn of the year.

### Step 6 — moving averages

- `ma3` = the 3-month mean over `kwh` (for water over `m3`)
- `ma6` = the 6-month mean
- `ma12` = the 12-month mean

### Step 7 — contract aggregation (`contractStatus`)

After the month rows, `ConsumptionService::contractStatus()` delivers the
aggregation per contract ID:

```
actual_kwh       = Σ kwh           (measured months; with two contracts
actual_cost      = Σ cost           in a month only this contract's part)
actual_kwh_cost  = Σ kwh_cost
actual_base_total  = Σ base_price_eur
actual_bonus_total = Σ bonus_eur
months_actual    = count(months)
```

Since v2.8.0 the **balance** is computed by calendar up to today
(`balanceProjection`), like the annual statement:

```
cost_to_date          = energy_cost_to_date + base_to_date − bonus_to_date
advance_paid          = advances per payment plan up to and including
                        the current month
current_balance       = cost_to_date − advance_paid + special_payment_net
projected_end_balance = current_balance + estimated_cost_remaining
                        − advance_remaining
```

Consumption since the last reading is estimated (heating model or seasonal
profile). Open and renewed contracts (`renewed`, since v2.9.0) are calculated up
to the next billing date. Since v2.9.0 there is also the cancellation deadline
(`ContractService::switchTiming`: `cancel_by`, `switch_date`, `notice_basis`),
the reminder level relative to that day (`remind_basis`), `cancel_missed` and
the next entered price increase (`price_increase`).

`verdict` threshold:
- `back-payment` if `projected > +5 €`
- `refund` if `projected < −5 €`
- `balanced` in between

---

## 2. Forecast algorithm

`ForecastService::forMeter($utility, $meter)` mixes two sources:

1. **Regression component** — on the past `(hdd, kwh)` pairs, the model configured in
   the settings fits. The predict per forecast month uses the HDD forecast from the
   seasonal profile of the last years (daily means over the calendar day, aggregated
   to the month).
2. **Seasonal component** — the mean of the consumption in each calendar month over
   all past years.

The final forecast is:

```
weight = min(blend_max, r2)   // settings.blend_max = 0.8 default
forecast_kwh = weight × regression_pred + (1 − weight) × seasonal_avg
```

With `hgt_relevant: false` (water), `weight = 0` is set — the forecast consists
exclusively of the seasonal profile.

**Cost forecast (F-02, since v1.1.0).** Per forecast month this yields
`cost_estimated` (working price × quantity + base price − known bonuses),
`advance_estimated` (the valid advance) and `balance_running` (cumulative costs −
advance). Since v2.9.0 `projectStandardMonth()` splits the month at contract and
price effective dates like the actual calculation (`segmentsBetween`); the
quantity is distributed over the segments by days, base price and advance pro
rata. After a contract end without a successor, the last contract runs on as an
assumption (`contract_assumed`). Water still resolves the contract on the first of
the month (`resolveForDate`). Future bonuses are not carried forward. If there is
no contract at all, `last_price_ct` applies as a fallback working price.


---

## 3. First start

If the server finds an empty data directory (neither `meta.json` nor a v0.9.0
legacy store), `Storage\Migrator::initFresh()` creates — instead of migrating:

- per utility `meters.json`, `readings.json` or `deliveries.json`,
  `contracts.json` and `meter_groups.json`;
- one **default meter** each for gas, electricity, water and district heating;
  heating oil, pellets and the PV utilities stay empty until someone creates a
  meter or tank;
- `temperatures.json`, `reminders.json` and an empty `settings.json` — the
  defaults come from `SettingsService::DEFAULTS`, only deviations are stored
  (such as the country profile on the first start);
- finally `meta.json` with `schema_version` and `created_at`.

Up to v1.9.1 an empty directory went through the migration, and a fresh
installation had not a single meter.

---

## 4. Trying it locally and debugging

```bash
# always start the server with router.php — without it PHP also serves data/
php -S 127.0.0.1:8080 router.php

# against a copy of the sample data, without touching your own
cp -R demo-data /tmp/etdata && ET_DATA_DIR=/tmp/etdata php -S 127.0.0.1:8080 router.php

# one endpoint via curl
curl -s http://127.0.0.1:8080/api.php/api/diagnostics
```

- `ET_DEBUG=1` adds file, line and exception type to error responses — only
  briefly, never in open operation.
- `ET_LOG_LEVEL=debug` additionally writes one log entry per request (JSON
  lines; target via `ET_LOG_DEST`).
- A `500` names an error ID; the same one is in the log.
- Tests and their harnesses: [Tests](tests.md).

---

[← Compendium index](../README.md)
