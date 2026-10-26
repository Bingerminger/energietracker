# Household from documents

[Deutsch](../../anleitungen/haushalt-aus-dokumenten.md) · **English**

[← Compendium index](../README.md)

*New in v3.7.0.* Instead of typing in meters, contracts, prices and readings,
you put your supplier's mail into the [inbox](posteingang.md) and have it
read. The result is **one draft of your whole household**: meters, contracts
with their price chain and advance payments, meter readings, bills and
reminders — combined across all documents. You only check what is really
doubtful and take it over with one click.

---

## 1. How it works

1. **Set up** — at the end the setup assistant asks “What comes next” and offers
   **“From documents”** (recommended), **“Enter by hand”** and (for
   administrators) **“Look at it with sample data”**
   ([Setup](../einstieg/einrichtung.md)). “From documents” takes you to the
   inbox. If no service for reading is set up yet, **“Set up reading”** opens
   the “Reading documents” card (Local | Cloud) right away as a dialog — in the
   assistant and above the inbox list, later on too; the same card stays under
   Settings → Expert. Whoever manages the installation sets it up
   ([Inbox](posteingang.md)).
2. **File** — all PDFs at once, with the file picker (on the iPad from the
   Files app) or by dragging.
3. **Read** — “Read all”; the page works through the pile one document at a
   time, even while you click on in the app.
4. **Open the draft** — as soon as the first document has been read, the button
   **“Household draft from … read documents”** appears above the inbox list
   (`#/inbox/draft`). The draft grows while reading goes on.
5. **Check** — only what is marked ([§ 4](#4-check-and-take-over)).
6. **Take over** — one button for everything ticked. Afterwards the page shows
   what was created, what is still missing (with a jump to the place) and
   “check the bill” for each bill taken over.

The draft works only with the **stored readings** of the documents — it asks no
service and sends no document anywhere.

## 2. Which documents help

| Household | These documents |
|---|---|
| Renting | the last annual electricity bill and the contract confirmation |
| Flat or house | for each energy source the last annual bill, the contract confirmation and price changes |
| with PV feed-in | plus the feed-in contract or the statement from the grid operator |
| with a heat pump or wallbox | plus the confirmation of the special tariff |

More helps more: supply start letters, cancellations, meter change letters and
older bills fill gaps and confirm each other. Dunning letters, withdrawals and
advertising are recognised by the inbox and only filed.

## 3. What the draft does

- **Supply relationship.** Documents belong together when they carry the same
  contract or customer number or name the same supplier — also in another
  spelling, by the same rule as in the [Inbox](posteingang.md): legal form,
  upper/lower case and punctuation do not count, nor does an addition like
  “Vertrieb” (“Muster-Energie GmbH” = “MUSTERENERGIE” =
  “Muster Energie Vertrieb AG”). Generic words like “Stadtwerke” or “Energie”
  alone name no supplier. If a document names a brand and the supplier behind
  it (“Musterstrom, eine Marke der Muster Energie GmbH”), documents under
  either name belong together.
- **Another spelling at the same meter.** Bills without a confirmation of their
  own whose supplier is named differently but shares a word with the supplier
  of the contract running at their meter at the same time
  (“Musterstadt Energie AG” next to “Stadtwerke Musterstadt GmbH”) belong to
  that contract — only one supplier delivers to a meter at a time. The draft
  says so under “Noticed”. It does not recognise abbreviations (“SWM”); then a
  contract of its own starts with the first bill.
- **Meters from meter number and market location.** New numbers become a new
  meter. Numbers of the same metering point — same market location, a meter
  change letter, or a seamless following bill with another number — are a
  **meter swap** on the same meter, not a second meter. The empty default
  meter of the first start (no number, no data) gets the number and the
  installation date instead of a second meter being created. A meter is only
  created if something hangs on it (meter reading, contract, bill) — a past
  request to report the reading alone holds no meter.
- **Existing household, meters without a number** *(3.7.1)*. If you keep your
  meters without a meter number and market location, the recorded data does
  not know the numbers in the documents. The draft then takes over the **one
  active meter of the utility with data** (readings, contracts, bills) if
  nothing speaks against it — no other number on the running device, no other
  market location — and adds the number and market location as an addition.
  No second meter is created. If several meters are possible, another part of
  the documents already has this meter or it fits only half, “Still missing”
  asks which meter the number belongs to; the documents stay open until you
  assign one of them to the right meter in the inbox (in the preview: choose
  the meter). If the documents name a number of their own and a different
  market location than every meter with data, it is another meter — it is
  created.
- **Documents without a meter number** find their meter through the contract
  or customer number, otherwise through the supply relationship; if the
  utility has only one meter — or only one with data —, through that one.
- **Contract.** Start from the contract confirmation, otherwise from the supply
  start letter, otherwise from the first bill (then it says so). A supply start
  letter alone creates a contract. A start that is only “expected” applies with
  a note. End from the cancellation or the final bill, otherwise the day before
  the following contract. **The advance payment is not required** — if it is
  missing, it is listed under “Still missing”.
  - **Confirmation without a start:** the draft finds its letter through the
    contract or customer number, otherwise through the same prices (one
    contract, or the bills of one supplier with exactly these prices — if a
    contract of the same supplier is already running at that time, it belongs
    to that one), otherwise it is the one free supply start letter of the same
    supplier. If
    that is ambiguous, there is no contract and no meter, only “Still
    missing” — the draft does not guess, least of all across utilities.
  - **Two confirmations** with the same start from the same supplier at the
    same meter are one contract, even with different numbers. Confirmations
    with the same contract number (a later tariff confirmation, for example)
    are one contract with the earliest start, whatever order you filed them in
    *(3.9.0)*.
  - **A few days apart** *(3.9.0)*: if a confirmation and a supply start
    letter of the same supply relationship name days up to a week apart at the
    same meter (the brand names the contract start, the billing municipal
    utility the first day of supply, for example), that is one contract with
    the start of the confirmation. Different contract numbers stay two
    contracts.
  - **Bills before the start:** if the bill belongs to the same contract (no
    other contract number), the contract starts with it — “Noticed” says so.
    With another contract number it becomes a previous contract up to the day
    before the start, at the meter of the bill.
  - **End:** if the end in the document is doubtful and there is a following
    contract, the day before its start applies — pre-selected.
  - **Recorded contracts without a number** *(3.7.1)*: same meter (or its
    group), the same supplier and a start on the same day or at most seven
    days off — that is the recorded contract, not a second one. The recorded
    start stays (“Noticed” names both days; confirmation, supply start letter
    and first bill often name different days, a switch at the same meter never
    lies within a week of the previous start). If a value is missing there —
    contract number, tariff, price, advance payment —, the entry says “add to
    it”; a value that differs from the recorded one is a conflict. Bills over
    several years each belong to the contract that ran at the time. The final
    start is compared: if a contract moves forward to its first bill, the
    draft compares it again with that start *(3.9.0)*.
  - **The same supplier under another name?** *(3.9.0)* Same start as a
    recorded contract, but another name (the brand in your records, the
    municipal utility or the group in the document), and the recorded contract
    carries no other contract number: the entry asks “the same supplier?” and
    names both. It is not pre-selected. If you tick it, the values add to the
    recorded contract and its name stays. With another contract number in your
    records it stays a conflict.
- **Price chain.** Confirmation and price changes (valid from) form the chain
  and are pre-selected. A price on a bill is evidence — compared gross: if it
  is printed net with a VAT rate that was read, the draft converts it as the
  [Inbox](posteingang.md) does — also a confirmed price (the printed value
  applies) and a corrected one (entered in the same basis as printed); the
  field shows the basis with the result (“net according to the bill, with 19%
  VAT = … gross”). If it fits, it confirms the chain; if only the
  VAT rate differs (same net price), a new price row starts on the day the bill
  names, pre-selected (on the day the contract starts it replaces the first
  row); if it differs otherwise, that is listed under
  “Noticed” — the chain stays,
  “Check a bill” shows the difference. Only where no other document names a
  price for the period does the bill price become a link of the chain —
  pre-selected if net or gross with a VAT rate is shown, otherwise to be
  checked. Advance payments form a chain too (confirmation, advance payment
  change, new advance payment from the bill).
- **Cross-checks across documents.**
  - Closing reading of one bill = opening reading of the next: both readings
    confirmed, one meter reading. If they do not match, both need checking. If
    the same reading is recorded one day off (31 Dec against 1 Jan), it is
    already there *(3.7.1)*.
  - Gaps and overlaps between successive contracts of the same utility are
    listed under “Noticed” — nothing is corrected. Without an end in the
    documents, a contract ends on the day before the next contract. If an end
    from the documents reaches into the next contract, it is stored as it
    stands; advance payments, standing charge and balance count the contract
    only up to the day before the next one (the later start applies).
  - A falling reading without a meter change becomes a **suggested** meter swap
    (not pre-selected). If the meter number changes a few days later, that is
    the same swap — one device change on the day of the drop. If the closing
    reading of the old meter comes from the bill of the day before or of the
    swap day, the swap is pre-selected. The closing reading is only inferred
    from a reading at most seven days before the swap; otherwise it is listed
    under “Still missing”, and the readings of the new meter ask until the swap
    is entered. If the bill lists the readings per meter (both meters of a
    swap), both come from there. Such a swap is only pre-selected if
    everything fits — as in the single document: the old number is the running
    device, removal on the swap day or the day before, no known reading above
    the closing reading, the new meter rises, no reading from the swap day on
    in the existing data. No meter reading is created on the swap day itself:
    the swap carries the closing and the opening reading. If the same number
    appears more than once, the period decides: if the periods follow each
    other (one meter over several readings), the intermediate readings become
    readings of that meter; if they overlap (two-rate meter, peak/off-peak),
    neither a swap nor a reading comes from it, only an entry under “Still
    missing”.
  - **Estimated readings** (according to the bill, decided by the quote:
    “geschätzt” or a reading code explained on the document) are taken over as
    estimated meter readings, not as measurements.
  - The same value in two documents: confirmed — identifiers (customer,
    contract, meter number, market location) also with other spaces, dashes or
    leading zeros. Different values: for identifiers the number from the most
    recent document is pre-selected (by issue date, otherwise by reference
    date), the other one stays to choose; otherwise a decision with both
    quotes.
  - **Corrected bill:** two bills for the same period, one with a later issue
    date or marked as a correction (“korr” in the file name; “Korrektur”,
    “korrigiert”, “berichtigt” in the title or the text): the later version
    applies and is pre-selected, the earlier one stays as evidence.
- **Probably a duplicate.** The same content as an older document (another
  file) — just as the inbox list shows it as “probably a duplicate”: counts
  once, even if the older one has already been taken over. After taking over it
  is “Filed”, the file stays.
- **Existing data.** What is already there is “already there” and skipped; a
  “conflict” only overwrites when you confirm the field; “overlap” means new,
  but within the period of another contract; “add to it”: existing, one value
  is added. **“Add to it” and “overlap” are never pre-selected** *(3.7.1)* —
  they touch what is already there and are always a question; pre-selected is
  only what is new and changes nothing that exists.

## 4. Check and take over

- **One tick per group of fields:** meter, meter swap, contract, prices,
  advance payments, the readings of one meter, bill, gas conversion, reminder.
  Pre-selected is what is new, without doubt and without touching the
  recorded data.
- **Coupled to the contract** *(3.9.0)*: prices, advance payments and bills of
  a contract that does not exist yet, or whose supplier is a question, only go
  with it. Without its tick they are locked and not pre-selected. If you tick
  the contract, they come along as they would be pre-selected; if you untick
  it, they go too. At a recorded contract that is settled they stand on their
  own. Meter readings belong to the meter, not to the contract. If the contract
  is missing while taking over, because it fails for example, its bill is not
  created either. It never books its result on another contract.
- **Doubtful** is a field the [Inbox](posteingang.md) flags — with the same
  marks by today’s data that the list counts — and that no second document
  confirms. A price on the bill that matches the contract is “already there”.
  Two bills with the same price do not confirm it — net, gross or a partial
  amount would be wrong in both alike.
- **Still missing:** what is in no document — per contract the prices and the
  advance payment, plus the open points of the inbox (“Not in the document,
  add later: …”) with their documents, once per contract, unless another
  document brings them. What was read
  with doubt is asked by the entry itself. If a contract would be without an
  energy price or standing charge after taking over (only what is
  pre-selected), it is listed here — also when the prices are only on bills and
  still need confirming. Likewise a period without a price, such as the time
  before the first price row when the start was moved forward to the first
  bill.
- **Core fields** decide on the tick: for a contract the supplier and the
  start, for a meter swap the date and the closing reading, for a bill the
  period, for a reminder the date. If one is doubtful, the group is not
  pre-selected — ticking it confirms them.
- **Other doubtful fields** have their own “take over” tick; without it the
  field stays empty and the rest of the group is taken over.
- **Different values:** pick the right one; each shows its quote.
- **Quote:** tapping “Quote” shows the document, page and quotation; the name
  opens the document.
- **Order of the take-over:** meters → meter swaps → contracts → prices and
  advance payments → meter readings → bills (with the result booked as a
  special payment) → gas conversion → reminders. Through the same checks as
  manual entry; one error does not stop the rest.
- **Way back:** the app makes a backup first (Settings → Data, reason “before
  household from documents”). Taking over a second time changes nothing: what
  has been taken over shows as “already there”.
- **Afterwards** the documents the draft covers completely are “Taken over”: a
  letter hangs as a receipt on the contract, a bill on the bill, and the file of
  the contract shows them. The app then works out their key message in the inbox
  again (for a bill with “Check bill”). What you did not tick stays in the inbox
  and appears again in the next draft.

## 5. Limits

- **Meter change letters without numbers and readings:** without the closing
  reading of the old meter the draft does not enter the swap (it would stand
  there with an invented value) — it is listed under “Still missing” with a
  jump to the meters. If a bill states the reading, the draft takes it from
  there.
- **Documents without a supply start:** if no document of a supplier names the
  start and there is no bill, no contract and no meter without a number is
  created; “Still missing” says so. Add it by hand, the remaining values follow
  in the next draft.
- **Water:** the draft creates the contract; you enter the three prices
  (drinking water, wastewater, rainwater) by hand.
- **PV feed-in:** if the inbox does not recognise the utility, assign the
  document yourself; the draft follows your assignment.
- **Past dates** from old mail (report a reading, special cancellation) are not
  created.
- **Prices on bills** without a confirmation or price change for the period
  stay to be checked as long as they do not fit the existing contract. A net
  price only counts converted if the VAT rate was read — it is never guessed;
  otherwise the price stays as printed.

---

For integrations: `GET /api/inbox/draft` and `POST /api/inbox/draft/apply`
in the [API reference](../referenz/api.md#household-from-documents-v370).
