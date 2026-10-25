<?php

declare(strict_types=1);

namespace Pages;

use Core\Auth;
use Core\ContentRenderer;
use Core\Diff;
use Core\Database;
use Core\Csrf;
use Core\ObjectDeleter;
use Core\Response;
use Core\User;
use Core\View;

final class PagesController
{
    public function __construct(
        private Database $db,
        private View $view,
        private ContentRenderer $renderer,
        private ObjectDeleter $deleter,
        private Csrf $csrf
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
            'title' => t('pages', 'title'),
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
            'title' => t('pages', 'edit_page', ['title' => (string)$page['title']]),
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
            'title' => t('pages', 'new_page'),
            'page' => null,
        ]);
    }

    public function store(User $user): Response
    {
        $slug = trim((string)($_POST['slug'] ?? ''));
        $title = trim((string)($_POST['title'] ?? ''));
        $body = (string)($_POST['body'] ?? '');

        if ($slug === '') {
            $slug = slugify($title);
        } else {
            $slug = slugify($slug);
        }

        $error = $this->validateSlug($slug);
        if ($error !== null) {
            return $this->page($user, 'modules/Pages/templates/edit.php', [
                'title' => t('pages', 'new_page'),
                'page' => null,
                'slug' => $slug,
                'formTitle' => $title,
                'formBody' => $body,
                'error' => $error,
            ], 422);
        }

        $slug = $this->uniqueSlug($slug);
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

    public function destroy(string $slug, User $user): Response
    {
        $page = $this->findPage($slug);
        if ($page === null) {
            return $this->notFound($user);
        }
        $this->deleter->delete('page', (string)$page['object_id']);
        return Response::redirect('/pages');
    }

    private function uniqueSlug(string $slug): string
    {
        $base = $slug;
        $n = 2;
        while ($this->db->one(
            "SELECT 1 FROM content_objects WHERE type = 'page' AND slug = ?",
            [$slug]
        ) !== null) {
            $slug = $base . '-' . $n;
            $n++;
        }
        return $slug;
    }

    private function validateSlug(string $slug): ?string
    {
        if ($slug === '' || preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
            return t('pages', 'error_slug_derive');
        }
        $exists = $this->db->one(
            "SELECT 1 FROM content_objects WHERE type = 'page' AND slug = ?",
            [$slug]
        );
        return $exists !== null ? t('pages', 'slug_taken') : null;
    }

    /**
     * GET /pages/{slug}/revisions - admin-only revision list,
     * newest-first, with a "current" badge on the latest revision.
     */
    public function revisions(string $slug, User $user): Response
    {
        $page = $this->findPage($slug);
        if ($page === null) {
            return $this->notFound($user);
        }
        $revisions = $this->db->all(
            'SELECT r.id, r.title, r.body, r.created_at, r.rowid, u.username AS author
             FROM content_revisions r
             LEFT JOIN users u ON u.id = r.author_id
             WHERE r.object_id = ?
             ORDER BY r.created_at DESC, r.rowid DESC',
            [$page['object_id']]
        );
        $rows = [];
        $count = count($revisions);
        foreach ($revisions as $i => $revision) {
            $isLatest = $i === 0;
            $added = 0;
            $removed = 0;
            if (!$isLatest) {
                $stats = Diff::stats((string)$revisions[$i - 1]['body'], (string)$revision['body']);
                $added = $stats['added'];
                $removed = $stats['removed'];
            }
            $rows[] = [
                'id' => (string)$revision['id'],
                'author' => (string)($revision['author'] ?? ''),
                'created_at' => (string)$revision['created_at'],
                'added' => $added,
                'removed' => $removed,
                'isLatest' => $isLatest,
                'isOldest' => $i === $count - 1,
            ];
        }
        $notice = trim((string)($_GET['notice'] ?? ''));
        return $this->page($user, 'modules/Pages/templates/revisions.php', [
            'title' => t('pages', 'history_title', ['title' => (string)$page['title']]),
            'page' => $page,
            'rows' => $rows,
            'notice' => $notice === '' ? null : $notice,
        ]);
    }

    /**
     * GET /pages/{slug}/revisions/{revisionId} - inline diff vs. the
     * predecessor (the oldest revision diffs against empty text).
     */
    public function revisionDiff(string $slug, string $revisionId, User $user): Response
    {
        $page = $this->findPage($slug);
        if ($page === null) {
            return $this->notFound($user);
        }
        $revision = $this->findRevision((string)$page['object_id'], $revisionId);
        if ($revision === null) {
            return $this->notFound($user);
        }
        $predecessor = $this->db->one(
            'SELECT r.id, r.title, r.body, r.created_at, u.username AS author
             FROM content_revisions r
             LEFT JOIN users u ON u.id = r.author_id
             WHERE r.object_id = ?
               AND (r.created_at < ? OR (r.created_at = ? AND r.rowid < ?))
             ORDER BY r.created_at DESC, r.rowid DESC
             LIMIT 1',
            [$page['object_id'], $revision['created_at'], $revision['created_at'], $revision['rowid']]
        );
        $from = $predecessor ?? ['id' => '', 'title' => '', 'body' => '', 'created_at' => '', 'author' => null];
        $segments = Diff::compare((string)$from['body'], (string)$revision['body']);
        return $this->page($user, 'modules/Pages/templates/revision_diff.php', [
            'title' => t('pages', 'history_diff_title', ['title' => (string)$page['title']]),
            'page' => $page,
            'from' => [
                'author' => (string)($from['author'] ?? ''),
                'created_at' => (string)$from['created_at'],
                'title' => (string)$from['title'],
            ],
            'to' => [
                'author' => (string)($revision['author'] ?? ''),
                'created_at' => (string)$revision['created_at'],
                'title' => (string)$revision['title'],
            ],
            'segments' => $segments,
            'titleChanged' => (string)$from['title'] !== (string)$revision['title'],
        ]);
    }

    /**
     * POST /pages/{slug}/revisions/{revisionId}/restore - creates a NEW
     * revision with the old content, authored by the restorer. Restoring
     * the latest revision is a no-op redirect with an info message.
     * CSRF is enforced globally by App for every POST route.
     */
    public function restoreRevision(string $slug, string $revisionId, User $user): Response
    {
        $page = $this->findPage($slug);
        if ($page === null) {
            return $this->notFound($user);
        }
        $revision = $this->findRevision((string)$page['object_id'], $revisionId);
        if ($revision === null) {
            return $this->notFound($user);
        }
        $latest = $this->db->one(
            'SELECT id FROM content_revisions WHERE object_id = ?
             ORDER BY created_at DESC, rowid DESC LIMIT 1',
            [$page['object_id']]
        );
        if ($latest !== null && (string)$latest['id'] === (string)$revision['id']) {
            return Response::redirect('/pages/' . rawurlencode($slug) . '/revisions?notice='
                . urlencode(t('pages', 'history_restore_noop')));
        }
        $this->insertRevision((string)$page['object_id'], (string)$revision['title'], (string)$revision['body'], $user);
        return Response::redirect('/pages/' . rawurlencode($slug) . '/revisions?notice='
            . urlencode(t('pages', 'history_restore_done')));
    }

    /** @return array<string, mixed>|null */
    private function findRevision(string $objectId, string $revisionId): ?array
    {
        return $this->db->one(
            'SELECT r.id, r.title, r.body, r.created_at, r.rowid, u.username AS author
             FROM content_revisions r
             LEFT JOIN users u ON u.id = r.author_id
             WHERE r.object_id = ? AND r.id = ?',
            [$objectId, $revisionId]
        );
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
        $data['csrf'] = $this->csrf;
        $content = $this->view->render($innerTemplate, $data);
        return Response::html($this->view->render('templates/layout.php', [
            'title' => (string)($data['title'] ?? 'ContentBase'),
            'user' => $user,
            'csrf' => $this->csrf,
            'content' => $content,
        ]), $status);
    }

    private function notFound(User $user): Response
    {
        $content = $this->view->render('templates/error.php', [
            'code' => 404,
            'message' => t('pages', 'error_not_found'),
        ]);
        return Response::html($this->view->render('templates/layout.php', [
            'title' => '404',
            'user' => $user,
            'csrf' => $this->csrf,
            'content' => $content,
        ]), 404);
    }
}
