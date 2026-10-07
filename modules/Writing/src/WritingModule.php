<?php
declare(strict_types=1);

namespace Writing;

use Core\Database;
use Core\ModuleInterface;
use Core\Router;

final class WritingModule implements ModuleInterface
{
    private WritingController $controller;
    private ProjectAccess $access;

    private ChapterController $chapters;
    private ChapterSynopsisController $synopses;
    private CharacterController $characters;
    private BackgroundController $backgrounds;
    private RelationshipController $relationships;

    public function __construct(
        Database $db,
        \Core\View $view,
        \Core\Csrf $csrf,
        \Core\ContentRenderer $renderer
    ) {
        $this->access = new ProjectAccess($db);
        $this->controller = new WritingController($db, $view, $csrf, $this->access, $renderer);
        $this->synopses = new ChapterSynopsisController($db, $view, $csrf, $this->access);
        $this->chapters = new ChapterController($db, $view, $csrf, $this->access, $renderer, $this->synopses);
        $this->characters = new CharacterController($db, $view, $csrf, $this->access, $renderer);
        $this->backgrounds = new BackgroundController($db, $view, $csrf, $this->access, $renderer);
        $this->relationships = new RelationshipController($db, $view, $csrf, $this->access);
    }

    public function id(): string
    {
        return 'writing';
    }

    public function routes(Router $router): array
    {
        $c = $this->controller;
        $ch = $this->chapters;
        $sy = $this->synopses;
        $ca = $this->characters;
        $bg = $this->backgrounds;
        $rel = $this->relationships;
        return [
            ['method' => 'GET', 'pattern' => '/writing', 'handler' => fn ($params, $user) => $c->index($user), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing', 'handler' => fn ($params, $user) => $c->store($user), 'roles' => ['user']],
            ['method' => 'GET', 'pattern' => '/writing/{id}', 'handler' => fn ($params, $user) => $c->show($user, $params['id']), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/{id}', 'handler' => fn ($params, $user) => $c->update($user, $params['id']), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/{id}/delete', 'handler' => fn ($params, $user) => $c->destroy($user, $params['id']), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/{id}/share', 'handler' => fn ($params, $user) => $c->share($user, $params['id']), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/{id}/unshare', 'handler' => fn ($params, $user) => $c->unshare($user, $params['id']), 'roles' => ['user']],
            ['method' => 'GET', 'pattern' => '/writing/{id}/chapters/new', 'handler' => fn ($params, $user) => $ch->create($user, $params['id']), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/{id}/chapters', 'handler' => fn ($params, $user) => $ch->store($user, $params['id']), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/{id}/chapters/preview', 'handler' => fn ($params, $user) => $ch->preview($user), 'roles' => ['user']],
            ['method' => 'GET', 'pattern' => '/writing/{id}/chapters/{slug}', 'handler' => fn ($params, $user) => $ch->show($user, $params['id'], $params['slug']), 'roles' => ['user']],
            ['method' => 'GET', 'pattern' => '/writing/{id}/chapters/{slug}/edit', 'handler' => fn ($params, $user) => $ch->edit($user, $params['id'], $params['slug']), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/{id}/chapters/{slug}', 'handler' => fn ($params, $user) => $ch->update($user, $params['id'], $params['slug']), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/{id}/chapters/{slug}/delete', 'handler' => fn ($params, $user) => $ch->destroy($user, $params['id'], $params['slug']), 'roles' => ['user']],
            ['method' => 'GET', 'pattern' => '/writing/{id}/synopses/new', 'handler' => fn ($params, $user) => $sy->create($user, $params['id']), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/{id}/synopses', 'handler' => fn ($params, $user) => $sy->store($user, $params['id']), 'roles' => ['user']],
            ['method' => 'GET', 'pattern' => '/writing/{id}/synopses/{slug}', 'handler' => fn ($params, $user) => $sy->show($user, $params['id'], $params['slug']), 'roles' => ['user']],
            ['method' => 'GET', 'pattern' => '/writing/{id}/synopses/{slug}/edit', 'handler' => fn ($params, $user) => $sy->edit($user, $params['id'], $params['slug']), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/{id}/synopses/{slug}', 'handler' => fn ($params, $user) => $sy->update($user, $params['id'], $params['slug']), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/{id}/synopses/{slug}/delete', 'handler' => fn ($params, $user) => $sy->destroy($user, $params['id'], $params['slug']), 'roles' => ['user']],
            ['method' => 'GET', 'pattern' => '/writing/{id}/characters/new', 'handler' => fn ($params, $user) => $ca->create($user, $params['id']), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/{id}/characters', 'handler' => fn ($params, $user) => $ca->store($user, $params['id']), 'roles' => ['user']],
            ['method' => 'GET', 'pattern' => '/writing/{id}/characters/{slug}', 'handler' => fn ($params, $user) => $ca->show($user, $params['id'], $params['slug']), 'roles' => ['user']],
            ['method' => 'GET', 'pattern' => '/writing/{id}/characters/{slug}/edit', 'handler' => fn ($params, $user) => $ca->edit($user, $params['id'], $params['slug']), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/{id}/characters/preview', 'handler' => fn ($params, $user) => $ca->preview($user), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/{id}/characters/{slug}', 'handler' => fn ($params, $user) => $ca->update($user, $params['id'], $params['slug']), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/{id}/characters/{slug}/delete', 'handler' => fn ($params, $user) => $ca->destroy($user, $params['id'], $params['slug']), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/{id}/backgrounds/preview', 'handler' => fn ($params, $user) => $bg->preview($user), 'roles' => ['user']],
            ['method' => 'GET', 'pattern' => '/writing/{id}/backgrounds/new', 'handler' => fn ($params, $user) => $bg->create($user, $params['id']), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/{id}/backgrounds', 'handler' => fn ($params, $user) => $bg->store($user, $params['id']), 'roles' => ['user']],
            ['method' => 'GET', 'pattern' => '/writing/{id}/backgrounds/{slug}', 'handler' => fn ($params, $user) => $bg->show($user, $params['id'], $params['slug']), 'roles' => ['user']],
            ['method' => 'GET', 'pattern' => '/writing/{id}/backgrounds/{slug}/edit', 'handler' => fn ($params, $user) => $bg->edit($user, $params['id'], $params['slug']), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/{id}/backgrounds/{slug}', 'handler' => fn ($params, $user) => $bg->update($user, $params['id'], $params['slug']), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/{id}/backgrounds/{slug}/delete', 'handler' => fn ($params, $user) => $bg->destroy($user, $params['id'], $params['slug']), 'roles' => ['user']],
            ['method' => 'GET', 'pattern' => '/writing/{id}/setup', 'handler' => fn ($params, $user) => $c->setup($user, $params['id']), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/outline/preview', 'handler' => fn ($params, $user) => $c->outlinePreview($user), 'roles' => ['user']],
            ['method' => 'GET', 'pattern' => '/writing/{id}/outline/idea', 'handler' => fn ($params, $user) => $c->outlineEdit($user, $params['id'], 'idea'), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/{id}/outline/idea', 'handler' => fn ($params, $user) => $c->outlineUpdate($user, $params['id'], 'idea'), 'roles' => ['user']],
            ['method' => 'GET', 'pattern' => '/writing/{id}/outline/blurb', 'handler' => fn ($params, $user) => $c->outlineEdit($user, $params['id'], 'blurb'), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/{id}/outline/blurb', 'handler' => fn ($params, $user) => $c->outlineUpdate($user, $params['id'], 'blurb'), 'roles' => ['user']],
            ['method' => 'GET', 'pattern' => '/writing/{id}/outline/synopsis_long', 'handler' => fn ($params, $user) => $c->outlineEdit($user, $params['id'], 'synopsis_long'), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/{id}/outline/synopsis_long', 'handler' => fn ($params, $user) => $c->outlineUpdate($user, $params['id'], 'synopsis_long'), 'roles' => ['user']],
            ['method' => 'GET', 'pattern' => '/writing/{id}/relations/new', 'handler' => fn ($params, $user) => $rel->create($user, $params['id']), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/{id}/relations', 'handler' => fn ($params, $user) => $rel->store($user, $params['id']), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/{id}/relations/delete', 'handler' => fn ($params, $user) => $rel->destroy($user, $params['id']), 'roles' => ['user']],
            ['method' => 'GET', 'pattern' => '/writing/{id}/relations/{rid}/edit', 'handler' => fn ($params, $user) => $rel->edit($user, $params['id'], $params['rid']), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/{id}/relations/{rid}', 'handler' => fn ($params, $user) => $rel->update($user, $params['id'], $params['rid']), 'roles' => ['user']],
        ];
    }

    public function migrations(): array
    {
        return [
            function (Database $db): void {
                $db->run(
                    'CREATE TABLE projects (
                        id TEXT PRIMARY KEY,
                        owner_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                        title TEXT NOT NULL,
                        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                    )'
                );
                $db->run(
                    'CREATE TABLE project_members (
                        project_id TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                        user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                        role TEXT NOT NULL DEFAULT \'member\' CHECK (role IN (\'owner\', \'member\')),
                        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (project_id, user_id)
                    )'
                );
                $db->run('CREATE INDEX idx_projects_owner ON projects(owner_id)');
                $db->run('CREATE INDEX idx_members_user ON project_members(user_id)');
            },
            // v2: chapters (slug unique per project, PLANNING.md section 2)
            function (Database $db): void {
                $db->run(
                    'CREATE TABLE chapters (
                        id TEXT PRIMARY KEY,
                        project_id TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                        slug TEXT NOT NULL,
                        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE (project_id, slug)
                    )'
                );
                $db->run('CREATE INDEX idx_chapters_project ON chapters(project_id)');
            },
            // v3: characters (per-project slugs, PLANNING.md section 2)
            function (Database $db): void {
                $db->run(
                    'CREATE TABLE characters (
                        id TEXT PRIMARY KEY,
                        project_id TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                        slug TEXT NOT NULL,
                        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE (project_id, slug)
                    )'
                );
                $db->run('CREATE INDEX idx_characters_project ON characters(project_id)');
            },
            // v4: character_relations (edges between characters of one project)
            function (Database $db): void {
                $db->run(
                    'CREATE TABLE character_relations (
                        id TEXT PRIMARY KEY,
                        project_id TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                        from_character_id TEXT NOT NULL REFERENCES characters(id) ON DELETE CASCADE,
                        to_character_id TEXT NOT NULL REFERENCES characters(id) ON DELETE CASCADE,
                        kind TEXT NOT NULL DEFAULT \'related\',
                        description TEXT NOT NULL DEFAULT \'\',
                        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        CHECK (from_character_id <> to_character_id),
                        UNIQUE (from_character_id, to_character_id, kind)
                    )'
                );
                $db->run('CREATE INDEX idx_relations_project ON character_relations(project_id)');
                $db->run('CREATE INDEX idx_relations_from ON character_relations(from_character_id)');
                $db->run('CREATE INDEX idx_relations_to ON character_relations(to_character_id)');
            },
            // v5: backgrounds (per-project slugs, PLANNING.md section 2)
            function (Database $db): void {
                $db->run(
                    'CREATE TABLE backgrounds (
                        id TEXT PRIMARY KEY,
                        project_id TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                        slug TEXT NOT NULL,
                        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE (project_id, slug)
                    )'
                );
                $db->run('CREATE INDEX idx_backgrounds_project ON backgrounds(project_id)');
            },
            // v6: outline fields on projects (blurb, synopsis, extended synopsis)
            function (Database $db): void {
                $db->run("ALTER TABLE projects ADD COLUMN blurb TEXT NOT NULL DEFAULT ''");
                $db->run("ALTER TABLE projects ADD COLUMN synopsis TEXT NOT NULL DEFAULT ''");
                $db->run("ALTER TABLE projects ADD COLUMN synopsis_long TEXT NOT NULL DEFAULT ''");
            },
            // v7: logline idea on projects
            function (Database $db): void {
                $db->run("ALTER TABLE projects ADD COLUMN idea TEXT NOT NULL DEFAULT ''");
            },
            // v8: standalone chapter synopses (title owned by the synopsis, optional summary)
            function (Database $db): void {
                $db->run(
                    'CREATE TABLE chapter_synopses (
                        id TEXT PRIMARY KEY,
                        project_id TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                        slug TEXT NOT NULL,
                        title TEXT NOT NULL,
                        summary_text TEXT NOT NULL DEFAULT \'\',
                        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE (project_id, slug)
                    )'
                );
                $db->run('CREATE INDEX idx_synopses_project ON chapter_synopses(project_id)');
            },
            // v9: chapters get a mandatory synopsis; existing chapters are backfilled
            function (Database $db): void {
                $chapters = $db->all(
                    'SELECT c.id, c.project_id, c.slug, c.created_at, o.owner_id,
                            COALESCE((
                                SELECT r.title FROM content_revisions r
                                WHERE r.object_id = c.id
                                ORDER BY r.created_at DESC, r.rowid DESC LIMIT 1
                            ), c.slug) AS title,
                            COALESCE((
                                SELECT r.summary FROM content_revisions r
                                WHERE r.object_id = c.id
                                ORDER BY r.created_at DESC, r.rowid DESC LIMIT 1
                            ), \'\') AS summary
                     FROM chapters c
                     JOIN content_objects o ON o.id = c.id'
                );
                $db->run('ALTER TABLE chapters RENAME TO chapters_old');
                $db->run(
                    'CREATE TABLE chapters (
                        id TEXT PRIMARY KEY,
                        project_id TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                        chapter_synopsis_id TEXT NOT NULL REFERENCES chapter_synopses(id),
                        slug TEXT NOT NULL,
                        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE (project_id, slug),
                        UNIQUE (chapter_synopsis_id)
                    )'
                );
                foreach ($chapters as $chapter) {
                    $synopsisId = \Core\Auth::uuid4();
                    $db->run(
                        'INSERT INTO chapter_synopses (id, project_id, slug, title, summary_text)
                         VALUES (?, ?, ?, ?, ?)',
                        [$synopsisId, (string)$chapter['project_id'], (string)$chapter['slug'],
                            (string)$chapter['title'], (string)$chapter['summary']]
                    );
                    $db->run(
                        'INSERT INTO chapters (id, project_id, chapter_synopsis_id, slug, created_at)
                         VALUES (?, ?, ?, ?, ?)',
                        [(string)$chapter['id'], (string)$chapter['project_id'], $synopsisId,
                            (string)$chapter['slug'], (string)$chapter['created_at']]
                    );
                }
                $db->run('DROP TABLE chapters_old');
                $db->run('CREATE INDEX idx_chapters_project ON chapters(project_id)');
                $db->run('CREATE INDEX idx_chapters_synopsis ON chapters(chapter_synopsis_id)');
            },
        ];
    }

    public function contentTypes(): array
    {
        return ['chapter', 'chapter_synopsis', 'character', 'background'];
    }

    public function resolveLink(Database $db, string $slug): ?array
    {
        foreach (['chapter', 'chapter_synopsis', 'character', 'background'] as $type) {
            $row = $this->findObject($db, $type, $slug);
            if ($row !== null) {
                return ['url' => $this->url($type, (string)$row['project_id'], (string)$row['slug'])];
            }
        }
        return null;
    }

    public function resolveLinks(Database $db, array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }
        $result = array_fill_keys($slugs, null);
        $placeholders = implode(',', array_fill(0, count($slugs), '?'));
        $rows = $db->all(
            "SELECT o.type, c.project_id, o.slug
             FROM content_objects o
             JOIN chapters c ON c.id = o.id AND o.type = 'chapter'
             WHERE o.slug IN ($placeholders)
             UNION
             SELECT o.type, k.project_id, o.slug
             FROM content_objects o
             JOIN chapter_synopses k ON k.id = o.id AND o.type = 'chapter_synopsis'
             WHERE o.slug IN ($placeholders)
             UNION
             SELECT o.type, k.project_id, o.slug
             FROM content_objects o
             JOIN characters k ON k.id = o.id AND o.type = 'character'
             WHERE o.slug IN ($placeholders)
             UNION
             SELECT o.type, b.project_id, o.slug
             FROM content_objects o
             JOIN backgrounds b ON b.id = o.id AND o.type = 'background'
             WHERE o.slug IN ($placeholders)",
            array_merge($slugs, $slugs, $slugs, $slugs)
        );
        foreach ($rows as $row) {
            $result[(string)$row['slug']] = [
                'url' => $this->url((string)$row['type'], (string)$row['project_id'], (string)$row['slug']),
            ];
        }
        return $result;
    }

    public function onDelete(Database $db, string $uuid): void
    {
        $db->run('DELETE FROM character_relations WHERE from_character_id = ?', [$uuid]);
        $db->run('DELETE FROM character_relations WHERE to_character_id = ?', [$uuid]);
        $db->run('DELETE FROM chapters WHERE id = ?', [$uuid]);
        $db->run('DELETE FROM chapter_synopses WHERE id = ?', [$uuid]);
        $db->run('DELETE FROM characters WHERE id = ?', [$uuid]);
        $db->run('DELETE FROM backgrounds WHERE id = ?', [$uuid]);
    }

    /** @return array{project_id: string, slug: string}|null */
    private function findObject(Database $db, string $type, string $slug): ?array
    {
        $table = ['chapter' => 'chapters', 'chapter_synopsis' => 'chapter_synopses',
            'character' => 'characters', 'background' => 'backgrounds'][$type]
            ?? throw new \InvalidArgumentException("Unknown type {$type}");
        $row = $db->one(
            "SELECT t.project_id, o.slug
             FROM content_objects o
             JOIN {$table} t ON t.id = o.id
             WHERE o.type = ? AND o.slug = ?",
            [$type, $slug]
        );
        return $row === null ? null : ['project_id' => (string)$row['project_id'], 'slug' => (string)$row['slug']];
    }

    private function url(string $type, string $projectId, string $slug): string
    {
        $segment = ['chapter' => 'chapters', 'chapter_synopsis' => 'synopses',
            'character' => 'characters', 'background' => 'backgrounds'][$type]
            ?? throw new \InvalidArgumentException("Unknown type {$type}");
        return '/writing/' . rawurlencode($projectId) . '/' . $segment . '/' . rawurlencode($slug);
    }
}
