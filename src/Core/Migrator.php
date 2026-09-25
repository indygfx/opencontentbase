<?php

declare(strict_types=1);

namespace Core;

final class Migrator
{
    public function __construct(private Database $db)
    {
        $this->db->run(
            'CREATE TABLE IF NOT EXISTS schema_versions (
                module TEXT NOT NULL,
                version INTEGER NOT NULL,
                applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (module, version)
            )'
        );
    }

    /**
     * @param list<callable(Database): void> $migrations
     */
    public function migrate(string $module, array $migrations): void
    {
        $current = $this->currentVersion($module);
        $latest = count($migrations);
        for ($v = $current + 1; $v <= $latest; $v++) {
            $this->db->transaction(function (Database $db) use ($module, $v, $migrations): void {
                $migrations[$v - 1]($db);
                $db->run(
                    'INSERT INTO schema_versions (module, version) VALUES (?, ?)',
                    [$module, $v]
                );
            });
        }
    }

    public function currentVersion(string $module): int
    {
        $row = $this->db->one(
            'SELECT MAX(version) AS v FROM schema_versions WHERE module = ?',
            [$module]
        );
        return (int)($row['v'] ?? 0);
    }
}
