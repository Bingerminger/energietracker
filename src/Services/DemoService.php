<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;
use Energietracker\Config\Utilities;
use Energietracker\Http\NotFoundException;

/**
 * F1007 (v1.7.4) — Demo-Daten-Komfort-Import.
 *
 * Spielt das mitgelieferte Demo-JSON-Backup
 * (demo-data/energietracker-demo-backup.json) über den bestehenden
 * BackupService-Restore-Pfad ein — inklusive Schema-Guard und automatischem
 * Pre-Restore-Snapshot aus N1004. Damit ist der Import auch bei bereits
 * vorhandenen Daten verlustfrei rückholbar.
 *
 * Im Docker-Image sind diese Demo-Datei, die Übersetzungen und seit v3.2.0
 * die Beispielhaushalte (demo-data/personas/) enthalten (per
 * .dockerignore-Ausnahme), nicht das ganze demo-data/-Verzeichnis.
 */
final class DemoService
{
    /**
     * v3.5.0 (N1022) — Einstellungen der Person bzw. des Landes, die ein
     * Beispielhaushalt nicht ersetzt: Sprache, Land, Währung, Zeitzone und die
     * Einheit, in der der Brennwert eingegeben wird. Ein Wert, der vorher nicht
     * festgeschrieben war, bleibt es auch danach nicht (Default).
     */
    public const KEEP_SETTINGS = ['language', 'country', 'currency', 'timezone', 'gas_cv_unit'];

    /** v3.6.0 — rechnet gewanderte Versorgerrechnungen nach (optional; ohne ihn bleiben die Beträge). */
    private ?BillService $bills = null;

    public function __construct(
        private JsonStore $store,
        private BackupService $backups,
        private I18nService $i18n
    ) {}

    public function attachBills(BillService $bills): void
    {
        $this->bills = $bills;
    }

    /**
     * Pfad zum mitgelieferten Demo-Backup (relativ zur App-Wurzel).
     * v3.2.0 (F1018) — je Persona ein eigener Beispielhaushalt
     * (demo-data/personas/<id>.json); ohne Persona oder „showcase" der
     * bisherige Haushalt mit allen Verbrauchsarten.
     */
    public function demoBackupPath(?string $persona = null): string
    {
        $root = dirname(__DIR__, 2) . '/demo-data';
        if ($persona === null || $persona === 'showcase') return $root . '/energietracker-demo-backup.json';
        if (!in_array($persona, SettingsService::PERSONAS, true)) {
            throw new \InvalidArgumentException($this->i18n->t('errors.demo.personaUnknown', ['persona' => $persona]));
        }
        return $root . '/personas/' . $persona . '.json';
    }

    public function isAvailable(?string $persona = null): bool
    {
        return is_file($this->demoBackupPath($persona));
    }

    /**
     * „Leer" = noch nichts erfasst: keine Ablesung, Lieferung, kein Zeitraum
     * und kein Vertrag. v3.2.0 — bis v3.1 zählten schon die Standardzähler
     * einer Neuinstallation als Daten; der Einrichtungsassistent hätte dann
     * vor dem Überschreiben gewarnt, obwohl es nichts zu verlieren gab.
     */
    public function isEmpty(): bool
    {
        foreach (Utilities::keys() as $key) {
            foreach (['readings', 'deliveries', 'periods', 'contracts'] as $pot) {
                $rows = $this->store->read("$key/$pot.json", []);
                if (is_array($rows) && $rows !== []) return false;
            }
        }
        return true;
    }

    /** @return array{available:bool,is_empty:bool,personas:list<string>} */
    public function status(): array
    {
        return [
            'available' => $this->isAvailable(),
            'is_empty'  => $this->isEmpty(),
            // v3.2.0 (F1018) — vorhandene Beispielhaushalte je Persona
            'personas'  => array_values(array_filter(SettingsService::PERSONAS,
                fn($p) => $p === 'showcase' || is_file($this->demoBackupPath($p)))),
        ];
    }

    /**
     * v3.2.0 (F1018) — Ein Beispielhaushalt ersetzt den ganzen Haushalt.
     * Töpfe, die ein Demo-Backup nicht kennt (die Haupt-Demo stammt aus der
     * Zeit vor Heizwärme, Zeiträumen, Rechnungen und Mietverhältnis), werden
     * leer eingespielt — sonst blieben nach einem Wechsel des Haushalts Reste
     * des vorigen stehen.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function completePayload(array $payload): array
    {
        // alle Töpfe aus der Liste des Backups (ein neuer Topf ist so von selbst dabei)
        foreach (array_keys(BackupService::TOP_POTS) as $k) {
            if (!array_key_exists($k, $payload) && !in_array($k, ['settings', 'temperatures'], true)) $payload[$k] = [];
        }
        foreach (Utilities::keys() as $u) {
            foreach (BackupService::UTILITY_POTS as $pot) {
                if (!isset($payload['utilities'][$u][$pot])) $payload['utilities'][$u][$pot] = [];
            }
        }
        return $payload;
    }

    /**
     * Importiert die Demo-Daten. Sind bereits Daten vorhanden, ist der Import
     * nur mit $force = true erlaubt — sonst wirft die Methode, und das
     * Frontend zeigt zuvor eine Warnung und ruft mit force erneut auf.
     *
     * v3.0.0 — Die Daten werden vorher bis heute fortgeschrieben
     * (`DemoDataAligner`); `$today` nur für Tests.
     */
    public function import(bool $force = false, ?string $today = null, ?string $persona = null): array
    {
        if (!$this->isAvailable($persona)) {
            throw new NotFoundException($this->i18n->t('errors.demo.backupNotFound'));
        }
        if (!$force && !$this->isEmpty()) {
            throw new \InvalidArgumentException(
                $this->i18n->t('errors.demo.dataExists')
            );
        }
        $raw = @file_get_contents($this->demoBackupPath($persona));
        $payload = json_decode((string)$raw, true);
        if (!is_array($payload)) {
            throw new \RuntimeException($this->i18n->t('errors.demo.backupInvalid'));
        }
        $payload = self::completePayload(DemoDataAligner::align($payload, $today ?? date('Y-m-d')));
        // v3.1.0 (I18N-24) — Namen, Notizen und Termine in der Sprache der Anfrage
        $payload = DemoDataTranslator::translate($payload, $this->i18n->locale(),
            DemoDataTranslator::load(dirname($this->demoBackupPath()) . '/translations.json'));
        // v3.6.0 — gewanderte Rechnungen (DemoDataAligner): Markierung nicht einspielen, Beträge danach
        $recalc = [];
        foreach (($payload['utilities'] ?? []) as $u => $data) {
            foreach ((array)($data['bills'] ?? []) as $i => $b) {
                if (!is_array($b) || empty($b['_demo_recalc'])) continue;
                $recalc[$u][] = (string)($b['id'] ?? '');
                unset($payload['utilities'][$u]['bills'][$i]['_demo_recalc']);
            }
        }
        // v3.2.0 (F1019) — die eigene Nutzungsstufe bleibt; das Backup bringt seine mit
        $before = $this->store->read('settings.json', []);
        $report = $this->backups->import($payload);
        if ($recalc !== []) $this->recalculateBills($recalc);
        $after = $this->store->read('settings.json', []);
        if (is_array($after)) {
            if (is_array($before) && isset($before['ui_level'])) $after['ui_level'] = $before['ui_level'];
            // v3.5.0 (N1022) — Sprache und Land aus Schritt 1 der Einrichtung
            // bleiben ebenso (vorher fiel die Sprache auf Deutsch zurück, weil der
            // Beispielhaushalt keine mitbringt). Standort, Heizgrenze und CO₂
            // gehören zum Beispielhaushalt (Leipzig) und kommen aus ihm.
            foreach (self::KEEP_SETTINGS as $k) {
                if (is_array($before) && array_key_exists($k, $before)) $after[$k] = $before[$k];
                else unset($after[$k]);
            }
            $after['setup_pending'] = false;
            $after['setup_persona'] = $persona ?? 'showcase';
            $this->store->write('settings.json', $after);
        }
        $report['demo_import'] = true;
        $report['persona'] = $persona ?? 'showcase';
        return $report;
    }

    /**
     * v3.6.0 — Beträge gewanderter Versorgerrechnungen aus der eigenen
     * Rechnung (BillService::compare, recalculatedBill), die verknüpfte
     * Sonderzahlung zum Ergebnis (settledPayment). So bleibt „Rechnung
     * prüfen“ bei „passt“.
     *
     * @param array<string,list<string>> $ids Verbrauchsart → Rechnungs-IDs
     */
    private function recalculateBills(array $ids): void
    {
        if ($this->bills === null) return;
        foreach ($ids as $u => $list) {
            $bills = $this->store->read("$u/bills.json", []);
            $contracts = $this->store->read("$u/contracts.json", []);
            if (!is_array($bills) || !is_array($contracts)) continue;
            foreach ($bills as $bi => $b) {
                if (!is_array($b) || !in_array((string)($b['id'] ?? ''), $list, true)) continue;
                $b = $bills[$bi] = self::recalculatedBill($b, $this->bills->compare($u, (string)$b['id'])['ours'], $u);
                $sp = (string)($b['special_payment_id'] ?? '');
                if ($sp === '') continue;
                foreach ($contracts as $ci => $c) {
                    foreach ((array)($c['special_payments'] ?? []) as $si => $s) {
                        if (($s['id'] ?? null) !== $sp) continue;
                        $contracts[$ci]['special_payments'][$si] = self::settledPayment($s, (float)$b['invoice']['result_eur'], $b['issued_on'] ?? null);
                    }
                }
            }
            $this->store->write("$u/bills.json", $bills);
            $this->store->write("$u/contracts.json", $contracts);
        }
    }

    /**
     * Rechnung mit den eigenen Werten (`ours` aus BillService::compare):
     * Menge, Betrag, gezahlte Abschläge, Ergebnis = Betrag − Abschläge; CO₂
     * im Verhältnis der Menge.
     *
     * @param array<string,mixed> $b
     * @param array<string,mixed> $ours
     * @return array<string,mixed>
     */
    public static function recalculatedBill(array $b, array $ours, string $utility): array
    {
        $qtyKey = $utility === 'wasser' ? 'volume_m3' : 'energy_kwh';
        $inv = (array)($b['invoice'] ?? []);
        $oldQty = (float)($inv[$qtyKey] ?? 0);
        $qty = $ours[$utility === 'wasser' ? 'm3' : 'kwh'] ?? null;
        if ($qty !== null) $inv[$qtyKey] = round((float)$qty, 1);
        $inv['amount_eur'] = round((float)$ours['total'], 2);
        $inv['advances_paid_eur'] = round((float)($ours['advances'] ?? 0), 2);
        $inv['result_eur'] = round($inv['amount_eur'] - $inv['advances_paid_eur'], 2);
        $b['invoice'] = $inv;
        if (isset($b['co2']['emissions_kg']) && $oldQty > 0 && $qty !== null) {
            $b['co2']['emissions_kg'] = round((float)$b['co2']['emissions_kg'] * (float)$qty / $oldQty, 1);
        }
        return $b;
    }

    /**
     * Sonderzahlung zum Ergebnis einer Rechnung: Betrag und Richtung (Nach-
     * bzw. Rückzahlung; „mit“ oder „ohne“ Abschlagsänderung bleibt), Datum =
     * Rechnungsdatum.
     *
     * @param array<string,mixed> $sp
     * @return array<string,mixed>
     */
    public static function settledPayment(array $sp, float $result, ?string $issuedOn): array
    {
        $suffix = str_ends_with((string)($sp['kind'] ?? ''), '_mit') ? '_mit' : '_ohne';
        $sp['kind'] = ($result > 0 ? 'nachzahlung' : 'rueckzahlung') . $suffix;
        $sp['amount_eur'] = abs($result);
        if ($issuedOn !== null && $issuedOn !== '') $sp['date'] = $issuedOn;
        return $sp;
    }
}
