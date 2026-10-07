# Meter-reading capture (F1004)

**English** · [Deutsch](../../verstehen/11-zaehlerstaende.md)

> Applies to **gas, electricity, water, district heating, PV** and since v3.1.0
> **heat** — the utilities with the cumulative meter-reading model. **Heating
> oil** and **pellets** are excluded: they record consumption via deliveries,
> not via readings — their own data model with its own UI (see
> [Heating oil](05-heizoel.md), [Pellets](06-pellets.md)). Meters with
> **consumption per period** (v3.1.0) are here too, with a card of their own
> ([below](#meters-with-consumption-per-period-v310)).

## Purpose

The previous capture worked per utility: you switched into the gas view, entered
the reading, went back, switched into the electricity view, and so on. When
reading on site with a smartphone — cellar, house connection room, outside at the
garden meter — this is a chain of clicks and waits.

F1004 bundles this process into a single view: all cumulative meters in a compact
card list, each with the last known reading as a reference and an input field for
the new value. A "Save all" button at the bottom sends the filled-in rows
sequentially to the backend; empty cards are skipped, errors made visible isolated
per row.

## Structure of a card

Per meter, the card contains:

- **Label** (meter name, utility, optional location note)
- **Last known reading** (value + date + possibly the tag "estimated")
- **New reading** — a numeric input field; `inputmode="decimal"` opens the number
  keypad directly on iPhone/Android
- **Date** — one date at the top for all cards (default today); per card,
  since v2.12.0, collapsed behind "Other date". A card with a date of its own
  keeps it when the top date changes.
- **Estimated** — a toggle that marks the reading as an estimate (maps to the
  existing `is_estimated` flag of the reading schema)
- **Note** — expandable on click, optional, max. 200 characters
- **Photo** (v3.1.0) — “📷 Photo” takes a picture of the register along as a
  receipt (on a phone the camera opens). The browser scales it down to at most
  1600 pixels and re-encodes it as JPEG; EXIF data including the GPS location
  are dropped along the way. If a text recognition service in the home network
  is set up, the server suggests the reading: “Recognised: … – Use”
  ([Text recognition in the home network](../anleitungen/texterkennung.md)).

## Meters with consumption per period *(v3.1.0)*

If a meter has the recording “Consumption per period” (meter dialog →
“Recording”), you do not enter a reading but the consumption of a month — for
example from the monthly consumption information of the metering service. Its
card shows “Consumption per period” below the name and contains:

- **Last period** — from, to and consumption of the latest entry,
- **Month** — pre-filled with the month after the last period, without a
  period with the previous month,
- **Consumption** in the consumption unit of the utility (kWh, for water m³),
- expandable **“Comparison values from the consumption information”** —
  previous month, same month last year, average user; they are stored and
  shown, but not calculated with,
- **Estimated** and **Note** as for a reading.

The date at the top, photo, text recognition and the plausibility questions do
not apply to this card. It is saved together with the other cards via “Save
all” (`POST /api/utility/{u}/periods` with `month`); “Undo” and the offline
queue apply here too. A month that overlaps an existing period is rejected —
the message appears below the field. How the app spreads a period over the
months: [Heat → consumption per period](15-waerme.md#2-consumption-per-period).

## Saving

A single sticky button at the bottom. On click:

1. Iterates over all cards,
2. skips empty ones ("New reading" not filled),
3. POSTs per card against `/api/utility/{u}/readings`,
4. shows per card ✓ (saved) or ✗ (error) as a status indicator,
5. summarises at the end via a toast ("3 saved · 1 empty" or "2 saved, 1 failed");
   since v2.12.0 with the change per utility ("Gas +5.4 m³") and ten seconds of
   **"Undo"**, which deletes the readings just created. Replaced readings
   (question "Replace") are not reverted by "Undo".

A faulty card does **not** block the others — robust against partial failures.
After a successful save, the "last reading" in the card is updated so that a
second click validates against the new baseline.

**Without a connection (v3.1.0):** if a card fails for lack of a connection or
on the time limit, the reading — photo included — goes into the offline queue
instead of being lost. The card shows ⏳ “Waiting for a connection”, the list
“Not saved yet” appears at the top, and the app sends it by itself as soon as
the server can be reached again. Conflicts on the same day are yours to decide
(“Replace” or “Keep existing”). Details and limits:
[Use on your phone](../einstieg/handy.md#the-not-saved-yet-queue).

## Validation

- **Numeric input** — only numbers, comma or dot as the decimal separator.
- **Empty input** — the card is silently skipped on save, not marked as an error.
- **Error at the field** (v2.12.0) — an unreadable value or a rejection by the
  server appears below the field (`aria-invalid`, `aria-describedby`), not only
  in the title of the ✗.

## Direct link per meter (v2.12.0)

`#/zaehlerstaende?meter=<id>` opens the capture view with that meter's card
marked and in focus. Meant for a home-screen bookmark ("Read the gas meter"), a
shortcut and "To do" on the overview. The meter ID appears in the address bar
of the meters view and in `GET /api/utility/{u}/meters`.

## Plausibility (v2.6.0)

Otherwise a typo travels silently into costs, forecast and efficiency class:
"12345" instead of "1234.5" is a month's consumption the size of a year. So the
capture — the card as well as the reading dialog of the utility view — asks
**before saving**. None of it blocks hard; meter swaps, rollovers and
back-filled readings are legitimate.

| Question | When | Note |
|---|---|---|
| **Jump** | daily consumption since the last reading > **3 ×** the typical one | "That would be 400 kWh a day, usually it is about 8. Typo?" |
| **Decimal separator missing?** | no typical value known yet and the new reading > 10 × the last | only without a comparison value, otherwise the jump check applies |
| **Decrease** | lower than the last reading — unless a new device was installed in between | with a link to the meter swap |
| **Future** | date after today | typo in the year? |
| **Same day** | there is already a reading for that day | button "Replace": the existing reading is updated instead of a second one being created |

The **typical daily consumption** is the median of the last up to ten reading
intervals of the same device (at least two) — robust against a single outlier.
The backend delivers it in `readings-overview` as `typical_per_day`; the utility
view calculates it by the same rule. The notes appear while typing; the question
on save names the meter in its title. Whoever declines in the batch capture keeps
the input; the card shows "Not saved – please check", and a message counts the
held cards.

### After saving: outliers, suspicion, rollover

The consumption calculation checks independently of the capture (CSV import and
Home Assistant as well):

- **Sandwiched outliers** — if the reading drops between two readings of the
  same device, either the earlier one is a spike or the later one a dip. If one
  interpretation fits, that reading is left out of the calculation; if both fit,
  the smoother daily consumption decides. Up to v2.5.3 the calculation counted
  the following interval in full from the wrong reading: a single value of 0
  turned 190 kWh in a month into 50,270 kWh.
- **Suspicion** — a falling value from Home Assistant is stored but marked and
  skipped until confirmed.
- **Decrease** — a falling reading without an identifiable outlier (usually an
  unrecorded meter swap) is reported; the negative interval does not count.
- **Rollover** — if the register digits are maintained on the device (edit meter
  → "Register digits"), 99,998 → 12 is a consumption of 14, not a decrease.

The utility view shows all cases in a notice above the year selection, the table
marks the readings ("CHECK", "IMPLAUSIBLE"); a suspect reading can be confirmed
with ✅. Technical details:
[API reference → `warnings`](../referenz/api.md).

## Many values at once: time series from portals *(v3.1.0)*

Meter entry is built for single readings. If a portal delivers many values at
once — quarter hours from the grid operator, daily values from the inverter,
the heat output of the heat pump —, the button **“Import time series”** under
Consumption → *utility* → ⚙️ Meters reads them:

- A **column mapping** says where date, time and value are, whether the values
  are meter readings or consumption per interval, in which unit (kWh, Wh, MWh)
  and whether the time stamp marks the start or the end of the interval.
- The app condenses into **daily values**: for meter readings the last reading
  of the day as a reading, for consumption values the day’s total — as a
  period (meter with consumption per period) or as a reading, summed up from a
  starting reading.
- Daylight saving time and end stamps at 0:00 count correctly; a preview shows
  days, period and total before anything is written. The browser remembers the
  mapping per meter.

After that the plausibility checks after saving run as for any reading
(outliers, decreases). Step by step:
[Time series from portals](../anleitungen/daten-aus-portalen.md).

## Mobile first

The view is built from the ground up for iPhone portrait:

- cards fill the full width, one per screen row
- input fields with a min. 48 px touch-target height (Apple-HIG-compliant)
- Enter or "Next" jumps to the next meter field, from the last one to "Save
  all" (`enterkeyhint`, v2.12.0)
- a sticky save bar with `env(safe-area-inset-bottom)` for the home-indicator area
  of newer iPhones
- `inputmode="decimal"` opens the number keypad without letters

On desktop and tablet the card stays single-column and is centred at a max.
720 px width — high readability, no endless scanning across the full screen
width. (Up to v2.11 the per-card date sat to the right of the meter field.)

## Architecture

- **Backend:** a single aggregate endpoint `GET /api/readings-overview` that
  delivers all active cumulative meters plus each one's last real reading in one
  round trip. On opening the view: one HTTP call, then pure client-side rendering.
  Since v3.1.0 every row additionally carries `capture`, `last_period` and
  `role`; `capture` decides which card the view shows.
- **Saving:** reuses the existing route `POST /api/utility/{u}/readings` — no
  batch endpoint. A faulty row affects only that one row.
- **No duplicates when sending later (v3.1.0):** every entry sends a
  `client_ref`. If the same identifier arrives a second time for the same
  meter — the response got lost on the way, the queue sends again —, the server
  returns the existing reading with `200` and `duplicate: true` instead of
  creating a second one.
- **Queue (v3.1.0):** `public/js/lib/outbox.js`, stored in the browser's
  IndexedDB (`et-outbox`).
- **Photo (v3.1.0):** `POST /api/attachments?kind=reading_photo` creates the
  receipt, the field `attachment_id` on the reading links it; text recognition
  runs through `POST /api/ocr/reading`
  ([API reference → Receipts](../referenz/api.md#receipts-and-text-recognition-v310)).
- **Status:** the existing `is_estimated` flag in the reading schema carries the
  status information. `client_ref` and `attachment_id` are additive fields;
  existing readings stay as they are.
- **Scope gating:** the single source of truth is `Utilities::isCumulative()` in
  the backend, mirrored in the frontend.

## What is deliberately not included

Photo as a receipt, offline queue and text recognition exist since v3.1.0
(above). Deliberately not:

- **Text recognition through a cloud service** — meter photos do not leave
  the home network. As a text recognition service the app only accepts
  addresses in your own network; there is no switch to lift this
  ([Text recognition in the home network](../anleitungen/texterkennung.md)).
  Without a service of your own, Live Text on the iPhone does the job (“Scan
  Text” in the field).
- **Saving without confirmation** — text recognition only suggests. Only “Use”
  puts the value into the field; it is saved like any other reading, with the
  plausibility check.
- **Reading the meter directly** (optical reading head, smart-meter interface)
  — Home Assistant does that and sends the readings
  ([Connect Home Assistant](../anleitungen/home-assistant.md)). Since v3.1.0
  files from portals are read by the time series import (above); the app does
  not connect to the portals themselves.
- **Bulk saving as a single atomic endpoint** — the current sequential writing has
  the advantage that partial failures are located precisely. A batch endpoint would
  give up this advantage; it will only come if real performance measurements
  justify it.

[← Glossary](09-glossar.md) · [Fundamentals](00-overview.md)
