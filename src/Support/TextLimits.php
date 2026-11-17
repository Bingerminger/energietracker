<?php
declare(strict_types=1);

namespace Energietracker\Support;

/**
 * v3.13.0 (Paket F, Bekannte Grenze v3.12.0) — Höchstlängen für Freitext, eine Liste für alle Schreibwege.
 *
 * Bis v3.12 nahm die App Notizen und Namen jeder Länge an (eine Notiz von einem Megabyte ging durch, die
 * Größe der Anfrage war die einzige Grenze), oder sie schnitt still ab (Rechnung, Mietverhältnis, Zeitraum,
 * Posteingang, Tankstand). Jetzt gilt je Feld eine Grenze, und zwar dieselbe
 *   - über die API und die Oberfläche (Dienste: assert()),
 *   - im Backup-Import (BackupService: problems(), mit Feld und Grenze),
 *   - im CSV-Import (Ablesungen, Zeiträume: Zeile mit Meldung statt Abschneiden),
 *   - für die KI (MCP: `maxLength` im Schema aus dieser Liste),
 *   - am Feld der Oberfläche (`maxlength`, Zähler bei langen Feldern: public/js/lib/text-limits.js,
 *     dieselben Zahlen, TextLimitsTest vergleicht beide).
 *
 * Gezählt wird in Zeichen (mb_strlen) des Werts ohne Leerraum am Rand, wie er gespeichert wird.
 *
 * Bestand über der Grenze bleibt lesbar und wird nie gekürzt: Geprüft wird nur, was eine Anfrage
 * mitschickt. Wer das Feld speichert (das Formular schickt es mit), bekommt `400 errors.common.tooLong`
 * mit Feld, Grenze und Länge und kürzt selbst. Einträge einer Liste am Datensatz (Sonderzahlungen,
 * Peilstände, Zäsuren …) schicken Formulare und interne Wege immer ganz: Dort zählt ein Eintrag, der
 * wortgleich schon gespeichert ist, als Bestand (sonst sperrte eine alte Notiz jede Buchung einer Rechnung).
 */
final class TextLimits
{
    /** Namen und Bezeichnungen: Zähler, Gruppe, Tarif, Lieferant, Mietverhältnis, Posten, Dokument. */
    public const NAME = 120;
    /** Anbieter am Vertrag: der volle Firmenname mit Rechtsform, so wie ihn ein Brief als Empfänger braucht. */
    public const COMPANY = 160;
    /**
     * Titel eines Termins. Auch die App schreibt Titel aus dem Posteingang („Sonderkündigung {Anbieter} möglich,
     * Zugang bis {Tag} (laut Schreiben {Tag}, gesetzlich {Tag})“) mit einem Namen bis NAME: 300 hält das in jeder
     * Sprache (TextLimitsTest).
     */
    public const TITLE = 300;
    /** Notizen. */
    public const NOTE = 2000;
    /** Notizen, die schon vor v3.13 auf 500 begrenzt waren (Verlauf, Posteingang). */
    public const SHORT_NOTE = 500;
    /** Vertrags-, Kunden- und Gerätenummern (seit v3.7.0 bzw. v2.x so begrenzt). */
    public const NUMBER = 64;
    /** Namen von Personen im Verzeichnis und als Zuständige (seit v3.5.0/v3.6.0). */
    public const PERSON = 60;
    /** Kurze Bezeichnung an einer Stütze: Zäsur und Peilstand (seit v2.4.0 bzw. v2.10.0 80 Zeichen). */
    public const MARK = 80;
    /**
     * Notiz einer Ablesung, die eine KI vorschlägt (`propose_reading`): enger als die Notiz der Ablesung,
     * weil die Zusammenfassung, die die Person bestätigt, die Notiz nicht zeigt (Entscheidung v3.12.0 S1).
     */
    public const MCP_NOTE = 200;
    /** Korrigierter Textwert eines Felds im Posteingang (Versorger, Tarif, Nummer), seit v3.7.0 200 Zeichen. */
    public const DOC_VALUE = 200;

    /**
     * Topf (Schlüssel wie im Backup) → Feld → Höchstlänge in Zeichen. „liste.feld“: das Feld in jedem
     * Eintrag einer Liste am Datensatz (bzw. in einem Objekt, etwa `notice` an der Rechnung).
     */
    public const FIELDS = [
        // v3.13.0 — Gerätenummer wie die übrigen Nummern (bisher ohne Grenze; der Posteingang begrenzte sie schon auf 64)
        'meters'             => ['name' => self::NAME, 'notes' => self::NOTE, 'baseline_events.label' => self::MARK,
                                 'tank_levels.note' => self::MARK, 'devices.serial' => self::NUMBER],
        'meter_groups'       => ['name' => self::NAME],
        'readings'           => ['note' => self::NOTE],
        'contracts'          => ['provider' => self::COMPANY, 'tariff_name' => self::NAME, 'shadow_label' => self::NAME,
                                 'notes' => self::NOTE, 'contract_number' => self::NUMBER, 'customer_number' => self::NUMBER,
                                 'bonuses.label' => self::NAME, 'special_payments.note' => self::NAME],
        'deliveries'         => ['supplier' => self::NAME, 'note' => self::NOTE],
        'periods'            => ['note' => self::NOTE],
        'bills'              => ['note' => self::NOTE, 'invoice_number' => 80, 'items.label' => self::NAME,
                                 'notice.file_number' => 80],
        'reminders'          => ['title' => self::TITLE, 'notes' => self::NOTE, 'assignee' => self::PERSON],
        'tenancies'          => ['label' => self::NAME, 'landlord' => self::NAME, 'notes' => self::NOTE,
                                 'fixed_costs.label' => self::NAME],
        'tenancy_statements' => ['note' => self::NOTE, 'positions.label' => self::NAME, 'positions.unit' => 12],
        'people'             => ['name' => self::PERSON],
        'inbox'              => ['name' => self::NAME, 'note' => self::SHORT_NOTE],
        'activity'           => ['note' => self::SHORT_NOTE],
        'settings'           => ['location_name' => self::NAME],
        // v3.13.0 (Paket B) — Auszahlungen der Einspeisung: die Notiz wie bisher bis 500 Zeichen, jetzt ohne stilles Kürzen
        'feed_in_payouts'    => ['note' => self::SHORT_NOTE],
    ];

    /** Grenze eines Felds (`liste.feld` für Einträge einer Liste). */
    public static function of(string $pot, string $field): int
    {
        return self::FIELDS[$pot][$field] ?? throw new \LogicException("text limit: $pot.$field");   // Programmierfehler
    }

    /** Länge eines Texts, wie er gespeichert wird (ohne Leerraum am Rand); kein Text: 0. */
    public static function length(mixed $v): int
    {
        if (is_int($v) || is_float($v)) $v = (string)$v;
        return is_string($v) ? mb_strlen(trim($v)) : 0;
    }

    /** 400 `errors.common.tooLong` mit Feld, Grenze und Länge. */
    public static function tooLong(string $field, int $max, int $length): LocalizedException
    {
        return new LocalizedException('errors.common.tooLong', ['field' => $field, 'max' => (string)$max, 'length' => (string)$length],
            "field $field: $length characters, at most $max");
    }

    /**
     * Prüft die Textfelder eines Topfs in einer Eingabe (nur die mitgeschickten). `$old`: der gespeicherte
     * Datensatz beim Ändern; ein Eintrag einer Liste, der wortgleich dort steht, gilt als Bestand.
     *
     * @param array<array-key,mixed> $in
     * @param array<array-key,mixed> $old
     * @throws LocalizedException errors.common.tooLong
     */
    public static function assert(array $in, string $pot, array $old = []): void
    {
        foreach (self::FIELDS[$pot] ?? [] as $field => $max) {
            if (!str_contains($field, '.')) {
                if (array_key_exists($field, $in) && ($n = self::length($in[$field])) > $max) throw self::tooLong($field, $max, $n);
                continue;
            }
            [$list, $sub] = explode('.', $field, 2);
            if (!is_array($in[$list] ?? null)) continue;
            $known = [];
            foreach (self::entries($old[$list] ?? null) as $e) {
                if (is_string($e[$sub] ?? null)) $known[trim($e[$sub])] = true;
            }
            foreach (self::entries($in[$list]) as $e) {
                if (!is_array($e) || !array_key_exists($sub, $e) || ($n = self::length($e[$sub])) <= $max) continue;
                if (is_string($e[$sub]) && isset($known[trim($e[$sub])])) continue;   // Bestand, unverändert
                throw self::tooLong($field, $max, $n);
            }
        }
    }

    /**
     * Felder eines Datensatzes über der Grenze (Backup-Import): je Fund Feld, Grenze und Länge.
     *
     * @param array<array-key,mixed> $row
     * @return list<array{field:string, max:int, length:int}>
     */
    public static function problems(array $row, string $pot): array
    {
        $out = [];
        foreach (self::FIELDS[$pot] ?? [] as $field => $max) {
            if (!str_contains($field, '.')) {
                if (($n = self::length($row[$field] ?? null)) > $max) $out[] = ['field' => $field, 'max' => $max, 'length' => $n];
                continue;
            }
            [$list, $sub] = explode('.', $field, 2);
            foreach (self::entries($row[$list] ?? null) as $e) {
                if (($n = self::length($e[$sub] ?? null)) > $max) {
                    $out[] = ['field' => $field, 'max' => $max, 'length' => $n];
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Einträge unter `liste` bzw. ein Objekt (`notice.file_number` an der Rechnung) als ein Eintrag.
     *
     * @return list<array<array-key,mixed>>
     */
    private static function entries(mixed $v): array
    {
        if (!is_array($v)) return [];
        if (!array_is_list($v)) return [$v];
        return array_values(array_filter($v, 'is_array'));
    }
}
