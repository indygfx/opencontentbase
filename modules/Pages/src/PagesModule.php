<?php

declare(strict_types=1);

namespace Pages;

use Core\ContentRenderer;
use Core\Csrf;
use Core\Database;
use Core\ModuleInterface;
use Core\ObjectDeleter;
use Core\Router;
use Core\View;

final class PagesModule implements ModuleInterface
{
    private PagesController $controller;

    public function __construct(Database $db, View $view, ContentRenderer $renderer, ObjectDeleter $deleter, Csrf $csrf)
    {
        $this->controller = new PagesController($db, $view, $renderer, $deleter, $csrf);
    }

    public function id(): string
    {
        return 'pages';
    }

    public function routes(Router $router): array
    {
        $c = $this->controller;

        return [
            ['method' => 'GET', 'pattern' => '/', 'handler' => fn ($params, $user) => $c->index($user), 'roles' => ['user']],
            ['method' => 'GET', 'pattern' => '/pages', 'handler' => fn ($params, $user) => $c->index($user), 'roles' => ['admin']],
            ['method' => 'GET', 'pattern' => '/pages/new', 'handler' => fn ($params, $user) => $c->create($user), 'roles' => ['admin']],
            ['method' => 'POST', 'pattern' => '/pages', 'handler' => fn ($params, $user) => $c->store($user), 'roles' => ['admin']],
            ['method' => 'POST', 'pattern' => '/pages/preview', 'handler' => fn ($params, $user) => $c->preview($user), 'roles' => ['admin']],
            ['method' => 'GET', 'pattern' => '/pages/{slug}', 'handler' => fn ($params, $user) => $c->show($params['slug'], $user), 'roles' => ['admin']],
            ['method' => 'GET', 'pattern' => '/pages/{slug}/edit', 'handler' => fn ($params, $user) => $c->edit($params['slug'], $user), 'roles' => ['admin']],
            ['method' => 'POST', 'pattern' => '/pages/{slug}', 'handler' => fn ($params, $user) => $c->update($params['slug'], $user), 'roles' => ['admin']],
            ['method' => 'POST', 'pattern' => '/pages/{slug}/delete', 'handler' => fn ($params, $user) => $c->destroy($params['slug'], $user), 'roles' => ['admin']],
            ['method' => 'POST', 'pattern' => '/pages/{slug}/preview', 'handler' => fn ($params, $user) => $c->preview($user), 'roles' => ['admin']],
        ];
    }

    public function migrations(): array
    {
        return [
            function (Database $db): void {
                $db->run(
                    'CREATE TABLE pages (
                        id TEXT PRIMARY KEY,
                        object_id TEXT NOT NULL UNIQUE REFERENCES content_objects(id) ON DELETE CASCADE,
                        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                    )'
                );
                $db->run('CREATE INDEX idx_pages_object ON pages(object_id)');
            },
        ];
    }

    public function contentTypes(): array
    {
        return ['page'];
    }

    public function resolveLink(Database $db, string $slug): ?array
    {
        $row = $db->one(
            "SELECT o.slug
               FROM content_objects o
               JOIN pages p ON p.object_id = o.id
              WHERE o.type = 'page' AND o.slug = ?",
            [$slug]
        );
        return $row === null ? null : ['url' => '/pages/' . rawurlencode((string)$row['slug'])];
    }

    public function resolveLinks(Database $db, array $slugs): array
    {
        $result = array_fill_keys($slugs, null);
        if ($slugs === []) {
            return $result;
        }
        $placeholders = implode(',', array_fill(0, count($slugs), '?'));
        $rows = $db->all(
            "SELECT o.slug
               FROM content_objects o
               JOIN pages p ON p.object_id = o.id
              WHERE o.type = 'page' AND o.slug IN ({$placeholders})",
            $slugs
        );
        foreach ($rows as $row) {
            $slug = (string)$row['slug'];
            $result[$slug] = ['url' => '/pages/' . rawurlencode($slug)];
        }
        return $result;
    }

    public function onDelete(Database $db, string $uuid): void
    {
        $db->run('DELETE FROM pages WHERE object_id = ?', [$uuid]);
    }
}
