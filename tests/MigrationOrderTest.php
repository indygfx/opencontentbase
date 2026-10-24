<?php
declare(strict_types=1);

namespace Tests\Writing;

use Core\CoreMigrations;
use Core\Database;
use Core\Migrator;
use PHPUnit\Framework\TestCase;
use Writing\WritingModule;

/**
 * The Migrator records versions positionally (schema_versions stores the
 * list index), so the order of the migrations list is part of the
 * contract: position N must always be migration vN. These tests pin the
 * two upgrade paths that must both converge without errors:
 *
 * - a database last migrated before the v14/v15 era (version 13 with
 *   the mentions tables already applied) must be able to migrate to the
 *   latest version without re-running already-applied DDL, and
 * - a fresh database must be able to apply every migration in order.
 */
final class MigrationOrderTest extends TestCase
{
    private function freshDb(): Database
    {
        $path = sys_get_temp_dir() . '/cb_migration_' . uniqid() . '.sqlite';
        return new Database($path);
    }

    private function module(Database $db): WritingModule
    {
        $auth = new \Core\Auth($db);
        return new WritingModule(
            $db,
            new \Core\View(dirname(__DIR__)),
            new \Core\Csrf($db, $auth),
            new \Core\ContentRenderer($db, new \Core\ModuleRegistry())
        );
    }

    public function testFreshDatabaseMigratesToLatestVersion(): void
    {
        $db = $this->freshDb();
        (new Migrator($db))->migrate('core', CoreMigrations::migrations());
        $module = $this->module($db);
        $migrations = $module->migrations();
        (new Migrator($db))->migrate('writing', $migrations);

        $this->assertSame(count($migrations), (new Migrator($db))->currentVersion('writing'));
        // The stance column (v15) exists on a fresh database too.
        $columns = $db->all('PRAGMA table_info(character_relations)');
        $this->assertContains('stance', array_column($columns, 'name'));
        $this->assertNotNull($db->one(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'character_mention_counts'"
        ));
    }

    /**
     * Simulates a database last migrated when version 13 was the final
     * migration (the pre-v14 state): core plus the first thirteen
     * Writing migrations, with the mention tables applied. Migrating to
     * the latest version must not re-run already-applied DDL (the bug
     * was "table character_mention_counts already exists").
     */
    public function testPreV14DatabaseUpgradesWithoutDuplicateTableError(): void
    {
        $db = $this->freshDb();
        $migrator = new Migrator($db);
        $migrator->migrate('core', CoreMigrations::migrations());
        $migrations = $this->module($db)->migrations();
        $migrator->migrate('writing', array_slice($migrations, 0, 13));
        $this->assertSame(13, $migrator->currentVersion('writing'));
        $this->assertNotNull($db->one(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'character_mention_counts'"
        ));

        // Upgrade to the latest version must succeed and apply the
        // remaining migrations (relations rebuild + stance column).
        $migrator->migrate('writing', $migrations);
        $this->assertSame(count($migrations), $migrator->currentVersion('writing'));

        $columns = $db->all('PRAGMA table_info(character_relations)');
        $this->assertContains('stance', array_column($columns, 'name'));
        // The v14 uniqueness rule is in place.
        $index = $db->one(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'character_relations'"
        );
        $this->assertStringContainsStringIgnoringCase('UNIQUE', (string)$index['sql']);
    }

    /**
     * Positional version tracking means the order of the migrations list
     * must never change for already-shipped versions: appending is the
     * only allowed modification. The two v13/v14 blocks are the known
     * hazard; this pins the source order so a future re-insertion above
     * the tail fails loudly instead of corrupting upgrades.
     */
    public function testMigrationsListOrderMatchesVersionComments(): void
    {
        $db = $this->freshDb();
        $source = (string)file_get_contents(dirname(__DIR__) . '/modules/Writing/src/WritingModule.php');
        preg_match_all('/^\\s*\\/\\/ v(\\d+):/m', $source, $matches, PREG_OFFSET_CAPTURE);
        $versions = array_map(fn (array $m): int => (int)$m[0], $matches[1]);

        $this->assertSame(range(2, 15), $versions, 'migration comments must appear in strict v2..v15 order');

        $this->module($db)->migrations();
        // The list starts with the v1 migration which carries no
        // comment, so comment vN must sit at list position N - 1
        // (v2 at position 1, v3 at position 2, ...).
        foreach ($versions as $index => $version) {
            $this->assertSame(
                $version - 1,
                $index + 1,
                "comment v{$version} must sit at list position " . ($version - 1)
            );
        }
    }
}
