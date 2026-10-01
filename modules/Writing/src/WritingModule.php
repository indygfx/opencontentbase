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

    public function __construct(Database $db, \Core\View $view, \Core\Csrf $csrf)
    {
        $this->access = new ProjectAccess($db);
        $this->controller = new WritingController($db, $view, $csrf, $this->access);
    }

    public function id(): string
    {
        return 'writing';
    }

    public function routes(Router $router): array
    {
        $c = $this->controller;
        return [
            ['method' => 'GET', 'pattern' => '/writing', 'handler' => fn ($params, $user) => $c->index($user), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing', 'handler' => fn ($params, $user) => $c->store($user), 'roles' => ['user']],
            ['method' => 'GET', 'pattern' => '/writing/{id}', 'handler' => fn ($params, $user) => $c->show($user, $params['id']), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/{id}', 'handler' => fn ($params, $user) => $c->update($user, $params['id']), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/{id}/delete', 'handler' => fn ($params, $user) => $c->destroy($user, $params['id']), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/{id}/share', 'handler' => fn ($params, $user) => $c->share($user, $params['id']), 'roles' => ['user']],
            ['method' => 'POST', 'pattern' => '/writing/{id}/unshare', 'handler' => fn ($params, $user) => $c->unshare($user, $params['id']), 'roles' => ['user']],
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
        ];
    }

    public function contentTypes(): array
    {
        return [];
    }

    public function resolveLink(Database $db, string $slug): ?array
    {
        return null;
    }

    public function resolveLinks(Database $db, array $slugs): array
    {
        return array_fill_keys($slugs, null);
    }

    public function onDelete(Database $db, string $uuid): void
    {
    }
}
