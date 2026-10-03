<?php

declare(strict_types=1);

namespace Core;

final class CoreMigrations
{
    /**
     * @return list<callable(Database): void>
     */
    public static function migrations(): array
    {
        return [
            // v1: users + sessions
            function (Database $db): void {
                $db->run(
                    'CREATE TABLE users (
                        id TEXT PRIMARY KEY,
                        username TEXT NOT NULL UNIQUE,
                        password TEXT NOT NULL,
                        role TEXT NOT NULL DEFAULT \'user\'
                            CHECK (role IN (\'admin\', \'editor\', \'user\')),
                        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                    )'
                );
                $db->run(
                    'CREATE TABLE sessions (
                        id TEXT PRIMARY KEY,
                        user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                        token_hash TEXT NOT NULL UNIQUE,
                        expires_at TEXT NOT NULL,
                        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                    )'
                );
                $db->run('CREATE INDEX idx_sessions_user ON sessions(user_id)');
            },
            // v2: generische content_objects + content_revisions
            function (Database $db): void {
                $db->run(
                    'CREATE TABLE content_objects (
                        id TEXT PRIMARY KEY,
                        type TEXT NOT NULL,
                        slug TEXT NOT NULL,
                        owner_id TEXT REFERENCES users(id) ON DELETE SET NULL,
                        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE (type, slug)
                    )'
                );
                $db->run(
                    'CREATE TABLE content_revisions (
                        id TEXT PRIMARY KEY,
                        object_id TEXT NOT NULL REFERENCES content_objects(id) ON DELETE CASCADE,
                        title TEXT NOT NULL DEFAULT \'\',
                        body TEXT NOT NULL DEFAULT \'\',
                        author_id TEXT REFERENCES users(id) ON DELETE SET NULL,
                        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                    )'
                );
                $db->run('CREATE INDEX idx_revisions_object ON content_revisions(object_id, created_at)');
            },
            // v3: CSRF-Token pro Session
            function (Database $db): void {
                $db->run('ALTER TABLE sessions ADD COLUMN csrf_token TEXT');
            },
            // v4: drop global (type, slug) uniqueness; writing module detail tables
            // enforce per-project uniqueness (UNIQUE (project_id, slug)) - PLANNING.md section 2
            function (Database $db): void {
                $db->run(
                    'CREATE TABLE content_objects_new (
                        id TEXT PRIMARY KEY,
                        type TEXT NOT NULL,
                        slug TEXT NOT NULL,
                        owner_id TEXT REFERENCES users(id) ON DELETE SET NULL,
                        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                    )'
                );
                $db->run(
                    'INSERT INTO content_objects_new (id, type, slug, owner_id, created_at)
                     SELECT id, type, slug, owner_id, created_at FROM content_objects'
                );
                $db->run('DROP TABLE content_objects');
                $db->pdo()->exec('ALTER TABLE content_objects_new RENAME TO content_objects');
                $db->run('CREATE INDEX idx_objects_type_slug ON content_objects(type, slug)');
            },
            // v5: chapter summaries (idea first, prose later)
            function (Database $db): void {
                $db->run("ALTER TABLE content_revisions ADD COLUMN summary TEXT NOT NULL DEFAULT ''");
            },
        ];
    }
}
