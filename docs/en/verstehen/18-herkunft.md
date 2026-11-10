# Where a figure comes from

**English** · [Deutsch](../../verstehen/18-herkunft.md)

[← CO₂ price in fuel](16-co2-preis.md) · [Compendium index](../README.md)

Since **v3.12.0** Energietracker answers an AI assistant
([Connect an AI assistant](../anleitungen/ki-assistent.md)). An AI reads a
figure differently from you: it does not see that March is greyed out or that
the balance has a “≈” in front of it. That is why, over the AI interface,
**every figure carries its basis**, and the AI is to give it with that basis.
This page explains the five levels, the figure object and the sign of a
balance. The AI can read it itself, as the resource
`energietracker://hilfe/herkunft`.

The figures themselves are the same as in the user interface: a tool calls the
same service as the view and only adds the basis.

---

## The five bases

From strong to weak. The field is called `basis`.

| `basis` | German UI | Meaning | Examples |
|---|---|---|---|
| `measured` | gemessen | from real readings | consumption of a month whose consumption and days come at least half from reading intervals of up to 45 days; quarter-hour values; a meter reading taken |
| `entered` | eingetragen | typed in by a person or taken over from a document | unit price and standing charge, advance payment, amounts of a bill, gas factor with a date |
| `distributed` | verteilt | spread evenly over the days between two real readings | a month between two readings more than 45 days apart |
| `assumed` | angenommen | carried forward without an entry | price after the end of the price guarantee, contract running on without a successor, gas factor without a date, climate normal |
| `estimated` | geschätzt | projected beyond the last reading or from a model | consumption since the last reading, balance at settlement, forecast, scenario, a reading recorded as estimated |

Measured does not mean “exact”: a measured month, too, is spread over the days
between two readings. It is called measured because the readings are close
enough together that the spreading hardly shifts the month. How the app spreads
consumption between two readings is described in
[Fundamentals & methodology](00-overview.md).

## Composite figures

A result made of several parts carries the **weakest** basis of its parts:

```text
basis(result) = weakest basis of the parts
order:          measured > entered > distributed > assumed > estimated
```

Example: the balance to date consists of the costs up to the last reading
(measured), the costs since then (estimated) and the advance payments
(entered). So it is **estimated**. How much of it falls on which basis is in
`parts`, such as `{"measured": 0.71, "estimated": 0.29}` (shares of the amount,
adding up to 1). Such figures carry `derived: true`.

One exception upwards: a total over whole reading intervals (a reading lies at both edges)
is called **measured**, even if all its months are `distributed`. Distributing over months
evens out within each interval, so the total is as good as its readings.

## The figure object

Every key figure comes as an object:

```json
{"value": 412.5, "unit": "EUR", "basis": "estimated",
 "range": [380.0, 445.0], "as_of": "2026-11-03",
 "parts": {"measured": 0.71, "estimated": 0.29},
 "uncertain": ["few_measured"], "derived": true,
 "sign": "positive_is_back_payment"}
```

| Field | Meaning |
|---|---|
| `value` | the value, rounded as in the user interface: amounts to 2, quantities to 3 decimal places |
| `unit` | unit (`EUR`, `kWh`, `m³` …) |
| `basis` | basis, see above |
| `range` | range `[low, high]`, only when there is a band (forecast, expected balance, suggested advance payment) |
| `as_of` | reference date, such as the day of the last reading for the measured balance |
| `parts` | shares of the bases in the value, only for sums of several bases |
| `uncertain` | reasons for uncertainty as keys, such as `few_measured` (too few measured months) |
| `derived` | `true` for calculated results; then `basis` is the weakest basis of the parts |
| `sign` | only for a balance: how to read the sign (below) |

`value`, `unit` and `basis` are mandatory. In **series** (months, billing
years) the basis appears once per row in the field `basis`, not on every
figure, so the answer stays small. Counts and calendar values (days, number,
pages, year, month) carry no basis.

## The sign of a balance

A balance states in the field `sign` how to read its sign:

| `sign` | positive | negative |
|---|---|---|
| `positive_is_back_payment` | back payment (you pay extra) | credit (you get money back) |
| `positive_is_payout` | payout (feed-in, you get money) | repayment due |

Next to it, it is spelled out, because models can misread a sign: `meaning` (`credit`,
`back_payment`, `balanced`, `payout`, `reclaim`, without a unit price `no_price`) and `text`, a
ready sentence in the server’s language, such as “Credit of €828.01”; at 0 “Settled”, for
estimated values with “Estimated:” in front. An answer with a balance also states these
sentences in a text block of their own.

With feed-in, money is in the field `revenue` (`accounting_kind: feed_in`), with generation
not at all. If no contract applies, the amount is missing (`no_contract: true`): it is not 0.

The API and the CSV export calculate the same way
([Glossary: balance](09-glossar.md#terms)). The user interface shows the
customer’s view without a sign: “credit” or “back payment”. The AI is to say
it the same way, in words instead of plus and minus.

## How the AI should say it

The server instructions require the AI to name figures only from the tools,
each with its basis, to call an estimate an estimate and give its range, and
not to extrapolate on its own. Good answers sound like this:

- “Gas in October: 412 kWh, measured.”
- “In March 380 kWh are distributed: there were more than six weeks between
  the two readings, and the app spread the consumption evenly over the days.”
- “Expected at settlement: about €120 back payment, estimated, range €60 to
  €180. Measured up to the last reading on 28 Oct: €40 credit.”
- “From January the price is only assumed: the price guarantee ends and no new
  price is entered.”

If an AI states a figure without a basis or keeps calculating on its own, ask
where it comes from. The figure in the user interface is the one that counts.

---

[← CO₂ price in fuel](16-co2-preis.md) · [Compendium index](../README.md)
