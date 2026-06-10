# Meter-reading capture (F1004)

**English** · [Deutsch](../../functional/11-zaehlerstaende.md)

> Applies to **gas, electricity, water, district heating** — the utilities with
> the cumulative meter-reading model. **Heating oil** and **pellets** are excluded:
> they record consumption via deliveries, not via readings — their own data model
> with its own UI (see [Heating oil](05-heizoel.md), [Pellets](06-pellets.md)).

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
- **Date** — default today, overridable per row
- **Estimated** — a toggle that marks the reading as an estimate (maps to the
  existing `is_estimated` flag of the reading schema)
- **Note** — expandable on click, optional, max. 200 characters

## Saving

A single sticky button at the bottom. On click:

1. Iterates over all cards,
2. skips empty ones ("New reading" not filled),
3. POSTs per card against `/api/utility/{u}/readings`,
4. shows per card ✓ (saved) or ✗ (error) as a status indicator,
5. summarises at the end via a toast ("3 saved · 1 empty" or "2 saved, 1 failed").

A faulty card does **not** block the others — robust against partial failures.
After a successful save, the "last reading" in the card is updated so that a
second click validates against the new baseline.

## Validation

- **Numeric input** — only numbers, comma or dot as the decimal separator.
- **Backward meter reading** — if the new value is smaller than the last known
  one, the card shows an orange note ("with a meter swap etc. this is ok"). It is
  **not hard-blocked** — a meter swap is a real case, and the backend checks the
  device history independently.
- **Empty input** — the card is silently skipped on save, not marked as an error.

## Mobile first

The view is built from the ground up for iPhone portrait:

- cards fill the full width, one per screen row
- input fields with a min. 48 px touch-target height (Apple-HIG-compliant)
- date + meter single-column under one another below 600 px width
- a sticky save bar with `env(safe-area-inset-bottom)` for the home-indicator area
  of newer iPhones
- `inputmode="decimal"` opens the number keypad without letters

On desktop and tablet, the layout is expanded into two columns (meter left, date
right) and centred at a max. 720 px width — high readability, no endless scanning
across the full screen width.

## Architecture

- **Backend:** a single aggregate endpoint `GET /api/readings-overview` that
  delivers all active cumulative meters plus each one's last real reading in one
  round trip. On opening the view: one HTTP call, then pure client-side rendering.
- **Saving:** reuses the existing route `POST /api/utility/{u}/readings` — no new
  schema, no batch endpoint, no migration. A faulty row affects only that one row.
- **Status:** the existing `is_estimated` flag in the reading schema carries the
  status information. No new field, no data-model change.
- **Scope gating:** the single source of truth is `Utilities::isCumulative()` in
  the backend, mirrored in the frontend.

## What is deliberately not in v1.6.0

- **Photo capture** — on the roadmap. It brings binary storage, thumbnails,
  possibly EXIF adoption of the capture date and would be a standalone major effort.
- **Offline mode / buffering on connection loss** — currently the card loses its
  inputs if the save fails and the browser is reloaded. An implementation with
  IndexedDB is planned in the roadmap.
- **OCR / automatic digit recognition** — thematically belongs to photo capture;
  together in a later iteration.
- **Bulk saving as a single atomic endpoint** — the current sequential writing has
  the advantage that partial failures are located precisely. A batch endpoint would
  give up this advantage; it will only come if real performance measurements
  justify it.

[← Glossary](09-glossar.md) · [Fundamentals](00-overview.md)
