# Posteingang: Versorgerpost auslesen lassen

**Deutsch** · [English](../en/anleitungen/posteingang.md)

[← Kompendium-Index](../README.md)

Seit v3.7.0 hat der Energietracker einen **Posteingang**. Post vom Versorger
— Jahresabrechnung, Vertragsbestätigung, Preisänderung, Kündigung — kommt als
PDF hinein. Eine **KI im eigenen Heimnetz** liest sie aus, du prüfst den
Vorschlag neben dem Dokument und übernimmst ihn mit einem Klick in Verträge,
Zähler, Rechnungen und Ablesungen. Dokument für Dokument, nichts im
Verborgenen. Ohne KI bleibt der Posteingang ein Ablageort: ablegen, zuordnen,
von Hand erfassen.

Zu jedem Dokument sagt die App in einem Satz, **was der Brief für dich
bedeutet und was jetzt zu tun ist** ([Was heißt das für dich?](#was-heißt-das-für-dich)).
Hinein kommt die Post nicht nur über den Browser, sondern auch per
[Kurzbefehl](#per-kurzbefehl-vom-iphone-oder-ipad), über einen
[überwachten Ordner](#überwachter-ordner) oder per
[E-Mail über Home Assistant](#per-e-mail-über-home-assistant). Und an jedem
Vertrag und Zähler liegt eine [Akte](#akte-je-vertrag-und-zähler) mit allen
Dokumenten.

---

## Ablegen

**Posteingang** (📥 in der Seitenleiste, am iPhone unter „Mehr“ und im
Erfassen-Blatt) öffnen und das PDF auf die Fläche ziehen oder antippen und
auswählen. Am iPad öffnet die Auswahl die **Dateien-App**: ein PDF aus der Mail
dort sichern und hier wählen — ein „Teilen“-Ziel für Web-Apps bietet iPadOS
bisher nicht; dafür gibt es den [Kurzbefehl](#per-kurzbefehl-vom-iphone-oder-ipad).
Mehrere Dateien auf einmal gehen auch.

- Nur **PDF**, bis 10 MB je Datei. Gespeichert wird es im Belegspeicher wie
  jeder Beleg; es zählt zum Speicher der Belege (`attachments_max_mb`) und
  reist mit jedem Backup.
- **Doppelt?** Liegt dieselbe Datei schon im Posteingang, sagt die App „Dieses
  Dokument liegt schon seit … im Posteingang“ und legt sie nicht noch einmal
  ab (erkannt an der Prüfsumme, nicht am Namen). Dasselbe Schreiben in einer
  anderen Datei, etwa ein zweiter Scan, erkennt sie *(v3.7.0)* nach dem
  Auslesen am Inhalt — gleiche Art, gleicher Zeitraum bzw. Stichtag, gleiche
  Nummern und Beträge: Die Vorschau sagt „Wohl dasselbe Dokument wie …“ mit
  Sprung dorthin. Gelöscht wird nichts; was das eine schon übernommen hat,
  steht beim anderen als „schon vorhanden“.
- Die Liste zeigt je Dokument Art, Stand und abgelegt am: **Neu**,
  **Vorgemerkt**, **Ausgelesen** (mit „n Felder prüfen“), **Fehlgeschlagen**,
  **Übernommen**, **Abgelegt**.

---

## Auslesen

In der Vorschau eines Dokuments **„Auslesen“** tippen. Drei Schritte, die die
Seite anzeigt:

1. **Text lesen** — der Browser liest die Textebene des PDFs (mit PDF.js, das
   die App mitbringt). Fotos und Scans ohne Textebene liest der Posteingang
   nicht; die erfasst du von Hand.
2. **Art erkennen** — Rechnung, Vertragsbestätigung, Preisänderung,
   Kündigung, Lieferbeginn, Zählerwechsel, Zählerstandsanforderung,
   Abschlagsänderung; Mahnung, Widerruf und Sonstiges werden nur erkannt und
   abgelegt.
3. **Felder lesen** — genau die Felder dieser Art, jedes mit seinem Wert, der
   **wörtlichen Belegstelle** und der Seite.

Lokal dauert ein Dokument mit qwen3.8 gut eine Minute (in der Erprobung rund
78 Sekunden). „Abbrechen“ hält die Seite an; der
Dienst rechnet im Hintergrund zu Ende, und das Ergebnis steht beim nächsten
Auslesen ohne Wartezeit bereit (zwischengespeichert je Dokument, Modell und
Schema-Version). „Neu auslesen“ fragt den Dienst noch einmal.

Seiten mit allgemeinen Geschäftsbedingungen, Widerrufsbelehrung oder
Datenschutzhinweisen (ab der zweiten Seite, an der Überschrift oder an vielen
Paragrafen erkannt) schickt die App nicht mit — dort steht kein Feld, und sie
kosten viel Zeit.

### Die Belegstelle prüft der Server

Jedes Feld kommt mit einem Zitat aus dem Dokument. Ein Feld gilt als
**„prüfen“** (gelb markiert), wenn

- es kein Zitat hat oder das Zitat nicht im Dokument steht — verglichen ohne
  Unterschiede bei Leerraum, Silbentrennung und Groß/klein, auch wenn die
  Textebene Umlaute als zwei Zeichen liefert („Ã¤“ oder „√§“ statt „ä“) *(v3.7.0)*
  oder „Š“, „š“, „Ÿ“ statt „ä“, „ö“, „ü“,
- der Wert nicht in seinem eigenen Zitat steht,
- eine Marktlokations-ID nicht ihre Form hat: 11 Ziffern mit gültiger
  Prüfziffer nach BDEW *(v3.7.0)*,
- vor einer Zählernummer ein Kürzel mit Sternchen steht („*EMH …“): Was zur
  Nummer gehört, prüfst du *(v3.7.0)*,
- es leer ist, obwohl ein Vorschlag es braucht — etwa das Datum zu einem
  Zählerstand, die Art des Ergebnisses zu seinem Betrag oder bei einer
  Rechnung Verbrauch und Betrag, wenn beide fehlen.

Dazu kommen feste Prüfungen: **jedes Feld** eines Zählerwechsels, einer
Zählerstandsanforderung und einer Abschlagsänderung (bei den beiden ersten
verwechselte das Modell in der Erprobung Zählernummern), **Preise aus
Rechnungen** (netto, brutto, anteiliger Betrag), wenn sie von der Preiszeile
des Vertrags am selben Tag abweichen oder er keine kennt, und zwei
Gegenrechnungen der Rechnung: Die gezahlten Abschläge zählt der Server selbst
zusammen, und das Ergebnis muss Gesamtbetrag minus Abschläge sein — geht es
nicht auf, sind *(v3.7.0)* alle beteiligten Beträge markiert, denn welcher
falsch gelesen ist, weiß die App nicht. Datum und Titel eines Dokuments sind
von „jedes Feld“ ausgenommen, solange kein Vorschlag sie braucht.

**Keine Marke, nur ein Hinweis** *(v3.7.0)*:

- Ein Feld, das **im Dokument fehlt** und das kein Vorschlag braucht, steht
  eingeklappt unter „nicht im Dokument“ — etwa der neue Abschlag einer
  Schlussrechnung. Fehlt es einem Vorschlag, nennt er es als offenen Punkt.
- Ein Datum, das nur **„voraussichtlich“** ist, übernimmt die App als Hinweis;
  der Vorschlag bleibt angehakt.
- Ein **Auswahlfeld** (Energie, Art der Rechnung, Ergebnis) ohne passendes
  Zitat, das andere Angaben stützen: die Zählernummer oder Marktlokation am
  zugeordneten Zähler, die Vertragsnummer am Vertrag, Einheiten (m³ und kWh
  zusammen nur bei Gas) und Stichwörter im Tarif oder in den übrigen Zitaten,
  bei der Art der Rechnung der neue Abschlag, beim Ergebnis Betrag gegen
  Abschläge.
- Ein **Preis aus der Rechnung**, der zur Preiszeile des Vertrags am selben
  Tag passt (auf Rundung genau): Er ist „schon vorhanden“.
- Ein **Nettopreis** *(v3.7.0)*: Weist die Rechnung den Preis netto aus
  („netto“, „zzgl. 19 % USt“) und steht der Steuersatz in der Belegstelle,
  rechnet die App ihn in brutto um — sie führt Bruttopreise — und vergleicht
  brutto mit brutto. Ein Steuersatz wird gelesen, nie vermutet; erkennt die App
  netto nicht sicher, bleibt die Marke. Gelesen wird nur aus einer Belegstelle,
  die im Dokument steht (bei „…“ nur aus ihren Teilen, die dastehen). Steht der Satz nicht in der
  Belegstelle, gilt er auch aus derselben Textzeile, wenn das Modell genau ihn
  nennt, aus einer Steuerspalte (Kopf „USt %“ über der Zeile, die Zahl ohne
  „%“) oder aus der einen Umsatzsteuerzeile der Rechnung; weist sie zwei Sätze
  aus, rechnet die App nicht um.
- **Gleicher Preis, anderer Steuersatz** *(v3.7.0)*: Ist der Nettopreis
  derselbe wie im Vertrag und nur der Steuersatz ein anderer (etwa die
  Bestätigung „brutto inkl. 7 % USt“, die Rechnung netto mit 19 %), ist das
  kein neuer Tarifpreis: keine Marke, eine neue Preiszeile ab dem Tag, für den
  die Rechnung den Preis ausweist. Den Satz des Vertrags liest die App aus
  seinen Dokumenten im Posteingang oder aus derselben Rechnung.

Eine **Belegstelle aus mehreren Stellen** *(v3.7.0)* — in Tabellen stehen
Bezeichnung und Wert in getrennten Spalten — gilt als gefunden, wenn die Teile
eines Zitats mit „…“ in ihrer Reihenfolge nah beieinander auf derselben Seite
stehen (in derselben Zeile oder der darunter, auch in einer breiten
Tabellenzeile — gezählt wird nach Zeilen) und der Wert in einem Teil. Ohne
„…“ darf ein Zitat in zwei Teile zerfallen, aber nur streng: beide auf
derselben Seite, der erste kurz vor dem zweiten (wie Spaltenkopf und Zeile),
der Wert im zweiten und nicht im ersten.

**Stände je Zähler über mehrere Zeiträume** *(v3.7.0)*: Listet die Rechnung
einen Zähler in mehreren Ablesezeiträumen, fasst die App sie zusammen —
erster Anfangsstand, letzter Endstand, die Stände dazwischen werden eigene
Ablesungen. Steht die Zählernummer als Kopfzeile über den Standzeilen statt in
ihnen, gilt sie als belegt. Nach einem Zählertausch sind die Stände des neuen
Zählers nur vorab angehakt, wenn der Tausch es ist; stehen mehrere
Zählernummern auf der Rechnung, ist ein einzelner Stand nur vorab angehakt,
wenn er sicher zum zugeordneten Gerät gehört.

**Ablesecodes je Stand** *(v3.9.0)*: Erklärt die Rechnung Codes wie
„S=Schätzung I=Internetablesung“, gilt ein Code für den Stand direkt davor. In
„Altstand 1.234 I · Neustand 1.300 S“ ist nur der Neustand geschätzt, nicht
jeder Stand der Zeile. Datum und Zählernummer zählen dabei nicht als Stand.
Stehen zwei Zeiträume hintereinander (Endstand am 30.09., Anfangsstand am 01.10.
mit demselben Wert), wird daraus eine Ablesung.

Zu jedem Dokument liest die App *(v3.7.0)* auch **Datum und Titel** (Brief- bzw.
Rechnungsdatum, Überschrift wie „Rechnungskorrektur“, auch aus dem Seitenkopf):
Sie ordnen die Post zeitlich und zeigen eine Korrektur als solche. Fehlen sie,
ist das keine Marke.

Die Marken richten sich nach dem, was heute in der App steht, und gelten auch
für Dokumente, die du vor diesem Stand ausgelesen hast. Nur die Belegstellen
prüft die App beim Auslesen: Eine frühere Auslesung behält ihre Prüfung der
Zitate, bis du das Dokument neu ausliest.

Deutsche Zahlen liest die Prüfung deutsch: „12.345“ ist zwölftausend, nicht
zwölf Komma drei — Modelle hielten den Tausenderpunkt gern für ein Komma. Mit
diesen Regeln rutschte in der Machbarkeitsprobe (61 echte Dokumente) kein
falscher Kernwert ungeprüft durch.

**Gesperrte Ziffern** *(v3.9.0)*: Manche Rechnungen setzen Beträge mit
Leerzeichen zwischen den Ziffern („3 1 5,5 9 €“, „-8 0 0 , 0 0 €“, „1 8 3 Tage“).
Die Prüfung liest das als eine Zahl, aber nur, wenn jede Ziffer einzeln steht,
jeweils durch genau ein Leerzeichen getrennt, und das Ergebnis eine gültige
Zahl ist. Mehrstellige Zahlen nebeneinander (Spalten einer Tabelle wie
„12 345“) bleiben getrennt. Zeichnet ein PDF rechtsbündige Zahlen Zeichen für
Zeichen von rechts, liest der Browser die Zeile seit v3.9.0 nach der Lage der
Zeichen von links nach rechts; ohne das stand „6 5 , 4 3 2 . 1 €“ statt
„1.234,56 €“ in der Textebene. Ein Dokument, das du davor ausgelesen hast,
behält seine Auslesung, bis du es neu ausliest. Ein Datum mit gesperrten Ziffern
(„0 1 . 0 4 . 2 0 2 5“) setzt die Prüfung bewusst nicht zusammen; ein solches
Feld bleibt markiert, bis du es bestätigst.

Ein markiertes Feld **bestätigst** („Stimmt“) oder **korrigierst** du. Ein Tipp
auf „S. 2“ neben dem Zitat blättert links zur Seite.

---

## Vorschau und Übernehmen

Links das Dokument, rechts von oben:

- **Zuordnung** — Verbrauchsart, Zähler und Vertrag, vorbelegt über die
  Zählernummer, die Marktlokation, die Vertrags- oder Kundennummer, die
  Energieart im Dokument und den Vertrag des Versorgers am Stichtag (bei einer
  Rechnung den, der ihren Zeitraum überwiegend abrechnet). Den Vertrag eines
  **anderen** Versorgers belegt die App nie vor *(v3.7.0)*; der Name zählt ohne
  Groß/klein, Satzzeichen und Rechtsform („Stadtwerke Musterstadt GmbH“ =
  „Stadtwerke Musterstadt“). Passt keiner, bietet sie „Vertrag aus diesem
  Dokument anlegen“ an (siehe Tabelle). Änderbar; die App merkt sich deine
  Wahl.
- **Ausgelesen** — die Felder mit Belegstelle, markierte zuerst. Felder, die
  nicht im Dokument stehen, sind eingeklappt.
- **Vorgeschlagene Änderungen** — abgeglichen mit dem, was schon da ist:
  **neu**, **schon vorhanden** (wird übersprungen), **Widerspruch** (an
  derselben Stelle steht etwas anderes; überschreibt nur, wenn du es anhakst),
  **Überschneidung** (etwa ein neuer Vertrag, der in die Laufzeit eines
  anderen fällt).

Angehakt ist vorab nur, was neu ist (oder sich nur überschneidet), keine Marke
trägt und nicht freiwillig ist. Die alte Ablesung einer Rechnung ist vorab
angehakt *(v3.7.0)*, wenn an dem Tag noch kein Stand steht und sie zwischen die
bekannten Stände passt (nicht kleiner als der vorige, nicht größer als der
nächste oder der Endstand derselben Rechnung, kein Zählertausch dazwischen);
sonst bleibt sie ein Angebot. *(v3.9.0)* Dasselbe gilt für jeden Stand aus einem
Dokument: Passt er nicht zwischen deine vorhandenen Stände, ist er nicht vorab
angehakt, und darunter steht der Nachbar („Liegt unter deinem Stand … vom …“).
Steht derselbe Wert schon an einem früheren Tag im Abrechnungszeitraum und der
Stand der Rechnung am letzten Tag des Zeitraums, ist das dein gemeldeter Stand,
den der Versorger auf das Zeitraumende datiert: „schon vorhanden“, und „Rechnung
prüfen“ rechnet die Menge bis zu deinem Ablesetag. Ein Stand, den das Dokument
auf weniger Stellen druckt, ist derselbe wie deiner, wenn deiner auf diese
Stellen gerundet passt (29.310 im Dokument, 29.310,2 bei dir). Markiert ist ein
Vorschlag nur, wenn eines **seiner** Kernfelder zweifelhaft ist *(v3.7.0)*:
Ein neuer Vertrag braucht Beginn, Versorger und Preise, nicht den Abschlag.
Fehlt der Abschlag, entsteht der Vertrag ohne ihn; ist er unsicher gelesen,
übernimmt der Vertrag ihn nicht. Beides steht unter dem Vorschlag als offener
Punkt („Fehlt im Dokument, später nachtragen: Abschlag“), bis du den Wert
bestätigst oder korrigierst. **„Übernehmen“** schreibt genau das Angehakte —
über dieselben Dienste und Prüfungen wie die Handeingabe. Was es gibt:

| Dokument | Vorschläge |
|---|---|
| Rechnung | Ablesung neu (und alt) — *(v3.7.0)* weist die Rechnung einen Stand als geschätzt aus (Wort wie „geschätzt“ oder ein Ablesecode, den sie selbst erklärt, etwa „S = geschätzt“), wird er als geschätzt angelegt — ebenso „hochgerechnet“, eine Fußnote wie „5)“ mit der Erklärung „5) hochgerechnet“, ein Code in einer Gruppe wie „10/12/26“ und ein Code in der Spalte „Ableseart“ oder „Ablesegrund“, wenn das Dokument ihn als Schätzung erklärt; auch aus den Ständen je Zähler, zugeordnet nach Zählernummer; stehen zwei Zähler auf der Rechnung (der zugeordnete und ein neuer), ein **Zählertausch** mit Endstand des alten und Anfangsstand des neuen Zählers, vorab angehakt, wenn Nummern, Tag und Stände zusammenpassen —, Rechnung erfassen (bei Gas mit Zustandszahl und Brennwert laut Rechnung für „Rechnung prüfen“), Ergebnis als Sonderzahlung buchen, neuer Abschlag am Vertrag desselben Versorgers, der dann gilt; bei Gas Zustandszahl und Brennwert je Zeitraum als Stichtag der [Gas-Umrechnung](../verstehen/01-gas.md); Arbeitspreise und Grundpreis: wie im Vertrag „schon vorhanden“, sonst als Vorschlag zum Prüfen; Marktlokation am Zähler, Vertrags- und Kundennummer am Vertrag. Ohne Vertrag dieses Versorgers *(v3.7.0)*: „Vertrag … aus dieser Rechnung anlegen“ als Angebot (Beginn = Beginn des Zeitraums, eine Schlussrechnung endet mit ihm), und gebucht wird nur an einen Vertrag des Versorgers — sonst lehnt die App ab, statt an einen fremden zu buchen. *(v3.9.0)* Gilt am Zähler gar kein Vertrag, ist dieser Vorschlag vorab angehakt, mit Preisen je Abschnitt und Abschlagsplan ([Vertrag aus der Rechnung](#vertrag-aus-der-rechnung-v390)) |
| Vertragsbestätigung | neuer Vertrag mit Arbeitspreis, Grundpreis (je Jahr umgerechnet in je Monat), Abschlag, Mindestlaufzeit, Kündigungsfrist, Bonus, Preisgarantie, Vertrags- und Kundennummer — unsichere Nebenangaben bleiben offene Punkte; der bisherige Vertrag endet am Vortag — vorab angehakt *(v3.7.0)* bei einem Wechsel (anderer Versorger) oder Tarifwechsel (anderer Tarif oder andere Vertragsnummer) am selben Zähler, als Angebot bei einem Gruppenvertrag, an einem Unterzähler, bei offenbar demselben Vertrag und bei älteren Vorgängern; hat der bisherige ein späteres Ende, das eine Kündigung belegt, ist das ein Widerspruch |
| Preisänderung | Arbeitspreis und Grundpreis ab dem Stichtag, Termin für das Sonderkündigungsrecht |
| Kündigung | Vertragsende, ohne Verlängerung; fehlt ein Anschlussvertrag, ein Termin „Anschlussvertrag abschließen“ 14 Tage vor dem Lieferende (näher am Lieferende: heute) |
| Lieferbeginn | Zählerstand bei Beginn, Beginn des Vertrags; ohne Vertrag dieses Versorgers *(v3.7.0)* „Vertrag … anlegen“ als Angebot (die Preise bleiben offen) |
| Zählerwechsel | Zählertausch mit Endstand und Anfangsstand (alles zu prüfen) |
| Zählerstandsanforderung | Termin „Zählerstand melden“ am Zähler, bei der zuständigen Person |
| Abschlagsänderung | neuer Abschlag ab dem Stichtag; *(v3.9.0)* im Rhythmus des laufenden Abschlags (alle zwei oder drei Monate bleibt so) |

**Termine aus Dokumenten** hängen am zugeordneten Zähler und landen bei der
Person, die zuständig ist: Hat der Vertrag eine, steht sie am Termin; sonst erbt
der Termin die des Zählers ([Benutzer → Zuständig](benutzer.md)). Ein Termin
„Zählerstand melden“ führt in Hinweise und Terminen direkt in die Erfassung
dieses Zählers.

Danach hängt das Dokument als **Beleg** an der Rechnung bzw. am Vertrag, und
der Verlauf (Hinweise → Erledigt) vermerkt „Aus Dokument übernommen“. Wer
dasselbe Dokument noch einmal übernimmt, sieht alles als „schon vorhanden“ —
nichts kommt doppelt.

### Vertrag aus der Rechnung *(v3.9.0)*

Gilt am Zähler im Zeitraum einer Rechnung **kein Vertrag**, schlägt die App
„Vertrag … ab … aus dieser Rechnung anlegen“ vor, für jede Verbrauchsart und
auch für Wasser, und hakt ihn vorab an. Er übernimmt:

- Versorger, Vertragskonto (Vertragsnummer) und den Beginn des Zeitraums; nach
  einer Jahresrechnung läuft der Vertrag weiter, eine Schlussrechnung beendet ihn,
- die **Preise je Abschnitt** ab ihrem Tag (ein Preiswechsel zum 01.10. ergibt
  zwei Preiszeilen), brutto: Nettopreise mit dem Steuersatz, den die Rechnung
  für die Sparte ausweist; ein Satz wird gelesen, nie vermutet,
- **Bestandteile als ein Preis**: Basis- und Bereitstellungspreis einer Sparte
  ergeben zusammen den Grundpreis je Monat (ein Jahrespreis geteilt durch 12),
  Arbeitspreis, Energiesteuer und CO₂-Preis desselben Tags zusammen den
  Arbeitspreis. Unter dem Vorschlag steht, woraus jeder Preis entsteht,
- bei Wasser **alle drei Sparten**: Trinkwasser und Schmutzwasser je m³ und mit
  ihrem Grundpreis, Niederschlagswasser je m² und Jahr mit der Fläche laut
  Rechnung,
- die **laut Rechnung gezahlten Abschläge** des Zeitraums *(v3.9.0)*: gleich hohe
  Zahlungen mit Datum an ihren Terminen, sonst die Summe gleichmäßig je Monat
  (800 € in zwölf Monaten: 66,6667 € je Monat). Bis zum neuen Plan steht danach
  kein Abschlag, den die Rechnung nicht nennt. So zählen Abrechnungsjahr,
  Jahresbericht und „Rechnung prüfen“ dasselbe Bezahlte wie die Rechnung,
- den **neuen Abschlagsplan** mit seinem Rhythmus (siehe unten).

Die Rechnung hängt dann am neuen Vertrag, und
„Ergebnis als Sonderzahlung buchen“ geht im selben Durchgang: Die App legt erst
den Vertrag an, dann die Rechnung, dann bucht sie das Ergebnis. Nimmst du den Haken am Vertrag weg, ist
das Buchen gesperrt („Nur zusammen mit dem Vertrag aus dieser Rechnung.“);
erfassen kannst du die Rechnung trotzdem. Nach dem Übernehmen steht die
Zuordnung des Dokuments am neuen Vertrag („Beim Übernehmen festgehalten.“).

Gilt im Zeitraum schon ein Vertrag, überschreibt die App nichts:

- Ein Vertrag eines **anderen Versorgers** oder einer, der sich überschneidet:
  Der Vorschlag ist nur ein Angebot (nicht angehakt), und an den fremden Vertrag
  wird nicht gebucht.
- Ein Vertrag **desselben Versorgers**, der vor dem Ende des Zeitraums endet:
  Für die Tage danach bietet die App einen **Folgevertrag** an (ab dem Tag nach
  dem Ende), für Tage davor einen Vorvertrag. Beide sind Angebote.
- **Preise der Rechnung am bestehenden Vertrag**: je Sparte und Abschnitt eine
  eigene Zeile, „schon vorhanden“, wenn der Vertrag sie an dem Tag schon hat,
  sonst neu, aber nie vorab angehakt.

### Abschläge alle zwei oder drei Monate *(v3.9.0)*

Nennt die Rechnung den neuen Abschlag mit Terminen („165,00 € am 15.05.2026,
15.07.2026, …“) oder einem Rhythmus („zweimonatlich“, „vierteljährlich“),
übernimmt die App ihn so: Der Betrag gilt **je Termin**, alle zwei oder drei
Monate oder an den genannten Terminen; nach dem letzten genannten Termin geht
es jährlich zu denselben Tagen weiter. Ohne „ab“ beginnt der Plan am ersten
Termin. Unsicher gelesene Termine zählen nicht. Den Rhythmus nimmt die App aus
den Terminen, wenn mindestens zwei gleichmäßig auseinanderliegen (ein, zwei oder
drei Monate, ein Termin darf bis zu drei Tage verschoben sein, etwa wegen eines
Wochenendes); sonst aus der Angabe der Rechnung, gelesen in jeder Sprache der
App („zweimonatlich“, „alle 2 Monate“, „bimonthly“ …). Passt die Angabe nicht zu
den Terminen, gelten die Termine, und der Vorschlag ist nicht vorab angehakt. Eine Abschlagsänderung nennt
meist nur den Betrag: Dann bleibt der Rhythmus des laufenden Abschlags. Auch
die Kernaussage beurteilt den neuen Abschlag dann je Termin, nicht je Monat. Wie
Saldo, Vorschlag und Prognose damit rechnen, steht unter
[Wasser](../verstehen/03-wasser.md#abschläge-alle-zwei-oder-drei-monate-v390);
das gilt für jede Verbrauchsart.

---

## Was heißt das für dich?

Oben in der Vorschau und in der Liste unter dem Namen steht zu jedem
ausgelesenen Dokument **ein Satz, eine Zahl und ein Knopf**: was der Brief
bedeutet und was als Nächstes zu tun ist. Alles Weitere steht unter „Mehr dazu“.
Die Zahlen rechnet der Server mit den Diensten, die auch die übrigen Seiten
rechnen — nichts wird geschätzt, was die App nicht schon weiß.

| Dokument | Satz und Zahl | Knopf |
|---|---|---|
| Preisänderung | Mehr- oder Minderkosten **im Jahr**: Unterschied im Arbeitspreis × erwarteter Verbrauch der nächsten zwölf Monate (dieselbe Prognose wie im [Tarifvergleich](../referenz/ansichten.md)) + Unterschied im Grundpreis × 12. Der alte Preis kommt aus dem Brief, sonst aus dem Vertrag am Tag davor. Darunter die Frist des Sonderkündigungsrechts, wenn der Brief sie nennt. | Spart ein gespeichertes Angebot laut Wechselentscheidung: **„Wechsel spart rund … – Tarife vergleichen“**; sonst „Neue Preise übernehmen“ |
| Rechnung | vor der Übernahme Nachzahlung oder Guthaben laut Rechnung; **nach der Übernahme läuft „Rechnung prüfen“ von selbst**: „passt zu deinen Daten“, „liegt … über/unter deiner eigenen Rechnung“ oder *(v3.7.0)* „im Vertrag fehlen Preise“ mit den Abschnitten ohne Preis, dazu die Gründe (etwa geschätzter Zählerstand an der Grenze) | „Übernehmen und prüfen“, danach bei einer Abweichung „Rechnung prüfen“, ohne Preise „Preise im Vertrag eintragen“ |
| Abschlagsänderung | neuer Abschlag gegen den Abschlag, mit dem die laufende Abrechnung bei null endet (Abschlag laut Plan + erwarteter Endsaldo ÷ Monate bis zur Abrechnung): **passt**, **zu hoch** oder **zu niedrig** um rund … im Monat. „Passt“ heißt: Der Rest am Ende bliebe unter 50 € bzw. einem Abschlag — dieselbe Schwelle wie im [Jahresbericht](jahresbericht-lesen.md). *(v3.9.0)* Mit Rhythmus zählen die Raten bis zur Abrechnung statt der Monate, und das Urteil gilt je Termin. | „Abschlag übernehmen“ |
| Kündigung | Lieferende; ohne Anschlussvertrag „ein Anschlussvertrag fehlt noch“ und der Termin aus den Vorschlägen | „Anschlussvertrag finden“ (Tarifvergleich) |
| Zählerstandsanforderung | Frist und wer zuständig ist; liegt schon ein Zählerstand vor, der höchstens 30 Tage vor der Frist abgelesen wurde, ist es erledigt | „Zählerstand erfassen“ — direkt zum Zähler |
| Zählerwechsel | Tag, Endstand, neuer Zähler und Anfangsstand | „Zählertausch eintragen“ öffnet den Dialog mit allen Werten vorbelegt |
| Vertragsbestätigung, Lieferbeginn | neuer Vertrag bzw. Beginn der Lieferung | „Vertrag anlegen“ bzw. „Übernehmen“ |
| Mahnung | „Prüfe, ob eine Zahlung offen ist“ — die App liest Mahnungen nicht aus | **„Termin anlegen“** mit Frist, vorgeschlagen 7 Tage nach dem Eingang (änderbar); danach ist das Dokument abgelegt |
| Widerruf, Sonstiges | nur zur Kenntnis | „Ablegen“ |

Ohne Zuordnung zu Zähler und Vertrag fehlt jede Zahl, die am Bestand hängt; die
Kernaussage sagt das. Offene Punkte der Vorschläge (fehlt im Dokument, unsicher
und nicht übernommen) stehen unter „Mehr dazu“. Ist es wohl dasselbe Dokument
wie ein anderes, steht der Hinweis mit Sprung in dieser Karte — am iPhone also
vor dem Dokument —, in der Liste und in der Akte „wohl ein Doppel“; hat das
andere schon alles übernommen, heißt der Knopf „Ablegen“. In der Liste führt ein Knopf, der etwas schreibt
(„Übernehmen“, „Termin anlegen“), in die Vorschau — übernommen wird dort, wie
gewohnt nur das Angehakte.

---

## Von Hand erfassen

Ohne KI — oder wenn die Auslesung nicht passt — öffnet **„Von Hand erfassen“**
das passende Formular: bei einer Rechnung „Rechnung prüfen“ mit Zähler und
Zeitraum, bei einer Vertragsbestätigung die Verträge, bei einem Zählerwechsel
die Zähler. **Das Dokument bleibt daneben stehen** — am Mac und am iPad quer
rechts am Rand, am iPhone und am iPad hochkant als Leiste über der
Tab-Leiste. Auch ein Formular im Dialog lässt das Dokument frei. „Zur Vorschau“
führt zurück, ✕ schließt es.

**Ablegen ohne Übernahme** nimmt ein Dokument aus der Liste der offenen, ohne
etwas zu ändern; „Wieder in den Posteingang“ holt es zurück. **Löschen**
entfernt das Dokument; hängt es schon als Beleg an einer Rechnung oder einem
Vertrag, bleibt es dort.

---

## Viele Dokumente auf einmal

Liegen mehrere neue Dokumente im Posteingang, bietet die Liste **„Alle n
auslesen“**. Die Seite arbeitet sie nacheinander ab, auch wenn du in der App
weiterklickst; der Fortschritt steht in der Liste, „Abbrechen“ hält an. Was
noch nicht ausgelesen ist, bleibt vorgemerkt — beim nächsten Öffnen geht es mit
„Fortsetzen“ weiter. Einen Zeitplan (Cron) braucht das nicht.

Danach zeigt die **Prüfliste** nur die markierten Felder aller ausgelesenen
Dokumente; ein Klick öffnet das Dokument an dieser Stelle. Mit qwen3.8 waren in
der Erprobung rund 46 Dokumente je Stunde drin, auf einem starken Mac.

---

## Andere Wege in den Posteingang

Die Post muss nicht durch den Browser. Drei Wege legen ein PDF ab, als hättest
du es hineingezogen — mit derselben Prüfung (nur PDF, bis 10 MB, kein Doppel).
Ausgelesen wird danach wie gewohnt in der Vorschau oder mit „Alle auslesen“.

<a id="inbox-shortcut"></a>

### Per Kurzbefehl vom iPhone oder iPad

Mit einem Kurzbefehl steht der Energietracker im **Teilen-Menü**: In Mail,
Dateien oder Safari ein PDF teilen, „Energietracker“ antippen, fertig.

**Welcher Schlüssel?** Ohne Anmeldung (Einstellungen → Zugriff) braucht der
Kurzbefehl keinen. Mit Anmeldung legst du unter **Einstellungen → Zugriff →
API-Schlüssel für Skripte** einen Schlüssel mit dem Bereich **„nur Posteingang
(PDF ablegen)“** an. Er darf genau eines: ein PDF in den Posteingang legen —
weder lesen noch ändern noch löschen. Geht das Telefon verloren, widerrufst du
nur ihn. Ein Schlüssel „Verwalten“ ginge auch, kann aber alles.

In der App **Kurzbefehle**:

1. **+** tippen, einen neuen Kurzbefehl anlegen und ihn „Energietracker“ nennen.
2. Unten auf das Symbol für die **Details** (ⓘ) tippen und **„Im Share-Sheet
   anzeigen“** einschalten, **Fertig**. Oben erscheint die Eingabe; dort nur
   PDFs bzw. Dateien als Eingabetyp lassen.
3. Die Aktion **„Inhalte von URL abrufen“** hinzufügen. Als URL:
   `http://DEINE-ENERGIETRACKER-IP:8080/api.php/api/inbox` — dieselbe Adresse,
   unter der du die App im Heimnetz öffnest, mit `/api.php/api/inbox` dahinter.
4. Bei der Aktion **„Mehr anzeigen“**: **Methode** `POST`. Mit Anmeldung unter
   **Header** einen Eintrag `Authorization` mit dem Wert `Bearer etk_…` (dein
   Schlüssel, mit „Bearer“ und Leerzeichen davor).
5. **„Haupttext anfordern“** auf **„Datei“** stellen und als Datei die
   **Kurzbefehleingabe** wählen.
6. Optional die Aktion **„Mitteilung anzeigen“** mit dem Ergebnis von „Inhalte
   von URL abrufen“ — dann siehst du die Antwort.

Den Dateinamen kann der Kurzbefehl in der Adresse mitgeben
(`…/api/inbox?name=` und dahinter der Name der Kurzbefehleingabe). Ohne Namen
heißt das Dokument „Dokument JJJJ-MM-TT HH.MM.pdf“; Leerzeichen im Namen können
in der Adresse stören — dann den Namen weglassen.

**Unterwegs?** Der Kurzbefehl erreicht die App nur dort, wo das Telefon sie
erreicht: im Heimnetz oder über dein VPN. Ein Doppel meldet die App mit „liegt
schon seit … im Posteingang“. In der App für Home Assistant geht der Kurzbefehl
nur über einen freigegebenen direkten Port
([Home Assistant](home-assistant.md#als-app-in-home-assistant-os)), nicht über
die Seitenleiste.

<a id="inbox-folder"></a>

### Überwachter Ordner

Im Datenverzeichnis gibt es den Ordner **`posteingang/`** (die App legt ihn beim
ersten Einlesen an). Ein PDF, das dort liegt, wandert in den Posteingang; die
Datei kommt danach nach `posteingang/übernommen/`, eine abgelehnte (kein PDF,
größer als 10 MB) nach `posteingang/fehler/`. Ein Doppel gilt als übernommen.

**Wann?** Die App liest den Ordner, wenn jemand mit Verwalterrechten sie öffnet
oder den Posteingang aufruft (ohne Anmeldung ist jeder Verwalter). *(v3.9.0)*
Dazu höchstens alle fünf Minuten nach einer beliebigen Anfrage, wenn eine
Datei wartet. Einen eigenen Zeitplan hat die App nicht; im Docker-Image und in
der App für Home Assistant fragt der Gesundheitscheck alle 30 Sekunden, ein
abgelegtes PDF ist dort also nach spätestens fünf Minuten im Posteingang. Wer
es sofort will, ruft `POST /api/inbox/folder` per Cron oder aus Home Assistant
auf (mit einem Schlüssel „Verwalten“, wenn die Anmeldung an ist;
[API](../referenz/api.md#ordner--post-apiinboxfolder)). Ist die
[Benachrichtigung an Home Assistant](home-assistant.md#schritt-6--benachrichtigungen-an-home-assistant-v390)
für neue Dokumente an, meldet die App jedes eingelesene Dokument.

**Eigener Ort *(v3.9.0)*:** Mit der Umgebungsvariablen `ET_INBOX_DIR`
(absoluter Pfad) liegt der Ordner woanders, auch außerhalb des
Datenverzeichnisses ([Docker](../betrieb/docker.md#konfiguration-über-umgebungsvariablen)).

**Was die App nicht anfasst:** symbolische Links (auch nicht als Ordner selbst),
Unterordner, versteckte Dateien (`.…`, auch die `._…`-Begleitdateien vom Mac)
und Dateien, die jünger als 10 Sekunden sind — eine Kopie über das Netz könnte
noch laufen. Je Aufruf höchstens 20 Dateien; der Rest kommt beim nächsten Mal.
Gelesen wird nach Inhalt, nicht nach Endung.

| Betrieb | Wo der Ordner liegt |
|---|---|
| NAS mit Webserver (etwa Synology) | `data/posteingang/` im Ordner der App, über die Freigabe des Webordners erreichbar (z. B. `\\nas.local\web\energietracker\data\posteingang`). Der Benutzer des Webservers (bei Synology `http`) muss darin lesen und schreiben dürfen, sonst bleiben die Dateien liegen. |
| Docker | `/data/posteingang/` im Container — mit `-v "$PWD/data:/data"` also `./data/posteingang/` auf dem Host. Mit einem Named Volume einen Ordner des Hosts darüber legen, etwa `-v "$PWD/posteingang:/data/posteingang"`; er muss für den Benutzer des Containers beschreibbar sein ([Docker](../betrieb/docker.md#wo-liegen-meine-daten-das-wichtigste)). |
| App für Home Assistant *(v3.9.0)* | `/share/energietracker/posteingang` (die App setzt `ET_INBOX_DIR` und legt den Ordner beim Start an). Mit der App „Samba share“ im Netz unter `\\<Adresse von Home Assistant>\share\energietracker\posteingang`. Bis v3.7 war der Ordner dort nicht erreichbar. |
| Docker ohne root (`rootless`) | wie Docker; einen Ordner aus `ET_INBOX_DIR` legt das Image dort nicht an, er muss für den Benutzer des Containers (UID 10001 oder die eigene, Gruppe 0) beschreibbar sein. Sonst meldet das Protokoll beim Start eine Warnung. |

<a id="inbox-email"></a>

### Per E-Mail über Home Assistant

Kommt die Post als Anhang einer E-Mail, kann Home Assistant sie weiterreichen:
Die **IMAP-Integration** meldet jede neue Mail als Ereignis `imap_content` mit
ihren Teilen, die Aktion `imap.fetch_part` holt einen Anhang (Base64, wie er in
der Mail steht), und ein `rest_command` legt ihn im Posteingang ab. Der
Energietracker nimmt dafür statt des rohen PDFs auch JSON
`{"name": …, "pdf_base64": …}` an.

1. In Home Assistant die **IMAP**-Integration für das Postfach einrichten, in
   das die Versorgerpost kommt; die Suche am besten auf einen eigenen Ordner
   oder die Absender beschränken.
2. Im Energietracker einen Schlüssel „nur Posteingang“ anlegen (nur nötig mit
   eingeschalteter Anmeldung — in der App für Home Assistant ist sie immer an).
3. In `configuration.yaml`:

```yaml
rest_command:
  energietracker_posteingang:
    url: "http://DEINE-ENERGIETRACKER-IP:8080/api.php/api/inbox"
    method: post
    headers:
      Authorization: !secret energietracker_posteingang   # ganzer Wert, samt „Bearer“
    content_type: "application/json"
    payload: '{"name": {{ name | tojson }}, "pdf_base64": {{ data | tojson }}}'
```

`secrets.yaml` — der **ganze** Header-Wert, wie beim Push der Zählerstände:

```yaml
energietracker_posteingang: "Bearer etk_…"
```

4. Eine Automation, die jeden PDF-Anhang einer neuen Mail weitergibt:

```yaml
automation:
  - alias: "Versorgerpost in den Energietracker"
    triggers:
      - trigger: event
        event_type: imap_content
        event_data:
          initial: true
    actions:
      - repeat:
          for_each: >-
            {% set ns = namespace(keys=[]) %}
            {% for key, part in trigger.event.data.parts.items()
               if (part.content_type == 'application/pdf'
                   or (part.filename | default('') | lower).endswith('.pdf'))
                  and (part.content_transfer_encoding | default('') | lower) == 'base64' %}
              {% set ns.keys = ns.keys + [key] %}
            {% endfor %}
            {{ ns.keys }}
          sequence:
            - action: imap.fetch_part
              data:
                entry: "{{ trigger.event.data.entry_id }}"
                uid: "{{ trigger.event.data.uid }}"
                part: "{{ repeat.item }}"
              response_variable: anhang
            - action: rest_command.energietracker_posteingang
              data:
                name: "{{ anhang.filename or 'Versorgerpost.pdf' }}"
                data: "{{ anhang.part_data }}"
```

In der App für Home Assistant lautet die Adresse
`http://<Hostname der App>/api.php/api/inbox` (Port 80, ohne Ingress), wie beim
[Push der Zählerstände](home-assistant.md#als-app-in-home-assistant-os). Dieselbe
Mail zweimal ergibt kein zweites Dokument (Prüfsumme). Die Ereignisse und die
Aktion `imap.fetch_part` beschreibt die
[Doku der IMAP-Integration](https://www.home-assistant.io/integrations/imap/)
(nachgesehen am 21.10.2026). *(v3.9.0)* Dieser Weg ist in einem laufenden Home
Assistant noch ungeprüft; geprüft ist die Seite des Energietrackers
([Home Assistant → Versorgerpost per E-Mail](home-assistant.md#versorgerpost-per-e-mail)).

---

## Akte je Vertrag und Zähler

Unter jedem Vertrag (Verträge & Abschläge) und jedem Zähler (Zähler verwalten)
klappt **📁 Akte** alle zugehörigen Dokumente auf, neueste zuerst:

- **übernommene oder abgelegte Post** aus dem Posteingang, die diesem Zähler
  bzw. Vertrag zugeordnet ist — mit ihrer Kernaussage und dem Link in die
  Vorschau,
- **Rechnungen** mit ihrem PDF (eine Rechnung aus dem Posteingang steht nur
  einmal),
- **Belege am Vertrag**,
- beim Zähler die **Fotos der Ablesungen**.

Je Eintrag Datum (der Stichtag des Dokuments, sonst der Eingang), Art,
Kernaussage und **Öffnen**. Oben filtert die Auswahl nach der Art, das Suchfeld
nach Name und Text.

---

## Auslesen einrichten

**Einstellungen → Experte → 📥 Auslesen** — eine Karte für beide Wege. Der
Umschalter **Lokal | Cloud** entscheidet, wer die Post ausliest
(`inbox_provider`); eine zweite Stelle dafür gibt es nicht, und der Server
prüft dasselbe. Umstellen dürfen nur Personen, die die Installation verwalten
([Benutzer im Haushalt](benutzer.md)). Die Karte hat eine eigene Taste
**Speichern**.

### Lokal: eigene KI im Heimnetz

Lokal liest dein **eigener Dienst** im Heimnetz, Ollama oder OpenAI-kompatibel
(LM Studio). **Adresse und Schnittstelle gelten auch für die Texterkennung der
Zählerfotos** ([Texterkennung im Heimnetz](texterkennung.md)); deshalb stehen sie
nur einmal, hier unter „Lokal“. In der Karte „Texterkennung im Heimnetz“ bleiben
Bildmodell und Zeitlimit der Fotos. Dazu kommt ein **Textmodell**:

```sh
ollama pull qwen3.8
```

**Empfohlen: `qwen3.8`.** Es braucht rund **18 GB Arbeitsspeicher** — ein Mac
mit Apple-Chip und 32 GB oder ein PC mit entsprechender Grafikkarte. In der
Machbarkeitsprobe erkannte es die Art bei allen 61 Dokumenten und las 98,6 %
der Felder richtig. Andere Modelle gehen auch, lesen aber womöglich schlechter.

| Feld | Schlüssel | Eintrag |
|---|---|---|
| Adresse des Dienstes | `ocr_endpoint` | etwa `http://192.168.178.20:11434` (Ollama), nur im Heimnetz; nur Verwalter |
| Schnittstelle | `ocr_api` | Ollama oder OpenAI-kompatibel (LM Studio) |
| Textmodell | `inbox_model` | Name wie im Dienst, Standard `qwen3.8` (leer = `qwen3.8`) |
| Zeitlimit je Schritt | `inbox_timeout_s` | Sekunden je Schritt (Art, Felder), Standard 300 (30–300) |

**Verbindung prüfen** fragt den Dienst nach seinen Modellen (Ollama
`/api/tags`, OpenAI-kompatibel `/v1/models`) — mit dem, was gerade im Feld
steht, auch vor dem Speichern — und sagt, ob das Textmodell dabei ist. Es geht
kein Dokument mit, und es gilt dieselbe Heimnetz-Regel wie beim Auslesen. Den
Knopf sehen nur Verwalter.

Die App fragt so, wie es in der Probe trug: Temperatur 0, Antwort als JSON,
**ohne Denkmodus** (der verbrauchte das Antwortbudget, ohne zu antworten),
erst die Art, dann die Felder. Bei Ollama setzt sie das Kontextfenster passend
zur Länge des Dokuments (2,5 Zeichen je Token, dazu 8.192 Token für die
Antwort, höchstens 32.768) *(v3.7.0)*. Bricht die Antwort am Fenster ab, fragt
sie **einmal** mit doppeltem Antwortbudget (16.384 Token) nach — in der Zeit,
die vom Zeitlimit übrig ist; reicht auch das nicht, sagt sie das
(„passte nicht ins Kontextfenster“). Ebenso beim OpenAI-kompatiblen
Cloud-Dienst. Bei einem OpenAI-kompatiblen Server stellst du den
Denkmodus dort ab, falls das Modell einen hat.

**Zeitlimit hinter dem Webserver.** Im Docker-Image wartet nginx bis zu
310 Sekunden; jeder Schritt (Art, Felder) ist eine eigene Anfrage und bleibt
darunter. Hinter einem eigenen Reverse-Proxy dessen Grenze ebenso hoch setzen
([Webserver](../betrieb/webserver.md)).

**Webserver mit kurzer Zeitgrenze** *(3.9.0)*. Gibt der Webserver vor PHP
früher auf — etwa auf einer Synology nach 60 Sekunden mit `504` —, rechnet PHP
trotzdem zu Ende und speichert. Die Oberfläche fragt dann alle 5 Sekunden nach
dem Dokument, bis der Schritt gespeichert ist (höchstens 660 Sekunden ab dem
Absenden), und zeigt erst danach einen Fehler — ebenso nach einem Netzabbruch,
im einzelnen Dokument wie beim Auslesen vieler Dokumente. Ohne Warten geht es
mit einer höheren Zeitgrenze am Webserver
([Webserver](../betrieb/webserver.md#kurze-zeitgrenze-vor-php-390)).

### In der Cloud auslesen

Wer keinen Rechner hat, auf dem ein Sprachmodell flüssig läuft, kann das
Auslesen an einen Cloud-Dienst geben. Das ist ein **Opt-in**: Es ist aus, bis
es jemand einschaltet, der die Installation verwaltet, und vor jedem Auslesen
nennt die App den Dienst. Ohne dieses Opt-in verlässt kein Dokument das
Heimnetz.

> **Was hinausgeht und was nicht.** Mit der Cloud verlässt **jedes Dokument,
> das du auslesen lässt**, dein Heimnetz. Es geht an den gewählten Dienst und
> wird dort nach dessen Bedingungen verarbeitet. Der Dienst sieht den ganzen
> Inhalt, auch Name, Anschrift, Zähler- und Kundennummer. Lies vorher, was der
> Anbieter mit eingereichten Daten macht (Speicherdauer, Nutzung fürs Training).
> **Sonst geht nichts hinaus:** keine Zählerstände, Verträge, Einstellungen oder
> anderen Daten deines Haushalts. An den Dienst gehen nur das Dokument (PDF oder
> sein Text), die Leseanweisung der App und dein Schlüssel zur Anmeldung beim
> Dienst. Die Karte „Auslesen“ sagt das in der Ansicht „Cloud“ ebenso deutlich.
> Wer das nicht möchte, bleibt bei „Lokal“.

> **Experimentell.** Alle Cloud-Wege, auch Claude, sind noch nicht mit echten
> Rückmeldungen geprüft. Die Karte und die Rückfrage vor dem Auslesen sagen das.

#### Anbieter

| Anbieter | Adresse | Was hinausgeht |
|---|---|---|
| Claude (Anthropic) | `api.anthropic.com` | das PDF; die Belegstelle liefert der Dienst als wörtliches Zitat samt Seite |
| OpenAI | `https://api.openai.com/v1` | der Text des Dokuments; das PDF bleibt hier |
| Mistral | `https://api.mistral.ai/v1` | der Text des Dokuments |
| OpenRouter | `https://openrouter.ai/api/v1` | der Text des Dokuments |
| Google Gemini | `https://generativelanguage.googleapis.com/v1beta/openai` | der Text des Dokuments |
| Eigene Adresse | deine Adresse mit `https://`, bis zur Versionsstufe (etwa `…/v1`) | der Text des Dokuments |

Alle außer Claude laufen über denselben OpenAI-kompatiblen Anschluss; die
Voreinstellung füllt nur die Adresse vor, so wie sie in der Doku des Anbieters
steht (geprüft am 21.10.2026). Dort zitiert das Modell, und der Posteingang
prüft das Zitat gegen den Text.

#### Modell

Nach dem Speichern des Schlüssels lädt die Karte die **Liste der Modelle** des
Dienstes — `GET …/models`, bei Claude `GET https://api.anthropic.com/v1/models`.
Die Anfrage stellt der Server, der Schlüssel bleibt dort. Du wählst aus der
Liste; später holt „Modelle des Dienstes laden“ sie neu. Klappt das nicht, bleibt
das Feld frei, und du trägst den Namen ein, wie der Dienst ihn kennt. Fest
eingebaut ist nur der Claude-Standard `claude-opus-5-5` (leeres Feld = dieser).

#### Einschalten

1. Beim Anbieter einen API-Schlüssel anlegen. Die Kosten rechnet der Anbieter
   mit dir ab, nicht der Energietracker.
2. Karte „Auslesen“ → **Cloud**: Anbieter wählen (bei „Eigene Adresse“ die
   Adresse eintragen), den Schlüssel einfügen, **Speichern**. Die Karte lädt
   jetzt die Modelle.
3. Modell wählen, **Eingeschaltet** anhaken und **Speichern**. Die App fragt
   einmal nach, was wohin geht; danach liest der Dienst aus.

Wer ausliest, folgt dem Umschalter: **Cloud** gespeichert und eingeschaltet —
der Cloud-Dienst liest. **Lokal** gewählt und gespeichert — es liest der eigene
Dienst; die Cloud bleibt eingeschaltet, aber nichts geht hinaus, und die Karte
sagt das. Ausschalten stellt zurück auf lokal. Einen Cloud-Dienst nimmt der
Server nur an, solange er eingeschaltet ist und einen Schlüssel hat.

Der Schlüssel bleibt auf dem Server. Nach dem Speichern zeigt das Feld nur
„Hinterlegt“, nie den Schlüssel, und er kommt nicht ins Backup. **Ein Schlüssel
gehört zu seinem Dienst:** Wer von Mistral auf OpenRouter umstellt, trägt dort
einen eigenen ein; der von Mistral bleibt für Mistral gespeichert und geht nie
an einen anderen Host. Spielst du eine Sicherung auf einem anderen Server ein,
trägst du ihn dort neu ein; bis dahin sagt der Posteingang, dass der gewählte
Dienst nicht bereit ist. **Schlüssel löschen** nimmt ihn heraus und schaltet den
Anbieter ab.

Ändern dürfen das nur Personen, die die Installation verwalten; Mitglieder
sehen die Cloud-Seite ohne Eingabe und speichern nur die lokalen Felder, die
ihnen offenstehen.

#### Was beim Auslesen passiert

- Vor jedem Auslesen fragt die App „Dieses Dokument geht an …“ und nennt den
  Dienst, sein Ziel, was hinausgeht und dass der Weg experimentell ist. Die
  Warteschlange („Alle auslesen“, „Fortsetzen“) fragt einmal für alle Dokumente
  dieses Laufs und nennt ihre Zahl („12 Dokumente gehen an …“); was danach
  dazukommt, fragt der nächste Lauf. Gemerkt wird die Zustimmung nicht.
- Erst die Art des Dokuments, dann die Felder dieser Art — zwei Anfragen, mit
  denselben Arten und Feldern wie beim lokalen Auslesen. Bei Claude liest die
  zweite das PDF aus dem Zwischenspeicher des Dienstes.
- Werte kommen wörtlich, wie sie im Dokument stehen; umgerechnet und geprüft
  wird im Energietracker, mit derselben [Belegprüfung](#die-belegstelle-prüft-der-server)
  wie beim lokalen Auslesen. Ein Feld ohne Belegstelle oder mit einem Wert, der
  nicht in seinem Zitat steht, ist zur Prüfung markiert.
- Lehnt das Modell ab, sagt die App das, und du erfasst das Dokument von Hand.
  Bei den aktuellen Claude-Modellen versucht der Dienst es vorher selbst mit
  einem anderen seiner Modelle.

#### Grenzen

- Claude nimmt höchstens 32 MB und 600 Seiten je Anfrage.
- Das Zeitlimit ist 120 Sekunden (einstellbar von 10 bis 600). Hinter einem
  eigenen Webserver oder Reverse-Proxy gilt dessen Grenze.
- Weiterleitungen folgt die App nicht: Trag die Adresse ein, unter der der
  Dienst selbst antwortet.
- Eine Adresse im Heimnetz lehnt die App hier ab — dafür ist „Lokal“ da.

#### Wenn es in der Cloud nicht klappt

| Meldung | Was tun |
|---|---|
| Der Dienst hat den Schlüssel abgelehnt | Schlüssel beim Anbieter prüfen und neu einfügen |
| Zu viele Anfragen | die genannte Zeit warten |
| Gerade nicht verfügbar | später noch einmal |
| Liegt im Heimnetz | eine öffentliche Adresse mit `https://` eintragen |
| Leitet weiter | die Adresse eintragen, auf die weitergeleitet wird |
| Antwort unvollständig | noch einmal; bei sehr langen Dokumenten hilft ein kürzeres PDF |
| Liste nicht geladen | den Namen des Modells von Hand eintragen, so wie der Dienst ihn nennt |

Was jeder Dienst sieht und wie die Verbindung geprüft wird:
[Sicherheit & Netzbetrieb](../betrieb/sicherheit.md#auslesen-in-der-cloud-v370).
Die Einstellungen im Einzelnen:
[Einstellungen](../referenz/einstellungen.md#posteingang-auslesen-in-der-cloud-v370).

---

## Datenschutz: was wohin geht

- Das **PDF** bleibt auf deinem Server (Belegspeicher, im Backup).
- Die **Textebene** liest der Browser und schickt sie an deinen Server; der
  schickt sie — ohne AGB-Seiten — an **deinen** Dienst im Heimnetz. Gespeichert
  wird der Text nicht; er steckt ja im PDF.
- Die Adresse des Diensts muss im eigenen Netz liegen, geprüft bei jedem Aufruf
  nach der Namensauflösung, verbunden mit genau der geprüften Adresse —
  dieselbe Regel wie bei der [Texterkennung](texterkennung.md#datenschutz-nur-im-eigenen-netz).
- Ein Anbieter außerhalb des Heimnetzes ist nur möglich, wenn ein Verwalter ihn
  [in der Cloud](#in-der-cloud-auslesen) einschaltet und die Karte „Auslesen“
  auf „Cloud“ stellt; dann fragt die App **vor dem Auslesen**
  „Dieses Dokument geht an …“ (bei der Warteschlange einmal für die Dokumente
  dieses Laufs, mit ihrer Zahl) und schickt nichts ohne deine Zustimmung.
- Ohne eingetragenen Dienst baut der Posteingang keine Verbindung auf.

Mehr: [Sicherheit → Posteingang](../betrieb/sicherheit.md#posteingang-v370).

---

## Wenn etwas nicht klappt

| Meldung | Ursache und Lösung |
|---|---|
| „Für das Auslesen ist kein Dienst eingetragen …“ | Unter Experte → Auslesen → Lokal die Adresse eintragen; „Verbindung prüfen“ zeigt, ob der Dienst antwortet. |
| „Das Modell „qwen3.8“ gibt es auf dem Dienst nicht …“ | `ollama pull qwen3.8` auf dem Rechner des Diensts, oder unter „Textmodell“ ein vorhandenes eintragen (`ollama list`). |
| „Die Texterkennung hat nicht innerhalb von 300 Sekunden geantwortet.“ | Das erste Auslesen nach einer Pause lädt das Modell erst in den Speicher. Noch einmal versuchen; zu wenig Arbeitsspeicher macht es sehr langsam. |
| „Der Dienst hat nicht verwertbar geantwortet …“ | Meist ein Modell mit eingeschaltetem Denkmodus oder eines, das kein JSON liefert. Noch einmal versuchen oder qwen3.8 nehmen. |
| „Das Dokument hat keine Textebene (ein Scan) …“ | Von Hand erfassen; das PDF bleibt als Beleg. |
| Viele Felder gelb | Belegstellen ansehen: Steht der Wert dort anders, korrigieren; steht er richtig da, „Stimmt“. |

Technisch: [API-Referenz → Posteingang](../referenz/api.md#posteingang-v370),
[Datenmodell → Posteingang](../referenz/datenmodell.md#posteingang-v370).

---

[← Kompendium-Index](../README.md) · [Texterkennung im Heimnetz](texterkennung.md)
