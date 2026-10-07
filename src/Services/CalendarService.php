<?php
declare(strict_types=1);

namespace Energietracker\Services;

/**
 * v3.1.0 (Paket H, MKT-09) — Fristen und Termine als abonnierbarer Kalender
 * (RFC 5545), ohne Drittdienst: `GET /api/calendar.ics`.
 *
 * Die Ereignisse kommen aus der Agenda (dieselbe Quelle wie „Zu tun" und
 * Home Assistant). Ganztägig, UID je Objekt stabil (`<art>-<id>@<instanz>`):
 * Wird ein Termin erledigt, steht beim nächsten Abruf dasselbe Ereignis am
 * neuen Datum. Texte in der Standardsprache der Installation — Kalender
 * schicken keine Sprache mit.
 */
final class CalendarService
{
    /** Arten mit Vorab-Erinnerung (VALARM) im Kalender. */
    private const ALARM_KINDS = ['reminder', 'cancel_by', 'term_end', 'price_guarantee_end', 'price_increase'];

    public function __construct(
        private AgendaService $agenda,
        private SettingsService $settings,
        private InstanceService $instance,
        private I18nService $i18n,
    ) {}

    public function ics(?string $today = null): string
    {
        $events = $this->agenda->events(365, $today);
        $instance = $this->instance->id();
        $warn = max(0, (int)$this->settings->get('reminder_warn_days_before', 14));
        $stamp = gmdate('Ymd\THis\Z');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Energietracker//Kalender//' . strtoupper($this->i18n->locale()),
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::text($this->i18n->t('calendar.name')),
            'REFRESH-INTERVAL;VALUE=DURATION:PT12H',
            'X-PUBLISHED-TTL:PT12H',
        ];
        foreach ($events as $e) {
            $date = str_replace('-', '', (string)$e['date']);
            $next = date('Ymd', (int)strtotime($e['date'] . ' +1 day'));
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . self::text($e['uid'] . '@' . $instance);
            $lines[] = 'DTSTAMP:' . $stamp;
            $lines[] = 'DTSTART;VALUE=DATE:' . $date;
            $lines[] = 'DTEND;VALUE=DATE:' . $next;
            $lines[] = 'SUMMARY:' . self::text((string)$e['title']);
            $lines[] = 'CATEGORIES:' . strtoupper((string)$e['kind']);
            $lines[] = 'TRANSP:TRANSPARENT';
            if ($warn > 0 && in_array($e['kind'], self::ALARM_KINDS, true)) {
                $lines[] = 'BEGIN:VALARM';
                $lines[] = 'ACTION:DISPLAY';
                $lines[] = 'DESCRIPTION:' . self::text((string)$e['title']);
                $lines[] = "TRIGGER:-P{$warn}D";
                $lines[] = 'END:VALARM';
            }
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    /** TEXT-Wert nach RFC 5545 §3.3.11: Backslash, Semikolon, Komma, Zeilenumbruch. */
    public static function text(string $s): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\\;', '\\,', '\\n', '\\n', ''], $s);
    }

    /**
     * Zeilen über 75 Oktette falten (CRLF + Leerzeichen), ohne ein UTF-8-Zeichen
     * zu teilen.
     */
    public static function fold(string $line): string
    {
        if (strlen($line) <= 75) return $line;
        $out = [];
        $limit = 75;
        while (strlen($line) > $limit) {
            $cut = $limit;
            // nicht mitten in eine Mehrbyte-Folge schneiden (Folgebytes 10xxxxxx)
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) $cut--;
            $out[] = substr($line, 0, $cut);
            $line = ' ' . substr($line, $cut);
            $limit = 75;
        }
        $out[] = $line;
        return implode("\r\n", $out);
    }
}
