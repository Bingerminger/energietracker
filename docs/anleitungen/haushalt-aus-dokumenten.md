# Haushalt aus Dokumenten anlegen

**Deutsch** · [English](../en/anleitungen/haushalt-aus-dokumenten.md)

[← Kompendium-Index](../README.md)

*Neu in v3.7.0.* Statt Zähler, Verträge, Preise und Stände abzutippen, legst du
die Post deines Versorgers in den [Posteingang](posteingang.md) und lässt sie
auslesen. Daraus entsteht **ein Entwurf deines ganzen Haushalts**: Zähler,
Verträge mit Preiskette und Abschlägen, Ablesungen, Rechnungen und Termine —
zusammengeführt über alle Dokumente. Du prüfst nur, was wirklich zweifelhaft
ist, und übernimmst mit einem Klick.

---

## 1. Der Ablauf

1. **Einrichten** — der Einrichtungsassistent fragt am Ende „So geht es
   weiter“ und bietet **„Aus Dokumenten“** (empfohlen), **„Von Hand anlegen“**
   und (für Verwalter) **„Mit Beispieldaten ansehen“**
   ([Einrichten](../einstieg/einrichtung.md)). Mit „Aus Dokumenten“ geht es in
   den Posteingang. Ist noch kein Dienst zum Auslesen eingerichtet, öffnet
   **„Auslesen einrichten“** die Karte „Auslesen“ (Lokal | Cloud) gleich als
   Dialog — im Assistenten und über der Liste des Posteingangs, auch später
   noch; dieselbe Karte steht weiter unter Einstellungen → Experte. Einrichten
   darf, wer die Installation verwaltet ([Posteingang](posteingang.md)).
2. **Ablegen** — alle PDFs auf einmal, per Dateiauswahl (am iPad aus der
   Dateien-App) oder per Ziehen.
3. **Auslesen** — „Alle auslesen“; die Seite arbeitet den Stapel Dokument für
   Dokument ab, auch wenn du in der App weiterklickst.
4. **Entwurf öffnen** — sobald das erste Dokument ausgelesen ist, steht über der
   Liste des Posteingangs der Knopf
   **„Entwurf des Haushalts aus … ausgelesenen Dokumenten“** (`#/inbox/draft`).
   Der Entwurf wächst mit, solange noch ausgelesen wird.
5. **Prüfen** — nur, was markiert ist ([§ 4](#4-prüfen-und-übernehmen)).
6. **Übernehmen** — ein Knopf für alles Angehakte. Danach steht da, was
   entstanden ist, was noch fehlt (mit Sprung zur Stelle) und je übernommener
   Rechnung „Rechnung prüfen“.

Der Entwurf arbeitet nur mit den **gespeicherten Auslesungen** — er fragt
keinen Dienst und schickt kein Dokument irgendwohin.

## 2. Welche Dokumente helfen

| Haushalt | Diese Dokumente |
|---|---|
| Zur Miete | die letzte Jahresrechnung für Strom und die Vertragsbestätigung |
| Wohnung oder Eigenheim | je Energieträger die letzte Jahresrechnung, die Vertragsbestätigung und Preisänderungen |
| mit PV-Einspeisung | dazu der Einspeisevertrag oder die Abrechnung des Netzbetreibers |
| mit Wärmepumpe oder Wallbox | dazu die Bestätigung des Sondertarifs |

Mehr hilft mehr: Lieferbeginn-Briefe, Kündigungen, Zählerwechsel-Briefe und
ältere Rechnungen füllen Lücken und bestätigen Angaben gegenseitig. Mahnungen,
Widerrufe und Werbung erkennt der Posteingang und legt sie nur ab.

## 3. Was der Entwurf macht

- **Lieferverhältnis.** Dokumente gehören zusammen, wenn sie dieselbe Vertrags-
  oder Kundennummer tragen oder denselben Versorger nennen — auch in anderer
  Schreibweise, nach derselben Regel wie im [Posteingang](posteingang.md):
  Rechtsform, Groß/klein und Satzzeichen zählen nicht, ein Zusatz wie
  „Vertrieb“ auch nicht („Muster-Energie GmbH“ = „MUSTERENERGIE“ =
  „Muster Energie Vertrieb AG“). Allgemeine Wörter wie „Stadtwerke“ oder
  „Energie“ allein bezeichnen keinen Versorger. Nennt ein Dokument eine Marke
  und den Versorger dahinter („Musterstrom, eine Marke der Muster Energie
  GmbH“), gehören Dokumente unter beiden Namen zusammen.
- **Andere Schreibweise am selben Zähler.** Rechnungen ohne eigene Bestätigung,
  deren Versorger anders heißt, aber ein Wort mit dem Versorger des Vertrags
  teilt, der zur selben Zeit an ihrem Zähler läuft („Musterstadt Energie AG“
  neben „Stadtwerke Musterstadt GmbH“), gehören zu diesem Vertrag — an einem
  Zähler liefert zur selben Zeit nur einer. Der Entwurf sagt es unter
  „Aufgefallen“. Abkürzungen („SWM“) erkennt er nicht; dann entsteht ein
  eigener Vertrag ab der ersten Rechnung.
- **Zähler aus Zählernummer und Marktlokation.** Neue Nummern werden ein neuer
  Zähler. Nummern derselben Messstelle — gleiche Marktlokation, ein
  Zählerwechsel-Brief oder eine lückenlose Folgerechnung mit anderer Nummer —
  sind ein **Zählertausch** am selben Zähler, kein zweiter Zähler. Der leere
  Standardzähler des Erststarts (ohne Nummer, ohne Daten) bekommt Nummer und
  Einbau, statt dass ein zweiter entsteht. Angelegt wird ein Zähler nur, wenn
  etwas an ihm hängt (Ablesung, Vertrag, Rechnung) — eine verstrichene
  Aufforderung, den Zählerstand zu melden, hält allein keinen Zähler.
- **Bestehender Haushalt, Zähler ohne Nummer** *(3.7.1)*. Führst du deine
  Zähler ohne Zählernummer und Marktlokation, kennt der Bestand die Nummern der
  Dokumente nicht. Dann übernimmt der Entwurf den **einen aktiven Zähler der
  Art mit Daten** (Ablesungen, Verträge, Rechnungen), wenn nichts dagegen
  spricht — keine andere Nummer am laufenden Gerät, keine andere
  Marktlokation —, und trägt Nummer und Marktlokation als Ergänzung nach.
  Ein zweiter Zähler entsteht nicht. Kommen mehrere Zähler in Frage, hat schon
  ein anderer Teil der Dokumente diesen Zähler oder passt er nur halb, fragt
  „Fehlt noch“, zu welchem Zähler die Nummer gehört; die Dokumente bleiben
  offen, bis du eines davon im Posteingang dem richtigen Zähler zuordnest (in
  der Vorschau: Zähler wählen). Nennen die Dokumente eine eigene Nummer und
  eine andere Marktlokation als jeder Zähler mit Daten, ist es ein anderer
  Zähler — er entsteht neu.
- **Dokumente ohne Zählernummer** finden ihren Zähler über die Vertrags- oder
  Kundennummer, sonst über das Lieferverhältnis; gibt es nur einen Zähler der
  Art — oder nur einen mit Daten —, über diesen.
- **Vertrag.** Beginn aus der Vertragsbestätigung, sonst aus dem
  Lieferbeginn-Brief, sonst aus der ersten Rechnung (dann steht das dabei). Ein
  Lieferbeginn-Brief allein legt einen Vertrag an. Ein Beginn, der nur
  „voraussichtlich“ ist, gilt mit Hinweis. Ende aus der Kündigung oder der
  Schlussrechnung, sonst am Tag vor dem Folgevertrag. **Der Abschlag ist kein
  Pflichtfeld** — fehlt er, steht er unter „Fehlt noch“.
  - **Bestätigung ohne Beginn:** Den Brief dazu findet der Entwurf über die
    Vertrags- oder Kundennummer, sonst über gleiche Preise (ein Vertrag oder
    die Rechnungen eines Versorgers mit genau diesen Preisen — läuft zu der Zeit
    schon ein Vertrag desselben Versorgers, gehört sie zu ihm), sonst ist es der
    eine freie Lieferbeginn-Brief desselben Versorgers. Ist das mehrdeutig,
    entsteht kein Vertrag und kein Zähler, nur „Fehlt noch“ — der Entwurf
    schätzt nicht, schon gar nicht über Verbrauchsarten hinweg.
  - **Zwei Bestätigungen** mit gleichem Beginn beim selben Versorger und am
    selben Zähler sind ein Vertrag, auch mit verschiedenen Nummern.
    Bestätigungen derselben Vertragsnummer (etwa eine spätere
    Tarifbestätigung) sind ein Vertrag mit dem frühesten Beginn, gleich in
    welcher Reihenfolge du sie abgelegt hast *(3.9.0)*.
  - **Wenige Tage auseinander** *(3.9.0)*: Nennen Bestätigung und
    Lieferbeginn-Brief desselben Lieferverhältnisses am selben Zähler Tage bis
    eine Woche auseinander (etwa die Marke den Vertragsbeginn, das abrechnende
    Stadtwerk den ersten Liefertag), ist das ein Vertrag mit dem Beginn der
    Bestätigung. Verschiedene Vertragsnummern bleiben zwei Verträge.
  - **Rechnungen vor dem Beginn:** Gehört die Rechnung zum selben Vertrag
    (keine andere Vertragsnummer), beginnt er mit ihr — „Aufgefallen“ sagt es.
    Mit anderer Vertragsnummer entsteht aus ihr ein Vorvertrag bis zum Tag vor
    dem Beginn, am Zähler der Rechnung.
  - **Ende:** Ist das Ende im Dokument zweifelhaft und gibt es einen
    Folgevertrag, gilt der Tag vor dessen Beginn — vorbelegt.
  - **Verträge im Bestand ohne Nummer** *(3.7.1)*: Gleicher Zähler (oder
    seine Gruppe), derselbe Versorger und ein Beginn am selben Tag oder
    höchstens sieben Tage daneben — das ist der Vertrag aus dem Bestand, kein
    zweiter. Der Beginn des Bestands bleibt („Aufgefallen“ nennt beide Tage;
    Bestätigung, Lieferbeginn-Brief und erste Rechnung nennen oft verschiedene
    Tage, ein Wechsel am selben Zähler liegt nie eine Woche neben dem vorigen
    Beginn). Fehlt dort eine Angabe — Vertragsnummer, Tarif, Preis, Abschlag —,
    heißt der Eintrag „ergänzen“; eine Angabe, die vom Bestand abweicht, ist
    ein Widerspruch. Rechnungen über mehrere Jahre gehören je an den Vertrag,
    der damals lief. Verglichen wird der endgültige Beginn: Rückt ein Vertrag
    auf seine erste Rechnung vor, gleicht der Entwurf ihn mit diesem Beginn
    noch einmal ab *(3.9.0)*.
  - **Derselbe Versorger unter anderem Namen?** *(3.9.0)* Gleicher Beginn wie
    ein Vertrag im Bestand, aber ein anderer Name (die Marke im Bestand, das
    Stadtwerk oder der Konzern im Dokument), und der Bestand trägt keine andere
    Vertragsnummer: Der Eintrag fragt „derselbe Versorger?“ und nennt beide
    Namen. Er ist nicht vorbelegt. Hakst du ihn an, ergänzen die Angaben den
    vorhandenen Vertrag, sein Name bleibt. Mit einer anderen Vertragsnummer im
    Bestand bleibt es ein Widerspruch.
- **Preiskette.** Bestätigung und Preisänderungen (gültig ab) bilden die Kette
  und sind vorbelegt. Ein Preis auf der Rechnung ist ein Beleg — verglichen
  wird brutto: Steht er netto mit gelesenem Steuersatz da, rechnet der Entwurf
  ihn um wie der [Posteingang](posteingang.md) — auch einen bestätigten Preis
  (der gedruckte Wert gilt) und einen korrigierten (eingegeben in derselben
  Basis wie gedruckt); am Feld steht die Basis mit dem Ergebnis („netto laut
  Rechnung, mit 19 % USt = … brutto“). Passt er, bestätigt er die
  Kette; ist nur der Steuersatz ein anderer (gleicher Nettopreis), entsteht ab
  dem Tag, den die Rechnung nennt, eine neue Preiszeile, vorbelegt (am Tag des
  Vertragsbeginns ersetzt sie die erste Zeile); weicht er
  sonst ab, steht das unter „Aufgefallen“ — die
  Kette bleibt, „Rechnung prüfen“ zeigt den Unterschied. Nur wo kein anderes
  Dokument für den Zeitraum einen Preis nennt, wird der Rechnungspreis ein
  Glied der Kette — vorbelegt, wenn netto oder brutto mit Steuersatz belegt
  ist, sonst zu prüfen. Abschläge ebenso als Kette (Bestätigung,
  Abschlagsänderung, neuer Abschlag aus der Rechnung).
- **Abgleich über die Dokumente.**
  - Endstand der einen Rechnung = Anfangsstand der nächsten: beide Stände
    bestätigt, eine Ablesung. Passen sie nicht, sind beide zu prüfen. Steht
    derselbe Stand im Bestand einen Tag daneben (31.12. gegen 01.01.), ist er
    schon vorhanden *(3.7.1)*.
  - Lücken und Überschneidungen zwischen aufeinanderfolgenden Verträgen
    derselben Verbrauchsart stehen unter „Aufgefallen“ — korrigiert wird
    nichts. Ohne Ende in den Dokumenten endet ein Vertrag am Tag vor dem
    Folgevertrag. Reicht ein Ende aus den Dokumenten in den Folgevertrag
    hinein, bleibt es gespeichert, wie es dasteht; Abschläge, Grundpreis und
    Saldo zählen den Vertrag aber nur bis zum Tag vor dem Folgevertrag (es gilt
    der spätere Beginn).
  - Ein fallender Stand ohne Zählerwechsel wird ein **vorgeschlagener**
    Zählertausch (nicht vorbelegt). Folgt wenige Tage darauf ein Wechsel der
    Zählernummer, ist das derselbe Tausch — ein Gerätewechsel am Tag des
    Sprungs. Stammt der Endstand des alten Zählers aus der Rechnung am Tag
    davor oder am Wechseltag, ist der Tausch vorbelegt. Erschlossen wird der
    Endstand nur aus einem Stand höchstens sieben Tage vor dem Tausch; sonst
    steht er unter „Fehlt noch“, und die Stände des neuen Zählers fragen nach,
    bis der Tausch eingetragen ist. Nennt die Rechnung die Stände je Zähler
    (beide Zähler eines Tauschs), kommen beide von dort. Vorbelegt ist ein
    solcher Tausch nur, wenn alles zusammenpasst — wie im Einzeldokument: die
    alte Nummer ist das laufende Gerät, Ausbau am Tauschtag oder am Vortag,
    kein bekannter Stand über dem Endstand, der neue Zähler steigt, im Bestand
    noch kein Stand ab dem Tauschtag. Am Tauschtag selbst entsteht keine
    Ablesung: Endstand und Anfangsstand trägt der Tausch. Steht dieselbe
    Nummer mehrmals da, zählt der Zeitraum: folgen die Zeiträume aufeinander
    (ein Zähler über mehrere Ablesungen), werden die Zwischenstände Ablesungen
    desselben Zählers; überlappen sie sich (Zweitarifzähler, HT/NT), entsteht
    weder Tausch noch Ablesung daraus, sondern ein Eintrag unter „Fehlt noch“.
  - **Geschätzte Stände** (laut Rechnung, am Zitat entschieden: „geschätzt“
    oder ein auf dem Dokument erklärter Ablesecode) werden als geschätzte
    Ablesung übernommen, nicht als Messung.
  - Dieselbe Angabe in zwei Dokumenten gleich: bestätigt — Kennungen
    (Kunden-, Vertrags-, Zählernummer, Marktlokation) auch mit anderen
    Leerzeichen, Strichen oder führenden Nullen. Verschieden: vorgewählt ist
    bei Kennungen die Nummer aus dem jüngsten Dokument (nach Ausstellungsdatum,
    sonst nach Stichtag), die andere steht zur Wahl; sonst eine Entscheidung
    mit beiden Belegstellen.
  - **Rechnungskorrektur:** Zwei Rechnungen für denselben Zeitraum, eine mit
    späterem Ausstellungsdatum oder als Korrektur gekennzeichnet („korr“ im
    Dateinamen; „Korrektur“, „korrigiert“, „berichtigt“ im Titel oder im
    Text): Die spätere Fassung gilt und ist vorgewählt, die frühere bleibt
    Beleg.
- **Wohl doppelt.** Derselbe Inhalt wie ein älteres Dokument (andere Datei) —
  so, wie es die Liste im Posteingang als „wohl ein Doppel“ zeigt: zählt
  einmal, auch wenn das ältere schon übernommen ist. Nach dem Übernehmen steht
  es auf „Abgelegt“, die Datei bleibt.
- **Bestand.** Was schon da ist, heißt „schon vorhanden“ und wird
  übersprungen; „Widerspruch“ überschreibt nur, wenn du das Feld bestätigst;
  „Überschneidung“ heißt: neu, aber im Zeitraum eines anderen Vertrags;
  „ergänzen“: vorhanden, eine Angabe kommt dazu. **„Ergänzen“ und
  „Überschneidung“ sind nie vorbelegt** *(3.7.1)* — sie berühren, was schon da
  ist, und sind immer eine Frage; vorbelegt ist nur, was neu ist und nichts
  Vorhandenes ändert.

## 4. Prüfen und übernehmen

- **Ein Haken je Feldgruppe:** Zähler, Zählertausch, Vertrag, Preise,
  Abschläge, Ablesungen eines Zählers, Rechnung, Gas-Umrechnung, Termin.
  Vorbelegt ist, was neu ist, ohne Zweifel und ohne den Bestand zu berühren.
- **Mit dem Vertrag gekoppelt** *(3.9.0)*: Preise, Abschläge und Rechnungen
  eines Vertrags, der erst entsteht oder dessen Versorger eine Rückfrage ist,
  gehen nur mit ihm. Ohne seinen Haken sind sie gesperrt und nicht vorbelegt.
  Hakst du den Vertrag an, kommen sie mit, so wie sie vorbelegt wären; nimmst
  du den Haken weg, gehen sie mit. An einem vorhandenen Vertrag, der
  feststeht, gelten sie für sich. Ablesungen hängen am Zähler, nicht am
  Vertrag. Fehlt der Vertrag beim Übernehmen, etwa weil er scheitert, entsteht
  auch seine Rechnung nicht. Sie bucht ihr Ergebnis nie auf einen anderen
  Vertrag.
- **Zweifelhaft** ist ein Feld, das der [Posteingang](posteingang.md) markiert
  — mit denselben Marken nach dem heutigen Stand, die auch die Liste zählt —
  und das kein zweites Dokument bestätigt. Ein Preis auf der Rechnung, der zum
  Vertrag passt, ist „schon vorhanden“. Zwei Rechnungen mit demselben Preis
  bestätigen ihn nicht — netto, brutto oder ein Teilbetrag stünde in beiden
  gleich falsch.
- **Fehlt noch:** was in keinem Dokument steht — je Vertrag Preise und
  Abschlag, dazu die offenen Punkte des Posteingangs („Fehlt im Dokument,
  später nachtragen: …“) mit den Dokumenten, einmal je Vertrag, soweit kein
  anderes Dokument sie bringt.
  Was unsicher gelesen ist, fragt der Eintrag selbst. Wäre ein Vertrag nach
  dem Übernehmen (nur das Vorbelegte) ohne Arbeits- oder Grundpreis, steht das
  hier — auch wenn die Preise nur auf Rechnungen stehen und noch zu bestätigen
  sind. Ebenso ein Abschnitt ohne Preis, etwa die Zeit vor der ersten
  Preiszeile, wenn der Beginn auf die erste Rechnung vorgezogen wurde.
- **Kernfelder** entscheiden über den Haken: beim Vertrag Versorger und
  Beginn, beim Zählertausch Tag und Endstand, bei der Rechnung der Zeitraum, beim
  Termin der Tag. Ist eines zweifelhaft, ist die Gruppe nicht vorbelegt — wer
  sie anhakt, bestätigt sie.
- **Andere zweifelhafte Felder** haben ein eigenes Häkchen „übernehmen“; ohne
  bleibt das Feld leer, der Rest der Gruppe wird übernommen.
- **Verschiedene Angaben:** Wähle die richtige; jede zeigt ihre Belegstelle.
- **Belegstelle:** „Belegstelle“ antippen zeigt Dokument, Seite und Zitat, der
  Name öffnet das Dokument.
- **Reihenfolge der Übernahme:** Zähler → Zählertausch → Verträge →
  Preise und Abschläge → Ablesungen → Rechnungen (mit Buchung des Ergebnisses
  als Sonderzahlung) → Gas-Umrechnung → Termine. Über dieselben Prüfungen wie
  die Handeingabe; ein Fehler hält den Rest nicht auf.
- **Rückweg:** Vorher legt die App eine Sicherung an (Einstellungen → Daten,
  Anlass „vor Haushalt aus Dokumenten“). Ein zweites Übernehmen ändert nichts:
  Was schon übernommen ist, steht als „schon vorhanden“ da.
- **Danach** sind die Dokumente, die der Entwurf vollständig abdeckt,
  „Übernommen“: Ein Brief hängt als Beleg am Vertrag, eine Rechnung an der
  Rechnung, und die Akte des Vertrags zeigt sie. Ihre Kernaussage im
  Posteingang rechnet die App danach neu (bei einer Rechnung mit „Rechnung
  prüfen“). Was du nicht angehakt hast, bleibt im Posteingang und erscheint im
  nächsten Entwurf wieder.

## 5. Grenzen

- **Zählerwechsel-Briefe ohne Nummer und Stände:** Ohne Endstand des alten
  Zählers trägt der Entwurf den Tausch nicht ein (er stünde sonst mit einem
  erfundenen Wert da) — unter „Fehlt noch“ mit Sprung zu den Zählern. Steht
  der Stand in einer Rechnung, nimmt der Entwurf ihn von dort.
- **Dokumente ohne Lieferbeginn:** Nennt kein Dokument eines Versorgers den
  Beginn und gibt es keine Rechnung, entsteht kein Vertrag und kein Zähler
  ohne Nummer; „Fehlt noch“ sagt es. Leg ihn von Hand an, die übrigen Angaben
  folgen im nächsten Entwurf.
- **Wasser:** Den Vertrag legt der Entwurf an, die drei Preise (Trinkwasser,
  Schmutzwasser, Niederschlag) trägst du von Hand ein.
- **PV-Einspeisung:** Erkennt der Posteingang die Verbrauchsart nicht, ordne
  das Dokument selbst zu; der Entwurf folgt deiner Zuordnung.
- **Verstrichene Termine** aus alter Post (Zählerstand melden, Sonderkündigung)
  entstehen nicht.
- **Preise auf Rechnungen** ohne Bestätigung oder Preisänderung für den
  Zeitraum bleiben zu prüfen, solange sie nicht zum Vertrag im Bestand passen.
  Ein Nettopreis zählt nur umgerechnet, wenn der Steuersatz gelesen ist —
  vermutet wird er nie; sonst steht der Preis wie gedruckt da.

---

Für Integrationen: `GET /api/inbox/draft` und `POST /api/inbox/draft/apply`
in der [API-Referenz](../referenz/api.md#haushalt-aus-dokumenten-v370).
