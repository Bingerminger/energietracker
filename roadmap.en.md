# Energietracker — Roadmap

What comes next, in plain words. The [project board](https://github.com/users/Bingerminger/projects/1)
shows what is being worked on, the [milestones](https://github.com/Bingerminger/energietracker/milestones)
where each release stands. You can suggest your own ideas any time under
[Discussions → Ideas](https://github.com/Bingerminger/energietracker/discussions/categories/ideas).

*Auf Deutsch:* [roadmap.md](roadmap.md). For contributors: the [planning page](roadmap-planung.md) with
every open item and the rules, earlier concepts in the [archive](roadmap-archiv.md) (both in German).

**As of:** 28 Oct 2026 · **Current version:** v3.9.0

---

## In progress: v3.10.0 — Promised and secured

- **What we promised you:**
  - Reading documents with newer OpenAI models ([#41](https://github.com/Bingerminger/energietracker/issues/41)).
  - Year-on-year comparison and correct labels in billing-year view, no jump to the top when switching
    ([#36](https://github.com/Bingerminger/energietracker/issues/36)).
  - The advance suggestion shortly before the bill, explained more clearly ([#38](https://github.com/Bingerminger/energietracker/issues/38)).
  - Notifications to Home Assistant also with your own certificate in the home network, and the “to do”
    sensor also shows appointments due soon ([#36](https://github.com/Bingerminger/energietracker/issues/36)).
  - Metering cascades per register and with feed-in at every meter ([#40](https://github.com/Bingerminger/energietracker/issues/40)).
  - Two balances on one page that no longer contradict each other ([#49](https://github.com/Bingerminger/energietracker/issues/49), from [discussion #42](https://github.com/Bingerminger/energietracker/discussions/42)).
- **Smaller fixes:** follow-up contract from the reference date, anomaly on a main meter with sub-meters,
  corrected bills in the inbox, the opening reading of a bill.
- **Security:** a slimmer Docker image, signed and with a list of its packages; clear deadlines in
  [SECURITY.md](SECURITY.md).
- **Taking part:** ideas via Discussions, a visible status per release, and a thank-you to everyone who
  supports the project.

---

## Next

The order is set; dates are announced per release. A release has one theme and comes out when it is
done and tested, usually after one to three weeks.

| Version | Theme | What it is about |
|---|---|---|
| **v3.11.0** | **Your rights as a customer** | Energietracker checks whether deadlines and rules are kept: Is the bill late? Is the advance too high? May you cancel after a price increase, and until when? Is a bonus missing? Was your reading estimated although you reported it? Is the metering fee above the legal cap? Is the deadline for objecting to a water fee notice running? Plus the matching letter as a template. |
| **v3.12.0** | **Energietracker for your AI** | If you use an AI, connect it to Energietracker via MCP: “Here is my gas bill, enter it and tell me whether it is right.” Energietracker stays the record and does the maths, every figure with its origin. Plus the Home Assistant integration via HACS. |
| **v3.13.0** | **Long histories and moving in** | Many years of data stay fast, consumption between two readings is distributed by the weather, and anyone coming from another app brings their history along. |
| **v3.14.0** | **Understandable for everyone** | Guides in French, Italian, Spanish, Portuguese and Dutch, country profiles for Austria and Switzerland. |

---

## Later

- **v4.0.0 — Several properties:** several properties and small managements in one installation,
  service charge and heating cost statements as a landlord, splitting the CO₂ price. We will ask in
  Discussions first what you need for it.
- **Storage with grid and solar power (MiSpeL),** once the rules are settled, at the latest in 2027.
- **Every year:** CO₂ price and the flat-rate electricity price, as soon as they are published.

## Deliberately not planned

- Reading smart meters ourselves: Home Assistant does that, Energietracker takes over the values.
- A cloud service with accounts: your data stays on your device.
- Controlling battery and car ourselves: evcc and Home Assistant do that.

---

## Suggest ideas, report bugs

- **Ideas** under [Discussions → Ideas](https://github.com/Bingerminger/energietracker/discussions/categories/ideas).
  Give existing ideas a 👍 so we see what matters to many. Before each release the ideas are assessed:
  benefit for many, closeness to the bill and to understanding it, effort, privacy. Accepted ideas get a
  milestone, declined ones a short reason.
- **Bugs** as an [issue](https://github.com/Bingerminger/energietracker/issues/new/choose), ideally with
  version, steps and an invented example instead of real bills.
- **Security issues** please not in public, see [SECURITY.md](SECURITY.md).

---

## Recently released

| Version | Date | Theme |
|---|---|---|
| v3.9.0 | 27 Oct 2026 | Every contract pays only its share: metering concepts, Home Assistant, the contract from the bill |
| v3.7.0 | 22 Oct 2026 | Mail from your supplier: reading and managing documents automatically |
| v3.6.0 | 20 Oct 2026 | What if, and as on the bill |
| v3.5.0 | 14 Oct 2026 | Who, when, where to |
| v3.4.0 | 13 Oct 2026 | Clear and secure |
| v3.3.0 | 12 Oct 2026 | What flexibility is worth |
| v3.2.0 | 09 Oct 2026 | Easy for everyone |
| v3.1.0 | 07 Oct 2026 | For every household |
| v3.0.0 | 07 Oct 2026 | Free to try |

Every change in detail: [changelog](CHANGELOG.en.md).
