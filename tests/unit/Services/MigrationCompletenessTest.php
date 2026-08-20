<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Storage\Migrator;
use Energietracker\Tests\Support\ServiceTestCase;

/**
 * v2.4.1 — Wächter gegen eine halb eingehängte Migrationsstufe.
 *
 * **Der Fehler, den diese Suite festnagelt (v2.4.0, auf Prod aufgefallen):**
 * `upgradeToV140()` wurde in `migrate()` eingehängt, aber `needsMigration()`
 * endete weiter bei v1.3.0. Auf einer Bestandsinstallation meldete
 * `needsMigration()` damit **false**, `migrate()` lief nie — und der Bootstrap
 * fiel in seinen dritten Zweig `!isAlreadyMigrated() → initFresh()`. Der
 * schreibt `meta.json` mit der neuen Schemaversion, ohne die Zähler
 * anzufassen. Danach galt die Installation als migriert, obwohl kein einziger
 * Zähler das neue Feld trug — und die Migration konnte nie mehr nachholen.
 *
 * Nutzdaten gingen dabei nicht verloren; `initFresh()` legt Dateien nur an,
 * wenn sie fehlen. Der Schaden war die **stillgelegte** Migration.
 *
 * Kein Test hatte das gefangen, weil alle Migrationstests
 * `needsVXXXUpgrade()` und `upgradeToVXXX()` direkt aufriefen — also genau an
 * `needsMigration()` vorbei.
 */
final class MigrationCompletenessTest extends ServiceTestCase
{
    /**
     * Jede `needsVXXXUpgrade()`-Methode muss in der Stufenliste stehen.
     * Wer eine neue Stufe schreibt und sie nicht einträgt, wird hier rot.
     */
    public function testEveryUpgradeCheckIsWiredIntoTheStepList(): void
    {
        $listed = array_column(Migrator::upgradeSteps(), 0);

        $found = [];
        foreach ((new \ReflectionClass(Migrator::class))->getMethods() as $m) {
            if (preg_match('/^needs(V\d+|WaterContracts)Upgrade$/', $m->getName())) {
                $found[] = $m->getName();
            }
        }
        sort($found);
        $sortedListed = $listed;
        sort($sortedListed);

        self::assertSame(
            $found,
            $sortedListed,
            'Jede needsVXXXUpgrade()-Methode gehört in Migrator::UPGRADE_STEPS — '
            . 'sonst kennt needsMigration() die Stufe nicht.'
        );
    }

    /** Zu jeder Prüfmethode muss es die passende Ausführmethode geben. */
    public function testEveryStepHasBothHalves(): void
    {
        foreach (Migrator::upgradeSteps() as [$check, $apply]) {
            self::assertTrue(method_exists(Migrator::class, $check), "fehlt: $check");
            self::assertTrue(method_exists(Migrator::class, $apply), "fehlt: $apply");
        }
    }

    /**
     * Der eigentliche Fall: Eine Installation auf dem Vorgängerschema muss
     * `needsMigration() === true` melden — sonst greift der initFresh-Zweig.
     */
    public function testInstallationOnThePreviousSchemaWantsToMigrate(): void
    {
        $this->fakePreviousSchema();

        $migrator = new Migrator($this->store);
        self::assertFalse($migrator->isAlreadyMigrated(), 'Kontrolle: gilt nicht als migriert');
        self::assertTrue(
            $migrator->needsMigration(),
            'Bestandsdaten auf dem Vorgängerschema müssen migriert werden wollen'
        );
    }

    /**
     * Und der Weg danach: migrieren, Feld da, meta korrekt fortgeschrieben —
     * mit `migrated_at` und Protokoll, nicht mit `created_at` (das schriebe
     * `initFresh()`).
     */
    public function testMigrationFromThePreviousSchemaCompletesProperly(): void
    {
        $this->fakePreviousSchema();

        $migrator = new Migrator($this->store);
        $migrator->migrate();

        $meta = $this->store->read('meta.json', []);
        self::assertSame(Migrator::SCHEMA_VERSION, $meta['schema_version'] ?? null);
        self::assertArrayHasKey('migrated_at', $meta, 'migrate() setzt migrated_at');
        self::assertArrayNotHasKey(
            'created_at',
            $meta,
            'created_at stammt von initFresh() — hier wäre es das Zeichen, '
            . 'dass der falsche Zweig gelaufen ist'
        );
        self::assertNotEmpty($meta['log'] ?? [], 'Migration protokolliert ihre Stufen');

        foreach (['gas', 'strom', 'wasser'] as $u) {
            foreach ($this->store->read("$u/meters.json", []) as $m) {
                self::assertArrayHasKey(
                    'baseline_events',
                    $m,
                    "Zähler in $u trägt das v1.4.0-Feld nach der Migration"
                );
            }
        }
        self::assertTrue((new Migrator($this->store))->isAlreadyMigrated());
    }

    /** Zweimal migrieren ändert nichts mehr. */
    public function testSecondRunIsANoOp(): void
    {
        $this->fakePreviousSchema();
        $migrator = new Migrator($this->store);
        $migrator->migrate();

        $before = [];
        foreach (['gas', 'strom', 'wasser'] as $u) {
            $before[$u] = $this->store->read("$u/meters.json", []);
        }
        self::assertFalse($migrator->needsMigration(), 'nach der Migration ist nichts mehr offen');

        $migrator->migrate();
        foreach (['gas', 'strom', 'wasser'] as $u) {
            self::assertSame($before[$u], $this->store->read("$u/meters.json", []));
        }
    }

    /**
     * Versetzt das frisch initialisierte Verzeichnis auf den Stand vor dem
     * aktuellen Schema: Feld der neuesten Stufe entfernen, `meta.json` auf die
     * Vorgängerversion setzen — so wie eine Bestandsinstallation aussieht.
     */
    private function fakePreviousSchema(): void
    {
        foreach (\Energietracker\Config\Utilities::keys() as $u) {
            $meters = $this->store->read("$u/meters.json", []);
            if (!is_array($meters) || !$meters) continue;
            foreach ($meters as &$m) {
                unset($m['baseline_events']);
            }
            unset($m);
            $this->store->write("$u/meters.json", $meters);
        }
        $this->store->write('meta.json', [
            'schema_version' => '1.3.0',
            'migrated_at'    => '2026-06-10T13:02:53+02:00',
            'log'            => ['v1.3.0: 2 Zähler um external_id (HA-Alias) ergänzt'],
        ]);
    }
}
