# Energietracker — Roadmap

What comes next, in plain words. The [project board](https://github.com/users/Bingerminger/projects/1)
shows what is being worked on, the [milestones](https://github.com/Bingerminger/energietracker/milestones)
where each release stands. You can suggest your own ideas any time under
[Discussions → Ideas](https://github.com/Bingerminger/energietracker/discussions/categories/ideas).

*Auf Deutsch:* [roadmap.md](roadmap.md). For contributors: the [planning page](roadmap-planung.md) with
every open item and the rules, earlier concepts in the [archive](roadmap-archiv.md) (both in German).

**As of:** 3 Nov 2026 · **Current version:** v3.10.0

---

## In progress: v3.11.0 — Your rights as a customer

Planned for **17 Nov 2026**.

- **Checks with a source:** Is the bill late? Is the advance clearly too high? May you cancel after a price
  increase, and until when? Is a bonus missing? Was your reading estimated although you reported it? Is the
  metering fee above the legal cap? Is the deadline for objecting to a water fee notice running?
- **The matching letter:** objection, special termination, request to adjust the advance or a question about
  an estimate as a template, with the figures from the check.
- **Price change in the inbox:** take over new prices and the effective date from the letter, see the effect
  on costs and advance, and get the termination deadline as an appointment.
- **Contracts that contradict each other** are reported, and the deadlines of tenancies go to the person in
  charge.

---

## Next

The order is set; dates are announced per release. A release has one theme and comes out when it is
done and tested, usually after one to three weeks.

| Version | Theme | What it is about |
|---|---|---|
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
| v3.10.0 | 3 Nov 2026 | Promised and secured: the balance in one piece, the promises from #36, #38, #40, #41 and #49, a slimmer signed image |
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
