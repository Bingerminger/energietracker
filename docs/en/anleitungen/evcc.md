# Charging sessions from evcc

**English** · [Deutsch](../../anleitungen/evcc.md)

[← Compendium index](../README.md)

[evcc](https://evcc.io) controls the wallbox: it charges with solar power or
in cheap hours and keeps a record of every charging session. Since **v3.2.0**
the Energietracker takes over these sessions and checks what came of them —
amount, share from the sun and the price evcc stated, per month. The wallbox
meter readings come along too.

> **The Energietracker controls nothing.** evcc (or Home Assistant) controls
> the wallbox and the battery, the Energietracker checks the result. How the
> two work together is shown in the [use cases](anwendungsfaelle.md).

Two ways bring the sessions in: the **CSV file** from evcc — always works — or
**fetching** them directly from evcc in the home network.

---

## 1. What you need

- evcc with at least one charging point.
- In the Energietracker an **electricity meter with the role “Wallbox (EV
  charger)”** (§ 2).
- The **“Experienced”** level or higher: only then does the wallbox view show
  the card “Charging sessions from evcc”. The address for fetching is under
  Settings → Expert (“Expert” level).
- With [users in the household](benutzer.md): only someone who manages the
  installation enters the evcc address. Everyone may take over sessions.

---

## 2. Set up the wallbox meter

**Consumption → Electricity → ⚙️ Meters → “+ New meter”**, as for the
[charging record](ladestrom-nachweis.md):

1. **Name**, e.g. “Wallbox”.
2. **Role: “Wallbox (EV charger)”.** Only with this role does the card appear.
3. **Parent meter:** the household meter if the wallbox sits behind it. The
   app then subtracts its consumption there instead of counting it twice.
4. **Initial reading of the device:** if the readings are to come from evcc
   (§ 5, “from the wallbox meter”), enter it as the wallbox meter counts it —
   otherwise the first reading from evcc does not match the start.

A meter that records consumption per period does not accept charging
sessions.

---

## 3. Download the CSV from evcc and upload it

**In evcc:**

1. Open **“Charging Sessions”** in the menu.
2. Choose the period — month, year or total.
3. On the **“Download”** button, tap the **arrow next to it** and choose
   **“CSV”**. The button itself downloads an Excel file (XLSX), which the
   Energietracker does not read.

The language of the file does not matter: the Energietracker recognises the
header in German, English, French, Italian, Spanish, Portuguese and Dutch,
with a comma or semicolon as the separator. The columns for start and energy
are required; end, charging point, vehicle, meter readings, solar share and
price are taken along when present.

**In the Energietracker:**

1. **Consumption → Electricity**, choose the wallbox meter at the top.
2. In the card **“Charging sessions from evcc”**, choose under “Meter
   readings” where the readings come from (§ 5).
3. **“Choose CSV from evcc …”** and pick the file.
4. Read the preview (§ 6) and **“Take over”**.

---

## 4. Set up fetching in the home network

Instead of a file, the Energietracker fetches the sessions from evcc itself at
the press of a button.

1. **Settings → Expert → “evcc in your home network” → “evcc address”**: the
   address you open evcc at in the browser, e.g. `http://192.168.178.30:7070`.
   Port 7070 is evcc’s default for the web interface and the API.
2. Save. The card “Charging sessions from evcc” now shows the button **“Fetch
   from evcc”**.
3. Tap it, read the preview, “Take over”.

The Energietracker’s server then calls `GET <address>/api/sessions`. No evcc
password is needed: evcc hands out the list of sessions without sign-in. Empty
means off; the CSV file always works.

**Your own network only.** As with [text recognition](texterkennung.md), the
app only accepts addresses in your own network: loopback, private networks
(`10.x`, `172.16.x`–`172.31.x`, `192.168.x`), link-local, `100.64.0.0/10` and
the matching IPv6 ranges. The name is resolved on every fetch, every address it
points to has to be local, the connection goes to exactly the checked address,
and the app does not follow redirects. No new way into the internet is opened.
A fetch waits at most 30 seconds.

### Name or IP address?

- **`evcc.local`** comes via mDNS, not via the router’s DNS. The browser on a
  Mac or phone finds the name, a server often does not — especially the
  **Energietracker in a Docker container**. The fetch then ends with “evcc
  cannot be reached.” Fix: enter evcc’s **IP address** and make it fixed in the
  router.
- **evcc as a container in the same Docker network** as the Energietracker: the
  service name is enough, e.g. `http://evcc:7070`. If evcc runs with
  `network_mode: host` (evcc’s recommendation for Docker), use the IP address
  of the machine.
- **evcc image for the Raspberry Pi:** it serves the interface at
  `https://evcc.local/` with a self-signed certificate. The fetch checks
  certificates and rejects it — enter the unencrypted address with port 7070,
  e.g. `http://192.168.178.30:7070`.

---

## 5. Meter readings: which choice?

The “Meter readings” selector in the card applies to the CSV and the fetch
alike:

| Choice | What happens | Use it when … |
|---|---|---|
| **“from the wallbox meter (otherwise added up)”** — default | The start reading on the day of charging, the end reading on the day after — the way the app reads a reading: as the reading at the start of the day. So every charge counts for its own day and month. If the wallbox reports no meter readings, the app adds up as in the next row. | the meter in the Energietracker is the meter in the wallbox that evcc reads (e.g. a MID meter in the wallbox). Usually the right choice. |
| **“add up from the energy charged”** | Starting from the last reading up to the day of the first session — without one, from the device’s initial reading — the app adds up the energy charged and creates a reading on the day after each charging day. | your meter in the Energietracker is a different one from the meter the wallbox reports (e.g. a separate sub-meter), or the wallbox readings do not match your own readings. What the wallbox uses in standby is in no session; the added-up reading therefore lags slightly behind the real one. Take a real reading now and then. |
| **“none – only the sessions”** | Only the sessions; the meter readings stay as they are. | the readings already come another way — by hand, from [Home Assistant](home-assistant.md) or from the [portal](daten-aus-portalen.md) — and you only want solar share and price. Likewise when all charging points of a file should go to one meter (§ 7). |

Readings from evcc carry the note “evcc”. An existing reading on the same day
is replaced; the preview says how many. The end reading of a session from
today only arrives tomorrow (it belongs to the day after) — with the next fetch
or the next file.

---

## 6. Preview and repeating

Both ways show a **preview** first (“Take over the charging sessions?”):

- how many sessions from when to when, how many kWh in total;
- how many readings are added and where they come from (§ 5);
- how many existing readings on the same days will be replaced;
- whether a session is still running;
- which charging point is taken over when the file contains several (§ 7).

Only **“Take over”** saves. The app then reports “… charging sessions taken
over.”

**Repeating does no harm.** The same session — same start, same charging point
— is replaced, not counted twice. So you can load the whole file or just the
new month every month. A session that is still running (without an end) waits
until next time. Rows without a start or without energy are skipped. In the
card a session counts for the day it ends, in the installation’s time zone.

---

## 7. Several charging points

The file from evcc contains every charging point of the period. Meter
readings, however, belong to exactly one wallbox — so the app asks:

- **One meter per charging point:** with “from the wallbox meter” or “add up”
  the app asks “Which charging point belongs to this meter?”. Choose this
  wallbox’s charging point; only its sessions and readings are taken over. For
  the second wallbox, load the same file again in its view. Via the API this
  is `loadpoint` (§ 11).
- **One wallbox meter in the Energietracker for all charging points:** choose
  “none – only the sessions”. Then all sessions go to this meter, the readings
  stay as they are; the preview names the charging points.

---

## 8. What the card shows

The card **“Charging sessions from evcc {year}”** sits in the view of the
wallbox meter, for the year selected at the top:

- a line such as “12 charging sessions, 180 kWh, 41 % of it from the sun · €38.50
  according to evcc” (example);
- one row per month: **Month**, **Sessions**, **kWh**, **Solar** (the solar
  share, weighted by kWh) and **Price (evcc)**.

The **price** is the one evcc calculated with its own tariff settings — not the
contract price in the Energietracker. It is there for comparison; nothing is
calculated with it.

---

## 9. Working with the charging record

The readings from evcc are ordinary wallbox meter readings. The card “Charging
record (company car)” (“Expert” level, below the evcc card) calculates with
them too: contract price or flat electricity rate per month, as CSV and PDF
([Record charging electricity for a company car](ladestrom-nachweis.md)). The
record does not use the price from evcc.

The readings from evcc sit around each charging day (start reading on the day,
end reading on the day after). The record forms the monthly amount from the
readings as always; every charge therefore lands in its own month. Only what
the wallbox uses in standby between charging days is spread by days.

---

## 10. If something does not work

| Message | Cause and fix |
|---|---|
| “evcc cannot be reached.” | evcc is not running, the port is wrong, a firewall blocks it, the name cannot be resolved (`evcc.local` in a Docker container, [§ 4](#name-or-ip-address)), or the address starts with `https://` and evcc has a self-signed certificate. First check: open the address with `/api/sessions` appended in the browser — if a list appears there, the problem is the way from the server to evcc (name, Docker, firewall). Without the PHP extension curl the fetch does not work either; the Docker image includes it. |
| “evcc has to run in your own network – “…” is not an address in the home network.” | The address — or one the name points to — is not in your own network. Enter evcc’s IP address in the home network. |
| “evcc did not return a list of charging sessions.” | Something other than evcc answers at the address, e.g. on a wrong port. |
| “No completed charging sessions found.” | The file is empty, all sessions are still running, or no session matches the chosen charging point (`loadpoint`). |
| “Several charging points (…): meter readings belong to one wallbox – …” | Via the API without `loadpoint` but with meter readings. Give the charging point or `counters: "none"` (§ 7). |
| “This does not look like the CSV export from evcc: the columns for start and energy are missing.” | A different file, or XLSX instead of CSV was downloaded in evcc (§ 3). |
| “No evcc address set up (Settings → Expert).” | A fetch via the API without an address. |
| “This meter records consumption per period – …” | The chosen meter records periods instead of readings. Use a meter with readings. |
| “Only someone who manages this installation may do that.” | A member tried to change the evcc address ([Users](benutzer.md)). |
| The card is missing | The meter does not have the role “Wallbox (EV charger)”, or the level is “Beginner”. |

---

## 11. Via the API

```text
POST /api/utility/strom/meters/{id}/import-evcc?dry_run=1   {csv, counters?, loadpoint?}   preview
POST /api/utility/strom/meters/{id}/import-evcc             {csv, counters?, loadpoint?}   take over
POST /api/utility/strom/meters/{id}/sync-evcc[?dry_run=1]   {counters?, loadpoint?}        fetch in the home network
GET  /api/ev-sessions?meter_id=m_wallbox&year=2026          → {sessions, monthly}
```

`counters` is `auto` (default), `energy` or `none` (§ 5). If evcc does not
answer, or not usefully, the fetch returns `502`. The sessions are stored in
`data/ev_sessions.json` and are part of the backup. Fields and error codes:
[API reference](../referenz/api.md).

> evcc terms checked on 9 Oct 2026 against the
> [evcc documentation](https://docs.evcc.io/en/features/sessions/) and the
> [evcc](https://github.com/evcc-io/evcc) source code.

---

[← Compendium index](../README.md)
