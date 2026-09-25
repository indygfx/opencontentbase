<?php

declare(strict_types=1);

namespace Pages;

use Core\Auth;
use Core\ContentRenderer;
use Core\Database;
use Core\Response;
use Core\User;
use Core\View;

final class PagesController
{
    public function __construct(
        private Database $db,
        private View $view,
        private ContentRenderer $renderer
    ) {
    }

    public function index(User $user): Response
    {
        $pages = $this->db->all(
            "SELECT o.slug, r.title
               FROM content_objects o
               JOIN pages p ON p.object_id = o.id
               JOIN content_revisions r ON r.object_id = o.id
                AND r.created_at = (
                    SELECT MAX(r2.created_at)
                      FROM content_revisions r2
                     WHERE r2.object_id = o.id
                )
              ORDER BY r.title COLLATE NOCASE"
        );
        return $this->page($user, 'modules/Pages/templates/index.php', [
            'title' => 'Seiten',
            'pages' => $pages,
        ]);
    }

    public function show(string $slug, User $user): Response
    {
        $page = $this->findPage($slug);
        if ($page === null) {
            return $this->notFound($user);
        }
        $revisions = $this->db->all(
            'SELECT r.id, r.title, r.created_at, u.username AS author
               FROM content_revisions r
               LEFT JOIN users u ON u.id = r.author_id
              WHERE r.object_id = ?
              ORDER BY r.created_at DESC, r.id DESC',
            [$page['object_id']]
        );
        return $this->page($user, 'modules/Pages/templates/show.php', [
            'title' => (string)$page['title'],
            'page' => $page,
            'rendered' => $this->renderer->render((string)$page['body']),
            'revisions' => $revisions,
        ]);
    }

    public function edit(string $slug, User $user): Response
    {
        $page = $this->findPage($slug);
        if ($page === null) {
            return $this->notFound($user);
        }
        return $this->page($user, 'modules/Pages/templates/edit.php', [
            'title' => 'Bearbeiten: ' . (string)$page['title'],
            'page' => $page,
        ]);
    }

    public function update(string $slug, User $user): Response
    {
        $page = $this->findPage($slug);
        if ($page === null) {
            return $this->notFound($user);
        }
        $title = trim((string)($_POST['title'] ?? ''));
        $body = (string)($_POST['body'] ?? '');
        $this->insertRevision((string)$page['object_id'], $title, $body, $user);
        return Response::redirect('/pages/' . rawurlencode($slug));
    }

    public function create(User $user): Response
    {
        return $this->page($user, 'modules/Pages/templates/edit.php', [
            'title' => 'Neue Seite',
            'page' => null,
        ]);
    }

    public function store(User $user): Response
    {
        $slug = trim((string)($_POST['slug'] ?? ''));
        $title = trim((string)($_POST['title'] ?? ''));
        $body = (string)($_POST['body'] ?? '');

        $error = $this->validateSlug($slug);
        if ($error !== null) {
            return $this->page($user, 'modules/Pages/templates/edit.php', [
                'title' => 'Neue Seite',
                'page' => null,
                'error' => $error,
            ], 422);
        }

        $this->db->transaction(function (Database $db) use ($slug, $title, $body, $user): void {
            $objectId = Auth::uuid4();
            $db->run(
                "INSERT INTO content_objects (id, type, slug, owner_id) VALUES (?, 'page', ?, ?)",
                [$objectId, $slug, $user->id()]
            );
            $db->run(
                'INSERT INTO pages (id, object_id) VALUES (?, ?)',
                [Auth::uuid4(), $objectId]
            );
            $this->insertRevision($objectId, $title, $body, $user);
        });

        return Response::redirect('/pages/' . rawurlencode($slug));
    }

    public function preview(User $user): Response
    {
        $body = (string)($_POST['body'] ?? '');
        return Response::json(['html' => $this->renderer->render($body)]);
    }

    private function validateSlug(string $slug): ?string
    {
        if ($slug === '' || preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
            return 'Slug erforderlich (a-z, 0-9, Bindestrich).';
        }
        $exists = $this->db->one(
            "SELECT 1 FROM content_objects WHERE type = 'page' AND slug = ?",
            [$slug]
        );
        return $exists !== null ? 'Slug bereits vergeben.' : null;
    }

    private function insertRevision(string $objectId, string $title, string $body, User $user): void
    {
        $this->db->run(
            'INSERT INTO content_revisions (id, object_id, title, body, author_id) VALUES (?, ?, ?, ?, ?)',
            [Auth::uuid4(), $objectId, $title, $body, $user->id()]
        );
    }

    /** @return array<string, mixed>|null */
    private function findPage(string $slug): ?array
    {
        return $this->db->one(
            "SELECT o.id AS object_id, o.slug, r.title, r.body
               FROM content_objects o
               JOIN pages p ON p.object_id = o.id
               LEFT JOIN content_revisions r ON r.object_id = o.id
                AND r.created_at = (
                    SELECT MAX(r2.created_at)
                      FROM content_revisions r2
                     WHERE r2.object_id = o.id
                )
              WHERE o.type = 'page' AND o.slug = ?",
            [$slug]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function page(User $user, string $innerTemplate, array $data, int $status = 200): Response
    {
        $data['user'] = $user;
        $content = $this->view->render($innerTemplate, $data);
        return Response::html($this->view->render('templates/layout.php', [
            'title' => (string)($data['title'] ?? 'ContentBase'),
            'user' => $user,
            'content' => $content,
        ]), $status);
    }

    private function notFound(User $user): Response
    {
        $content = $this->view->render('templates/error.php', [
            'code' => 404,
            'message' => 'Seite nicht gefunden.',
        ]);
        return Response::html($this->view->render('templates/layout.php', [
            'title' => '404',
            'user' => $user,
            'content' => $content,
        ]), 404);
    }
}
