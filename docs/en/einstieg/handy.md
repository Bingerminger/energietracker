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
| Save readings and other changes | — the app reports that nothing was saved; the input stays on the card until the connection is back and you save again |
| Sync weather data | — |

A buffer that saves input later by itself does not exist and is not planned:
meters are mostly read on your own Wi-Fi.

## 5. On the road

Outside the home network only with **sign-in** switched on and over HTTPS —
easiest through a VPN (Tailscale, WireGuard, the router's VPN), so the app stays
invisible on the internet. The checklist is in
[Security & network operation](../betrieb/sicherheit.md#11-checklist-before-exposing-it).

---

[← Compendium index](../README.md)
