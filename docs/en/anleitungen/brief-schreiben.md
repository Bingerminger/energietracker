# Writing a letter

[Deutsch](../../anleitungen/brief-schreiben.md) · **English**

[← Compendium index](../README.md)

Since **v3.11.0** a note “Your rights as a customer” offers a letter where it
fits: the button **“Draft a letter”** opens a dialog with a ready draft from a
fixed template. You check it, add what is missing and send it yourself. The
letter is only created when you press the button, never on its own. It is a
note, not legal advice.

---

## 1. Which letters there are

| Letter | When | To whom |
|---|---|---|
| **Special termination** | The supplier announced a price change (electricity, gas) | supplier |
| **Request to adjust the advance payment** | Your advance payment is clearly above what your consumption gives | supplier |
| **Question about the estimate** | The bill estimates the meter reading although you read it yourself | supplier |
| **Complaint about a bill** | The bill came late, a bonus is missing or the metering charge is above the cap | supplier or metering operator |
| **Objection to a fee assessment** | Water or sewage with an assessment notice from the municipality | the authority that issued the notice |

Which legal provision a letter names comes from the same stored legal rules as
the note itself, with source and date checked. If a detail is missing, the
letter shows a visible gap **[…]**; the dialog says how many are still open.

## 2. The dialog

1. **Sender**: name and address, one item per line.
2. **Recipient**: prefilled with the contract’s supplier. The address is on
   the letter or bill you are replying to.
3. **Town**: appears before the date.
4. **Details for reference**: customer number, contract number, meter number
   and market location ID come from contract and meter; you enter the supply
   address. A field you clear is left out of the letter.
5. **Fields of the template**, depending on the letter: meter reading for the
   final bill, requested advance payment and reasons, photo as enclosure, an
   addition.
6. The **preview** shows the whole letter and follows every input.

The letter is always in **German**, even if the app runs in another language:
suppliers and authorities in Germany will read it.

## 3. Sending

- **Download PDF**: address in the window according to DIN 5008 (form B),
  with fold marks. Fold twice, put it into a window envelope.
- **Copy text**: the same letter as text, for an email or the customer portal.
- **Mark as sent**: ticks off the note under “To do”, with the day you sent
  it, a note and, if you like, the letter as proof. It then appears in the
  history ([Ticking off notices](hinweise-erledigen.md)).

## 4. Objection to a fee assessment

The letter is only available once you confirm that the **notice of legal
remedies** at the end of the assessment names “Widerspruch” (objection). If it
names an action in court (Klage), this letter does not help; get advice
instead. The letter is short: it lodges the objection and says that the
grounds will follow. You can enter grounds right away. Sign it and send it by
post or hand it in at the authority; the PDF has a signature line for this.

## 5. What the app stores

Nothing. Sender, recipient and the dialog’s fields stay in the browser until
you close it. With **“Remember the sender on this device”** the browser keeps
name, address and town, on this device only; untick it to delete them. The
demo on GitHub Pages only the first draft is precomputed; changes and the PDF
need your own installation.

---

**See also:** [Ticking off notices](hinweise-erledigen.md) ·
[API: letter for a finding](../referenz/api.md#letter-for-a-finding-v3110)
