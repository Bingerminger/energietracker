# Measuring with large amounts of data

[Deutsch](../../entwicklung/messung-grosse-daten.md) · **English**

[← Tests](tests.md) · [Compendium index](../README.md)

How long does the app take when a household reads its meters every day for ten
or twenty years — via Home Assistant, say, or a CSV import of long histories?
The measurement answers that with an invented household and sets the point
from which N1006 (caching and storage rework, see the roadmap) becomes
necessary. As of v3.7.0; print view and tank ledger: v3.13.0
([below](#v3130-print-view-and-tank-ledger)).

---

## Tools

| Tool | Purpose |
|---|---|
| `tools/large-household.mjs` | builds the invented household into an empty data directory |
| `tools/measure-large-household.mjs` | builds households of different sizes, starts a server for each, measures the endpoints and, with `--render`, the views |
| `tests/large-data.test.mjs` | guards the limits in the CI (see below) |

```bash
node tools/large-household.mjs --out /tmp/et-large              # 10 years, 2 years of quarter hours
ET_DATA_DIR=/tmp/et-large php -S 127.0.0.1:8930 router.php      # to look at it
NODE_PATH=<node_modules with jsdom> node tools/measure-large-household.mjs \
  --years 5,10,20 --runs 5 --demo --render                      # tables below
node tools/large-household.mjs --out /tmp/et-tank --tank --tank-years 75   # plus a heating oil tank over 75 years
node tools/measure-large-household.mjs --years 10 --tank        # also measures the heating oil views
```

**The household** (all invented, fixed seed): gas, electricity, water, PV
generation and PV feed-in with one meter each and a reading on **every day** up
to yesterday, daily temperatures for the same period, one contract per calendar
year for gas, electricity and water, a feed-in tariff over 20 years and, for
the last two full calendar years, quarter hours for electricity, PV generation
and PV feed-in (sum per day = reading) plus market prices. Ten years are
18,260 readings and 280,704 quarter-hour values. Not in `demo-data/`: the demo
data comes from `tools/build-personas.mjs`. *(v3.13.0)* `--tank` adds a
heating oil tank (6,000 L, filled “to full” every 1 July, a dipstick level every
quarter), `--tank-years` over more years than the rest; the temperatures before
come from a series of their own, so the household without a tank stays the same
file by file.

**Measured** on the development machine (Apple M1 Max, PHP 8.5 with the
built-in server `php -S`, Node 26): per endpoint the median of five requests
after one warm-up, response read completely.

## Result: endpoint response times

Per view the requests it waits for. The last column is the state before the
changes of this release (v3.6.0, ten years).

| View | Request | Demo data | 5 years | 10 years | 20 years | 10 years (v3.6.0) |
|---|---|---:|---:|---:|---:|---:|
| Overview | Summary | 67 ms | 199 ms | 403 ms | 876 ms | 1.13 s |
| Overview | Dates (90 days) | 53 ms | 170 ms | 340 ms | 771 ms | 873 ms |
| Overview | Benchmark | 29 ms | 84 ms | 174 ms | 361 ms | 324 ms |
| Utility | Gas: consumption per month | 6 ms | 36 ms | 73 ms | 152 ms | 150 ms |
| Utility | Gas: contract status/balance | 7 ms | 41 ms | 83 ms | 181 ms | 260 ms |
| Utility | Electricity: consumption per month | 5 ms | 33 ms | 66 ms | 139 ms | 143 ms |
| Utility | Electricity: readings (list) | 2 ms | 5 ms | 9 ms | 17 ms | 9 ms |
| Utility | PV: self-consumption | 8 ms | 83 ms | 166 ms | 353 ms | 253 ms |
| Insights | Efficiency | 26 ms | 35 ms | 68 ms | 146 ms | 149 ms |
| Billing periods | Gas: periods per meter | 6 ms | 76 ms | 161 ms | 374 ms | 761 ms |
| Billing periods | Gas: periods of the utility | 6 ms | 76 ms | 162 ms | 370 ms | 760 ms |
| Annual report | Model (print view) | 94 ms | 215 ms | 417 ms | 901 ms | 1.59 s |
| Annual report | Model with appendix | 114 ms | 410 ms | 777 ms | 1.70 s | 3.41 s |
| Flexibility | Analysis | 12 ms | 77 ms | 75 ms | 76 ms | 71 ms |
| Flexibility | Overview tile | 7 ms | 59 ms | 57 ms | 58 ms | 53 ms |
| Forecast workshop | Gas: forecast | 6 ms | 36 ms | 71 ms | 147 ms | 147 ms |
| Forecast workshop | Gas: scenario “Cold winter” | 8 ms | 80 ms | 160 ms | 369 ms | 761 ms |

With twenty years, v3.6.0 took 3.91 s for the summary, 2.75 s for the billing
periods and 12.2 s for the annual report with appendix. Since v3.7.0 every
request grows **linearly** with the number of readings (twice the data, twice
the time); before, several grew quadratically. The quarter hours do not grow
along: one file per year, and the flexibility analysis covers one year.

## Result: views

Time from the page change until the view stands without “Loading …” — the real
app in JSDOM against the same server, without drawing the charts.

| View | Demo data | 5 years | 10 years | 20 years |
|---|---:|---:|---:|---:|
| Overview | 359 ms | 1.23 s | 2.36 s | 4.83 s |
| Utility (gas) | 77 ms | 251 ms | 452 ms | 926 ms |
| Utility (electricity) | 51 ms | 204 ms | 369 ms | 764 ms |
| Insights | 38 ms | 104 ms | 191 ms | 386 ms |
| Forecast workshop | 48 ms | 148 ms | 266 ms | 534 ms |
| Flexibility | 20 ms | 111 ms | 112 ms | 107 ms |
| Annual report (print view) | 159 ms | 429 ms | 822 ms | 1.67 s |
| Capture meter readings | 21 ms | 29 ms | 46 ms | 76 ms |

The overview makes about ten requests. `php -S` answers them one after the
other; behind nginx with php-fpm (Docker image) they run in parallel, and the
overview then stands roughly after the slowest request.

## What v3.7.0 fixed

Four small changes in `ConsumptionService` and `ContractService`, without
changing any number (the results of all meters, contract status, bill checks
and billing periods are byte-identical before and after, measured on the demo
data and on the large household):

1. **Cut days once per meter.** The monthly calculation splits every reading
   interval at contract boundaries and price dates (`segmentsBetween`) and
   rebuilt the list of all dates of all contracts each time — with daily
   readings and one contract per year that is readings × contracts. Now it
   fetches the list once (`ContractService::cutDates()`, `segmentsWithin()`);
   the bill check per row likewise.
2. **Last reading once per meter.** The contract status asks for every
   contract how far the measurements go and read all readings for it each
   time.
3. **Basis of the bill check once per meter.** The billing periods check every
   period (with the appendix every month); each check rebuilt all intervals and
   searched the daily rate of every row from the start.
4. **Daily temperatures once per request** instead of per meter and contract.

All of it only applies within one request (like the existing in-request cache
of `forMeter()`), until the next write.

## v3.13.0: print view and tank ledger

In the CI the response time of “Model (print view)” was once 4,328 ms, above
the limit of 4,170 ms (ten times the measurement). The limit stays; the print
view got faster. In addition the tank ledger (heating oil, pellets) computes
its daily series once per request. All values stay the same: old against new
byte for byte on the demo households, the messy test household and a household
with a tank, on several reference dates.

1. **Last real reading once per meter and reference date.** Since v3.12.0 the
   contract status asks, for every contract, up to when it is really measured,
   and read all readings again each time. With ten years of daily values and
   one contract per year that was more than half of the print view and almost
   half of the overview. Now like the last reading: once per request.
2. **The tank ledger in columns, once per tank and reference date.** It no
   longer keeps one row per day but one list per quantity (consumption, stock,
   average price). The request reads the temperatures once, not per tank. The
   date of a day is a text of exactly its ten characters:
   `DateTimeImmutable::format()` returns about 250 bytes per text under
   PHP 8.5, which was 7 MB per tank ledger over 75 years.
3. **Only the requested period.** Overview, recommendations and agenda need
   today's stock (`DeliveryService::stockLatest()`), the annual report the days
   from the report year on. Up to v3.12 they built the daily rows of all years
   for it. The tank ledger itself still starts at the start: stock, price and
   rate depend on all days before.

The rules are held by `TankSeriesTest` (once per tank and reference date, only
the stock, a period as a slice, about 130 bytes per day) and
`LastMeasuredOnceTest`.

**Measured** alternately old and new on the same machine, a server of its own
per round, median of three rounds of three requests each. Other processes ran
during the measurement (load 4 to 10 on 10 cores); the numbers are therefore
higher than the “10 years” column above and only comparable with each other.
The column above and the limits of the test stay unchanged.

| View · request (10 years) | v3.12 | v3.13 |
|---|---:|---:|
| Overview · Summary | 2.27 s | 1.11 s |
| Overview · Dates (90 days) | 1.87 s | 1.03 s |
| Utility · Gas: contract status/balance | 295 ms | 173 ms |
| Annual report · Model (print view) | 3.14 s | 1.36 s |
| Annual report · Model with appendix | 3.63 s | 1.86 s |
| Forecast workshop · Gas: scenario “Cold winter” | 390 ms | 206 ms |

The other eleven requests of the table above are equally fast old and new
(differences up to 5 %, within the noise of the measurement). With a heating
oil tank over ten years (`--tank`): consumption per month 30 → 23 ms, stock
13 → 12 ms, forecast 36 → 24 ms.

**A tank over 75 years** (`--tank --tank-years 75`, otherwise ten years):

| Request | v3.12 | v3.13 |
|---|---:|---:|
| Overview · Summary | 2.57 s · 134 MB | 1.32 s · 112 MB |
| Overview · Dates (90 days) | 2.07 s · 134 MB | 1.25 s · 112 MB |
| Annual report · Model (print view) | 3.28 s · 146 MB | 1.57 s · 126 MB |
| Annual report · Model with appendix | HTTP 500 · 154 MB | 2.03 s · 132 MB |
| Heating oil · Consumption per month | 186 ms · 88 MB | 145 ms · 54 MB |
| Heating oil · Stock (tank ledger) | 76 ms · 64 MB | 72 ms · 52 MB |

Time at the server as above; memory as the peak per request in a PHP process of
its own without a limit. With the server's limit (`memory_limit = 128M`), under
v3.12 the appendix failed at the server and, in a process of its own, the print
view as well (error 500); under v3.13 both get through, the appendix just at the
limit. A tank ledger over 75 years now holds about 3 MB instead of 18 MB.

**What remains:** the showcase with heating oil and pellets, carried forward to
2099 (sceptic run on v3.12.0), still needs more than 128 MB for overview,
agenda, annual report and calendar: 134 to 142 MB instead of 164 to 176 MB;
only the recommendations fit now (116 MB). The rest is no longer in the tank
but in all meters over 77 years: monthly rows (about 32 MB), temperatures
(15 MB), the degree-day grid of the heating limit (15 MB) and the daily spans
(14 MB). That belongs to N1006.

## What remains — and the threshold for N1006

Open, because there is no small and safe change:

- **Every request reads the files again.** Ten years of daily readings are a
  JSON file of 0.7 to 1 MB per meter; every request reads and decodes it, and
  there is no cache across requests.
- **The overview calculates the same meters several times** — summary, dates,
  benchmark, PV and contract status are separate requests, and each calculates
  the monthly values of all meters on its own. A result cache per meter (key:
  modification time of the files) would share that; that is exactly N1006.
- Home Assistant polls the summary every five minutes
  (`Cache-Control: max-age=300`).

**Threshold:** N1006 becomes necessary as soon as the **summary
(`/api/summary`) takes longer than 300 ms on the development machine**. On a
NAS or a Raspberry Pi expect three to five times that (an assumption from the
computing power, not measured), so 1 to 1.5 s per request and a noticeably
sluggish overview. Since v3.7.0 the threshold lies at around **7.5 years of
daily readings for five meters (about 13,500 readings in the household)**;
under v3.6.0 it was about four years. With monthly readings it is practically
out of reach. Whoever imports many years of daily values is the case N1006 is
for — measure again first with `tools/measure-large-household.mjs`, ideally
with your own number of meters (`--years`).

## The test

`tests/large-data.test.mjs` runs **in the CI**, with generous limits: every
endpoint must stay below **ten times** its value from the “10 years” column
(at least 500 ms). A GitHub runner is slower than the development machine and
fluctuates; a tight limit would be flaky, no limit at all would let a relapse
into quadratic run time through (v3.6.0: up to five times slower with ten
years, up to seven times with twenty). Without any timing it checks that every
request answers with 200 and complete data. Locally,
`ET_PERF_STRICT=1 node tests/large-data.test.mjs` checks against the measured
value plus 50 %. The reference values in the test and the “10 years” column
above must match; the test compares both.

---

[← Tests](tests.md) · [Compendium index](../README.md)
