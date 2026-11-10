# Energietracker — Roadmap

What comes next, in plain words. The [project board](https://github.com/users/Bingerminger/projects/1)
shows what is being worked on, the [milestones](https://github.com/Bingerminger/energietracker/milestones)
where each release stands. You can suggest your own ideas any time under
[Discussions → Ideas](https://github.com/Bingerminger/energietracker/discussions/categories/ideas).

*Auf Deutsch:* [roadmap.md](roadmap.md). For contributors: the [planning page](roadmap-planung.md) with
every open item and the rules, earlier concepts in the [archive](roadmap-archiv.md) (both in German).

**As of:** 10 Nov 2026 · **Current version:** v3.11.0

---

## Being released: v3.12.0 — Energietracker for your AI

Finished and checked, out in the next few days.

- **Connect an AI:** an AI assistant connects to your Energietracker via MCP and reads the overview,
  deadlines, contracts, consumption, forecast and “Check bill”, every figure with its origin (measured,
  distributed, estimated). Templates for monthly and yearly reports, bill checks and deadlines come with it.
- **Propose instead of write:** an AI enters nothing by itself. It proposes a reading or taking over a
  document, you confirm, and the history shows which key it came through.
- **Off by default, home network only:** one key per assistant, identifiers and originals only with a switch,
  every access in the log.
- **Home Assistant via HACS:** the integration with its own sensors, as a custom repository and with a request
  for the default list.
- **New interface ([#59](https://github.com/Bingerminger/energietracker/issues/59)):** a menu in two areas
  (everyday, setup), settings by topic with a search, our own graphics instead of emoji, a fixed grid. No feature
  goes away, old addresses keep working.
- **Balance of meter groups ([#58](https://github.com/Bingerminger/energietracker/issues/58)):** fix for groups
  whose meters were not read on the same day.

---

## Next

The order is set; dates are announced per release. A release has one theme and comes out when it is
done and tested, usually after one to three weeks.

| Version | Theme | What it is about |
|---|---|---|
| **v3.13.0** · planned for Tuesday, 17 Nov 2026 | **Forecast for heat pump, PV and electric car** | The forecast calculates the heat pump by the weather and includes PV, battery and electric car; feed-in payouts can be recorded. Plus consistent terms in every language, length limits for free text and a faster tank model. |
| **v3.14.0** | **Long histories and moving in** | Many years of data stay fast, consumption between two readings is distributed by the weather, and anyone coming from another app brings their history along. |
| **v3.15.0** | **Understandable for everyone** | Guides in French, Italian, Spanish, Portuguese and Dutch, country profiles for Austria and Switzerland. |

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
| v3.11.0 | 4 Nov 2026 | Your rights as a customer: seven checks with a source, five letters, price changes in the inbox, contradicting contracts |
| v3.10.1 | 3 Nov 2026 | Security fixes in the Docker image |
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
