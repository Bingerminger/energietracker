# Deadlines and dates in your calendar

[Deutsch](../../anleitungen/kalender.md) · **English**

[← Compendium index](../README.md)

Since v3.1.0 Energietracker publishes its deadlines and dates as a calendar
subscription: cancellation deadlines, contract ends, maintenance dates, due
readings. Your calendar fetches them itself — on the Mac, the iPhone, in
Thunderbird or Google Calendar. No service in between is needed.

---

## What it contains

The same entries that the dashboard shows under “To do” and that Home Assistant
sees, as all-day events of the next 365 days, plus anything already due:

| Entry in the calendar | When |
|---|---|
| a date from “Reminders & maintenance” (smoke alarms, calibration deadline …) | on its next due date |
| “Electricity: take a meter reading (…)” | when the last reading is older than set under Settings → General → “Warn after” (default 45 days); if that is already the case, the entry is today |
| “…: last day to cancel (provider)” | on the cancellation deadline of a current or future contract — the contract needs a notice period for this |
| “…: contract ends (provider)” | on the fixed contract end; not for contracts that renew |
| “…: price guarantee ends (provider)” | on the last day of the price guarantee |
| “…: price increase (provider) — check special termination” | on the day of a recorded price increase; since v3.1.0 also under “To do” while the recommendation “price increase” for it stands (in Germany) |
| “Heating oil: plan a delivery (…)” | today, as long as the stock is low (heating oil, pellets) |
| “Service charge statement up to … due: …” *(v3.1.0)* | only with “I live: in a rented home” — end of the last completed billing period plus 12 months (German Civil Code, BGB § 556 (3)), as long as no statement is recorded for that period |
| “Last day to object to the service charge statement: …” *(v3.1.0)* | only when renting — receipt of the statement (“Received on”) plus 12 months; under “To do” from 30 days before |
| “CO₂ cost {year}: last day to claim the landlord’s share of …” *(v3.1.0)* | only when renting in Germany with your own gas boiler and an amount above 0 — bill date of the recorded gas bill plus 12 months; under “To do” from 30 days before |

For readings of meters with **consumption per period** (v3.1.0), the end of the
last period counts instead of the last reading. The app calculates the two
deadlines of the service charge statement from the tenancy — how is described
in [As a tenant](mieter.md#8-deadlines-in-the-calendar); the deadline for the
CO₂ refund from the gas bill
([Share CO₂ costs](co2-aufteilung.md#7-deadline-in-the-calendar)). Whether a
deadline applies in a particular case the app does not judge.

**Done** moves a date to its next due date; in the calendar the same entry
moves along, no duplicate appears. The texts are in the **default language of
the installation** (Settings → General), because calendars send no language.

---

## Setting up the subscription

In the app: **Reminders & tips → Reminders & maintenance → “Subscribe in your
calendar”**. The window shows two addresses, each with a “Copy address” button:

- `webcal://…` opens the calendar app directly on Mac and iPhone.
- `https://…` (or `http://…`) for everything else.

The address has the form `http://YOUR-IP:8080/api.php/api/calendar.ics`, with
sign-in switched on plus `?token=etk_…` (see [Access](#access-and-keys)).

### Apple Calendar on the Mac

1. **File → New Calendar Subscription …**
2. Paste the address and choose **Subscribe**.
3. Under **Location choose “On My Mac”**, not iCloud. Then the Mac fetches the
   calendar itself and reaches the app on your home network. With iCloud,
   Apple’s servers ask — and they cannot reach your home network.
4. For “Auto-refresh”, “Every day” or shorter is enough. For the advance
   reminder to appear, do not let it remove the alerts.

### iPhone and iPad

- The easiest way is to open the `webcal://` link on the iPhone (for example,
  open the window in the app on the iPhone and tap the link) and confirm
  **Subscribe**.
- Or by hand: **Settings → Calendar → Accounts → Add Account → Other → Add
  Subscribed Calendar** (in newer iOS versions under Settings → Apps → Calendar)
  and enter the address.
- If the iPhone asks for a location, choose **“On My iPhone”** instead of
  iCloud — for the same reason as on the Mac.

On the road, outside your home network, the device cannot reach the app; the
calendar then shows the last state and catches up once you are home.

### Thunderbird

1. In the calendar, **New Calendar … → On the Network**.
2. Paste the `https://` version as the location and leave the user name empty —
   a key is already part of the address.
3. **Find Calendars**, then **Subscribe**. Thunderbird detects the format
   (iCalendar) itself.

The calendar’s properties let you set how often it reloads and whether it
shows reminders.

### Google Calendar

Google fetches subscriptions from **Google’s servers**, not from your device.
That only works if the app can be **reached from the internet** — not in a
home-network-only setup.

If it can be reached (reverse proxy with HTTPS), then **with sign-in switched
on** and a calendar key: in Google Calendar, next to “Other calendars”, choose
**+ → From URL**, paste the `https://` address with `?token=…` and **Add
calendar**. Google decides by itself how often it reloads, often less often
than every 12 hours, and does not reliably take over the advance reminders of
subscriptions. Before you put the app on the internet for this: checklist in
[Security & network operation](../betrieb/sicherheit.md).

---

## Refresh and advance reminder

- **Every 12 hours.** The calendar asks to be refreshed every 12 hours. Apple
  Calendar and Thunderbird let you choose the interval yourself. Changes in
  the app appear with the next fetch.
- **Advance reminder.** Dates, cancellation deadlines, contract ends, price
  guarantees and price increases carry a reminder as many days before as set
  under Settings → General → Reminders → “Reminder “due soon” from”
  (default 14). `0` switches it off. Due readings and deliveries have no advance
  reminder — they are on today’s date anyway. The deadlines of the service
  charge statement and of the CO₂ refund (v3.1.0) have none either; instead,
  the objection deadline and the CO₂ deadline appear under “To do” 30 days
  before.

---

## Access and keys

- **Without sign-in** the address needs no key. Whoever can reach the app on
  the home network can also read the calendar — like the app itself.
- **With sign-in switched on**, the subscription needs a key of its own. Since
  v3.1.0, “Subscribe in your calendar” asks first and says how many calendar
  keys already exist; only **“Create link”** creates the key and appends it to
  the address. Cancel, and no key is created. Its permission is **“calendar
  subscription only”**: it only works for the calendar, only in the address,
  and opens nothing else. A read or manage key in the address is rejected — so
  a key with more rights never ends up in a calendar sync.
- The address with the key cannot be shown again later — copy it right away.
  Every further “Create link” creates another key. **Revoke** under
  **Settings → Access → API keys for scripts → Revoke**; after that the
  subscription receives no new entries. For a new subscription, open the window
  again and create the link.
- The address with the key reveals deadlines and provider names. Treat it like
  a password and do not pass it on.

Technical details (shape of the events, UIDs, access rules):
[API reference](../referenz/api.md#get-apicalendarics-v310).

---

## If something does not work

| Symptom | Cause and fix |
|---|---|
| The calendar stays empty or outdated | The fetching device cannot reach the app: subscription in iCloud instead of “On My Mac/iPhone”, on the road outside the home network, or Google without access from the internet. |
| Error “unauthorised” (401) | The calendar key was revoked, or the address contains a read or manage key. Get a new address via “Subscribe in your calendar”. |
| A cancellation deadline is missing | The contract has no notice period, or the deadline is more than 365 days ahead. |
| No advance reminder | Advance reminder set to 0, alerts removed in the subscription, or Google Calendar. |
| The service charge deadlines are missing | Settings → Household & building says “I live: in my own home”, there is no tenancy, or the statement has no “Received on” (then there is no objection deadline). Once the statement for the last period is recorded, the reminder for it goes away. |
| The deadline for the CO₂ refund is missing | No gas bill for the year recorded under Check a bill → “According to the bill”, the country is not Germany, the heating cost statement carries CO₂ details (then central heating applies and the landlord calculates), or the amount is 0 (stage 1 or reduced to 0). |

---

[← Compendium index](../README.md) · [Connect Home Assistant](home-assistant.md)
