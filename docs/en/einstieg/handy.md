# Use on your phone

[Deutsch](../../einstieg/handy.md) · **English**

[← Compendium index](../README.md)

The Energietracker is built for reading meters on the spot: a tab bar in the
thumb zone, all meters in one pass, large touch targets. What you need for it
and what works without a network.

---

## 1. Open it on the home network

The Energietracker runs on a computer or NAS in your network. On a phone in the
same Wi-Fi its address in the browser is enough, for example
`http://<address>:8080` — the router or the NAS shows the address. That is
enough for reading, entering and all evaluations.

## 2. As an app on the home screen

- **iPhone/iPad:** open in Safari → Share → **"Add to Home Screen"**.
- **Android:** in Chrome → menu → **"Install app"** or "Add to Home screen".

The installed app starts without the browser bar, reaches under the clock and
home indicator and shows the most recently loaded data even without a network.

> **This needs HTTPS.** Browsers only allow the service worker — the basis of
> the offline view — in a secure context: over HTTPS or `localhost`. Over
> `http://<address>` the home-screen icon is just a bookmark. HTTPS on the home
> network works through a reverse proxy with a certificate (Synology with Let's
> Encrypt, Caddy, nginx, Traefik) or through a VPN such as Tailscale with its
> own certificate — see
> [Security & network operation → HTTPS](../betrieb/sicherheit.md#7-https).

## 3. Read meters quickly

- **＋ Add** in the tab bar → "Enter meter readings": all meters on one page,
  the last reading above each. The keyboard shows "Next" and jumps to the next
  field, from the last one to "Save all".
- **Photograph the register:** tap the field → "Scan Text" (Live Text) — the
  digits land in the field.
- **Photo as a receipt (since v3.1.0):** every card has **“📷 Photo”**; on a
  phone it opens the camera. The browser scales the picture down to at most
  1600 pixels and saves it again as JPEG — the EXIF data including the GPS
  location are dropped along the way. The photo is saved with the reading; in
  the reading table of the utility a thumbnail points to it.
- **Let the photo suggest the reading:** if your own text recognition service
  in the home network is set up, the server reads the value from the photo and
  the card shows “Recognised: … – **Use**”. Nothing is saved until you click.
  Setup: [Text recognition in the home network](../anleitungen/texterkennung.md).
- **Bookmark per meter:** `#/zaehlerstaende?meter=<id>` opens the capture with
  exactly this meter in focus — as a home-screen icon or in a shortcut. The
  address bar shows the ID when "To do" leads straight to a meter;
  `GET /api/readings-overview` lists all IDs (field `meter_id`).
- Typos are caught by the plausibility check ("That would be 400 kWh a day,
  usually it is 8"), and after saving there are ten seconds of **"Undo"**.

## 4. What works without a network

| | without a network |
|---|---|
| View the overview and evaluations | ✓ with the state of the last visit; the top bar shows "Offline – data as of …" |
| Save readings in “Meter readings” | ✓ since v3.1.0: the reading waits in the browser and is sent later (see below) |
| Photo with the reading | ✓ waits with the reading and is uploaded before it |
| Monthly value of a meter with consumption per period | ✓ waits like a reading ([Heat](../verstehen/15-waerme.md)) |
| Let the photo suggest the reading | — text recognition runs through the server |
| Save other changes (contracts, settings, the reading table of a utility …) | — the app reports that nothing was saved; the input stays until the connection is back and you save again |
| Sync weather data | — |

### The “Not saved yet” queue

If saving in “Meter readings” fails for lack of a connection — no network, or
the server does not answer in time — the reading is not lost. It goes into a
queue in the browser of this device. The card shows ⏳ “Waiting for a
connection”, the list **“Not saved yet”** appears at the top of the view, and a
number on the “Meter readings” menu item and on the ＋ in the tab bar keeps
count.

Sending happens by itself: as soon as the device is online again, as soon as
the server answers again, when the app starts and when you bring it back to the
foreground — or right away with **“Send now”**. Nothing is doubled: every entry
carries an identifier, and the server never creates a second reading for the
same identifier.

- **Conflict:** if the meter already has a different reading on the same day
  (from Home Assistant or a second device, say), the app does not silently
  write over it. The entry shows the existing value and offers **“Replace”** or
  **“Keep existing”**.
- **Rejected:** if the server turns a reading down, “Not saved: reason” appears
  next to it. **“Edit”** puts the values and the photo back into the card, from where you
  save as usual; **“Discard”** deletes the entry.
- If the sign-in has expired, sending stops until you sign in again.

Limits:

- The queue lives in the browser of **this** device. Another device does not
  see it.
- Safari may delete the data of a website that is not on the home screen after
  seven days without use. If you read meters offline, put the app on the home
  screen (section 2) and open it again with a connection soon.
- Without HTTPS there is no service worker: without a network the app does not
  load again. The page has to be open already before the connection drops.
  Whatever is in the queue goes out the next time you open the app with a
  connection.
- Sending only happens while the app is open. There is no sending in the
  background — iOS cannot do it.

## 5. On the road

Outside the home network only with **sign-in** switched on and over HTTPS —
easiest through a VPN (Tailscale, WireGuard, the router's VPN), so the app stays
invisible on the internet. The checklist is in
[Security & network operation](../betrieb/sicherheit.md#11-checklist-before-exposing-it).

---

[← Compendium index](../README.md)
