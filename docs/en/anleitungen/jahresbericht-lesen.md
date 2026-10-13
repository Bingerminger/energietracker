# Reading the annual report

**English** · [Deutsch](../../anleitungen/jahresbericht-lesen.md)

[← Compendium index](../README.md)

The annual report sums up a calendar year so that it can be read without any
technical background — by you, by relatives who help run the household, or by
the caretaker. What matters comes first: large figures, four status lights and
the three most important tasks. Then what paid off, what needs doing,
deadlines, money, one page per utility and topic pages that only appear when
there is data for them. Since **v3.4.0**.

PDF and print view are built from **the same data model**: both show the same
sentences and figures. The print view is the accessible version (real
headings, tables, every chart with its message as text).

---

## 1. Getting the report

**Insights → Annual report**, choose the year (the previous year is
preselected):

- **Open print view** shows the report as a page in the app. “Print / save as
  PDF” puts it on paper or into a file, on the iPhone via Share → Print. The
  checkbox **“With appendix ‘Figures in detail’”** adds the monthly tables.
- **Open in browser** and **Download annual report** deliver the finished PDF
  file. It is created in the language of this device.

## 2. Structure

| Page | Content | Appears when |
|---|---|---|
| **At a glance** | energy costs, energy use (heating weather-adjusted), CO₂ and, with a solar system, its benefit — each against the previous year, with a small line over three years; four status lights; the three most important tasks | always |
| **What paid off** | successes with an amount: less heating, a measure that works (baseline date), solar system, battery, solar power in the car, module 3, lower costs; below that “Good to know” | always; without a success, the comparison with the previous year |
| **What needs doing** | tasks by priority, each with reason, amount, deadline and a hint | always; “Nothing to do” only when no appointments are due either |
| **Deadlines and appointments** | the next twelve months and anything overdue; a timeline per current contract | appointments or contracts exist |
| **Money** | costs per utility and per month against the previous year, price and quantity effect, advance payments against costs per contract, forecast for the next twelve months | there are costs |
| **Per utility** | annual value against the previous year, monthly bars; heating: “Colder, or more used?” and efficiency scale; electricity: what for (household, heat pump, wallbox); water: litres per person and day; heating oil and pellets: stock and range | consumption in the year |
| **Solar power** | energy flow, self-sufficiency, self-consumption, generation per month, payback, battery | solar system |
| **Heat pump** | performance factor per month against the field test average | heat meter with a linked electricity meter |
| **Charging the electric car** | charging power per month, of which solar, costs according to the charging record | wallbox meter |
| **Cheap hours** | share in cheap quarter hours, profile factor, module 3, value of the battery | quarter-hour values and market prices |
| **Rented home: service charges** | prepayment against expected costs, statement of the year, CO₂ split with deadline | tenancy |
| **Short glossary** | only the terms that appear in the report, one sentence each; sources | always |
| **Figures in detail** | one monthly table per utility | on request only (appendix) |

Anything that does not belong to the report year — bill, deadlines, tasks,
tank stock — sits in a yellow box **“As of today (date)”**.

## 3. The four status lights

Each light carries a symbol **and** a word; colour alone says nothing (also
readable in black-and-white print).

| Light | Green | Yellow | Red |
|---|---|---|---|
| **Costs** against the previous year | at most +3% | at most +10% | more than +10% |
| **Consumption** against the previous year (heating weather-adjusted) | not up | at most +5% | more than +5% |
| **Bill** (as of today) | credit or less than one monthly advance payment due | up to two advance payments | more |
| **Deadlines** (as of today) | nothing in the next 60 days | deadline within 60 days | 14 days or less, or overdue |

Below the cost light, the report always says how much is due to changed prices
and how much to the quantity used.

## 4. Tasks: priority, amount, deadline

Each task gives its reason with the household’s own figures, what it brings in
or avoids, and by when. **Priority**:

- **1** — deadline within 14 days or more than €200
- **2** — deadline within 60 days or more than €50
- **3** — everything else

The rules behind them:

| Task | When | Amount |
|---|---|---|
| Advance payment adjustment | expected additional payment or credit of at least €50 or one monthly advance payment | the expected sum at the bill; plus a suitable advance payment — or, if the payments made already cover the costs, only “can go down” without a figure |
| Notice or switch | notice deadline within 90 days (not for water) | with a saved offer, the difference from the second year, without bonus; otherwise the annual consumption for a comparison website |
| Price increase | announced higher prices | (new − old unit price) × expected consumption + difference in standing charge × 12 |
| Heating use up | weather-adjusted at least 5% more than the previous year | extra consumption × own unit price |
| Unusual month | consumption clearly above what the month suggested (only months of the report year, at most three) | extra consumption × own unit price |
| Efficiency class E to H | class on the country’s scale | only as a worked example: 10% less heating energy |
| Heat pump below the field test average | seasonal performance factor more than 0.3 below the air/water average | worked example with the average |
| Using more of the solar power | less than 30% of the solar power used at home | per 100 kWh: grid price − feed-in tariff |
| High battery losses | efficiency below 85% | losses × grid price |
| Expensive hours | a heat pump or wallbox tended to run in expensive hours | extra cost against the market average, market share only |
| Service charge prepayment | expected additional payment from €50 | the expected sum; a suitable prepayment, to agree with the landlord |
| CO₂ costs from the landlord | refund with an own gas boiler | the landlord’s share, with deadline |
| Appointments, readings, service charge deadlines | due within 60 days or overdue | — |
| Bill check | supplier bill differs from the own calculation by more than €10 | the difference |

**Amounts** come exclusively from the household’s own meter readings and
contract prices, never from market assumptions; without a price there is no
amount. They are rounded to whole euros and carry “about” plus their kind:
**measured** (from the report year), **projected** (up to the bill) or
**worked example**. Recommendations hidden in the app stay hidden in the report.

A legal note appears only where the app already gives it: for a price
increase, the special right to terminate under § 41(5) EnWG (Germany only).

## 5. Reading the charts

- **Grey** is always the previous year, **colour** the report year — in the
  utility’s colour, darkened for print so that it stands out from the paper.
- **Hatched** bars contain estimated days, **dashed** lines are forecasts up
  to the bill.
- In the **balance trend**, an additional payment lies above and a credit below
  the zero line.
- The **energy flow** shows sun, grid, house and battery; the thicker the
  arrow, the more kilowatt hours.
- Below each chart, its message is written as a sentence.

## 6. Partial report and language via link

The PDF address accepts three additions (also combined):

| Addition | Effect |
|---|---|
| `?lang=en` | language of the report (`de`, `en`, `fr`, `it`, `es`, `pt`, `nl`) — for relatives, say |
| `?utilities=gas,wasser` | only these utilities, for the caretaker, say |
| `?detail=1` | with appendix “Figures in detail” |

Example: `…/api.php/api/reports/yearly.pdf?year=2025&utilities=wasser&lang=en`.
Details in the [API reference](../referenz/api.md#get-apireportsyearlypdfyearyyyy).

## 7. Sending it via Home Assistant

Energietracker does not send anything itself. To receive the report
automatically every year, let Home Assistant fetch and pass it on.

**1. API key** (only with sign-in enabled): Settings → Access → “API keys for
scripts”, scope **“Read”**. Without sign-in, leave out the `Authorization`
header.

**2. Command to fetch it** in `secrets.yaml` — the whole command, so the key is
not in `configuration.yaml`:

```yaml
energietracker_report_cmd: "curl -sf -H 'Authorization: Bearer etk_…' -o /config/www/energietracker-annual-report.pdf 'http://YOUR-ENERGIETRACKER-IP:8080/api.php/api/reports/yearly.pdf?lang=en'"
```

**3. In `configuration.yaml`:**

```yaml
shell_command:
  energietracker_annual_report: !secret energietracker_report_cmd
```

**4. Automation** — fetch on 15 January at 9:00 and send it as a document via
the Telegram bot (the “Telegram bot” integration must be set up, and
`/config/www` must be listed under `allowlist_external_dirs`):

```yaml
automation:
  - alias: "Energietracker – send annual report"
    triggers:
      - trigger: time
        at: "09:00:00"
    conditions:
      - condition: template
        value_template: "{{ now().month == 1 and now().day == 15 }}"
    actions:
      - action: shell_command.energietracker_annual_report
      - action: telegram_bot.send_document
        data:
          file: /config/www/energietracker-annual-report.pdf
          caption: "Annual report {{ now().year - 1 }}"
```

Without Telegram, the file is available after fetching at
`http://YOUR-HOME-ASSISTANT:8123/local/energietracker-annual-report.pdf` — a
link to it can go out with any notification. Note: everything under
`/config/www` can be reached without signing in.

---

See also: [Features](../einstieg/funktionen.md) ·
[Views: annual report](../referenz/ansichten.md#annual-report-under-insights-since-v2110) ·
[Connecting Home Assistant](home-assistant.md)
