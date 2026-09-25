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
| "No connection to Energietracker – nothing was saved." | The server is not reachable (other Wi-Fi, server off, VPN disconnected) | Check the connection; meanwhile the installed app shows the last state ("Offline – data as of …") |
| The app cannot be installed on the phone or shows nothing offline | Opened over `http://`; the service worker and installation need HTTPS | HTTPS through a reverse proxy — [Use on your phone](../einstieg/handy.md) |
| After signing in, the sign-in screen keeps coming back | The session cookie does not arrive (proxy, other host name, embedding) | [Security & network operation](sicherheit.md#8-host-names-and-embedding) |
| Password forgotten | — | Set `"mode"` to `"off"` in `data/auth.json`, or start the Docker container with `ET_AUTH=off`; then set a new password ([Security](sicherheit.md#3-switching-sign-in-on)) |

## Saving and data

| Symptom | Cause | Fix |
|---|---|---|
| Saving fails, the diagnostics report missing write permissions | The web server user may not write `data/` | Set the permissions ([Installation → Write permissions](installation.md#33-write-permissions)); the diagnostics show which directory is affected |
| After "Try with sample data" your own data is gone | The sample data replaces the existing data; the app creates a snapshot first | Settings → Data → Stored snapshots → restore the snapshot "before demo data" with ↩️ |
| A backup import is rejected | The file is not a backup in format 3.0 or is damaged; the preview names the places | Check the file; an old v0.9.0 backup belongs in the [migration](../anleitungen/migration-v090.md) |
| Upload of large files aborts | Limits of PHP (`upload_max_filesize`, `post_max_size`) or nginx (`client_max_body_size`) | Raise the limits; the Docker image allows 32 MB |
| A reading makes a huge jump | Typo or a meter swap that was not entered | Correct the reading; enter a swap under ⚙️ Meters → Meter swap with the final reading of the old device. The readings table marks such readings "IMPLAUSIBLE" |
| Values from Home Assistant carry "CHECK" | The reading is lower than the previous one (suspected faulty value) | Check and confirm with ✅ — only then does it count |

## Calculating and display

| Symptom | Cause | Fix |
|---|---|---|
| No costs | No contract with a unit price for the period | Create a contract ([Annual bill](../anleitungen/jahresabrechnung.md)) |
| Gas in kWh differs from the bill | Only the default factor 11.5 is entered, or a period is missing | Enter volume correction factor and calorific value per period; Check a bill shows the sections |
| Balance differs from the bill | Billing date, a missing refund or back-payment, a missing factor, an estimated reading | [Enter and check the annual bill](../anleitungen/jahresabrechnung.md#5-compare-the-balance) |
| Analysis, weather adjustment or forecast empty | Too little data: 12 months with at least 8 usable points, anomalies from 5 months; or no temperatures | Keep reading; sync temperatures under Settings → Weather data |
| No temperatures, the sync fails | No location set, or the server cannot reach `archive-api.open-meteo.com` or `api.open-meteo.com` (firewall, proxy) | Search for and take over the place; allow outgoing HTTPS connections from the server to Open-Meteo — or import temperatures as CSV |
| Degree days seem too high or too low | The location is still the country default | Set your own place under Settings → Weather data; a note shows when the default is in use |
| No efficiency class | No whole year (360 covered days), no floor area, or a country without a scale | Floor area under Settings → Household & building; see the [FAQ](../einstieg/faq.md#why-is-the-efficiency-class-missing) |
| Numbers or dates in the wrong format | Language or country do not fit | Settings → General → Language & country |
| Umlauts missing in the PDF annual report | The PHP extension `iconv` is missing | Install `iconv`; the report also works without it, with simplified umlauts |

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
