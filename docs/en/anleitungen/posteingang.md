# Inbox: let the app read supplier mail

[Deutsch](../../anleitungen/posteingang.md) · **English**

[← Compendium index](../README.md)

Since v3.7.0 the Energietracker has an **inbox**. Mail from your supplier —
annual bill, contract confirmation, price change, cancellation — goes in as a
PDF. An **AI in your own home network** reads it, you check the proposal next
to the document and take it over into contracts, meters, bills and readings
with one click. Document by document, nothing behind your back. Without AI
the inbox stays a place to file things: drop, assign, enter by hand.

For every document the app says in one sentence **what the letter means for
you and what to do now** ([What does this mean for you?](#what-does-this-mean-for-you)).
Mail gets in not only through the browser but also with a
[shortcut](#with-a-shortcut-from-the-iphone-or-ipad), through a
[watched folder](#watched-folder) or [by email via Home Assistant](#by-email-via-home-assistant).
And every contract and meter has a [file](#file-per-contract-and-meter) with
all its documents.

---

## Filing

Open **Inbox** (📥 in the sidebar, on the iPhone under “More” and in the capture
sheet as “File supplier mail”) and drag the PDF onto the area, or tap it and
choose one. On the iPad the picker opens the **Files app**: save a PDF from
the mail there and pick it here — iPadOS does not offer a “Share” target for
web apps so far; that is what the [shortcut](#with-a-shortcut-from-the-iphone-or-ipad)
is for. Several files at once work too.

- **PDF** only, up to 10 MB per file. It is stored in the receipt store like
  any receipt; it counts towards the receipt storage (`attachments_max_mb`)
  and travels with every backup.
- **Already there?** If the same file is already in the inbox, the app says
  “This document has been in the inbox since …” and does not file it again
  (recognised by its checksum, not by its name). The same letter in another
  file, such as a second scan, is recognised *(v3.7.0)* after reading by its
  content — same type, same period or effective date, same numbers and
  amounts: the preview says “Probably the same document as …” with a link to
  it. Nothing is deleted; what one of them already took over shows as “already
  there” for the other.
- The list shows type, state and filing date of each document: **New**,
  **Queued**, **Read** (with “check n fields”), **Failed**, **Taken over**,
  **Filed**.

---

## Reading

In the preview of a document tap **“Read”**. Three steps, shown on the page:

1. **Read the text** — the browser reads the PDF’s text layer (with PDF.js,
   which the app ships). Photos and scans without a text layer are not read by
   the inbox; enter those by hand.
2. **Recognise the type** — bill, contract confirmation, price change,
   cancellation, start of supply, meter swap, meter reading request, advance
   payment change; payment reminder, withdrawal and other are only recognised
   and filed.
3. **Read the fields** — exactly the fields of that type, each with its value,
   the **verbatim quote** and the page.

Locally a document takes a little over a minute with qwen3.8 (about
78 seconds in the trial). “Cancel” stops the page; the service finishes in the
background, and the result is ready without waiting the next time you read
(cached per document, model and schema version). “Read again” asks the service
once more.

Pages with general terms and conditions, withdrawal instructions or privacy
notices (from the second page on, recognised by the heading or by many
paragraph signs) are not sent along — no field is there, and they cost a lot of
time.

### The server checks the quote

Every field comes with a quote from the document. A field is marked **“check”**
(highlighted in yellow) if

- it has no quote or the quote is not in the document — compared without
  differences in white space, hyphenation and upper/lower case, also when the
  text layer delivers umlauts as two characters (“Ã¤” or “√§” instead of “ä”)
  *(v3.7.0)* or “Š”, “š”, “Ÿ” instead of “ä”, “ö”, “ü”,
- the value is not in its own quote,
- a market location ID does not have its form: 11 digits with a valid check
  digit as defined by the BDEW *(v3.7.0)*,
- an abbreviation with an asterisk stands in front of a meter number
  (“*EMH …”): you check what belongs to the number *(v3.7.0)*,
- it is empty although a proposal needs it — such as the date of a meter
  reading, the kind of result for its amount, or for a bill consumption and
  amount when both are missing.

On top come fixed checks: **every field** of a meter swap, a meter reading
request and an advance payment change (with the first two the model mixed up
meter numbers in the trial), **prices from bills** (net, gross, partial
amount) when they differ from the contract’s price line on the same day or the
contract has none, and two cross-checks of the bill: the server adds up the
advance payments made itself, and the result must be the total minus the
advance payments — if it does not add up, *(v3.7.0)* every amount involved is
flagged, because the app does not know which one was misread. Date and title
of a document are exempt from “every field” as long as no proposal needs them.

**No flag, only a hint** *(v3.7.0)*:

- A field that is **not in the document** and that no proposal needs is
  collapsed under “not in the document” — such as the new advance payment of a
  final bill. If a proposal misses it, the proposal names it as an open point.
- A date that is only **“expected”** is taken over as a hint; the proposal
  stays ticked.
- A **choice field** (energy, bill type, result) without a matching quote that
  other details back: the meter number or market location at the assigned
  meter, the contract number at the contract, units (m³ and kWh together only
  for gas) and keywords in the tariff or in the other quotes, for the bill type
  the new advance payment, for the result the amount against the advance
  payments.
- A **price from the bill** that matches the contract’s price line on the same
  day (to rounding): it is “already there”.
- A **net price** *(v3.7.0)*: if the bill shows the price net (“netto”,
  “zzgl. 19 % USt”) and the VAT rate stands in the quote, the app converts it
  to gross — it keeps gross prices — and compares gross with gross. A VAT rate
  is read, never guessed; if the app does not recognise net for sure, the flag
  stays. It is only read from a quote that stands in the document (with “…”
  only from the parts that stand there). If the rate is not in the quote, it also counts from the same text
  line when the model names exactly that rate, from a tax column (header
  “USt %” above the row, the number without “%”) or from the one VAT line of
  the bill; if the bill shows two rates, the app does not convert.
- **Same price, different VAT rate** *(v3.7.0)*: if the net price is the same
  as in the contract and only the VAT rate differs (say the confirmation
  “brutto inkl. 7 % USt”, the bill net with 19 %), that is no new tariff
  price: no flag, a new price line from the day the bill shows the price for.
  The app reads the contract’s rate from its documents in the inbox or from
  the same bill.

A **quote from several places** *(v3.7.0)* — in tables the label and the
value stand in separate columns — counts as found when the parts of a quote
with “…” stand close together in their order on the same page (in the same
line or the one below, also in a wide table row — counted in lines) and the
value is in one part. Without “…” a
quote may split into two parts, but only strictly: both on the same page, the
first shortly before the second (like column header and row), the value in
the second and not in the first.

**Readings per meter over several periods** *(v3.7.0)*: if the bill lists a
meter in several reading periods, the app merges them — first initial
reading, last final reading, the readings in between become readings of their
own. If the meter number stands as a header line above the reading lines
instead of in them, it counts as backed. After a meter swap the readings of
the new meter are only ticked in advance when the swap is; if several meter
numbers stand on the bill, a single reading is only ticked in advance when it
surely belongs to the assigned device.

**Reading codes per reading** *(v3.9.0)*: if the bill explains codes such as
“S=Schätzung I=Internetablesung”, a code applies to the reading right before
it. In “Altstand 1.234 I · Neustand 1.300 S” only the new reading is
estimated, not every reading of the line. Dates and meter numbers do not count
as readings here. If two periods follow each other (closing reading on 30 Sep,
opening reading on 1 Oct with the same value), they become one reading.

For every document the app *(v3.7.0)* also reads **date and title** (date of
the letter or bill, a heading like “Rechnungskorrektur”, also from the page
header): they put the post in order and show a correction as such. If they are
missing, that is no flag.

The flags follow what is in the app today, and they also apply to documents
you read before this version. Only the quotes are checked while reading: a
reading made before keeps its quote check until you read the document again.

The check reads German numbers the German way: “12.345” is twelve thousand,
not twelve point three — models liked to take the thousands separator for a
decimal point. With these rules no wrong core value slipped through unchecked
in the feasibility trial (61 real documents).

**Spaced digits** *(v3.9.0)*: some bills set amounts with spaces between the
digits (“3 1 5,5 9 €”, “-8 0 0 , 0 0 €”, “1 8 3 Tage”). The check reads that as
one number, but only when every digit stands alone, each separated by exactly
one space, and the result is a valid number. Numbers of several digits next to
each other (columns of a table like “12 345”) stay apart. If a PDF draws
right-aligned numbers character by character from the right, the browser has
read the line from left to right by the position of the characters since
v3.9.0; before, the text layer said “6 5 , 4 3 2 . 1 €” instead of “1.234,56 €”.
A document you read before keeps its reading until you read it again.

You **confirm** a flagged field (“Correct”) or **change** it. Tapping “p. 2”
next to the quote turns the document on the left to that page.

---

## Preview and taking over

The document on the left, on the right from the top:

- **Assignment** — utility, meter and contract, prefilled via the meter
  number, the market location, the contract or customer number, the energy
  named in the document and the supplier’s contract on the key date (for a
  bill the one that bills most of its period). The app never prefills the
  contract of **another** supplier *(v3.7.0)*; the name counts without case,
  punctuation and legal form (“Stadtwerke Musterstadt GmbH” = “Stadtwerke
  Musterstadt”). If none fits, it offers “Create the contract from this
  document” (see the table). Changeable; the app remembers your choice.
- **Read from the document** — the fields with their quotes, flagged ones
  first. Fields that are not in the document are collapsed.
- **Proposed changes** — compared with what is already there: **new**,
  **already there** (skipped), **conflict** (something else is in the same
  place; overwrites only if you tick it), **overlap** (for example a new
  contract that falls into the term of another one).

Ticked in advance is only what is new (or merely overlaps), carries no flag and
is not optional. The old reading of a bill is ticked in advance *(v3.7.0)* when
there is no reading on that day yet and it fits between the known readings (not
lower than the previous one, not higher than the next one or the closing
reading of the same bill, no meter swap in between); otherwise it stays an
offer. A proposal is flagged only when one
of **its** core fields is in doubt *(v3.7.0)*: a new contract needs start,
supplier and prices, not the advance payment. If the advance is missing, the
contract is created without it; if it was read with doubt, the contract does
not take it over. Both show below the proposal as an open point (“Not in the
document, add later: Advance payment”) until you confirm or correct the value.
**“Take over”** writes exactly what is ticked — through the same services and
checks as manual entry. What there is:

| Document | Proposals |
|---|---|
| Bill | new reading (and old one) — *(v3.7.0)* if the bill marks a reading as estimated (a word like “geschätzt” or a reading code it explains itself, such as “S = geschätzt”), it is created as estimated — likewise “hochgerechnet”, a footnote like “5)” with the explanation “5) hochgerechnet”, a code in a group like “10/12/26” and a code in the column “Ableseart” or “Ablesegrund” when the document explains it as an estimate; also from the readings per meter, assigned by meter number; if two meters stand on the bill (the assigned one and a new one), a **meter swap** with the final reading of the old and the initial reading of the new meter, ticked in advance when numbers, day and readings fit together —, enter the bill (for gas with correction factor and calorific value as on the bill, for “Check bill”), book the result as a special payment, new advance payment on the contract of the same supplier that applies then; for gas the conversion factor and calorific value per period as a key date of the [gas conversion](../verstehen/01-gas.md); working prices and standing charge: “already there” when as in the contract, otherwise as a proposal to check; market location on the meter, contract and customer number on the contract. Without a contract of this supplier *(v3.7.0)*: “Create the contract … from this bill” as an offer (start = start of the period, a final bill ends with it), and booking only goes to a contract of the supplier — otherwise the app refuses instead of booking to someone else’s. *(v3.9.0)* If no contract at all applies at the meter, this proposal is ticked in advance, with prices per section and the advance payment plan ([Contract from the bill](#contract-from-the-bill-v390)) |
| Contract confirmation | new contract with working price, standing charge (per year converted to per month), advance payment, minimum term, notice period, bonus, price guarantee, contract and customer number — uncertain side details stay open points; the previous contract ends the day before — ticked in advance *(v3.7.0)* for a switch (other supplier) or a tariff change (other tariff or other contract number) at the same meter, as an offer for a group contract, at a sub-meter, for apparently the same contract and for older predecessors; if the previous contract has a later end backed by a cancellation, that is a conflict |
| Price change | working price and standing charge from the key date, a date for the special right of termination |
| Cancellation | end of contract, without renewal; without a follow-up contract a reminder “Arrange a follow-up contract” 14 days before the end of supply (closer to the end: today) |
| Start of supply | meter reading at the start, start of the contract; without a contract of this supplier *(v3.7.0)* “Create the contract …” as an offer (prices stay open) |
| Meter swap | meter swap with final and initial reading (all to check) |
| Meter reading request | “Report meter reading” reminder at the meter, with the person responsible |
| Advance payment change | new advance payment from the key date; *(v3.9.0)* in the rhythm of the current advance payment (every two or three months stays so) |

**Reminders from documents** hang at the assigned meter and go to the person
responsible: if the contract has one, they are on the reminder; otherwise the
reminder inherits the meter’s ([Users → responsible](benutzer.md)). A “Report
meter reading” reminder leads straight to entering that meter’s reading, in
tips and in reminders.

Afterwards the document is attached as a **receipt** to the bill or contract,
and the history (Reminders & tips → Done) notes “Taken over from a document”. Taking
over the same document again shows everything as “already there” — nothing
comes in twice.

### Contract from the bill *(v3.9.0)*

If **no contract** applies at the meter during the period of a bill, the app
proposes “Create the contract … from … from this bill”, for every utility and
for water too, and ticks it in advance. It takes over:

- supplier, contract account (contract number) and the start of the period;
  after an annual bill the contract keeps running, a final bill ends it,
- the **prices per section** from their day on (a price change on 1 October
  gives two price rows), gross: net prices with the tax rate the bill shows for
  the component; a rate is read, never guessed,
- **components as one price**: basic and provision price of a component
  together give its standing charge per month (a yearly price divided by 12),
  working price, energy tax and CO₂ price of the same day together the working
  price. Below the proposal it says what each price is made of,
- for water **all three components**: drinking water and waste water per m³
  and with their standing charge, rainwater per m² and year with the area
  according to the bill,
- the **new advance payment plan** with its rhythm (see below).

The bill then belongs to the new contract, and
“Book the result as a special payment” works in the same run: the app first
creates the contract, then the bill, then books the result. If you untick the contract, booking is locked
(“Only together with the contract from this bill.”); you can still enter the
bill.

If a contract already applies in the period, the app overwrites nothing:

- A contract of **another supplier**, or one that overlaps: the proposal is only
  an offer (not ticked), and nothing is booked to the other contract.
- A contract of **the same supplier** that ends before the end of the period:
  for the days after it the app offers a **follow-up contract** (from the day
  after the end), for days before it a preceding contract. Both are offers.
- **Prices of the bill at the existing contract**: one row per component and
  section, “already there” when the contract has them on that day, otherwise
  new, but never ticked in advance.

### Advance payments every two or three months *(v3.9.0)*

If the bill names the new advance payment with due dates (“165,00 € am
15.05.2026, 15.07.2026, …”) or a rhythm (“zweimonatlich”, “vierteljährlich”),
the app takes it over like that: the amount applies **per due date**, every two
or three months or on the dates listed; after the last date listed it continues
yearly on the same days. Without “from” the plan starts on the first due date.
Due dates read with doubt do not count. The app takes the rhythm from the due
dates when at least two are evenly spaced (one, two or three months; a date may
be shifted by up to three days, for example because of a weekend); otherwise
from what the bill states, read in every language of the app (“zweimonatlich”,
“alle 2 Monate”, “bimonthly” …). If the statement does not match the due dates,
the due dates apply and the proposal is not ticked in advance. An advance payment change usually names
only the amount: then the rhythm of the current advance payment stays. The key
statement then also judges the new advance payment per due date, not per month.
How balance, suggestion and forecast use it is under
[Water](../verstehen/03-wasser.md#advance-payments-every-two-or-three-months-v390);
it applies to every utility.

---

## What does this mean for you?

At the top of the preview, and in the list below the name, every document
that has been read shows **one sentence, one figure and one button**: what the
letter means and what to do next. Everything else is under “More”. The server
calculates the figures with the services that calculate the other pages —
nothing is guessed that the app does not already know.

| Document | Sentence and figure | Button |
|---|---|---|
| Price change | extra or lower cost **per year**: difference in unit price × expected consumption of the next twelve months (the same forecast as in the [tariff comparison](../referenz/ansichten.md)) + difference in standing charge × 12. The old price comes from the letter, otherwise from the contract on the day before. Below it the deadline of the special right to cancel, if the letter names it. | If a saved offer saves money according to the switching decision: **“Switching saves about … – compare tariffs”**; otherwise “Take over the new prices” |
| Bill | before taking over, the additional payment or credit according to the bill; **after taking over, “Check bill” runs by itself**: “matches your data”, “is … higher/lower than your own calculation” or *(v3.7.0)* “the contract lacks prices” with the sections without a price, plus the reasons (for example an estimated reading at the boundary) | “Take over and check”, afterwards “Check bill” when it differs, without prices “Add prices to the contract” |
| Advance payment change | new advance payment against the advance payment that brings the current billing period to zero (advance payment as planned + expected final balance ÷ months until the bill): **fits**, **too high** or **too low** by about … a month. “Fits” means the rest at the end would stay below €50 or one advance payment — the same threshold as in the [annual report](jahresbericht-lesen.md). *(v3.9.0)* With a rhythm the instalments until the bill count instead of the months, and the verdict applies per due date. | “Take over the advance payment” |
| Cancellation | end of supply; without a follow-up contract “there is no follow-up contract yet” and the reminder from the proposals | “Find a follow-up contract” (tariff comparison) |
| Meter reading request | deadline and who is responsible; if there is already a reading taken at most 30 days before the deadline, it is done | “Enter reading” — straight to the meter |
| Meter swap | date, final reading, new meter and initial reading | “Record meter swap” opens the dialog with all values prefilled |
| Contract confirmation, start of supply | new contract or start of supply | “Create contract” or “Take over” |
| Payment reminder | “check whether a payment is outstanding” — the app does not read payment reminders | **“Create reminder”** with a deadline, suggested 7 days after it arrived (changeable); the document is filed afterwards |
| Withdrawal, other | for your information | “File” |

Without an assignment to meter and contract every figure that depends on your
data is missing; the key message says so. Open points of the proposals (not
in the document, uncertain and not taken over) are under “More”. If it is
probably the same document as another one, the hint with a link is in this
card — on the iPhone therefore before the document —, and the list and the
dossier say “probably a duplicate”; if the other one already took everything
over, the button is “File”. In the list, a button that writes
something (“Take over”, “Create reminder”) leads to the preview — what is
taken over there is, as usual, only what is ticked.

---

## Enter by hand

Without AI — or if the reading does not fit — **“Enter by hand”** opens the
matching form: for a bill “Check bill” with meter and period, for a contract
confirmation the contracts, for a meter swap the meters. **The document stays
next to it** — on the Mac and the iPad in landscape at the right edge, on the
iPhone and the iPad in portrait as a strip above the tab bar. A form in a
dialog leaves the document free as well. “To the preview” leads back, ✕ closes
it.

**File without taking over** removes a document from the open ones without
changing anything; “Back to the inbox” brings it back. **Delete** removes the
document; if it is already attached to a bill or contract as a receipt, it
stays there.

---

## Many documents at once

If several new documents are in the inbox, the list offers **“Read all n”**.
The page works through them one by one, even while you click on in the app;
the progress is shown in the list, “Cancel” stops. Whatever has not been read
yet stays queued — the next time you open it, “Continue” carries on. No
schedule (cron) is needed.

Afterwards the **review list** shows only the flagged fields of all documents
that have been read; a click opens the document at that place. With qwen3.8
about 46 documents per hour were possible in the trial, on a powerful Mac.

---

## Other ways into the inbox

Mail does not have to go through the browser. Three ways file a PDF as if you
had dragged it in — with the same check (PDF only, up to 10 MB, no duplicate).
Reading works as usual in the preview or with “Read all”.

<a id="inbox-shortcut"></a>

### With a shortcut from the iPhone or iPad

With a shortcut the Energietracker appears in the **share sheet**: share a PDF
in Mail, Files or Safari, tap “Energietracker”, done.

**Which key?** Without sign-in (Settings → Access) the shortcut needs none. With
sign-in, create a key under **Settings → Access → API keys for scripts** with
the scope **“inbox only (file PDFs)”**. It can do exactly one thing: put a PDF
into the inbox — neither read nor change nor delete. If the phone gets lost,
you revoke only that key. A “Manage” key would work too, but can do anything.

In the **Shortcuts** app:

1. Tap **+**, create a new shortcut and call it “Energietracker”.
2. Tap the **Details** icon (ⓘ) and switch on **“Show in Share Sheet”**,
   **Done**. The input appears at the top; leave only PDFs or files as input
   types.
3. Add the action **“Get Contents of URL”**. As the URL:
   `http://YOUR-ENERGIETRACKER-IP:8080/api.php/api/inbox` — the address you open
   the app with in your home network, with `/api.php/api/inbox` after it.
4. In the action, **“Show More”**: **Method** `POST`. With sign-in, under
   **Headers** add `Authorization` with the value `Bearer etk_…` (your key, with
   “Bearer” and a space in front).
5. Set **“Request Body”** to **“File”** and choose the **Shortcut Input** as
   the file.
6. Optionally the action **“Show Notification”** with the result of “Get
   Contents of URL” — then you see the answer.

The shortcut can pass the file name in the address (`…/api/inbox?name=` followed
by the name of the Shortcut Input). Without a name the document is called
“Document YYYY-MM-DD HH.MM.pdf”; spaces in the name can upset the address — then
leave the name out.

**On the road?** The shortcut reaches the app only where the phone reaches it:
in the home network or through your VPN. A duplicate is reported with “has been
in the inbox since …”. With the app for Home Assistant the shortcut works only
through an enabled direct port
([Home Assistant](home-assistant.md#as-an-app-in-home-assistant-os)), not
through the sidebar.

<a id="inbox-folder"></a>

### Watched folder

The data directory has the folder **`posteingang/`** (the app creates it on
the first read). A PDF placed there moves into the inbox; afterwards the file
goes to `posteingang/übernommen/`, a refused one (not a PDF, larger than 10 MB)
to `posteingang/fehler/`. A duplicate counts as taken over. The folder names
are the same in every language.

**When?** The app reads the folder when someone with admin rights opens it or
opens the inbox (without sign-in everybody is an admin). *(v3.9.0)* In
addition at most every five minutes after any request, if a file is waiting.
The app has no schedule of its own; in the Docker image and in the app for
Home Assistant the health check asks every 30 seconds, so a PDF placed there is
in the inbox within five minutes at most. If you want it at once, call
`POST /api/inbox/folder` from cron or Home Assistant (with a “Manage” key when
sign-in is on; [API](../referenz/api.md#folder--post-apiinboxfolder)). If the
[notification to Home Assistant](home-assistant.md#step-6--notifications-to-home-assistant-v390)
for new documents is on, the app reports every document it reads in.

**Your own location *(v3.9.0)*:** with the environment variable
`ET_INBOX_DIR` (absolute path) the folder is somewhere else, also outside the
data directory ([Docker](../betrieb/docker.md#configuration-via-environment-variables)).

**What the app leaves alone:** symbolic links (also as the folder itself),
sub-folders, hidden files (`.…`, including the `._…` companion files from the
Mac) and files younger than 10 seconds — a copy over the network might still
be running. At most 20 files per call; the rest comes next time. Files are
recognised by content, not by extension.

| Setup | Where the folder is |
|---|---|
| NAS with a web server (e.g. Synology) | `data/posteingang/` in the app folder, reachable through the share of the web folder (e.g. `\\nas.local\web\energietracker\data\posteingang`). The web server user (on Synology `http`) must be able to read and write there, otherwise the files stay. |
| Docker | `/data/posteingang/` in the container — with `-v "$PWD/data:/data"` that is `./data/posteingang/` on the host. With a named volume, mount a host folder on top, e.g. `-v "$PWD/posteingang:/data/posteingang"`; it must be writable for the container user ([Docker](../betrieb/docker.md#where-is-my-data-the-most-important-part)). |
| App for Home Assistant *(v3.9.0)* | `/share/energietracker/posteingang` (the app sets `ET_INBOX_DIR` and creates the folder at start). With the “Samba share” app on the network at `\\<Home Assistant address>\share\energietracker\posteingang`. Up to v3.7 the folder was out of reach there. |
| Docker without root (`rootless`) | as Docker; the image does not create a folder from `ET_INBOX_DIR` there, it must be writable for the container’s user (UID 10001 or your own, group 0). Otherwise the log shows a warning at start. |

<a id="inbox-email"></a>

### By email via Home Assistant

If the mail comes as an email attachment, Home Assistant can pass it on: the
**IMAP integration** reports every new mail as an `imap_content` event with its
parts, the action `imap.fetch_part` fetches an attachment (Base64, as it is in
the mail), and a `rest_command` files it in the inbox. For this the
Energietracker also accepts JSON `{"name": …, "pdf_base64": …}` instead of the
raw PDF.

1. In Home Assistant set up the **IMAP** integration for the mailbox the
   supplier mail goes to; best limit the search to a folder of its own or to
   the senders.
2. In the Energietracker create an “inbox only” key (only needed with sign-in
   switched on — with the app for Home Assistant it always is).
3. In `configuration.yaml`:

```yaml
rest_command:
  energietracker_inbox:
    url: "http://YOUR-ENERGIETRACKER-IP:8080/api.php/api/inbox"
    method: post
    headers:
      Authorization: !secret energietracker_inbox   # the whole value, including “Bearer”
    content_type: "application/json"
    payload: '{"name": {{ name | tojson }}, "pdf_base64": {{ data | tojson }}}'
```

`secrets.yaml` — the **whole** header value, as for pushing meter readings:

```yaml
energietracker_inbox: "Bearer etk_…"
```

4. An automation that passes on every PDF attachment of a new mail:

```yaml
automation:
  - alias: "Supplier mail into the Energietracker"
    triggers:
      - trigger: event
        event_type: imap_content
        event_data:
          initial: true
    actions:
      - repeat:
          for_each: >-
            {% set ns = namespace(keys=[]) %}
            {% for key, part in trigger.event.data.parts.items()
               if (part.content_type == 'application/pdf'
                   or (part.filename | default('') | lower).endswith('.pdf'))
                  and (part.content_transfer_encoding | default('') | lower) == 'base64' %}
              {% set ns.keys = ns.keys + [key] %}
            {% endfor %}
            {{ ns.keys }}
          sequence:
            - action: imap.fetch_part
              data:
                entry: "{{ trigger.event.data.entry_id }}"
                uid: "{{ trigger.event.data.uid }}"
                part: "{{ repeat.item }}"
              response_variable: attachment
            - action: rest_command.energietracker_inbox
              data:
                name: "{{ attachment.filename or 'supplier-mail.pdf' }}"
                data: "{{ attachment.part_data }}"
```

With the app for Home Assistant the address is
`http://<host name of the app>/api.php/api/inbox` (port 80, without Ingress), as
for [pushing meter readings](home-assistant.md#as-an-app-in-home-assistant-os).
The same mail twice gives no second document (checksum). The events and the
action `imap.fetch_part` are described in the
[documentation of the IMAP integration](https://www.home-assistant.io/integrations/imap/)
(looked up on 21 Oct 2026). *(v3.9.0)* This way has not been tested in a
running Home Assistant yet; Energietracker’s side is tested
([Home Assistant → Supplier mail by email](home-assistant.md#supplier-mail-by-email)).

---

## File per contract and meter

Below every contract (Contracts & advance payments) and every meter (Manage
meters), **📁 File** unfolds all its documents, newest first:

- **taken-over or filed mail** from the inbox assigned to this meter or
  contract — with its key message and the link to the preview,
- **bills** with their PDF (a bill from the inbox appears only once),
- **receipts on the contract**,
- for a meter, the **photos of its readings**.

Each entry shows the date (the document’s effective date, otherwise when it
arrived), kind, key message and **Open**. At the top the selection filters by
kind and the search field by name and text.

---

## Setting up reading

**Settings → Expert → 📥 Reading documents** — one card for both paths. The
switch **Local | Cloud** decides who reads the post (`inbox_provider`); there is
no second place for it, and the server checks the same. Only people who manage
the installation may change it ([Users in the household](benutzer.md)). The card
has its own **Save** button.

### Local: your own AI in the home network

Locally your **own service** in the home network reads, Ollama or
OpenAI-compatible (LM Studio). **Address and interface also apply to text
recognition for meter photos** ([Text recognition in the home
network](texterkennung.md)); that is why they appear only once, here under
“Local”. The “Text recognition in the home network” card keeps the image model
and the time limit for photos. On top it needs a **text model**:

```sh
ollama pull qwen3.8
```

**Recommended: `qwen3.8`.** It needs about **18 GB of memory** — a Mac with an
Apple chip and 32 GB or a PC with a matching graphics card. In the feasibility
trial it recognised the type of all 61 documents and read 98.6 % of the fields
correctly. Other models work too, but may read less well.

| Field | Key | Entry |
|---|---|---|
| Service address | `ocr_endpoint` | e.g. `http://192.168.178.20:11434` (Ollama), home network only; admins only |
| Interface | `ocr_api` | Ollama or OpenAI-compatible (LM Studio) |
| Text model | `inbox_model` | name as on the service, default `qwen3.8` (empty = `qwen3.8`) |
| Time limit per step | `inbox_timeout_s` | seconds per step (type, fields), default 300 (30–300) |

**Check connection** asks the service for its models (Ollama `/api/tags`,
OpenAI-compatible `/v1/models`) — with whatever is in the field right now, even
before saving — and says whether the text model is among them. No document is
sent, and the same home-network rule applies as for reading. Only admins see the
button.

The app asks the way it worked in the trial: temperature 0, answer as JSON,
**without thinking mode** (it used up the answer budget without answering),
first the type, then the fields. With Ollama it sets the context window to
suit the length of the document (2.5 characters per token, plus 8,192 tokens
for the answer, at most 32,768) *(v3.7.0)*. If the answer is cut off at the
window, it asks **once** more with the double answer budget (16,384 tokens) —
in the time left of the time limit; if that is not enough either, it says so
(“did not fit into the context window”). The same with the OpenAI-compatible
cloud service. With an OpenAI-compatible server switch the
thinking mode off there if the model has one.

**Time limit behind the web server.** In the Docker image nginx waits up to
310 seconds; every step (type, fields) is a request of its own and stays below
that. Behind your own reverse proxy set its limit just as high
([Web server](../betrieb/webserver.md)).

**Web server with a short time limit** *(3.7.1)*. If the web server in front
of PHP gives up earlier — on a Synology, for example, after 60 seconds with
`504` —, PHP still finishes and saves. The interface then asks for the
document every 5 seconds until the step is saved (at most 660 seconds from
sending) and only shows an error after that — likewise after a network
interruption, for a single document as well as when reading many documents.
A higher time limit on the web server avoids the wait
([Web server](../betrieb/webserver.md#short-time-limit-in-front-of-php-371)).

### Reading in the cloud

If you have no computer that runs a language model smoothly, you can hand the
reading to a cloud service. This is an **opt-in**: it is off until someone who
manages the installation switches it on, and before every reading the app names
the service. Without this opt-in no document leaves the home network.

> **What goes out and what does not.** With the cloud, **every document you
> have read** leaves your home network. It goes to the chosen service and is
> processed there under that service’s terms. The service sees the whole
> content, including name, address, meter and customer number. Read first what
> the provider does with submitted data (how long it keeps it, whether it uses
> it for training). **Nothing else goes out:** no meter readings, contracts,
> settings or other household data. The service only receives the document (PDF
> or its text), the app’s reading instruction and your key to sign in to the
> service. The “Reading documents” card says the same just as clearly in its
> “Cloud” view. If you do not want that, stay with “Local”.

> **Experimental.** All cloud paths, Claude included, have not yet been checked
> against real-world feedback. The card and the question before reading say so.

#### Providers

| Provider | Address | What leaves |
|---|---|---|
| Claude (Anthropic) | `api.anthropic.com` | the PDF; the service returns the source passage as a verbatim quote with its page |
| OpenAI | `https://api.openai.com/v1` | the document’s text; the PDF stays here |
| Mistral | `https://api.mistral.ai/v1` | the document’s text |
| OpenRouter | `https://openrouter.ai/api/v1` | the document’s text |
| Google Gemini | `https://generativelanguage.googleapis.com/v1beta/openai` | the document’s text |
| Own address | your address with `https://`, up to the version (e.g. `…/v1`) | the document’s text |

All except Claude run through the same OpenAI-compatible connection; the preset
only fills in the address as the provider’s documentation gives it (checked on
21 October 2026). There the model quotes, and the inbox checks the quote against
the text.

#### Model

Once the key is saved, the card loads the service’s **list of models** —
`GET …/models`, for Claude `GET https://api.anthropic.com/v1/models`. The server
makes the request; the key stays there. You pick from the list; later “Load the
service’s models” fetches it again. If that fails, the field stays free and you
enter the name as the service knows it. The only built-in model name is the
Claude default `claude-opus-5-5` (empty field = this one).

#### Switching it on

1. Create an API key with the provider. The provider bills you for the costs,
   not Energietracker.
2. “Reading documents” card → **Cloud**: choose the provider (for “Own address”
   enter the address), paste the key, **Save**. The card now loads the models.
3. Pick a model, tick **Switched on** and **Save**. The app asks once what goes
   where; after that the service reads.

Who reads follows the switch: **Cloud** saved and switched on — the cloud
service reads. **Local** chosen and saved — your own service reads; the cloud
stays switched on, but nothing leaves, and the card says so. Switching off sets
it back to local. The server only accepts a cloud service while it is switched
on and has a key.

The key stays on the server. After saving, the field only shows “Stored”, never
the key, and it is not part of the backup. **A key belongs to its service:** if
you switch from Mistral to OpenRouter, you enter a key of its own there; the
Mistral key stays stored for Mistral and never goes to another host. If you
restore a backup on another server, enter it there again; until then the inbox
says that the chosen service is not ready. **Delete key** removes it and
switches the provider off.

Only people who manage the installation may change this; members see the cloud
side without input and only save the local fields open to them.

#### What happens when reading

- Before every reading the app asks “This document goes to …” and names the
  service, its destination, what leaves and that the path is experimental. The
  queue (“Read all”, “Continue”) asks once for all documents of that run and
  names their number (“12 documents go to …”); whatever comes in afterwards is
  asked for by the next run. Consent is not remembered.
- First the kind of document, then the fields of that kind — two requests, with
  the same kinds and fields as when reading locally. With Claude the second one
  reads the PDF from the service’s cache.
- Values come verbatim, as they are written in the document; converting and
  checking happens in Energietracker, with the same
  [quote check](#the-server-checks-the-quote) as when reading locally. A field
  without a source passage, or with a value that is not in its quote, is marked
  for review.
- If the model declines, the app says so and you enter the document by hand.
  With the current Claude models the service first tries another of its own
  models.

#### Limits

- Claude takes at most 32 MB and 600 pages per request.
- The time limit is 120 seconds (adjustable from 10 to 600). Behind your own
  web server or reverse proxy its limit applies.
- The app does not follow redirects: enter the address at which the service
  itself answers.
- The app rejects an address in the home network here — that is what “Local” is
  for.

#### When something goes wrong in the cloud

| Message | What to do |
|---|---|
| The service rejected the key | check the key with the provider and paste it again |
| Too many requests | wait for the time given |
| Unavailable right now | try again later |
| Is in the home network | enter a public address with `https://` |
| Redirects | enter the address the redirect points to |
| Answer incomplete | try again; for very long documents a shorter PDF helps |
| List not loaded | enter the model name by hand, as the service names it |

What each service sees and how the connection is checked:
[Security & network operation](../betrieb/sicherheit.md#reading-in-the-cloud-v370).
The settings in detail:
[Settings](../referenz/einstellungen.md#inbox-reading-in-the-cloud-v370).

---

## Privacy: what goes where

- The **PDF** stays on your server (receipt store, in the backup).
- The browser reads the **text layer** and sends it to your server; the server
  sends it — without the terms pages — to **your** service in the home
  network. The text is not stored; it is in the PDF anyway.
- The service address must be in your own network, checked on every call
  after name resolution, connected to exactly the checked address — the same
  rule as for [text recognition](texterkennung.md#privacy-your-own-network-only).
- A provider outside the home network is only possible if an administrator
  switches it on [in the cloud](#reading-in-the-cloud) and sets the “Reading
  documents” card to “Cloud”; then the app asks **before reading** “This
  document goes to …” (for the queue once for the documents of that run, with
  their number) and sends nothing without your consent.
- Without a service set up the inbox makes no connection.

More: [Security → Inbox](../betrieb/sicherheit.md#inbox-v370).

---

## If something does not work

| Message | Cause and fix |
|---|---|
| “No service is set up for reading …” | Enter the address under Expert → Reading documents → Local; “Check connection” shows whether the service answers. |
| “The service does not have the model “qwen3.8” …” | `ollama pull qwen3.8` on the computer running the service, or enter an existing one under “Text model” (`ollama list`). |
| “Text recognition did not answer within 300 seconds.” | The first reading after a pause loads the model into memory first. Try again; too little memory makes it very slow. |
| “The service did not give a usable answer …” | Usually a model with thinking mode on or one that does not deliver JSON. Try again or use qwen3.8. |
| “The document has no text layer (a scan) …” | Enter it by hand; the PDF stays as a receipt. |
| Many fields yellow | Look at the quotes: if the value is different there, change it; if it is right, “Correct”. |

Technical: [API reference → Inbox](../referenz/api.md#inbox-v370),
[Data model → Inbox](../referenz/datenmodell.md#inbox-v370).

---

[← Compendium index](../README.md) · [Text recognition in the home network](texterkennung.md)
