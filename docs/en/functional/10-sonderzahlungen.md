# Special payments (F1003)

**English** · [Deutsch](../../functional/10-sonderzahlungen.md)

> Applies to **gas, electricity, district heating** — the utilities with the
> classic advance/balance model. Water (the three-component tariff) and the
> delivery-based utilities heating oil/pellets have no advance balancing and thus
> no special payments.

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
