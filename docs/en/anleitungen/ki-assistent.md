# Connect an AI assistant (MCP)

**English** · [Deutsch](../../anleitungen/ki-assistent.md)

> **Goal:** you ask your AI “How is my gas balance?” or “What is coming up in
> the next few weeks?”, and it looks it up in Energietracker. It tells you where
> every figure comes from. It can only change things as a proposal: by default you
> record it yourself in the app ([Who confirms a
> change?](#who-confirms-a-change)).

Since **v3.12.0** Energietracker speaks the **Model Context Protocol (MCP)**,
the common language AI programs use to work with tools. The AI is the user
interface, Energietracker the record behind it: it calculates, the AI tells.
The AI gets the same figures as the app’s views, each with its
[basis](../verstehen/18-herkunft.md) (measured, entered, distributed, assumed,
estimated).

The interface is **off by default**. An admin switches it on and creates a key
of its own for every AI program.

---

<a id="mehrwert"></a>

## What does it do for you?

MCP is an open standard through which an AI program finds out by itself which tools
a service offers. You ask in plain language, the AI gets the answer from your
Energietracker, and the calculating still happens there.

Six examples. **All answers are invented**, the tools are real.

**1. “Is my gas bill right?”** Tools: inbox, `get_document`, `check_bill`.
> *Invented answer:* “The quantity fits, the amount is €14.20 above our own
> calculation: the standing charge on the bill is higher than in the contract. This
> is a pointer, not legal advice.”

You create the letter in the app.

**2. “Why is my balance worse than last year?”** Tools: `get_overview`,
`get_consumption`, `get_contracts`. There is no view of its own for this. The AI puts
the figures side by side and separates measured from estimated.
> *Invented answer:* “Measured: 9% more consumption up to the last reading, plus a
> higher unit price since April. The rest until December is estimated, a range of €40
> to €95 back payment.”

**3. “What would 1 degree less bring me, and what a new tariff?”** Tool:
`run_scenario`, calculated against the baseline.
> *Invented answer:* “Against the baseline: 1 degree less saves an estimated €110 a
> year, the tariff from your offer €65, both together €170.”

**4. “What is coming up this month?”** Tool: `get_deadlines`, plus the prompt
`monthly_report`.
> *Invented answer:* “Electricity notice deadline on 30 Nov, water reading due, Anna
> takes care of the maintenance on 18 Nov.”

**5. “Gas reading 13,200”** Tool: `propose_reading`. It checks with the same questions
as the form; you confirm in the app.
> *Invented answer:* “Proposal created: gas, reading 13,200 m³ from today, 96 m³ since
> the last reading, nothing unusual. Nothing has changed yet: confirm it in the app.”

**6. Several services together.** This depends on the AI program and on further MCP
servers; **Energietracker alone cannot do this**. With a tariff search on the web,
`run_scenario` checks an offer against your consumption. Through a calendar server,
deadlines from `get_deadlines` become appointments. With Home Assistant the base load
can be put next to your meter readings.

**Copied text against MCP:**

| | Copying text into a chat | Through MCP |
|---|---|---|
| Up to date | as of the moment you copied | fresh from your data with every question |
| Basis of each figure | gets lost | measured, entered, distributed, assumed or estimated is on every figure |
| Who calculates | the language model | Energietracker, the AI tells |
| Changes | by hand | only as a proposal that you confirm |

Unlike the [programming interface](../referenz/api.md) you program nothing: you enter
a template into your AI program.

**The limits:**

- The AI can misread correct figures. Energietracker supplies every figure with its
  basis and every balance with a ready sentence, but it does not decide what the model
  makes of it.
- What it reads goes to its language model. Locally with Ollama through Open WebUI or
  with LM Studio it stays in the house, in the cloud it goes to the provider.
- If you use no AI, you lose nothing: the interface is off by default.

<a id="programme"></a>

## What you need

- Energietracker v3.12.0 or later, reachable in the home network (Docker, web
  server or the app for Home Assistant).
- An AI program with MCP over HTTP (“Streamable HTTP”) that can send a header
  with a key.

<!-- doc-claim: mcp-tested-with -->
**Checked with Open WebUI 0.12.4 and a local model through Ollama 0.40.14
(November 2026).** Claude Code, VS Code, Cursor, LM Studio and cloud models are
**experimental**: the templates follow the manufacturers’ guides, we have not checked
them ourselves. Feedback is welcome, in the
[Discussions](https://github.com/Bingerminger/energietracker/discussions) or the
[Issues](https://github.com/Bingerminger/energietracker/issues).

| Program | Status | Where it runs | Note |
|---|---|---|---|
| Open WebUI 0.6.31 or later | **recommended** | on a server in the home network, with Ollama | everything stays in the home network; only admins add tool servers |
| Claude Code | experimental | on your computer | prompts as commands, resources to attach |
| VS Code (Copilot Chat) | experimental | on your computer | key as a hidden input |
| Cursor | experimental | on your computer | |
| LM Studio | experimental | on your computer, with a local model | everything stays in the home network |

A **cloud model** is experimental as well: the data then goes to the provider, and
costs may arise there.

Programs that connect from their provider’s **cloud** (connectors in the
browser or in desktop apps) do not reach your home network. See
[From the cloud](#from-the-cloud-only-with-your-own-https-access) below.

## Step 1 — Switch it on

**Connections → AI assistants**, switch **Switch on the AI
interface** (admins only, setting `mcp_enabled`). While it is off, the
interface answers with `403` and says where to switch it on.

If you never want the interface on a server, set the environment variable
`ET_MCP=off` ([Docker](../betrieb/docker.md#configuration-via-environment-variables)).
Then it stays closed even if someone flips the switch.

Three more switches on the same card:

- **Confirm changes in the app only** (`mcp_apply_in_app`), on by default:
  [Who confirms a change?](#who-confirms-a-change)
- **Give identifiers to the AI** (`mcp_share_identifiers`), off by default: meter
  number, market and metering location, customer, contract and invoice number. Without
  the switch the app replaces **numbers it knows** wherever the AI receives text: fields,
  quotes, file names, provider, tariff, notes, names. A quote is dropped if it still holds
  a long number or a number after a label (“Customer no.”, “Kassenzeichen”). In a file
  name every run of five or more digits that is not a date is replaced.

  **The limits:**

  - **Provider and tariff** do not count as identifiers and go to the AI.
  - The app does not recognise a **number without a label** if it is short and appears
    nowhere in the household (“Please state 54321”).
  - What the AI reads **from an original itself**, or what you give it, the app cannot
    hold back.
- **Give originals to the AI** (`mcp_share_documents`), off by default: the AI may fetch the
  PDFs from the inbox, for instance to read a bill itself.

## Step 2 — Create a key

Create a key on the same card, **one per program and device** (such as “Claude
Code on the laptop”). The name appears in the log and in the history. The app
shows the key (`etk_…`) **once**; only its hash is stored.

| Scope | may |
|---|---|
| AI read (`mcp`) | all reading tools, the prompts and the resources |
| AI read and propose (`mcp_write`) | in addition read documents, propose changes and apply them after your consent; also put a PDF into the inbox (`POST /api/inbox`) |

Start with **AI read**. The interface **always** requires such a key, even when
sign-in is off in Energietracker. Other keys (read, admin, calendar, inbox) do
not count here, and an AI key counts nowhere else.

## Step 3 — Address of the interface

The card shows the address. It depends on how Energietracker runs:

| Setup | Address |
|---|---|
| Docker (nginx in the image) | `http://192.168.178.20:8080/api/mcp` |
| Apache, Synology Web Station | `http://192.168.178.20/energietracker/api.php/api/mcp` |
| Reverse proxy with HTTPS | `https://energie.example.org/api/mcp` |
| App in Home Assistant | `http://homeassistant.local:8095/api/mcp`; it does not work over Ingress (sidebar): in the app under **Configuration → Network**, open a port, such as `8095` ([Home Assistant](home-assistant.md#as-an-app-in-home-assistant-os)) |

Replace the example addresses with yours. The card always names the address
with `api.php`: it works with every web server; in the Docker image and in the
app in Home Assistant `/api/mcp` as in the table works as well. A test in the
browser does not help: the interface only accepts `POST` with a key.

## Step 4 — Connect Open WebUI with Ollama

This is the recommended way: model and data stay in the home network. In all examples
`etk_…` stands for your key.

### Open WebUI

1. Install **Ollama** and load a model that can call tools (marked “tools” in Ollama’s
   model overview): `ollama pull <model>`.
2. Start **Open WebUI** 0.6.31 or later and connect it to Ollama as its guide describes.
3. Create the key in Energietracker (step 2). Start with **AI read**.
4. In Open WebUI as an admin: **Settings → Integrations → Tools, External Tool
   Servers → +** (the labels of version 0.12.4; other versions may differ), type
   **MCP (Streamable HTTP)**, the address from step 3 as URL, authentication **Bearer**
   with the key. You can also import the entry there, with your address and key:

   ```json
   [{"type":"mcp","url":"http://host.docker.internal:8080/api/mcp","spec_type":"url","spec":"","path":"openapi.json","auth_type":"bearer","key":"etk_…","info":{"id":"energietracker","name":"","description":"energietracker"}}]
   ```
5. Open WebUI often runs in a container. Then `localhost` means the container itself:
   use `http://host.docker.internal:8080/api/mcp` (on Linux with
   `--add-host=host.docker.internal:host-gateway`) or the computer’s address in the home
   network, such as `http://192.168.178.20:8080/api/mcp`; on Apache and Synology with
   `api.php` in the path (step 3). If `ET_ALLOWED_HOSTS` is set, the name must be listed
   there.
6. In the chat, switch on “energietracker” under the tools and ask: “How is my gas
   balance?”. The answer should state the same figure as the balance card. If the tool
   is not offered, sign out of Open WebUI once and sign in again.

With a model from Ollama nothing leaves the home network.

## Other programs (experimental)

These templates follow the manufacturers’ guides and have not been checked by us. Where
possible, the key is not in plain text in the file.

### Claude Code

```bash
claude mcp add --transport http --scope user energietracker 'http://192.168.178.20:8080/api/mcp' --header 'Authorization: Bearer etk_…'
```

`--scope user` makes the entry apply in all projects: your household belongs to
none of them. Or for a project in `.mcp.json`, with the key from the environment
variable `ENERGIETRACKER_KEY`:

```json
{
  "mcpServers": {
    "energietracker": {
      "type": "http",
      "url": "http://192.168.178.20:8080/api/mcp",
      "headers": { "Authorization": "Bearer ${ENERGIETRACKER_KEY}" }
    }
  }
}
```

### VS Code

`.vscode/mcp.json` (or “MCP: Add Server” in the command palette); VS Code asks
for the key at the first start and stores it hidden:

```json
{
  "inputs": [
    { "type": "promptString", "id": "energietracker-key", "description": "API key for Energietracker", "password": true }
  ],
  "servers": {
    "energietracker": {
      "type": "http",
      "url": "http://192.168.178.20:8080/api/mcp",
      "headers": { "Authorization": "Bearer ${input:energietracker-key}" }
    }
  }
}
```

### Cursor and LM Studio

Both read the same form: Cursor `~/.cursor/mcp.json`, LM Studio the file
`mcp.json` that you open under **Program → Install → Edit mcp.json**:

```json
{
  "mcpServers": {
    "energietracker": {
      "url": "http://192.168.178.20:8080/api/mcp",
      "headers": { "Authorization": "Bearer etk_…" }
    }
  }
}
```

Some versions of LM Studio treated an entry with `url` as the old SSE
transport; if no connection comes about, update the version.

## Examples

Just ask in your own words:

- “How is my gas balance?” The AI gives the measured balance up to the last
  reading and the expected one at settlement, with its range.
- “How much electricity did I use in March, and is that measured?”
- “What is coming up in the next 60 days, and who takes care of it?”
- “What if the winter is 20% colder?”
- “Enter gas 12,345.6 m³ for today.” With an “AI read and propose” key the AI
  first shows you the proposal; it is entered only once you confirm it.
- “Check the gas bill in the inbox.”

**Prompts** are ready-made tasks. Depending on the program they appear in the prompts
menu or as commands when you type `/`:

<!-- mcp-prompts -->
| Prompt | What it does | Arguments |
|---|---|---|
| `monthly_report` | monthly report: consumption against the same month last year, costs, balance, what is coming up, one tip | `month` (YYYY-MM, otherwise the last full month), `utility` |
| `yearly_report` | yearly report like the app’s PDF | `year` (otherwise last year), `billing_period` (`true`: billing year) |
| `bill_review` | check a bill: is it right, reasons, difference in euros and quantity | `document_id` (inbox) or `bill_id` (recorded), `utility` |
| `deadlines_check` | what is coming up: what to do, by when, who is responsible | `days` (1 to 366, otherwise 90) |
<!-- /mcp-prompts -->

**Resources** are reading material for the AI (depending on the program, to attach):

<!-- mcp-resources -->
| Resource | Content |
|---|---|
| `energietracker://hilfe/glossar` | glossary and formulas |
| `energietracker://hilfe/herkunft` | [Where a figure comes from](../verstehen/18-herkunft.md) |
| `energietracker://hilfe/{path}` | any page of this manual, such as `verstehen/00-overview.md`, English under `en/` |
| `energietracker://dokument/{id}.pdf` | an original from the inbox, only with “Give originals to the AI”, up to 5 MB |
<!-- /mcp-resources -->

## What the AI can do

<!-- mcp-tools -->
- **Read** (scope `mcp`): `get_overview` (start here: how are things?),
  `list_meters`, `get_deadlines`, `get_contracts`, `get_consumption`,
  `get_forecast`, `run_scenario` (what if), `list_readings`, `list_bills`,
  `check_bill`, `get_insights`, `get_yearly_report`, `list_documents`,
  `get_document`, `get_document_schema`.
- **Propose and apply** (scope `mcp_write`): `read_document` (the AI reads a
  document itself), `propose_reading`, `propose_document`, `apply_proposal`,
  `cancel_proposal`.
<!-- /mcp-tools -->

The server describes the input and output of every tool itself (JSON Schema in
`tools/list`); the overview is in the
[API reference](../referenz/api.md#ai-interface-mcp-v3120).

### Bills and other documents

A language model cannot send a PDF as text to a tool. The original therefore
always comes through the [inbox](posteingang.md): through the user interface,
the watched folder, e-mail via Home Assistant or with the AI key (scope
`mcp_write`) using `curl`:

```bash
curl -H "Authorization: Bearer etk_…" -H "Content-Type: application/pdf" \
  --data-binary @gas-bill.pdf \
  "http://192.168.178.20:8080/api/inbox?name=gas-bill.pdf"
```

Then the AI finds the document with `list_documents`. If it has been read
already (in the app), the AI proposes taking it over; otherwise it reads it
itself and supplies every item with quote and page, which Energietracker checks
against the text just like reading in the app.

<a id="wer-bestaetigt"></a>

## Who confirms a change?

The AI never writes directly. It creates a **proposal** (`propose_reading`,
`propose_document`); it checks everything that saving would check, but writes nothing.
Energietracker builds the summary itself from the data, with everything that moves money
or deadlines.

**Why the switch exists.** The AI program’s prompt is only a request: Energietracker sets
`_meta["anthropic/requiresUserInteraction"]`, but whether a program honours it has not been
checked, and Energietracker cannot check it either. If a program is set to “always allow”,
a script runs with the key or a document contains an injected instruction, without the
switch there would be no human in between. Hence: **Confirm changes in the app only**
(`mcp_apply_in_app`), on by default.

**What it protects:** no AI and no document can change anything in the household **through
the key and the tools of the interface** unless you click **Apply** in the app and see the
whole sentence. Apply and reject accept no key (403), even without sign-in, and only work
from the app’s own interface.

**What it does not protect:**

- **Reading.** What the AI reads goes to its language model.
- **The app itself when sign-in is off.** Without sign-in any device in the home network can
  operate the app. A program without a key that presents itself as a browser can then apply
  a proposal or turn the switch off. This also goes for an AI that has network or shell
  access of its own besides its key. The server cannot tell it from you. **Only sign-in
  helps against this:** then it takes your session and the admin role. If it is off, the card
  says so below the switch.
- **Your decision.** A wrong proposal that you apply anyway is recorded. `read_document` may
  replace a reading at a document that has not been checked yet (with a history entry, never
  after a correction, an apply or once you have filed it).

### With the switch (default)

1. You write: “Enter my gas reading 13,200.”
2. The AI calls `propose_reading`. Nothing is written except the proposal.
3. The AI answers along these lines: “Proposal created: *Gas: enter reading 13,200 m³ on
   04/11/2026 (estimated: no).* Nothing has changed yet. Confirm it in the app.” The exact
   wording with path and deadline is in the field `message`.
4. In the app you open **To do**, filter **AI proposals**, and
   click **Apply**. The prompt **Apply proposal?** shows the whole sentence, the key and the
   meter.
5. The AI asks with `apply_proposal` and reports the result: before, it got
   `status: awaiting_confirmation`, now `status: applied` with `repeated: true`.

The history names both: **AI (key “…”), confirmed by** you.

### Without the switch

1. You write: “Enter my gas reading 13,200.”
2. The AI calls `propose_reading` and shows you the sentence.
3. You say “yes”. The AI calls `apply_proposal` with the ID and the sentence word for word;
   Energietracker asks your program to prompt you. Whether it does is up to the program.
4. The reading is recorded (`status: applied`). The history names **AI (key “…”)**.

### Example with a bill

1. You put the PDF into the inbox and write: “Apply the gas bill.”
2. The AI reads the document with `read_document` or takes the reading from the app, then
   `propose_document`. It tells you:
   *Gas: apply from document inb_… (Bill): Meter reading (31/12/2025, 13,580 m³); Bill (01/01/2025 –
   31/12/2025, 13,216 kWh, amount €1,722.70, advance payments €1,680.00, back payment €42.70);
   Book the bill’s result (back payment €42.70).* The figures are in the sentence so that you
   can compare them. The provider stands only next to it, as text from the document.
3. You compare with the bill and click **Apply**, or **Reject**.

### What always applies

- With the switch, a proposal is valid for **24 hours**, because you have to get to the app
  and need not sit next to the AI program. Without it, a proposal is valid for **15 minutes**.
  In both cases only once; after that the AI starts again.
- It belongs to the key that created it. At most **50** open proposals per key. The proposal
  of a revoked key no longer applies.
- If a question arises after the proposal, for instance because another reading was recorded
  in the meantime, nothing is written and the AI creates a new one.
- The apply appears in the **history** with the name of the key and the list of changes; a
  reading carries the source `mcp`. **There is no undo for it:** delete individually in the
  app what is wrong.

Open proposals are under **To do**, the log of the accesses (who, when, which tool, without
content) under **To do → History → AI access**. If a proposal is waiting, the overview also says so
above “To do”.

## Limits

- **The AI can leave things out and make mistakes.** The figures from the tools are
  right, the story told about them not always. In our acceptance test a local model
  named the deadlines of the next 30 days correctly but left out some entries and added
  a later date. For deadlines that matter, look under “To do”.
- **Rate limits per key:** 120 requests per minute, of which 60 calls of
  reading tools; 30 proposals and readings of documents per hour and 30 applies
  per hour. After 20 wrong keys in 10 minutes the server blocks the address for
  5 minutes. Beyond that it answers with `429` and `Retry-After`.
  `cancel_proposal` counts like a reading tool: discarding still works when the
  proposals of the hour are used up. Filing in the inbox with the AI key: 30 per hour, a
  PDF up to 10 MB, only with the interface switched on, with a line in the access log.
- **Request at most 1 MB.** An original goes to the AI up to 5 MB; open larger
  ones in the app.
- **Texts for the AI** (descriptions, prompts, messages) exist in German and
  English; with another language of the installation in English. The AI still
  answers in your language.
- **Foreign text shortened:** names up to 120, quotes up to 300, notes up to 500
  characters.
  Texts of the app about a finding (rights, insights) up to 2000 characters.
- **Request and response only:** no session, no event stream (SSE), no
  subscriptions. Programs that only speak SSE do not work; this includes Home
  Assistant’s “Model Context Protocol” integration.
- **No OAuth.** Programs that insist on signing in through OAuth do not
  connect.
- **No tools for settings, people, keys, backups or deleting.** That stays in
  the app.
- Long reading with a local model and reports as PDF stay in the app; the AI
  points to them.
- The public demo has no AI interface (it runs without a server).

## From the cloud: only with your own HTTPS access

AI services that connect from their provider’s cloud need a **public HTTPS
address**. Most of them also require signing in through OAuth, which
Energietracker does not offer in v3.12.0. Only if your service allows a header
of its own (`Authorization: Bearer …`) does it work through a reverse proxy of
your own. This is a guide, not a recommendation: you put access to your
household on the internet, protected only by the key.

1. Put a reverse proxy with a valid certificate in front of Energietracker
   that lets **only** `/api/mcp` through. Example Caddy:

   ```text
   energie.example.org {
       @mcp path /api/mcp
       handle @mcp {
           reverse_proxy 192.168.178.20:8080
       }
       handle {
           respond 404
       }
   }
   ```

2. Set `ET_ALLOWED_HOSTS=energie.example.org` in Energietracker
   ([Security](../betrieb/sicherheit.md#8-host-names-and-embedding)).
3. Create a key **AI read** of its own just for this service.
4. Enter the address `https://energie.example.org/api/mcp` and the header in
   the service.
5. Check that nothing else is reachable from outside:
   `curl -i https://energie.example.org/api/summary` must return `404`.

Keep in mind: everything the AI reads goes to the provider of the service.
Revoke the key as soon as you no longer need the access; rate limits and the
block after wrong keys apply here too.

## Security and privacy

- **What the AI reads, the provider of the language model sees.** With a local
  model (LM Studio, Ollama with Open WebUI) everything stays in the home
  network; with a model in the cloud the tools’ answers go there.
- **What never goes out:** passwords and keys (not even hashes), settings with
  network addresses, the list of people, backups, logs and the proposals of
  other keys. Identifiers and originals only with the switches from step 1.
- **Details for letters never** *(v3.12.0)*: the sender’s name and address,
  the supply address (Household › Building) and the supplier’s address on the
  contract. No tool hands them out; if they appear in a note, a meter name or
  a document, the app replaces them with `[…]`, also with the switch for
  identifiers. **Limit:** the app does not recognise them written differently
  (abbreviated, with additions), and a shared original shows the document as
  it is. <!-- doc-claim: letter-data-private (McpLetterDataTest) -->
- **Foreign text is data:** names, notes and quotes from documents appear only
  cleaned in the field `untrusted`. If someone writes “Ignore all
  instructions” into a bill, it is a quote for the AI, not an instruction, and
  it can only change anything through a proposal that you confirm in the app.
- **No legal advice:** what the AI says about deadlines, contracts and rights
  is a pointer from your data.
- **Treat keys like passwords:** do not write them into shared project files,
  one per program, revoke those no longer needed (Connections → AI assistants → Connect).
- Over the interface the AI only reads; it never starts reading in the cloud
  for the inbox.

More in [Security & network operation](../betrieb/sicherheit.md#ai-assistants-mcp-v3120).

## Troubleshooting

| Message | Cause and fix |
|---|---|
| `401` | No key or a wrong one. Header exactly `Authorization: Bearer etk_…`; create revoked keys anew. Some programs look for an OAuth sign-in after a `401` and report an error about it: the cause is still the key. |
| `403` “switched off” | Step 1; or `ET_MCP=off` is set. |
| `403` with `insufficient_scope` | A key without an AI scope (such as “read” for Home Assistant), or a tool for proposing with an “AI read” key. |
| `404` | Wrong address: on Apache and Synology `api.php` belongs in it (step 3). |
| `413` | Request over 1 MB. |
| `429` | Rate limit; it continues after `Retry-After` seconds. |
| No tools visible | Reload the program; some programs only show tools after switching them on in the chat. |

---

[Inbox](posteingang.md) · [Where a figure comes from](../verstehen/18-herkunft.md) ·
[Compendium index](../README.md)
