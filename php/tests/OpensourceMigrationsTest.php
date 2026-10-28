<?php
declare(strict_types=1);

namespace Tds\Ext\Opensource\Tests;

use PHPUnit\Framework\TestCase;
use Tds\Ext\Opensource\OpensourceModule;

/**
 * The three rules that, when broken, abort the migration run for EVERY composed
 * extension rather than just this one.
 *
 * Every enabled extension shares one `phinxlog` and is included into one PHP
 * process by the core's in-process auto-migrator. So a reused class name is an
 * uncatchable fatal redeclaration, a file name that does not map to its class
 * throws while the SET is being scanned, and a reused version prefix collides in
 * the shared ledger. In all three cases nothing migrates anywhere and every
 * route 500s on a fresh database — which is a long way from the file that caused
 * it.
 */
final class OpensourceMigrationsTest extends TestCase
{
    /**
     * Version bands other extensions already own.
     *
     * A literal list because this repo cannot see its siblings — it is published
     * standalone. This module owns `20261028`.
     */
    private const CLAIMED_BANDS = [
        '20260713', '20260719', '20260720', '20260722', '20260725', '20260726',
        '20260727', '20260728', '20260801', '20260826', '20260901',
        '20260907', '20260908', '20260909', '20260910', '20260911', '20260912',
        '20260913', '20260914', '20260915', '20260929', '20261011', '20261024',
    ];

    private const OWN_BAND = '20261028';

    /** @return list<string> */
    private static function files(): array
    {
        $files = glob((new OpensourceModule())->migrations()[0] . '/*.php');
        return $files === false ? [] : array_values($files);
    }

    public function testThereAreMigrations(): void
    {
        self::assertNotSame([], self::files(), 'Keine Migrationen gefunden');
    }

    public function testEveryFileNameMapsToItsClassName(): void
    {
        $problems = [];
        foreach (self::files() as $file) {
            $base = basename($file, '.php');
            [, $snake] = explode('_', $base, 2);
            $expected = str_replace(' ', '', ucwords(str_replace('_', ' ', $snake)));

            $source = (string) file_get_contents($file);
            if (preg_match('/final class (\w+) extends AbstractMigration/', $source, $m) !== 1) {
                $problems[] = "{$base}: keine Migrationsklasse gefunden";
                continue;
            }
            if ($m[1] !== $expected) {
                $problems[] = "{$base}: Klasse {$m[1]}, erwartet {$expected}";
            }
        }

        self::assertSame([], $problems, 'Dateiname und Klassenname passen nicht zusammen');
    }

    public function testEveryClassIsModulePrefixed(): void
    {
        // The class names live in ONE global namespace across every extension.
        // `CreateOpensourceProject` is unique; `CreateProject` would not be.
        $problems = [];
        foreach (self::files() as $file) {
            $source = (string) file_get_contents($file);
            preg_match('/final class (\w+) extends AbstractMigration/', $source, $m);
            $class = $m[1] ?? '';
            if (preg_match('/^(Opensource|CreateOpensource|AddOpensource)/', $class) !== 1) {
                $problems[] = basename($file) . ": {$class}";
            }
        }

        self::assertSame([], $problems, 'Migrationsklassen ohne Modulpräfix');
    }

    public function testEveryVersionIsInThisModulesOwnBand(): void
    {
        $versions = [];
        foreach (self::files() as $file) {
            preg_match('/^(\d+)_/', basename($file), $m);
            $version = $m[1] ?? '';
            $band = substr($version, 0, 8);
            self::assertNotContains($band, self::CLAIMED_BANDS, basename($file));
            self::assertSame(self::OWN_BAND, $band, basename($file));
            $versions[] = $version;
        }
        self::assertSame(count($versions), count(array_unique($versions)), 'Doppelte Versionsnummer');
    }

    public function testForeignKeyColumnsAreUnsignedToSurviveMysql8(): void
    {
        // Production is MySQL 8; local and CI are often MariaDB, which silently
        // corrects the signedness mismatch MySQL 8 rejects outright. Only
        // INTEGER `*_id` columns — a string id has no signedness.
        $problems = [];
        foreach (self::files() as $file) {
            foreach (file($file) ?: [] as $n => $line) {
                if (preg_match("/->addColumn\('(\w+_id)', 'integer'/", $line, $m) !== 1) {
                    continue;
                }
                if (!str_contains($line, "'signed' => false")) {
                    $problems[] = basename($file) . ':' . ($n + 1) . " {$m[1]}";
                }
            }
        }

        self::assertSame([], $problems, 'FK-Spalten ohne signed => false');
    }

    public function testNoMigrationCallsAnAdapterInternal(): void
    {
        // `quoteValue()` is protected on `PdoAdapter` and absent from the
        // `TimedOutputAdapter` a migration actually receives. One call to it in
        // the website-CMS died on every run and blocked every migration pending
        // behind it, across all modules — the shop's tables never reached
        // production and its panel 500'd.
        $problems = [];
        foreach (self::files() as $file) {
            $source = (string) file_get_contents($file);
            // Not `fetchAll(` — that one IS a public method on AbstractMigration.
            foreach (['quoteValue(', 'getAdapter()->quote'] as $needle) {
                if (str_contains($source, $needle)) {
                    $problems[] = basename($file) . ": {$needle}";
                }
            }
        }

        self::assertSame([], $problems, 'Migration benutzt Adapter-Internas');
    }

    public function testAnyOwnPrimaryKeyColumnIsExplicitlyNotNull(): void
    {
        // Phinx defaults every `addColumn()` to nullable. MariaDB coerces;
        // MySQL 8 throws `1171 All parts of a PRIMARY KEY must be NOT NULL`,
        // which took `/install.php` down on a fresh host once already. Only
        // bites when a migration sets its own primary key instead of `id => true`.
        $problems = [];
        foreach (self::files() as $file) {
            $source = (string) file_get_contents($file);
            if (!str_contains($source, "'primary_key'")) {
                continue;
            }
            preg_match_all("/'primary_key' => \['([a-z_]+)'\]/", $source, $keys);
            foreach ($keys[1] as $column) {
                if (preg_match("/->addColumn\('{$column}'[^\n]*'null' => false/", $source) !== 1) {
                    $problems[] = basename($file) . ": {$column}";
                }
            }
        }

        self::assertSame([], $problems, 'Eigener Primärschlüssel ohne null => false');
    }
}
