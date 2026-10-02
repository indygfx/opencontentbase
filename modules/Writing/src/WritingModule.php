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

    public function __construct(
        Database $db,
        \Core\View $view,
        \Core\Csrf $csrf,
        \Core\ContentRenderer $renderer
    ) {
        $this->access = new ProjectAccess($db);
        $this->controller = new WritingController($db, $view, $csrf, $this->access);
        $this->chapters = new ChapterController($db, $view, $csrf, $this->access, $renderer);
    }

    public function id(): string
    {
        return 'writing';
    }

    public function routes(Router $router): array
    {
        $c = $this->controller;
        $ch = $this->chapters;
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
            ['method' => 'GET', 'pattern' => '/writing/{id}/chapters/{slug}', 'handler' => fn ($params, $user) => $ch->show($user, $params['id'], $params['slug']), 'roles' => ['user']],
            ['method' => 'GET', 'pattern' => '/writing/{id}/chapters/{slug}/edit', 'handler' => fn ($params, $user) => $ch->edit($user, $params['id'], $params['slug']), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/{id}/chapters/{slug}', 'handler' => fn ($params, $user) => $ch->update($user, $params['id'], $params['slug']), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/{id}/chapters/{slug}/delete', 'handler' => fn ($params, $user) => $ch->destroy($user, $params['id'], $params['slug']), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/{id}/chapters/preview', 'handler' => fn ($params, $user) => $ch->preview($user), 'roles' => ['user']],
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
        ];
    }

    public function contentTypes(): array
    {
        return ['chapter'];
    }

    public function resolveLink(Database $db, string $slug): ?array
    {
        $row = $db->one(
            "SELECT c.project_id, o.slug
             FROM content_objects o
             JOIN chapters c ON c.id = o.id
             WHERE o.type = 'chapter' AND o.slug = ?",
            [$slug]
        );
        return $row === null
            ? null
            : ['url' => '/writing/' . rawurlencode((string)$row['project_id']) . '/chapters/' . rawurlencode((string)$row['slug'])];
    }

    public function resolveLinks(Database $db, array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($slugs), '?'));
        $rows = $db->all(
            "SELECT c.project_id, o.slug
             FROM content_objects o
             JOIN chapters c ON c.id = o.id
             WHERE o.type = 'chapter' AND o.slug IN ($placeholders)",
            $slugs
        );
        $result = array_fill_keys($slugs, null);
        foreach ($rows as $row) {
            $result[(string)$row['slug']] = [
                'url' => '/writing/' . rawurlencode((string)$row['project_id']) . '/chapters/' . rawurlencode((string)$row['slug']),
            ];
        }
        return $result;
    }

    public function onDelete(Database $db, string $uuid): void
    {
        $db->run('DELETE FROM chapters WHERE id = ?', [$uuid]);
    }
}
