# Text recognition in the home network

[Deutsch](../../anleitungen/texterkennung.md) · **English**

[← Compendium index](../README.md)

Since v3.1.0 the Energietracker can suggest the meter reading from a photo. To
do so it sends the photo to a vision model that you run yourself in your home
network — with Ollama, LM Studio or another OpenAI-compatible server. Text
recognition is optional and off by default. Without it everything stays as it
is: the photo as a receipt, the reading typed by hand or, on the iPhone, taken
over with Live Text (“Scan Text” in the field).

---

## What for

- **Read meters without typing:** take a photo, check the suggestion, “Use”.
- **Receipt:** the photo stays with the reading. Whoever has doubts later can
  look it up.
- **You decide:** nothing is saved without your click. After that the app
  checks the value like any other
  ([plausibility](../verstehen/11-zaehlerstaende.md)).

---

## Privacy: your own network only

- The photo goes to **your** service, not to a cloud provider. As an address
  the app only accepts targets in your own network: loopback, private networks
  (`10.x`, `172.16.x`–`172.31.x`, `192.168.x`), link-local, `100.64.0.0/10`
  (Tailscale) and the corresponding IPv6 ranges. It refuses a public address.
  There is no switch to lift this.
- The check runs on **every** call, after name resolution, and the connection
  goes to exactly the checked address. A name that suddenly points to the
  internet (DNS rebinding) does not get through. The app does not follow
  redirects.
- **Why through the server?** The request to the service is made by the
  Energietracker server, not by the browser. The app's security rules (Content
  Security Policy) only allow the browser to connect to its own site — that
  stays so with text recognition too. And the photo is on the server anyway, as
  a receipt.
- A photo taken in the app has already been scaled down in the browser (at
  most 1600 pixels) and has no EXIF data — so no GPS location and no camera
  details.
- Without an address the app opens no connection. The privacy text in the
  app's help names your own text recognition service next to Open-Meteo as the
  only outside destination — and only if you set one up.

---

## What you need

- A computer in the home network that runs the service: a PC, a Mac or a NAS.
  With a graphics card (or on a Mac with Apple silicon) vision models answer
  much faster than on a NAS without one; they need several gigabytes of
  memory.
- A **vision model**. Pure language models cannot read images.
- On the Energietracker server the PHP extension curl. Text recognition cannot
  do without it (unlike the weather sync); the Docker image includes it.

---

## Set up Ollama

1. **Install:** [ollama.com/download](https://ollama.com/download) — for
   macOS, Windows and Linux; as a container there is the image `ollama/ollama`.
2. **Fetch a vision model:**

   ```sh
   ollama pull qwen2.5vl
   ```

   Other vision models are `llama3.2-vision` or `minicpm-v`, for example. Which
   one reads your meters best depends on the meter and the photo — try it out.
3. **Make it reachable on the network.** By default Ollama only listens on
   `127.0.0.1`, i.e. only to requests from the same computer. For the
   Energietracker to reach it from a container or from a NAS, it has to listen
   on all interfaces: set the environment variable `OLLAMA_HOST=0.0.0.0` and
   restart Ollama. How to do that per operating system is in the
   [Ollama FAQ](https://github.com/ollama/ollama/blob/main/docs/faq.md)
   (“How do I configure Ollama server?”). The container image `ollama/ollama`
   is already set up that way.
   Ollama has no sign-in: anyone in the home network can then use the model.
   Do not expose the port to the internet.
4. **Check**, ideally from the Energietracker server:

   ```sh
   curl http://192.168.178.20:11434/api/tags
   ```

   The answer lists the fetched models with their names.
5. **In the app:** address `http://192.168.178.20:11434`, interface
   **Ollama**, model `qwen2.5vl` (see [Settings](#settings)).

If Ollama and the Energietracker run on the same computer and the
Energietracker does **not** run in Docker, `http://127.0.0.1:11434` is enough —
step 3 is then not needed.

---

## Set up LM Studio

1. Install [LM Studio](https://lmstudio.ai) and load a vision model — marked
   “Vision” in the model search, such as Qwen2.5-VL.
2. **Start the server:** in the **Developer** section switch the server on
   (port 1234) and enable **“Serve on Local Network”**. Otherwise it only
   accepts requests from the same computer.
3. **Check:**

   ```sh
   curl http://192.168.178.20:1234/v1/models
   ```

4. **In the app:** address `http://192.168.178.20:1234/v1`, interface
   **“OpenAI-compatible (LM Studio, LocalAI)”**, model = the name that
   `/v1/models` lists.

Other OpenAI-compatible servers such as LocalAI work the same way: interface
“OpenAI-compatible”, address up to and including `/v1`. The model has to accept
images; the app sends the photo as a data URI.

---

## If the Energietracker runs in Docker

Inside the container, `localhost` (and `127.0.0.1`) is the container itself,
not the computer it runs on. So:

- Enter the **address of the computer in the home network**, such as
  `http://192.168.178.20:11434` — and start Ollama with
  `OLLAMA_HOST=0.0.0.0`.
- `http://host.docker.internal:11434` only points to the computer under
  **Docker Desktop** (Mac, Windows). On Linux the name only exists if the
  Compose file creates it: `extra_hosts: ["host.docker.internal:host-gateway"]`.
- If Ollama runs as a container of its own in the same Compose file, the name
  of the service is enough, such as `http://ollama:11434`.

All three lead to addresses in your own network and pass the check.

---

## Settings

**Settings → Expert → 🔎 Text recognition in the home network** (at the top of
the page, before the collapsed calculation parameters):

| Field | Key | Entry |
|---|---|---|
| Service address | `ocr_endpoint` | base address with `http://` or `https://`; the app appends the path (`/api/chat` or `/v1/chat/completions`). Empty = off |
| Interface | `ocr_api` | **Ollama** or **OpenAI-compatible (LM Studio, LocalAI)** |
| Model | `ocr_model` | the name as in the service, such as `qwen2.5vl` |
| Time limit | `ocr_timeout_s` | seconds the server waits; default 30 (5–300) — fully usable in the Docker image ([Limits](#limits)) |

Save. The next time you open “Meter readings”, text recognition is active. All
keys:
[Settings → Text recognition in the home network](../referenz/einstellungen.md#text-recognition-in-the-home-network-v310).

---

## How it works in the app

1. Open **Meter readings** and tap **“📷 Photo”** on the meter's card. On a
   phone the camera opens.
2. The photo is scaled down, uploaded and sent to the service. The card shows
   “Uploading photo…”, then “Recognising text…”.
3. “Recognised: 12,345.6” appears with **“Use”**. A tap puts the value into the
   field — check it and correct it if needed.
4. **“Save all”.** If the value is unusual, the plausibility check asks. The
   photo is saved with the reading.

If the model recognises nothing, it says “No meter reading recognised – please
enter it by hand.”; the photo stays as a receipt. Without a connection there is
no suggestion; the photo goes into the queue with the reading; “Edit” brings
reading and photo back from there into the card
([Use on your phone](../einstieg/handy.md#the-not-saved-yet-queue)).

**Good photos:** straight from the front, sharp, without reflections; the
register fills a large part of the picture. Other numbers next to it (meter
number, year of manufacture) can distract a model — then get closer.

The app sends the model a fixed instruction in English: read only the digits of
the register, decimal places as on the roller register, answer as JSON with the
value and a self-assessment. Technical details:
[API reference → `POST /api/ocr/reading`](../referenz/api.md#post-apiocrreading).

---

## If something does not work

| Message | Cause and fix |
|---|---|
| “Text recognition has to run in your own network – “…” is not an address in the home network.” | The address — or one of the addresses the name points to — is not in your own network. Enter the IP address of the computer in the home network. A cloud service is deliberately not possible. |
| “Text recognition did not answer within 30 seconds.” | The model is too slow for the time limit, especially on the first call after a break, when the service first loads the model into memory. Raise the time limit, use a smaller model or run the service on a faster computer. |
| “Text recognition cannot be reached.” | The service is not running, only listens on `127.0.0.1` (`OLLAMA_HOST`, “Serve on Local Network”), a firewall blocks the port, the container has `localhost` ([Docker](#if-the-energietracker-runs-in-docker)) or the name does not resolve. Likewise if the service answers with an error — say, because there is no model by that name (`ollama list` shows the names) or the interface does not match — or the server lacks the PHP extension curl. First test: the `curl` from above, run on the Energietracker server. |
| “Text recognition returned no usable answer.” | The service answered, but without text — a model that cannot make anything of the image, say, or a different service answers at that address. |
| “No meter reading recognised – please enter it by hand.” | The model answered but found no number. Take the photo closer, straighter and sharper, or try another model. |
| The suggestion is wrong or nonsense | A model **without vision** rejects the image (then “cannot be reached”) or answers without seeing it — with an invented value or none. Enter a vision model such as `qwen2.5vl`, `llama3.2-vision` or `minicpm-v`. If decimal places are missing or in the way, correct the value before saving: only what is in the field is saved. |

---

## Limits

- **Duration.** On a NAS without a graphics card, vision models often need 20
  to 60 seconds per photo; a computer with a graphics card is much faster.
- **Hit rate.** It depends on the model and the photo. The app promises none —
  it suggests, you decide.
- **Time limit behind the web server.** Since v3.1.0 nginx in the Docker image
  waits up to 310 seconds for an answer from PHP (`fastcgi_read_timeout`,
  previously 120 s) — so the highest time limit of 300 seconds runs through.
  Behind your own web server or reverse proxy, its limit applies; for long time
  limits set it above 300 seconds there too
  ([Web server](../betrieb/webserver.md)).
- **Only with a connection.** Without a network there is no suggestion.
- **Photos only in “Meter readings”.** The reading table of a utility shows a
  photo and removes it, but does not take one.

---

[← Compendium index](../README.md) · [Use on your phone](../einstieg/handy.md)
