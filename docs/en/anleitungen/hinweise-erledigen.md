# Ticking off notices and the history

[Deutsch](../../anleitungen/hinweise-erledigen.md) · **English**

[← Compendium index](../README.md)

Since **v3.4.0** a notice can be marked as done — by hand, or by itself as soon
as the data shows it. A history records **what** was done, **when** and **by
whom**: family, flatmates, caretaker. Undo strikes an entry through instead of
deleting it.

---

## 1. What clears itself

The truth comes from the data. Everything here also holds after a backup
import, in the demo and with readings from Home Assistant — nobody has to tick
anything off.

| You record … | … then this is done |
|---|---|
| a **follow-on contract without a gap** — same meter or same meter group, not an offer (shadow contract), starting no later than the day after the end | cancellation deadline, contract end, end of the price guarantee and price increase of the previous contract. The recommendation “Cancel by …”, the notice in the analysis and “Cancel by …” under Contracts & payments go away; that page shows “Follow-on contract … from …” instead |
| a **meter reading**, if it was due (last reading longer ago than set) | “Read the meter” |
| a **delivery** of heating oil or pellets, if the stock was low before | “Plan a delivery” |
| a **service-charge statement** for the period that has ended (tenants) | “Service-charge statement due” |

**Follow-on contract with a gap.** If the new contract starts later, the
reminder stays — and names the gap: “The follow-on contract … only starts on
… – 1 day without a contract in between.”

**Contracts that renew or have no end** can be cancelled at any time. Up to
v3.3 they produced a new “last day to cancel” in the calendar every day — no
longer: they have no dated cancellation deadline, neither under “To do” nor in
the calendar or in Home Assistant. Switch planning (“Tariff switch”) still
works with “can be cancelled from today”.

A reading that arrives from Home Assistant every day does not add an entry every
day — only when the notice was open.

---

## 2. Ticking off by hand

**Where:** on the **overview under “To do”** (tick ✓ to the right of the row)
and under **Reminders & tips → Reminders & maintenance** (✓ in the row).

A small dialog asks for:

- **Done on** — today by default; an earlier day works, a later one does not.
- **Note** (optional) — for example “cancelled by registered letter”.
- **Receipt** (optional) — photo or PDF, for example the cancellation
  confirmation.
- For a cancellation deadline also **“Cancelled – the contract does not
  renew”**: this sets “renews without cancellation” on the contract to no.

The row then disappears; the message at the bottom offers **Undo**.

| Notice | tick off? |
|---|---|
| Reminder (maintenance, calibration …) | yes — the reminder moves to its next due date as before |
| Cancellation deadline, contract end, price increase | yes |
| Plan a delivery (heating oil, pellets) | yes — for example “ordered”; done until the next delivery |
| Objections to the service-charge statement, claim CO₂ costs | yes |
| Read the meter | no — a meter reading clears it |
| Service-charge statement due | no — the recorded statement clears it |

**A tick applies to this one occurrence.** For a contract that is its end: if
the contract end changes, the notice is open again — on purpose, it is a new
deadline. For a tank the last delivery counts, for a reminder its due date, for
the tenancy deadlines their date.

**Hiding recommendations** belongs here too: “Hide” is recorded in the history
as well. A recommendation with a deadline (cancellation, contract end, price
increase) stays hidden at least until that day — the button then says “Hide
until …”. Up to v3.3 “Cancel by 30/11” came back on 11/11.

---

## 3. The history: Reminders & tips → Done

The **“Done”** tab under Reminders & tips (from the “Experienced” level) shows everything
that was done, by month, newest first:

- **Filters:** person (only if people appear in the history), period (last 3 or
  12 months, this or last year) and area (reminders, contracts, meter
  readings, tank and store, tenancy, recommendations).
- **Automatic entries** carry the badge “automatic” and their reason —
  “Follow-on contract Neuwerk from 01/01/2027”, “Meter reading of 12/10/2026
  recorded”.
- **Undo** strikes the entry through and says when and by whom. For a reminder
  it restores the due date and “Last done”, for a cancellation “renews without
  cancellation”, for a recommendation it shows it again — as long as nobody
  has changed it differently in the meantime.
- A **note** (✎) can be added and changed later.

**On the object:** under Costs & contracts → Contracts & payments a
contract with entries has the button **“History (n)”**. Under Reminders &
maintenance every reminder shows “Last done on … by …”.

---

## 4. Who appears in the history

| How the app is used | in the history |
|---|---|
| without sign-in | nobody — only the day and the deed |
| with sign-in, as a person | the person’s name |
| with an API key | “API key …” with its name |
| Home Assistant (ingest), evcc | “Home Assistant” or “evcc” |
| detected from the data, without a person acting | “automatically” with a reason |

People only exist with sign-in switched on
([Users in the household](benutzer.md)). Rights:

| | Admin | Member | Read key |
|---|---|---|---|
| tick off, undo, note | ✓ | ✓ | — |
| view the history | ✓ | ✓ | ✓ |
| clear the history, set retention | ✓ | — | — |

---

## 5. Retention, clearing, privacy

**Retention:** 60 months (five years), adjustable under Reminders & tips → Done →
**“Manage history”** (admins only, setting `activity_keep_months`, `0` =
unlimited). Older entries drop out with the next entry.

**Clearing:** in the same window — all entries done before a day, or all of
them without a date. Deleted is deleted; a snapshot under Settings → Data gives
you a way back.

**Names in the backup.** The history is part of the backup (pot `activity`).
The names are in it **as a snapshot** — whoever passes a backup on passes them
on too. The list of people itself (names, roles, passwords) stays in
`auth.json` and out of the backup. A backup without a history (from a version
before 3.4) leaves the existing history unchanged.

**Deleting a person:** under Settings → Access → “Users and roles”,
“Delete person” asks whether to replace the name in the history with
**“former member”**. The default is no — the history stays as it is.

The sample households and the public demo come with an empty history; loading
a sample household clears it.

---

## 6. Calendar and Home Assistant

- **Calendar:** a done event stays with the same UID, gets “✓ ” in front of
  its title and loses the advance alert ([Calendar](kalender.md)).
- **Home Assistant** (`/api/summary`): `agenda.due` and `agenda.overdue` only
  count open items, `agenda.next` skips done ones; `contract.cancel_by` is
  `null` when a follow-on contract continues without a gap or the contract can
  be cancelled at any time. The shape of the response stays the same.

---

## 7. Via the API

```text
GET    /api/agenda                 per event occurrence, can_mark_done, done
POST   /api/agenda/done            {uid, occurrence?, done_on?, note?, attachment_id?, cancelled?}
GET    /api/activity               ?from&to&by&kind&ref&limit — newest first
POST   /api/activity/{id}/undo     undo (strikes through, restores)
PATCH  /api/activity/{id}          {note}
DELETE /api/activity               ?before=YYYY-MM-DD — admins only
```

`POST /api/reminders/{id}/done` and `POST /api/recommendations/{id}/dismiss`
also return `activity_id`. Fields, status codes and stability:
[API reference](../referenz/api.md#done-with-history-v340); the pot
`activity`: [Data model](../referenz/datenmodell.md).

---

[← Compendium index](../README.md) · [Deadlines and dates in your calendar](kalender.md)
