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
| since v3.5.0 a **new period** on a meter with consumption per period, if it was due — like a reading; a CSV import records once, with the newest period | “Read the meter” |
| a **delivery** of heating oil or pellets, if the stock was low before | “Plan a delivery” |
| a **service-charge statement** for the period that has ended (tenants) | “Service-charge statement due” |
| since v3.5.0 a **supplier’s bill** covering a billing period that has ended ([section 9](#9-bill-expected-v350)) | “Bill expected” |

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
| Bill expected *(v3.5.0)* | yes — means “arrived without being recorded”; whoever records the bill has nothing to tick off |
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
- Since v3.5.0 a done reminder shows **who was responsible** (a snapshot,
  [section 8](#8-responsible-for-reminders-v350)), and a **changed
  service-charge statement** appears with the badge “changed” — as proof only,
  it clears nothing. If only the change closes the open period (for example a
  corrected end), it counts as clearing it, like recording it, never twice.
- **As CSV** *(v3.5.0)* downloads the history with the chosen filters as a
  spreadsheet — for example as proof for a landlord or property manager
  ([section 10](#10-history-as-csv-v350)).

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
GET    /api/activity.csv           (v3.5.0) the same filters, ?format=1|local&lang=
GET    /api/people                 (v3.5.0) people for “Responsible”: id and name only
```

`POST /api/reminders/{id}/done` and `POST /api/recommendations/{id}/dismiss`
also return `activity_id`. Since v3.5.0 `POST`/`PATCH /api/reminders…` accept
`assignee` and `assignee_id`. Fields, status codes and stability:
[API reference](../referenz/api.md#done-with-history-v340); the pot
`activity`: [Data model](../referenz/datenmodell.md).

---

## 8. Responsible for reminders *(v3.5.0)*

A reminder under **Reminders & tips → Reminders & maintenance** can have a
responsible person — who books the boiler service, who checks the smoke
alarm. **Reminders only**: meters and contracts on purpose not. **Nobody is
notified**; the [calendar](kalender.md) and Home Assistant are there for that.

**Entering it:** the **“Responsible”** field in the reminder dialog. With
sign-in switched on it suggests the people of the household
([Users in the household](benutzer.md)); picking one of them links the person
— if an admin renames them, the reminder shows the new name. Any other name
works too (free text, up to 60 characters), for example “Grandma” or the
caretaker. Empty means: nobody.

**Display:** as a name chip in the reminder list, under “To do” on the
overview, in the agenda (Home Assistant) and in the calendar as a line
“Responsible: …” in the description.

**Filter:** in the reminders and under “To do”, as soon as someone is
responsible, the choice **All · My tasks · Unassigned · names** appears. The
device remembers the choice. With sign-in “My tasks” is the signed-in person —
by link or by the same name, ignoring upper/lower case. **Without sign-in**
the app asks **“I am …”** next to it and remembers the answer on this device.
Under “To do” the filter also hides notices without anyone responsible
(readings, contracts); a line below says how many.

**Ticking off:** a recurring reminder keeps its responsible person. The
history entry names who was responsible — as a snapshot.

**Deleting a person:** the link disappears from their reminders, the name
stays as a free name. If the admin chooses “former member” when deleting
([section 5](#5-retention-clearing-privacy)), the reminders and the history
show **“former member”** instead of the name; the filter counts them as
“Unassigned” until someone new is entered.

---

## 9. Bill expected *(v3.5.0)*

When a billing period of a contract ends and no supplier’s bill is recorded
for it (**Costs → Check a bill → Record the supplier’s bill**), “To do” shows
**“Bill expected: Electricity (supplier) for 01/01/2025 – 31/12/2025”**. The
**“Record bill”** button opens the bill check with this period and the
recording section unfolded.

| Case | expected period |
|---|---|
| running (also renewed) contract | the last completed period after the billing anchor of the utility (Settings → Utilities, default 1 January), from the contract start |
| ended contract | **final bill** from the last anchor date to the contract end; a renewed contract ends the day before the follow-on contract |
| follow-on contract with the **same supplier** without a gap | no final bill — supply does not end |

**Deadline:**

| Utility | statutory deadline | in the app |
|---|---|---|
| Electricity, gas (Germany) | bill no later than **six weeks** after the end of the billing period, final bill six weeks after supply ends (§ 40c(2) EnWG); the billing period is at most one year (§ 40b(1) EnWG) | after the end + 42 days **“overdue — statutory deadline passed, ask the supplier”**, red under “To do” |
| District heating | none — billing “at least once a year” (§ 4(3) FFVAV, via § 24(1) AVBFernwärmeV) | “expected” only, never “overdue” |
| Water | none (§ 24(1) AVBWasserV; municipal charges under state law, § 35(1) AVBWasserV) | “expected” only, never “overdue” |
| other countries | not stored in the app | “expected” only |

The app does not model monthly billing (deadline three weeks, § 40c(2)
EnWG). Whether a weekend or public holiday moves the deadline is not
calculated — it simply takes the end plus 42 days. The deadline is a hint, not
legal advice.

**Never expected:** heating oil and pellets (one bill per delivery), PV feed-in
(a credit note instead of a bill), offers (shadow contracts), meters out of
service.

**Matching bill:** on the meter, on a member of the group or on the group;
without or with exactly this contract; the bill’s end may differ by up to
**31 days** from the end of the period — suppliers rarely read on the anchor
date exactly.

**How long:** the notice stays **up to 120 days after the end of the
period** — the statutory deadline of 42 days plus a good eleven weeks of
leeway. After that it disappears without a history entry. This way, anyone who
has not recorded supplier’s bills so far does not see an old “overdue” for
every contract after the update, although the bill arrived long ago.

**No notice** for periods that ended more than 120 days ago (not retroactively
for old years), that end before the meter’s first reading or before a
[baseline date](../verstehen/00-overview.md#effect-of-a-measure-baseline-date).
If a contract started less than two months before the anchor date, the app
does not expect a separate bill for that stretch: whether there is one or it
appears in the next annual bill is up to the supplier.

**Clearing:** with the recorded bill the notice goes away by itself; the
history shows “automatic” with the reason “Bill up to … recorded”. Deleted or
changed to another period, it is open again. If you do not want to record the
bill, tick it off (“arrived without being recorded”) — undo as usual.

---

## 10. History as CSV *(v3.5.0)*

Under **Reminders & tips → Done**, **“As CSV”** downloads the history as a
spreadsheet — with exactly the chosen filters (person, period, area).
Columns: **date, action, title, by, reason, note**. Title, action and reason
are in the language of the interface, date and separators as in the other CSV
exports ([data export](../referenz/api.md#csv-formats-v310)). Struck-through
(undone) entries are not included. Free text starting with `=`, `+`, `-` or
`@` gets an apostrophe in front so Excel does not run it as a formula.

---

[← Compendium index](../README.md) · [Deadlines and dates in your calendar](kalender.md)
