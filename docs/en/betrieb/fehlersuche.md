# Troubleshooting

[Deutsch](../../betrieb/fehlersuche.md) · **English**

[← Compendium index](../README.md)

Symptom → cause → fix. Questions about using the app are answered in the
[FAQ](../einstieg/faq.md); if none of this helps, the case belongs in the
[GitHub issues](https://github.com/Bingerminger/energietracker/issues) — with the
details from Settings → System → System diagnostics.

---

## Start-up and access

| Symptom | Cause | Fix |
|---|---|---|
| Docker Desktop: the container stays "Created", the interface reports "HTTP 500" or `mounts denied` | The data folder is under your home directory and not shared in Docker Desktop | Share it under Settings → Resources → File sharing — or use a named volume: `-v energietracker-data:/data` ([Docker](docker.md#folder-or-named-volume)) |
| After an update the interface stays at "Loading…" or shows the old version | The browser keeps old modules in its cache | Reload the page; close the installed app completely and reopen it. If it persists, the cache rules are missing on the web server (`AllowOverride None` silences the `.htaccess`) — [Web server](webserver.md) |
| "No connection to Energietracker – nothing was saved." | The server is not reachable (other Wi-Fi, server off, VPN disconnected) | Check the connection; meanwhile the installed app shows the last state ("Offline – data as of …"). Since v3.1.0 readings in “Meter readings” wait in the queue and are sent later ([Use on your phone](../einstieg/handy.md#the-not-saved-yet-queue)) |
| The app cannot be installed on the phone or shows nothing offline | Opened over `http://`; the service worker and installation need HTTPS | HTTPS through a reverse proxy — [Use on your phone](../einstieg/handy.md) |
| After signing in, the sign-in screen keeps coming back | The session cookie does not arrive (proxy, other host name, embedding) | [Security & network operation](sicherheit.md#8-host-names-and-embedding) |
| Password forgotten | — | Set `"mode"` to `"off"` in `data/auth.json`, or start the Docker container with `ET_AUTH=off`; then set a new password ([Security](sicherheit.md#3-switching-sign-in-on)) |

## Saving and data

| Symptom | Cause | Fix |
|---|---|---|
| Saving fails, the diagnostics report missing write permissions | The web server user may not write `data/` | Set the permissions ([Installation → Write permissions](installation.md#33-write-permissions)); the diagnostics show which directory is affected |
| After "Try with sample data" your own data is gone | The sample data replaces the existing data; the app creates a snapshot first | Settings → Data → Stored snapshots → restore the snapshot "before demo data" with ↩️ |
| A backup import is rejected | The file is not a backup in format 3.0 or is damaged; the preview names the places | Check the file; an old v0.9.0 backup belongs in the [migration](../anleitungen/migration-v090.md) |
| Upload of large files aborts, or “The file is too large – … MB at most.” | Limits of PHP (`upload_max_filesize`, `post_max_size`) or nginx (`client_max_body_size`, otherwise `413`). Above `post_max_size` the app answers with `400` and `errors.attachment.size` including the limit (v3.1.0) | Raise the limits; since v3.1.0 the Docker image allows 256 MB (previously 32 MB) — [Upload limits](webserver.md#upload-limits-and-php-memory-v310). Receipts also have their own limits: photo 3 MB, PDF 10 MB |
| Backup import aborts (too large) | Since v3.1.0 the receipts are part of the backup; it is larger than the upload limit, or PHP runs out of memory while restoring (`memory_limit` — about twice the size of the backup is needed) | Raise the limits ([Upload limits](webserver.md#upload-limits-and-php-memory-v310)). A snapshot under Settings → Data can be restored without an upload. For a quick backup of the figures only: `GET /api/backup/export?attachments=0` ([API](../referenz/api.md#snapshots-and-import-v260)) |
| A reading makes a huge jump | Typo or a meter swap that was not entered | Correct the reading; enter a swap under ⚙️ Meters → Meter swap with the final reading of the old device. The readings table marks such readings "IMPLAUSIBLE" |
| Values from Home Assistant carry "CHECK" | The reading is lower than the previous one (suspected faulty value) | Check and confirm with ✅ — only then does it count |
| “This meter records consumption per period …” (from Home Assistant too) | *(v3.1.0)* The meter records “consumption per period” and takes no readings (`errors.reading.periodMeter`, `errors.ingest.periodMeter`) | Enter a period, or create a separate meter with “Meter readings” for readings ([Heat](../verstehen/15-waerme.md)) |
| “The recording method cannot be changed …” | *(v3.1.0)* The meter already has readings or periods of the current kind (`errors.meter.captureLocked`) | Create a new meter with the desired recording; the old data stays with the old meter |
| The “Tenancy” page is missing | *(v3.1.0)* It only appears with “I live: in a rented home” | Settings → Household & building → Home and hot water ([As a tenant](../anleitungen/mieter.md)) |

## Photos, queue and text recognition *(v3.1.0)*

| Symptom | Cause | Fix |
|---|---|---|
| “The image cannot be read – please choose another photo.” | The browser cannot open the image format (HEIC in some Android browsers, say) | Take the photo with the camera from the app or save it as JPEG |
| “The storage for receipts is full …” | All receipts together reach `attachments_max_mb` (default 500 MB) | Raise the limit under Settings → Expert → Receipts or remove old photos (readings table → thumbnail → “Remove photo”; the file goes after 24 hours) |
| A reading stays under “Not saved yet” | No network, the server does not answer, the sign-in has expired — or the entry shows a conflict or “Not saved: …” | Check the connection and “Send now”; sign in; settle a conflict with “Replace” or “Keep existing”, correct a rejected reading with “Edit” ([Use on your phone](../einstieg/handy.md#the-not-saved-yet-queue)) |
| Readings recorded offline are gone | The queue lives in the device's browser; Safari may delete the data of websites that are not installed after seven days without use; clearing the website data in the browser empties it too | Put the app on the home screen and open it again with a connection soon |
| Text recognition: “cannot be reached”, “did not answer within … seconds”, “not an address in the home network” | The service cannot be reached, is too slow, or the address is outside your own network | [Text recognition in the home network → If something does not work](../anleitungen/texterkennung.md#if-something-does-not-work) |
| Text recognition with a long time limit ends without a message from the app (`504`) | The web server waits less than `ocr_timeout_s`. Since v3.1.0 the Docker image waits up to 310 s (previously 120 s) | Set your own web server or reverse proxy above 300 s (`fastcgi_read_timeout`, [Web server](webserver.md#upload-limits-and-php-memory-v310)) or lower the time limit |

## Calculating and display

| Symptom | Cause | Fix |
|---|---|---|
| No costs | No contract with a unit price for the period | Create a contract ([Annual bill](../anleitungen/jahresabrechnung.md)) |
| Gas in kWh differs from the bill | Only the default factor 11.5 is entered, or a period is missing | Enter volume correction factor and calorific value per period; Check a bill shows the sections |
| Balance differs from the bill | Billing date, a missing refund or back-payment, a missing factor, an estimated reading | [Enter and check the annual bill](../anleitungen/jahresabrechnung.md#5-compare-the-balance) |
| Analysis, weather adjustment or forecast empty | Too little data: 12 months with at least 8 usable points, anomalies from 5 months; or no temperatures | Keep reading; sync temperatures under Settings → Weather data |
| No temperatures, the sync fails | No location set, or the server cannot reach `archive-api.open-meteo.com` or `api.open-meteo.com` (firewall, proxy) | Search for and take over the place; allow outgoing HTTPS connections from the server to Open-Meteo — or import temperatures as CSV |
| “Load from SMARD” reports an error *(v3.1.0)* | The server cannot reach `www.smard.de` (firewall, proxy, no curl in PHP), or SMARD currently returns no values | Try again later; allow outgoing HTTPS connections to SMARD — or import the download from smard.de as a file (“Import file”) |
| Degree days seem too high or too low | The location is still the country default | Set your own place under Settings → Weather data; a note shows when the default is in use |
| No efficiency class | No whole year (360 covered days), no floor area, or a country without a scale | Floor area under Settings → Household & building; see the [FAQ](../einstieg/faq.md#why-is-the-efficiency-class-missing) |
| Numbers or dates in the wrong format | Language or country do not fit | Settings → General → Language & country |
| Umlauts missing in the PDF annual report | The PHP extension `iconv` is missing | Install `iconv`; the report also works without it, with simplified umlauts |
| PDF or CSV in another language than the app | Downloads are produced in the default language of the installation; since v3.1.0 the app shows the language of the device | Change the default language under Settings → General → Language & country — or use the print view of the annual report, which follows the device |
| CSV opens in Excel in one column or with wrong numbers | CSV format 1 (semicolon, decimal comma) in an Excel that expects a comma and a decimal point | Settings → Data → Data export: choose "Spreadsheet in the default language" ([CSV formats](../referenz/api.md#csv-formats-v310)) |
| CSV import reports "month above 12" | A date with the month before the day (US style `01/15/2026`); the app does not reinterpret it | Write the date as `DD.MM.YYYY`, `DD/MM/YYYY` or `YYYY-MM-DD` |

## Home Assistant

| Symptom | Cause | Fix |
|---|---|---|
| `401` on push | Wrong or revoked token — or Apache does not pass the `Authorization` header on to PHP | Generate a new token; with Apache check that the shipped `.htaccess` applies |
| `400` "No meter found for … (neither as alias nor as ID)" | Alias or meter ID is wrong, or the utility in the URL does not match | Check the alias under Settings → Integrations |
| Values arrive, but in the wrong unit | Home Assistant delivers kWh, the meter counts m³ (or the other way round) | [The units must match](../anleitungen/home-assistant.md#important-the-units-must-match) |

More in the [troubleshooting of the Home Assistant guide](../anleitungen/home-assistant.md#troubleshooting).

## Finding out more

- **Diagnostics:** Settings → System → System diagnostics — version, schema,
  data directory, write permissions, number of meters and readings.
- **Health:** `GET /api/health` reports `ok`, `degraded` or `error` (then with
  HTTP 503).
- **Log:** Docker: `docker logs energietracker`; more detail with
  `ET_LOG_LEVEL=debug`. A `500` names an error ID, the same one is in the log
  ([Security → Logging](sicherheit.md#10-logging-and-troubleshooting)).

---

[← Compendium index](../README.md)
