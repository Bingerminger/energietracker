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
| since v3.9.0 **“Reading is correct”**, **“Only for the bill”** or a **corrected reading** ([section 11](#11-check-a-reading-v390)) | “Check the reading of …”, “Consumption before and after … does not add up” |
| since v3.9.0 **deleting** a reading entered twice | “Reading of … entered twice” |

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
| Check a reading, entered twice *(v3.9.0)* | no: the buttons on the entry settle it ([section 11](#11-check-a-reading-v390)) |
| Note on the baseline date *(v3.9.0)* | yes, with “Not now” on the entry (no dialog, with undo) |

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

Only someone signed in acts as a person
([Users in the household](benutzer.md)). If the person has been deleted since,
their name shows with **“(deleted)”** *(v3.6.0)*. Rights:

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
on too. Passwords, sessions and keys stay in `auth.json` and out of the
backup; the register of people (number, name, role, deletion note) has been
part of it since v3.6.0 (pot `people`). A backup without a history (from a
version before 3.4) leaves the existing history unchanged.

**Deleting a person:** under Settings → Access → “Users and roles”,
“Delete person” asks whether to replace the name in the history with
**“former member”**. The default is no — the history then keeps the name,
since v3.6.0 with **“(deleted)”**
([Users in the household](benutzer.md#deleting-people)).

Since v3.5.0 the sample households and the public demo come with a history of
made-up people; loading a sample household replaces the existing history.

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
GET    /api/users/{id}/handover    (v3.6.0) before deleting: open reminders to whom, completed entries;
                                   (v3.7.0) plus meters, contracts
GET    /api/calendar.ics           (v3.7.0) ?person=<id>: only what is assigned to this person
```

`POST /api/reminders/{id}/done` and `POST /api/recommendations/{id}/dismiss`
also return `activity_id`. Since v3.5.0 `POST`/`PATCH /api/reminders…` accept
`assignee` and `assignee_id`, since v3.7.0 also `utility` and `meter_id`
(reminder on a meter); meters and contracts accept `assignee_id`, agenda
events carry `assignee_source`. Fields, status codes and stability:
[API reference](../referenz/api.md#done-with-history-v340); the pot
`activity`: [Data model](../referenz/datenmodell.md).

---

## 8. Responsible for reminders *(v3.5.0)*

A reminder under **Reminders & tips → Reminders & maintenance** can have a
responsible person — who books the boiler service, who checks the smoke
alarm. Since v3.7.0 meters and contracts too; their deadlines then go to that
person ([below](#meters-and-contracts-v370)). **Nobody is notified**; the
[calendar](kalender.md) and Home Assistant are there for that.

**Entering it:** the **“Responsible”** field in the reminder dialog. It
suggests the people of the household ([Users in the household](benutzer.md))
— since v3.6.0 from the register of people, so also without sign-in (for
example in a sample household); deleted people are not offered. Picking one of
them links the person — if an admin renames them, the reminder shows the new
name; a completed one-off reminder keeps the name it had when done. Any other name
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
Under “To do” the filter also hides notices without anyone responsible (for
example readings and contracts nobody is responsible for); a line below says
how many.

**Ticking off:** a recurring reminder keeps its responsible person. The
history entry names who was responsible — as a snapshot.

**Deleting a person** *(v3.6.0; up to v3.5 the name stayed as a free name)*:
their **open reminders pass to the person deleting** — including those with a
free name matching theirs. The dialog says beforehand how many and to whom.
**Completed items keep the name** they had when done, with **“(deleted)”**:
in the history (who did it and who was responsible), on completed one-off
reminders, in the history CSV file and in the calendar. If the admin chooses
“former member” when deleting ([section 5](#5-retention-clearing-privacy)),
those show **“former member”** instead of the name. The filter counts a
deleted or former person as “Unassigned” until someone new is entered; a
deleted person can no longer be assigned. Whoever deletes with an API key is
not a person: open reminders then stay with the deleted person, marked
“(deleted)”, until someone takes them over.

### Meters and contracts *(v3.7.0)*

A meter and a contract can have a responsible person too — their deadlines
then go to that person. It is chosen in the meter or contract form under
**“Responsible”**, from the register of people only (no free names);
**“– nobody –”** means nobody. The meter and contract cards show the person.

**Who is responsible for a deadline** — the first entry that exists applies:

| Deadline | first | then | otherwise |
|---|---|---|---|
| Reminder (calibration, meter swap, service …) | the reminder’s own responsible person | the meter the reminder belongs to | nobody |
| Notice deadline, end of contract, price guarantee, price increase | contract | the contract’s meter | nobody |
| Bill expected | contract | the contract’s meter | nobody |
| Reading due, low stock (tank) | meter | – | nobody |
| Service-charge and CO₂ deadlines (rented home) | – | – | nobody |

A reminder’s own responsible person always comes first — even a deleted person
or “former member”, until someone new is entered. A group contract has no
single meter; there only the contract’s person applies.

**Reminder on a meter:** in the reminder dialog the **“Meter”** field says
which meter a reminder belongs to. For a new calibration or delivery the only
matching meter is preselected. If “Responsible” stays empty, a line below says
who is responsible then (“If empty, responsible: Jonas (via the meter).”).

**Display:** inherited responsibility carries **“via the contract”** or **“via
the meter”** in the chip — in the reminders, under “To do”, on **Costs &
contracts → Contracts & payments** and in the calendar (“Responsible: Anna (via
the contract)”).

**Filter:** “My tasks” and the names count inherited responsibility — in the
reminders, under “To do” (including the combined line for readings) and on
**Contracts & payments**, which now has the filter too. Signed-in
people can subscribe to the [calendar](kalender.md) **with only what is
assigned to them** (tick box in the dialog “Subscribe in your calendar”).

**Ticking off:** for a deadline with inherited responsibility too, the
history names who was responsible — as a snapshot.

**Deleting a person:** meters and contracts pass to the person deleting, like
open reminders. Without sign-in the person stays with “(deleted)”; with the
tick box “former member” that is shown instead. The dialog gives the number
beforehand ([Deleting people](benutzer.md#deleting-people)).

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

## 11. Check a reading *(v3.9.0)*

A wrong reading between two correct ones moves consumption from one month to the
next. If the app finds such a reading (or several in a row with the same error),
**“To do”** says “Check the reading of …”, with what was measured and expected in the
interval before and after and the guess (“probably about 300 m³ too low”). In the list
of readings the reading carries the mark **UNUSUAL**. How the app spots it is in the
[Basics](../verstehen/00-overview.md#check-a-reading-since-v390).

| Button | what happens |
|---|---|
| **Reading is correct** | The app no longer asks about this reading. The list shows **VERIFIED**. If someone changes the reading later, it asks again. Also in the list (✅ in the row). |
| **Only for the bill** | The reading stays for bill and balance, monthly values and the heating model skip it: the consumption in between is spread by the weather. That fits when the supplier billed with an estimated or wrong reading. The list shows **BILL ONLY**; the tick can be removed again in the edit dialog. |
| **Correct it** | opens the edit dialog of the reading with the reading that would fit (“Insert”). Nothing is saved until “Save”. |

All three with **Undo** in the message afterwards.

**“Consumption before and after … does not add up”:** if the interval before is far
below and the one after far above the expectation (or the other way round), but no
common offset explains both, for instance because the readings got more and more wrong,
the app asks with this text. The buttons are the same; here it suggests no reading. A
photo of the reading or the bill shows which reading is right.

**Entered twice:** if the same reading on the same day is there twice, **“Delete
one”** deletes the second (the one with a photo is kept, otherwise the one with a
note). Undo creates it again.

**A different level:** if consumption was consistently far above or below today for at
least a year, that is more likely a different consumption (for heating such as a new
heating system, otherwise moving in, a vacancy, a renovation or a different appliance)
than a reading error. The app then suggests a
[baseline date](../verstehen/00-overview.md#effect-of-a-measure-baseline-date):
**“Set baseline date here”** opens a small dialog: the day is filled in with the end of
the unusual period (if you know the day of the change, such as the heating replacement,
enter it), plus a label. **“Set baseline date”** adds it to the meter, **“Not now”**
ticks the note off. Both with undo.

The entries appear at every level and count in the “To do” sensor of Home Assistant
(`/api/todo`).

---

[← Compendium index](../README.md) · [Deadlines and dates in your calendar](kalender.md)
