# Legal basis

**English** · [Deutsch](../../referenz/rechtsgrundlagen.md)

[← Compendium index](../README.md)

The notes under [Your rights as a customer](../verstehen/17-rechte.md) rely on
these provisions and sources. Each row gives the statement, the norm with a link
to the source, the day the source was last read, and how certain the statement
is. The app keeps the same details in one place in the code
(`src/Config/Rights.php`). A test checks that this page and the code agree:
every rule here, no row without a rule, same norm, same source, same date.

> **Note, not legal advice.** This page summarises laws and sources as they read
> on the given day. It does not replace advice. Help is available from the
> consumer advice centre (Verbraucherzentrale) and, for electricity and gas, the
> energy arbitration board (Schlichtungsstelle Energie).

The rules apply to Germany only. In any other country the app shows no notes on
rights. Of the letters only the termination on the contract card remains there;
it names no provision and makes no legal statement (v3.12.0). Norms keep their German citation, as on the official
source.

## Status

| Status | Meaning | What the app does with it |
|---|---|---|
| **W** | Wording of the official source read and summarised | may appear in a note |
| **S** | Secondary source: consumer advice centre, vzbv, Federal Network Agency, specialist portal | may appear in a note, the source is named |
| **G** | to be checked: from knowledge, not retrieved, or a detail is open | may appear in a note; this page marks the statement “To be checked” |
| **U** | uncertain: legal situation open or not documented | never appears in a note; this page lists it only so that what is open stays visible |

A rule marked “Not in app texts” only guides the design. The column **No.** is
the row in the review report for v3.11.0; several rules can share a row, such as
StromGVV and GasGVV.

**Review:** Before every release a person reads each row against the wording
then in force and sets the date anew. For v3.11.0 this is still outstanding.

## Deadlines

The app calculates these deadlines. A test keeps the numbers tied to the values
in the code.

| Deadline | Duration | Basis |
|---|---|---|
| Bill for electricity and gas <!-- doc-claim: rights-deadline bill --> | 42 days (six weeks) after the end of the billing period or of supply | § 40c Abs. 2 EnWG |
| Notice of a price change, special contract <!-- doc-claim: rights-deadline announce_special_months --> | 1 month before the change | § 41 Abs. 5 Satz 1 bis 3 EnWG |
| Notice of a price change, basic supply <!-- doc-claim: rights-deadline announce_basic_days --> | 42 days (six weeks) before the change, which takes effect at the start of a month | § 5 Abs. 2 StromGVV, § 5 Abs. 2 GasGVV |
| Notification of an assessment by post <!-- doc-claim: rights-deadline notice_postal_days --> | on the 4th day after posting | § 41 Abs. 2 Satz 1 VwVfG, § 122 Abs. 2 Nr. 1 AO |
| Objection or action against an assessment <!-- doc-claim: rights-deadline notice_months --> | 1 month after notification | § 70 Abs. 1 VwGO; end of the period under §§ 187 Abs. 1, 188 Abs. 2, 3 BGB (to be checked); on a Saturday, Sunday or public holiday the next working day (§ 57 Abs. 2 VwGO, § 222 Abs. 2 ZPO, § 31 Abs. 3 VwVfG) |

How the app calculates the dates:

- **Special termination:** the termination must arrive before the day of the
  price change; the date is the day before. If the letter names an earlier date,
  the earlier one is the date and the note names both.
- **Fee assessment:** date of the assessment plus four days, with a certificate
  of service the day of service; then one month. If the day does not exist in
  that month, the last day of the month applies. If that day is a Saturday,
  Sunday or a public holiday that applies throughout Germany, the date is the
  next working day (no. 60, 61); the app does not know holidays of individual
  states, and the note says so. Date, “To do”, calendar and letter name this
  day; the deadline only counts as expired the day after *(v3.11.0, follow-up
  review; before, the day of the month applied)*.
- Both dates appear under “To do” 14 days before. The app counts calendar days,
  without a time of day.

## Price caps for metering

Gross per year and metering point, the connection user’s share, for the default
metering operator. The app checks with this table from 2026-01-01
(Metering Point Operation Act, version of 23.12.2025). Each feature of a row is
enough on its own; if several rows apply, the highest cap applies. If no group
applies to a smart metering system, the optional installation applies.

| Case | Annual consumption | System | § 14a EnWG | Cap per year | Norm |
|---|---|---|---|---|---|
| Modern metering device <!-- doc-claim: rights-metering-cap mme --> | – | – | – | 25 € | [§ 32 Abs. 1 MsbG](https://www.gesetze-im-internet.de/messbg/__32.html) |
| Smart metering system, optional installation <!-- doc-claim: rights-metering-cap imsys_optional --> | – | – | – | 30 € | [§ 30 Abs. 1, 3 MsbG](https://www.gesetze-im-internet.de/messbg/__30.html) |
| Smart metering system <!-- doc-claim: rights-metering-cap imsys_6000 --> | above 6,000 up to 10,000 kWh | – | – | 40 € | [§ 30 Abs. 1, 3 MsbG](https://www.gesetze-im-internet.de/messbg/__30.html) |
| Smart metering system <!-- doc-claim: rights-metering-cap imsys_10000 --> | above 10,000 up to 20,000 kWh | above 7 up to 15 kW | yes | 50 € | [§ 30 Abs. 1 Nr. 4 MsbG](https://www.gesetze-im-internet.de/messbg/__30.html) |
| Smart metering system <!-- doc-claim: rights-metering-cap imsys_20000 --> | above 20,000 up to 50,000 kWh | above 15 up to 25 kW | – | 110 € | [§ 30 Abs. 1, 3 MsbG](https://www.gesetze-im-internet.de/messbg/__30.html) |
| Smart metering system <!-- doc-claim: rights-metering-cap imsys_50000 --> | above 50,000 up to 100,000 kWh | above 25 up to 100 kW | – | 140 € | [§ 30 Abs. 1, 3 MsbG](https://www.gesetze-im-internet.de/messbg/__30.html) |
| Smart metering system <!-- doc-claim: rights-metering-cap imsys_100000 --> | above 100,000 kWh | above 100 kW | – | an appropriate charge, no fixed amount | [§ 30 Abs. 1, 3 MsbG](https://www.gesetze-im-internet.de/messbg/__30.html) |

The app takes annual consumption as the mean of the last three full years. It
checks nothing for conventional meters and for gas.

## Statements and sources

### Billing and the bill

| No. | Statement | Norm and source | Checked | Status |
|---|---|---|---|---|
| 1 <!-- doc-claim: rights-rule de.enwg.40c.2 --> | The bill arrives no later than six weeks after the end of the billing period, the final bill six weeks after supply ends; with monthly billing three weeks. | [§ 40c Abs. 2 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__40c.html) | 2026-11-03 | W |
| 2 <!-- doc-claim: rights-rule de.enwg.40c.3 --> | A credit is paid out within two weeks or fully offset against the next advance payment; a credit from a final bill is paid out within two weeks. | [§ 40c Abs. 3 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__40c.html) | 2026-11-03 | W |
| 3 <!-- doc-claim: rights-rule de.enwg.40c.1 --> | A payment is due no earlier than two weeks after the payment request is received. | [§ 40c Abs. 1 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__40c.html) | 2026-11-03 | W |
| 4 <!-- doc-claim: rights-rule de.enwg.40b.1 --> | The billing period is at most one year; the supplier also offers monthly, quarterly and half-yearly billing. | [§ 40b Abs. 1 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__40b.html) | 2026-11-03 | W |
| 5 <!-- doc-claim: rights-rule de.enwg.40.2.6 --> | The bill states the opening and closing readings and how they were determined. | [§ 40 Abs. 2 Satz 1 Nr. 6 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__40.html) | 2026-11-03 | W |
| 6 <!-- doc-claim: rights-rule de.enwg.40.2.13 --> | The bill states whether you are in basic supply (Grundversorgung). | [§ 40 Abs. 2 Satz 1 Nr. 13 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__40.html) | 2026-11-03 | W |
| 7 <!-- doc-claim: rights-rule de.enwg.40.3.4 --> | Charges for metering are shown separately on the bill where they are part of the price calculation. | [§ 40 Abs. 3 Nr. 4 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__40.html) | 2026-11-03 | W |
| 33 <!-- doc-claim: rights-rule de.avbfernwaermev.24.1 --> | *Uncertain:* District heating has no statutory deadline for the bill, billing is at least yearly; whether a new version of the AVBFernwärmeV is in force is open. The app checks nothing here. | [§ 24 Abs. 1 AVBFernwärmeV](https://www.gesetze-im-internet.de/avbfernw_rmev/__24.html) | 2026-11-03 | U |
| 34 <!-- doc-claim: rights-rule de.avbwasserv.24.1 --> | Water (under private law) is billed in periods that do not significantly exceed twelve months. | [§ 24 Abs. 1 AVBWasserV](https://www.gesetze-im-internet.de/avbwasserv/__24.html) | 2026-11-03 | W |

### Determining and estimating consumption

| No. | Statement | Norm and source | Checked | Status |
|---|---|---|---|---|
| 8 <!-- doc-claim: rights-rule de.enwg.40a.2.s1 --> | The supplier may estimate only if no reading was submitted despite the obligation, or if it cannot determine consumption for reasons it is not responsible for; the basis is the previous year or comparable customers. | [§ 40a Abs. 2 Satz 1, 2 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__40a.html) | 2026-11-03 | W |
| 9 <!-- doc-claim: rights-rule de.enwg.40a.2.s3 --> | An estimate is highlighted on the bill, with reason and factors; on request the supplier explains it free of charge in text form. | [§ 40a Abs. 2 Satz 3 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__40a.html) | 2026-11-03 | W |
| 10 <!-- doc-claim: rights-rule de.enwg.40a.1 --> | With smart metering systems and load profile metering, the values of the metering or grid operator take precedence; the supplier states how the reading was determined. | [§ 40a Abs. 1 Satz 4, 5 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__40a.html) | 2026-11-03 | W |
| 11 <!-- doc-claim: rights-rule de.stromgvv.11 --> | Basic supply, electricity: consumption is determined under § 40a EnWG. | [§ 11 Abs. 1 StromGVV](https://www.gesetze-im-internet.de/stromgvv/__11.html) | 2026-11-03 | W |
| 11 <!-- doc-claim: rights-rule de.gasgvv.11 --> | *To be checked:* Basic supply, gas: the same. | [§ 11 Abs. 1 GasGVV](https://www.gesetze-im-internet.de/gasgvv/__11.html) | 2026-11-03 | G |
| 27 <!-- doc-claim: rights-rule de.stromgvv.17 --> | Basic supply, electricity: objections to a bill only justify deferring payment if an obvious error is seriously possible or consumption is more than twice as high and is being re-checked. The app never advises withholding payments. | [§ 17 Abs. 1 StromGVV](https://www.gesetze-im-internet.de/stromgvv/__17.html) | 2026-11-03 | W |
| 27 <!-- doc-claim: rights-rule de.gasgvv.17 --> | *To be checked:* Basic supply, gas: the same. | [§ 17 Abs. 1 GasGVV](https://www.gesetze-im-internet.de/gasgvv/__17.html) | 2026-11-03 | G |
| 28 <!-- doc-claim: rights-rule de.stromgvv.18 --> | Basic supply, electricity: if the bill amount is wrong, overpayments are refunded and underpayments charged, as a rule for the reading period before the error was found, for at most three years. | [§ 18 StromGVV](https://www.gesetze-im-internet.de/stromgvv/__18.html) | 2026-11-03 | W |
| 28 <!-- doc-claim: rights-rule de.gasgvv.18 --> | *To be checked:* Basic supply, gas: the same. | [§ 18 GasGVV](https://www.gesetze-im-internet.de/gasgvv/__18.html) | 2026-11-03 | G |
| 35 <!-- doc-claim: rights-rule de.avbwasserv.20.2 --> | Water: the supplier may estimate as long as its agent cannot enter the premises to read the meter; the basis is the last reading. | [§ 20 Abs. 2 AVBWasserV](https://www.gesetze-im-internet.de/avbwasserv/__20.html) | 2026-11-03 | W |
| 35 <!-- doc-claim: rights-rule de.avbfernwaermev.20.2 --> | District heating: the same. | [§ 20 Abs. 2 AVBFernwärmeV](https://www.gesetze-im-internet.de/avbfernw_rmev/__20.html) | 2026-11-03 | W |
| 57 <!-- doc-claim: rights-rule de.bnetza.bills --> | An estimate is recognisable on the bill, with reason and factors; if there is a problem, complain to the supplier first, then the Federal Network Agency’s consumer service and the arbitration board. | [Bundesnetzagentur: Rechnungen](https://www.bundesnetzagentur.de/DE/Vportal/Energie/RechnungenSperrungen/Rechnungen-table.html) | 2026-11-03 | S |

### Advance payments

| No. | Statement | Norm and source | Checked | Status |
|---|---|---|---|---|
| 12 <!-- doc-claim: rights-rule de.stromgvv.13 --> | Basic supply, electricity: the advance payment is based pro rata on consumption in the last billed period; if the customer credibly shows a considerably lower consumption, this must be taken into account appropriately. After a price change the advance may be adjusted by the same percentage; advances paid in excess are refunded without delay. | [§ 13 StromGVV](https://www.gesetze-im-internet.de/stromgvv/__13.html) | 2026-11-03 | W |
| 12 <!-- doc-claim: rights-rule de.gasgvv.13 --> | Basic supply, gas: the same. | [§ 13 GasGVV](https://www.gesetze-im-internet.de/gasgvv/__13.html) | 2026-11-03 | W |
| 13 <!-- doc-claim: rights-rule de.enwg.41b.3 --> | Special contract with household customers: prepayments or advance payments are based on consumption in the previous billing period or of comparable customers; a credibly considerably lower consumption must be taken into account appropriately; they are not due before supply begins. | [§ 41b Abs. 3 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__41b.html) | 2026-11-03 | W |
| 14 <!-- doc-claim: rights-rule de.avbfernwaermev.25 --> | District heating: advance payments as in basic supply (consumption in the last billed period, credibly considerably lower consumption, adjustment after a price change, refund). | [§ 25 AVBFernwärmeV](https://www.gesetze-im-internet.de/avbfernw_rmev/__25.html) | 2026-11-03 | W |
| 15 <!-- doc-claim: rights-rule de.avbwasserv.25 --> | Water (under private law): advance payments as in basic supply. | [§ 25 AVBWasserV](https://www.gesetze-im-internet.de/avbwasserv/__25.html) | 2026-11-03 | W |
| 16 <!-- doc-claim: rights-rule de.lg_ffm.3_06_o_13_23 --> | Single case: raising advance payments although a calculation based on consumption would give a reduction violates the EnWG (final according to the vzbv). | [LG Frankfurt a. M., 30.05.2023, 3-06 O 13/23](https://www.vzbv.de/urteile/gaspreise-vzbv-stoppt-ueberhoehte-abschlaege) | 2026-11-03 | S |
| 17 <!-- doc-claim: rights-rule de.vz.advance --> | Advance payments follow the previous year’s consumption; you should not cut them on your own. | [Verbraucherzentrale Niedersachsen: Abschlagszahlungen](https://www.verbraucherzentrale-niedersachsen.de/wissen/energie/preise-tarife-anbieterwechsel/abschlagszahlungen-fuer-strom-und-gas-richtig-berechnen-120062) | 2026-11-03 | S |

### Price changes and termination

| No. | Statement | Norm and source | Checked | Status |
|---|---|---|---|---|
| 18 <!-- doc-claim: rights-rule de.enwg.41.5.s1 --> | Special contract: the supplier informs household customers of a price change no later than one month in advance, in simple and clear terms, with reason, conditions and extent. | [§ 41 Abs. 5 Satz 1 bis 3 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__41.html) | 2026-11-03 | W |
| 19 <!-- doc-claim: rights-rule de.enwg.41.5.s4 --> | Special contract: you can terminate without notice period with effect from the date the price change takes effect, without a separate charge. | [§ 41 Abs. 5 Satz 4 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__41.html) | 2026-11-03 | W |
| 20 <!-- doc-claim: rights-rule de.enwg.41.6 --> | Special contract: no duty to inform and no right to terminate if the supplier passes on a statutory change in VAT unchanged, or a reduction of the price components under § 40 Abs. 3 Nr. 1 to 5 EnWG. | [§ 41 Abs. 6 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__41.html) | 2026-11-03 | W |
| 21 <!-- doc-claim: rights-rule de.vz.special_termination --> | The termination must reach the supplier by the date the change takes effect; the supplier may not set its own deadline for it; a minimum term does not exclude the right. | [Verbraucherzentrale Niedersachsen: Sonderkündigungsrecht (Stand 24.04.2026)](https://www.verbraucherzentrale-niedersachsen.de/themen/energie-wohnen/energiepreise-anbieterwechsel/preiserhoehung-wenn-energieanbieter-das-sonderkuendigungsrecht-verweigern) | 2026-11-03 | S |
| 22 <!-- doc-claim: rights-rule de.enwg.41b.1 --> | Special contract: the supplier confirms a termination in text form within one week of receipt, stating the end of the contract. | [§ 41b Abs. 1 Satz 2 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__41b.html) | 2026-11-03 | W |
| 24 <!-- doc-claim: rights-rule de.stromgvv.5.2 --> | Basic supply, electricity: price changes take effect at the start of a month, publicly announced at least six weeks in advance, together with a letter stating extent, reason and conditions. | [§ 5 Abs. 2 StromGVV](https://www.gesetze-im-internet.de/stromgvv/__5.html) | 2026-11-03 | W |
| 24 <!-- doc-claim: rights-rule de.gasgvv.5.2 --> | Basic supply, gas: the same. | [§ 5 Abs. 2 GasGVV](https://www.gesetze-im-internet.de/gasgvv/__5.html) | 2026-11-03 | W |
| 25 <!-- doc-claim: rights-rule de.stromgvv.5.3 --> | Basic supply, electricity: you can terminate without notice with effect from the date of the change; the change does not apply if you prove an initiated switch of supplier within one month of your termination being received. | [§ 5 Abs. 3 StromGVV](https://www.gesetze-im-internet.de/stromgvv/__5.html) | 2026-11-03 | W |
| 25 <!-- doc-claim: rights-rule de.gasgvv.5.3 --> | Basic supply, gas: the same. | [§ 5 Abs. 3 GasGVV](https://www.gesetze-im-internet.de/gasgvv/__5.html) | 2026-11-03 | W |
| 26 <!-- doc-claim: rights-rule de.stromgvv.20 --> | Basic supply, electricity: termination at any time with two weeks’ notice, in text form; the basic supplier confirms without delay, free of charge. | [§ 20 StromGVV](https://www.gesetze-im-internet.de/stromgvv/__20.html) | 2026-11-03 | W |
| 26 <!-- doc-claim: rights-rule de.gasgvv.20 --> | *To be checked:* Basic supply, gas: the same. | [§ 20 GasGVV](https://www.gesetze-im-internet.de/gasgvv/__20.html) | 2026-11-03 | G |
| 29 <!-- doc-claim: rights-rule de.avbfernwaermev.24.4 --> | District heating: prices change according to the price adjustment clause with a cost and a market element; the calculation factors must be shown. A special right of termination exists only in the special case of a gas price increase under the Energy Security Act. | [§ 24 Abs. 4 bis 7 AVBFernwärmeV](https://www.gesetze-im-internet.de/avbfernw_rmev/__24.html) | 2026-11-03 | W |
| 58 <!-- doc-claim: rights-rule de.enwg.2026 --> | *Uncertain:* The EnWG was amended in 2026 (BGBl. 2026 I Nr. 226); whether this affects §§ 40 to 41b has not been checked. | [EnWG, Änderung durch BGBl. 2026 I Nr. 226](https://www.buzer.de/41_EnWG.htm) | 2026-11-03 | U |

### Contract term

| No. | Statement | Norm and source | Checked | Status |
|---|---|---|---|---|
| 30 <!-- doc-claim: rights-rule de.avbfernwaermev.32 --> | District heating: term at most ten years; without notice nine months before expiry it is extended by five years; termination is in writing. | [§ 32 AVBFernwärmeV](https://www.gesetze-im-internet.de/avbfernw_rmev/__32.html) | 2026-11-03 | W |
| 31 <!-- doc-claim: rights-rule de.bgb.310.2 --> | §§ 308, 309 BGB do not apply to special customer contracts of electricity, gas, district heating and water suppliers unless these deviate from the supply conditions of the ordinances to the customer’s disadvantage; the same for sewage. | [§ 310 Abs. 2 BGB](https://www.gesetze-im-internet.de/bgb/__310.html) | 2026-11-03 | W |
| 32 <!-- doc-claim: rights-rule de.bgb.309.9 --> | *To be checked:* An initial term of more than 24 months is invalid in the standard terms of a consumer contract. The app’s warning for electricity and gas is based on this. | [§ 309 Nr. 9 BGB](https://www.gesetze-im-internet.de/bgb/__309.html) | 2026-11-03 | G |
| 59 <!-- doc-claim: rights-rule de.bgb.309.9.b --> | *Uncertain:* In standard terms, a tacit extension is only valid if the contract then runs for an indefinite period and can be cancelled at any time with at most one month’s notice. Whether this applies to an extended electricity or gas contract depends on § 310 Abs. 2 BGB (no. 31) and on the date the contract was concluded. The app therefore names no notice period and calculates no earliest end; it refers to the contract. | [§ 309 Nr. 9 Buchst. b BGB](https://www.gesetze-im-internet.de/bgb/__309.html) | 2026-11-03 | U |

### Bonus

| No. | Statement | Norm and source | Checked | Status |
|---|---|---|---|---|
| 23 <!-- doc-claim: rights-rule de.enwg.41.4 --> | The contract summary states bonus agreements and minimum terms; the contract states special offers and discounts. | [§ 41 Abs. 4 Satz 2 Nr. 6, Abs. 1 Satz 2 Nr. 5 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__41.html) | 2026-11-03 | W |
| 37 <!-- doc-claim: rights-rule de.vz.bonus --> | The consumer advice centre applies the two-week rule for credits to bonuses as well: pay out or offset against the next advance payment. | [§ 40c Abs. 3 EnWG (Auslegung der Verbraucherzentrale)](https://www.verbraucherzentrale.de/wissen/vertraege-reklamation/kundenrechte/wenn-energieanbieter-guthaben-oder-bonus-nicht-auszahlen-10913) | 2026-11-03 | S |
| 38 <!-- doc-claim: rights-rule de.bgh.ix_zr_267_20 --> | Single case (insolvency): a new-customer bonus designed as a consumption-based discount reduces the final bill. Only an example, not a general rule. | [BGH, 27.07.2023, IX ZR 267/20](https://rewis.io/urteile/urteil/f7q-27-07-2023-ix-zr-26720/) | 2026-11-03 | S |
| 39 <!-- doc-claim: rights-rule de.bgb.195 --> | *To be checked:* Claims are generally time-barred three years after the end of the year in which they arose. | [§§ 195, 199 Abs. 1 BGB](https://www.gesetze-im-internet.de/bgb/__195.html) | 2026-11-03 | G |

### Metering

| No. | Statement | Norm and source | Checked | Status |
|---|---|---|---|---|
| 40 <!-- doc-claim: rights-rule de.msbg.30 --> | Smart metering system: caps per case group for the connection user 40, 50, 110 and 140 € a year, above 100,000 kWh an appropriate charge, 30 € for an optional installation; gross per year and metering point, for the default metering operator (version of 23.12.2025). The amounts of older versions are not documented; the app checks only from 2026-01-01. | [§ 30 Abs. 1, 3 MsbG](https://www.gesetze-im-internet.de/messbg/__30.html) | 2026-11-03 | W |
| 41 <!-- doc-claim: rights-rule de.msbg.30.1.4 --> | With an agreement under § 14a EnWG or a system above 7 up to 15 kW the 50 € group applies. | [§ 30 Abs. 1 Nr. 4 MsbG](https://www.gesetze-im-internet.de/messbg/__30.html) | 2026-11-03 | W |
| 42 <!-- doc-claim: rights-rule de.msbg.30.4 --> | Annual consumption is the mean of the last three annual values, otherwise the grid operator’s forecast; if several groups apply, the highest cap applies; a determination by the Federal Network Agency would take precedence. Whether one exists is open (as of 2026-11). | [§ 30 Abs. 4 bis 6 MsbG](https://www.gesetze-im-internet.de/messbg/__30.html) | 2026-11-03 | W |
| 43 <!-- doc-claim: rights-rule de.msbg.32.1 --> | Modern metering device: at most 25 € gross a year (since 2025, before 20 €; the date of the increase is still to be checked). | [§ 32 Abs. 1 MsbG](https://www.gesetze-im-internet.de/messbg/__32.html) | 2026-11-03 | W |
| 44 <!-- doc-claim: rights-rule de.msbg.35.1 --> | Additional service early installation on request: once up to 100 €, in the optional case up to 30 € a year; installation on request within four months. | [§ 35 Abs. 1 Nr. 1 MsbG](https://www.gesetze-im-internet.de/messbg/__35.html) | 2026-11-03 | W |
| 45 <!-- doc-claim: rights-rule de.msbg.29 --> | Mandatory installation of a smart metering system above 6,000 kWh, with an agreement under § 14a EnWG and for systems above 7 kW; otherwise optional; modern metering device until the end of 2032. | [§ 29 MsbG](https://www.gesetze-im-internet.de/messbg/__29.html) | 2026-11-03 | W |
| 46 <!-- doc-claim: rights-rule de.bnetza.msb_costs --> | The price caps apply to the default metering operator; one you chose freely is not bound by them. | [Bundesnetzagentur: Kosten des Messstellenbetriebs](https://www.bundesnetzagentur.de/DE/Vportal/Energie/Metering/_faq/Kosten_table.html) | 2026-11-03 | S |

### Fee assessment (water, sewage)

| No. | Statement | Norm and source | Checked | Status |
|---|---|---|---|---|
| 36 <!-- doc-claim: rights-rule de.avbwasserv.30 --> | *To be checked:* Water (under private law): objections to a bill only justify deferring payment in case of obvious errors. | [§ 30 AVBWasserV](https://www.gesetze-im-internet.de/avbwasserv/__30.html) | 2026-11-03 | G |
| 47 <!-- doc-claim: rights-rule de.vwgo.70.1 --> | Objection within one month of notification, in writing, electronically under § 3a Abs. 2 VwVfG or recorded in writing at the authority that issued the notice. | [§ 70 Abs. 1 VwGO](https://www.gesetze-im-internet.de/vwgo/__70.html) | 2026-11-03 | W |
| 48 <!-- doc-claim: rights-rule de.vwgo.58.2 --> | Without or with incorrect instructions on legal remedies the deadline is one year. | [§ 58 Abs. 2 VwGO](https://www.gesetze-im-internet.de/vwgo/__58.html) | 2026-11-03 | W |
| 49 <!-- doc-claim: rights-rule de.vwgo.68.1 --> | *To be checked:* State law can exclude the objection procedure; then an action must be brought within one month. | [§ 68 Abs. 1 Satz 2, § 74 Abs. 1 VwGO](https://www.gesetze-im-internet.de/vwgo/__68.html) | 2026-11-03 | G |
| 50 <!-- doc-claim: rights-rule de.vwvfg.41.2 --> | A notice sent by post within Germany is deemed notified on the fourth day after posting. | [§ 41 Abs. 2 Satz 1 VwVfG](https://www.gesetze-im-internet.de/vwvfg/__41.html) | 2026-11-03 | W |
| 50 <!-- doc-claim: rights-rule de.ao.122.2 --> | For levies the same under the Fiscal Code; the reference in each state’s municipal levies act is still to be checked. | [§ 122 Abs. 2 Nr. 1 AO](https://www.gesetze-im-internet.de/ao_1977/__122.html) | 2026-11-03 | W |
| 50 <!-- doc-claim: rights-rule de.vwvfg.41.2.3 --> | If the notice did not arrive or arrived later, the fourth day does not apply; in case of doubt the authority must prove receipt and its date (“Dies gilt nicht, wenn der Verwaltungsakt nicht oder zu einem späteren Zeitpunkt zugegangen ist …”). | [§ 41 Abs. 2 Satz 3 VwVfG](https://www.gesetze-im-internet.de/vwvfg/__41.html) | 2026-11-09 | W |
| 50 <!-- doc-claim: rights-rule de.ao.122.2.zugang --> | The same for levies (“außer wenn er nicht oder zu einem späteren Zeitpunkt zugegangen ist”). | [§ 122 Abs. 2 AO, letzter Halbsatz](https://www.gesetze-im-internet.de/ao_1977/__122.html) | 2026-11-09 | W |
| 51 <!-- doc-claim: rights-rule de.vwgo.80 --> | Objection and action against public levies do not defer payment; enforcement should be suspended if there are serious doubts or it would cause undue hardship. | [§ 80 Abs. 2 Satz 1 Nr. 1, Abs. 4 Satz 3 VwGO](https://www.gesetze-im-internet.de/vwgo/__80.html) | 2026-11-03 | W |
| 52 <!-- doc-claim: rights-rule de.bgb.188 --> | *To be checked:* A one-month deadline ends on the day of the following month with the same number; if there is none, on the last day of the month (the BGB applies under § 222 Abs. 1 ZPO and § 31 Abs. 1 VwVfG). For an end on a weekend or holiday see no. 60. | [§§ 187 Abs. 1, 188 Abs. 2, 3 BGB](https://www.gesetze-im-internet.de/bgb/__188.html) | 2026-11-03 | G |
| 60 <!-- doc-claim: rights-rule de.vwgo.57.2 --> | If the end of a deadline falls on a Sunday, a general public holiday or a Saturday, it ends at the end of the next working day; under § 57 Abs. 2 VwGO this applies to the deadlines of the VwGO. | [§ 57 Abs. 2 VwGO, § 222 Abs. 2 ZPO](https://www.gesetze-im-internet.de/vwgo/__57.html) | 2026-11-03 | W |
| 60 <!-- doc-claim: rights-rule de.vwvfg.31.3 --> | Likewise in administrative proceedings: a deadline ending on a Sunday, public holiday or Saturday ends at the end of the next working day (not if the authority, referring to the provision, named a specific day as the end). | [§ 31 Abs. 3 VwVfG](https://www.gesetze-im-internet.de/vwvfg/__31.html) | 2026-11-03 | W |
| 60 <!-- doc-claim: rights-rule de.ao.108.3 --> | Likewise for levies under the Fiscal Code. | [§ 108 Abs. 3 AO](https://www.gesetze-im-internet.de/ao_1977/__108.html) | 2026-11-03 | W |
| 61 <!-- doc-claim: rights-rule de.feiertage --> | *To be checked:* Public holidays throughout Germany are New Year’s Day, Good Friday, Easter Monday, 1 May, Ascension Day, Whit Monday, 3 October, 25 and 26 December; 3 October under the Unification Treaty (read), the others under the public holiday acts of the states (not retrieved one by one). The app does not know further holidays of individual states. | [Art. 2 Abs. 2 Einigungsvertrag, Feiertagsgesetze der Länder](https://www.gesetze-im-internet.de/einigvtr/art_2.html) | 2026-11-03 | G |
| 53 <!-- doc-claim: rights-rule de.land.vorverfahren --> | *Uncertain:* The states regulate the objection procedure differently (examples 2021: North Rhine-Westphalia, Bavaria). Not in app texts. | [§ 110 JustG NRW, Art. 12 AGVwGO (Bayern)](https://steuerzahler.de/aktuelles/detail/rechtsschutz-gegenueber-behoerden-das-widerspruchsverfahren/) | 2026-11-03 | U |

### Complaints, terms, design

| No. | Statement | Norm and source | Checked | Status |
|---|---|---|---|---|
| 54 <!-- doc-claim: rights-rule de.enwg.111a --> | Consumer complaint: energy suppliers, metering operators and metering service providers answer within four weeks; if they refuse, they give the reasons in text form and point to the arbitration board. | [§ 111a EnWG](https://www.gesetze-im-internet.de/enwg_2005/__111a.html) | 2026-11-03 | W |
| 55 <!-- doc-claim: rights-rule de.enwg.3.31 --> | “Energy” within the meaning of the EnWG is electricity, gas and hydrogen, not district heating; the same section defines household customer. | [§ 3 Nr. 31, Nr. 57 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__3.html) | 2026-11-03 | W |
| 56 <!-- doc-claim: rights-rule de.bgh.i_zr_113_20 --> | A program that creates documents from templates is not a legal service (like a form book). Basis for how the letters are designed, not in app texts. | [BGH, 09.09.2021, I ZR 113/20](https://www.lto.de/recht/juristen/b/bgh-urteil-izr11320-vertragsgenerator-smartlaw-keine-unzulaessige-rechtsdienstleistung-rak-hamburg-rdg-legal-tech) | 2026-11-03 | S |

## What is open

- **Review** by a person before the release (all rows).
- **To be checked (G):** GasGVV §§ 11, 17, 18, 20 in their wording; § 309 Nr. 9
  BGB as the basis of the term warning; limitation (§§ 195, 199 BGB); exclusion
  of the objection procedure (§ 68, § 74 VwGO); end of a period (§§ 187, 188
  BGB); the nationwide public holidays under the states’ holiday acts (no. 61);
  § 30 AVBWasserV; each state’s reference from the municipal levies act to
  the Fiscal Code; the date since which 25 € applies to the modern metering
  device.
- **Uncertain (U):** new version of the AVBFernwärmeV; amendment of the EnWG by
  BGBl. 2026 I Nr. 226; objection procedure in the states; amounts of the MsbG
  versions of 2023 and 02/2025; a determination by the Federal Network Agency on
  the price caps; the notice period of a tacitly extended electricity or gas
  contract (no. 59).
