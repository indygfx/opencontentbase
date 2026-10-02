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
        $this->controller = new WritingController($db, $view, $csrf, $this->access);
        $this->chapters = new ChapterController($db, $view, $csrf, $this->access, $renderer);
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
            ['method' => 'POST', 'pattern' => '/writing/{id}/relations', 'handler' => fn ($params, $user) => $rel->store($user, $params['id']), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/{id}/relations/delete', 'handler' => fn ($params, $user) => $rel->destroy($user, $params['id']), 'roles' => ['user']],
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
                        kind TEXT NOT NULL DEFAULT 'related',
                        description TEXT NOT NULL DEFAULT '',
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
        ];
    }

    public function contentTypes(): array
    {
        return ['chapter', 'character', 'background'];
    }

    public function resolveLink(Database $db, string $slug): ?array
    {
        foreach (['chapter', 'character', 'background'] as $type) {
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
             JOIN characters k ON k.id = o.id AND o.type = 'character'
             WHERE o.slug IN ($placeholders)
             UNION
             SELECT o.type, b.project_id, o.slug
             FROM content_objects o
             JOIN backgrounds b ON b.id = o.id AND o.type = 'background'
             WHERE o.slug IN ($placeholders)",
            array_merge($slugs, $slugs, $slugs)
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
        $db->run('DELETE FROM characters WHERE id = ?', [$uuid]);
        $db->run('DELETE FROM backgrounds WHERE id = ?', [$uuid]);
    }

    /** @return array{project_id: string, slug: string}|null */
    private function findObject(Database $db, string $type, string $slug): ?array
    {
        $table = ['chapter' => 'chapters', 'character' => 'characters', 'background' => 'backgrounds'][$type]
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
        $segment = ['chapter' => 'chapters', 'character' => 'characters', 'background' => 'backgrounds'][$type]
            ?? throw new \InvalidArgumentException("Unknown type {$type}");
        return '/writing/' . rawurlencode($projectId) . '/' . $segment . '/' . rawurlencode($slug);
    }
}
