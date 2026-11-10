# Writing a letter

[Deutsch](../../anleitungen/brief-schreiben.md) · **English**

[← Compendium index](../README.md)

The app writes a letter for you from a fixed template. You check it, add what is
missing and send it yourself. The letter is only created when you press the
button, never on its own. It is a note, not legal advice.

## Three ways to a letter

1. **From a note:** **To do** → filter **Recommendations**. Notes of the
   category **Rights** come first within their urgency, the filter “Rights” shows
   only them. The card has **“Draft a letter”**. “To do” on the overview leads
   straight to that note.
2. **While checking a bill:** 💶 Costs & contracts → **Check a bill** (from the
   level “Experienced”), for the selected bill under “Compare”.
3. **On the contract card:** 💶 Costs & contracts → **Contracts & payments** →
   **Manage contracts** for the utility → **“Write a letter”**. Here you get
   letters without a note too, see [section 2](#2-letters-on-the-contract-card-v3120).

If you find no button, there is usually no note: the app has found nothing a
letter could answer. The third way works anyway.

---

## 1. Which letters there are

| Letter | When | To whom |
|---|---|---|
| **Special termination** | The supplier announced a price change (electricity, gas) | supplier |
| **Request to adjust the advance payment** | Your advance payment is clearly above what your consumption gives | supplier |
| **Question about the estimate** | The bill estimates the meter reading although you read it yourself | supplier |
| **Complaint about a bill** | The bill came late, a bonus is missing or the metering charge is above the cap | supplier or metering operator |
| **Objection to a fee assessment** | Water or sewage with an assessment notice from the municipality | the authority that issued the notice |

| **Termination at the next possible date** *(v3.12.0)* | You want to end your contract in the ordinary way (electricity, gas, district heating) | supplier |

Which legal provision a letter names comes from the same stored legal rules as
the note itself, with source and date checked. If a detail is missing, the
letter shows a visible gap **[…]**; the dialog says how many are still open.

## 2. Letters on the contract card *(v3.12.0)*

**“Write a letter”** on the card of a running contract opens a choice. Without a
note there are two letters:

- **Request to adjust the advance payment:** you enter the amount. If the balance
  card shows a suggested advance payment, it is already there. The last billed
  consumption goes into the letter once the bill is recorded. For water the letter
  names no legal provision: the app does not know whether a contract or a fee
  statute applies.
- **Termination at the next possible date:** ordinary, with no legal statement and
  no legal provision. Customer and contract number, meter number and the date
  according to the contract are in it, otherwise a gap. For the date, the dialog
  says why it is missing *(v3.12.0)*: no notice period and no end on the
  contract, deadline missed, or the contract continues after its end; after a
  missed deadline the app calculates no date. Check the date and the
  form in your contract. District heating is terminated in writing
  (§ 32 AVBFernwärmeV): the PDF then has a signature line. Water does not have
  this template.

**Special termination, objection and complaint** are only available from a note.
They claim something (a right, an error), and the app may only do that once it
has recognised the reason. If the contract has such a note, it comes first in the
choice and leads to it.

In a country without stored legal rules only the termination remains, with the
same neutral text in German.

## 3. The dialog

1. **Sender**: name and address, one item per line. **Prefilled from your
   household** (Household › Building › Details for letters) if you entered it
   there.
2. **Recipient**: prefilled with the contract’s supplier and its address if it
   is on the contract (Edit › Details for letters). Otherwise it is on the
   letter or bill you are replying to.
3. **Town**: appears before the date. Prefilled from the last line of the
   address if it starts with a postcode.
4. **Details for reference**: customer number, contract number, meter number
   and market location ID come from contract and meter, the supply address
   from the household. A field you clear is left out of the letter.

**What you change here only applies to this letter.** Below each detail the
dialog says where it comes from. If it is missing, the dialog says where to
enter it. With **“Save in household”** or **“Use in household”** and **“Save on
contract”** you store a detail on purpose; then it is in the next letter.
5. **Fields of the template**, depending on the letter: date of the termination,
   meter reading for the final bill, requested advance payment and reasons, photo
   as enclosure, an addition.
6. The **preview** shows the whole letter and follows every input.

The letter is always in **German**, even if the app runs in another language:
suppliers and authorities in Germany will read it.

## 4. Sending

- **Download PDF**: address in the window according to DIN 5008 (form B),
  with fold marks. Fold twice, put it into a window envelope.
- **Copy text**: the same letter as text, for an email or the customer portal.
- **Mark as sent** (only for a letter from a note): ticks off the note under
  “To do”, with the day you sent it, a note and, if you like, the letter as
  proof. It then appears in the history
  ([Ticking off notices](hinweise-erledigen.md)).

## 5. Objection to a fee assessment

The letter is only available once you confirm that the **notice of legal
remedies** at the end of the assessment names “Widerspruch” (objection). If it
names an action in court (Klage), this letter does not help; get advice
instead. The letter is short: it lodges the objection and says that the
grounds will follow. You can enter grounds right away. Sign it and send it by
post or hand it in at the authority; the PDF has a signature line for this.

## 6. What the app stores

**The app does not store the letter itself.** What you change in the dialog
stays in the browser until you close it.

*Since v3.12.0* the **sender and the supply address are optional details in
the household** (Household › Building › Details for letters) and the
**supplier’s address is on the contract**. They are part of your data and are
in the backup; the CSV export does not contain them. **The app never passes
them on to an AI**, and the inbox does not send them when reading documents
([AI assistant → privacy](ki-assistent.md#security-and-privacy)). Up to v3.11
the sender could only be remembered on one device; a sender remembered that
way still prefills until the household has one, and “Save in household” takes
it over.

In the demo on GitHub Pages only the first draft is precomputed; changes and
the PDF need your own installation.

---

**See also:** [Ticking off notices](hinweise-erledigen.md) ·
[API: letter for a finding](../referenz/api.md#letter-for-a-finding-v3110)
