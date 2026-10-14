# Special payments (F1003)

**English** · [Deutsch](../../verstehen/10-sonderzahlungen.md)

> Applies to **gas, electricity, water and district heating** — every utility
> with meter readings, advances and a bill. Water since v3.5.0
> ([#34](https://github.com/Bingerminger/energietracker/issues/34)); up to v3.4
> the section was missing there, although the water bill also ends with a
> back-payment or a refund. No special payments for the delivery-based utilities
> heating oil/pellets (no advance balancing) and for PV feed-in (paid by
> generation, no advance plan).

## Purpose

The running balance of a contract is normally `costs − advances paid`. In reality
there are, beyond that, one-off money movements that do not fit the monthly
advance grid: the annual statement brings a refund or a back-payment, or one
voluntarily makes an additional advance payment. F1003 models exactly these five
cases.

## The five types

| Type | Money direction | Effect on the balance | Changes the future advance? |
|---|---|---|---|
| Refund (with effect) | supplier → customer | balance **rises** | **Yes** |
| Refund (without effect) | supplier → customer | balance **rises** | No |
| Back-payment (with effect) | customer → supplier | balance **falls** | **Yes** |
| Back-payment (without effect) | customer → supplier | balance **falls** | No |
| Advance payment | customer → supplier | balance **falls** | No |

The amounts are always recorded as **positive** — the sign in the balance follows
solely from the chosen type, not from the input.

## Balance formula

```
balance = costs − advances paid + special-payment net

special-payment net = Σ refund − Σ back-payment − Σ advance payment
```

Intuition: a **refund** means you had previously paid too much; getting this
credit back balances the overpayment — the balance moves up towards zero. A
**back-payment** settles an underpayment — the balance falls towards zero. An
additional **advance payment** acts like a further advance and reduces the open
debt.

## Where special payments appear (since v2.5.1)

- **Balance card** of the current contract: refunds, back-payments and
  advance payments listed individually below the advance.
- **"Contracts & advances" table** (consumption view): column **Special
  payments** next to *Bonus*, for every contract in the history. It shows
  the **net from the customer's perspective** — refunds received count
  positive (`+142.50 €`), back-payments and advance payments made count
  negative; with several items the count follows. Tapping expands every item
  with date, type, amount and note (up to v2.12 a tooltip). A hint below the
  table explains both columns.
- The column exists for gas, electricity, water (since v3.5.0) and district
  heating. PV feed-in has no special payments; there it is omitted.
- **Overview and projection:** today's balance and the expected balance at the
  end of the contract include the net of the special payments — for water just
  as for gas, electricity and district heating. A *with-effect* type sets the
  advance from its effective date, in the **forecast** too.
- **Booking a supplier bill:** “Book as special payment” under *Check a bill*
  adds the result as a special payment “without effect” — since v3.5.0 in the
  water contract too. Up to v3.4 a water bill was marked “booked” afterwards,
  but nothing arrived in the contract. Such bills offer booking again as long
  as the water contract has not been saved since; otherwise enter the
  back-payment or refund by hand.
- *Bonus* and *Special payments* stay separate: a bonus is part of the
  contract (instant, switching, new-customer bonus) and reduces the costs; a
  special payment is money that flowed outside the advance schedule. Both
  enter the balance, but at different places in the formula.

## "with effect on advance payments"

After an annual statement the supplier often also adjusts the future monthly
advance (e.g. down after a credit, up after an additional claim). If you choose a
*with-effect* type, you additionally record the **new monthly advance** and the
**effective date** from which it applies. This point is internally mixed into the
effective advance plan — the monthly advance calculation picks it up
automatically, exactly as if you had maintained a regular advance change.

Example: the 2023 annual statement yields €142.50 credit; the advance drops from
€110 to €95 on 01/04/2024.

→ A special payment "Refund (with effect)" with amount 142.50, new advance €95 and
  effective date 2024-04-01. The balance books the credit, and from April the app
  continues to calculate with €95/month.

## Typical pitfalls

- **Double recording: refund + advance reduction separately.** If you already
  maintain the advance reduction as a regular `advance_payments` entry, you should
  **not** additionally use the *with-effect* variant, otherwise the advance is set
  twice. Rule of thumb: either a regular advance change **or** *with-effect* — not
  both for the same effective date.
- **Sign.** Always enter the amount as positive. There is no need to "trick" a
  back-payment with a negative amount, and it is normalised to the positive value
  anyway.
- **Wrong type.** "Advance payment" is the voluntary additional payment. The
  payment you make after a statement is a **back-payment**.

[← Glossary](09-glossar.md) · [Fundamentals](00-overview.md)
