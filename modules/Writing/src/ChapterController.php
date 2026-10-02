<?php
declare(strict_types=1);

namespace Writing;

use Core\ContentRenderer;
use Core\Csrf;
use Core\Database;
use Core\Response;
use Core\User;
use Core\View;

final class ChapterController
{
    public function __construct(
        private Database $db,
        private View $view,
        private Csrf $csrf,
        private ProjectAccess $access,
        private ContentRenderer $rendererService
    ) {
    }

    public function create(User $user, string $projectId): Response
    {
        if ($this->findProject($projectId) === null || !$this->access->canEdit($user, $projectId)) {
            return $this->notFound($user);
        }
        return $this->page($user, 'modules/Writing/templates/chapter_edit.php', [
            'title' => 'New chapter',
            'projectId' => $projectId,
            'projectTitle' => $this->projectTitle($projectId),
            'chapter' => null,
            'error' => null,
        ]);
    }

    public function store(User $user, string $projectId): Response
    {
        if ($this->findProject($projectId) === null || !$this->access->canEdit($user, $projectId)) {
            return $this->notFound($user);
        }
        $title = trim((string)($_POST['title'] ?? ''));
        $slug = slugify(trim((string)($_POST['slug'] ?? '')) ?: $title);
        $body = (string)($_POST['body'] ?? '');
        $error = $this->validate($projectId, $title, $slug);
        if ($error !== null) {
            return $this->editError($user, $projectId, null, $title, $slug, $body, $error);
        }
        $this->insertChapter($user, $projectId, $slug, $title, $body);
        return Response::redirect('/writing/' . $projectId . '/chapters/' . rawurlencode($slug));
    }

    public function show(User $user, string $projectId, string $slug): Response
    {
        $chapter = $this->findChapter($projectId, $slug);
        if ($chapter === null || !$this->access->canAccess($user, $projectId)) {
            return $this->notFound($user);
        }
        return $this->page($user, 'modules/Writing/templates/chapter.php', [
            'title' => (string)$chapter['title'],
            'projectId' => $projectId,
            'projectTitle' => $this->projectTitle($projectId),
            'chapter' => $chapter,
            'rendered' => $this->rendererService->render((string)$chapter['body']),
            'canEdit' => $this->access->canEdit($user, $projectId),
            'error' => null,
        ]);
    }

    public function edit(User $user, string $projectId, string $slug): Response
    {
        $chapter = $this->findChapter($projectId, $slug);
        if ($chapter === null || !$this->access->canEdit($user, $projectId)) {
            return $this->notFound($user);
        }
        return $this->page($user, 'modules/Writing/templates/chapter_edit.php', [
            'title' => 'Edit: ' . (string)$chapter['title'],
            'projectId' => $projectId,
            'projectTitle' => $this->projectTitle($projectId),
            'chapter' => $chapter,
            'error' => null,
        ]);
    }

    public function update(User $user, string $projectId, string $slug): Response
    {
        $chapter = $this->findChapter($projectId, $slug);
        if ($chapter === null || !$this->access->canEdit($user, $projectId)) {
            return $this->notFound($user);
        }
        $title = trim((string)($_POST['title'] ?? ''));
        $newSlug = slugify(trim((string)($_POST['slug'] ?? '')) ?: $title);
        $body = (string)($_POST['body'] ?? '');
        if ($newSlug !== $slug) {
            $error = $this->validate($projectId, $title, $newSlug);
        } else {
            $error = $title === '' ? 'The title must not be empty.' : null;
        }
        if ($error !== null) {
            return $this->editError($user, $projectId, $slug, $title, $newSlug, $body, $error);
        }
        $this->db->transaction(function (Database $db) use ($chapter, $newSlug, $title, $body, $user): void {
            if ($newSlug !== (string)$chapter['slug']) {
                $db->run(
                    'UPDATE content_objects SET slug = ? WHERE id = ?',
                    [$newSlug, (string)$chapter['object_id']]
                );
            }
            $db->run(
                'INSERT INTO content_revisions (id, object_id, title, body, author_id) VALUES (?, ?, ?, ?, ?)',
                [\Core\Auth::uuid4(), (string)$chapter['object_id'], $title, $body, $user->id()]
            );
        });
        return Response::redirect('/writing/' . $projectId . '/chapters/' . rawurlencode($newSlug));
    }

    public function destroy(User $user, string $projectId, string $slug): Response
    {
        $chapter = $this->findChapter($projectId, $slug);
        if ($chapter === null || !$this->access->canAccess($user, $projectId)) {
            return $this->notFound($user);
        }
        if (!$this->access->isOwner($user, $projectId)
            && (string)($chapter['owner_id'] ?? '') !== $user->id()) {
            return $this->forbidden($user);
        }
        $this->db->transaction(function (Database $db) use ($chapter): void {
            $db->run('DELETE FROM chapters WHERE id = ?', [(string)$chapter['id']]);
            $db->run('DELETE FROM content_objects WHERE id = ?', [(string)$chapter['object_id']]);
        });
        return Response::redirect('/writing/' . $projectId);
    }

    public function preview(User $user): Response
    {
        $body = (string)($_POST['body'] ?? '');
        return Response::json(['html' => $this->rendererService->render($body)]);
    }

    private function validate(string $projectId, string $title, string $slug): ?string
    {
        if ($title === '') {
            return 'The title must not be empty.';
        }
        if ($slug === '' || preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
            return 'Could not derive a slug from the title (a-z, 0-9, hyphen).';
        }
        $exists = $this->db->one(
            "SELECT 1 FROM content_objects o JOIN chapters c ON c.id = o.id WHERE o.type = 'chapter' AND o.slug = ? AND c.project_id = ?",
            [$slug, $projectId]
        );
        return $exists !== null ? 'A chapter with that slug already exists in this project.' : null;
    }

    private function insertChapter(User $user, string $projectId, string $slug, string $title, string $body): void
    {
        $this->db->transaction(function (Database $db) use ($user, $projectId, $slug, $title, $body): void {
            $objectId = \Core\Auth::uuid4();
            $db->run(
                "INSERT INTO content_objects (id, type, slug, owner_id) VALUES (?, 'chapter', ?, ?)",
                [$objectId, $slug, $user->id()]
            );
            $db->run(
                'INSERT INTO chapters (id, project_id, slug) VALUES (?, ?, ?)',
                [$objectId, $projectId, $slug]
            );
            $db->run(
                'INSERT INTO content_revisions (id, object_id, title, body, author_id) VALUES (?, ?, ?, ?, ?)',
                [\Core\Auth::uuid4(), $objectId, $title, $body, $user->id()]
            );
        });
    }

    /** @return array<string, mixed>|null */
    private function findChapter(string $projectId, string $slug): ?array
    {
        return $this->db->one(
            "SELECT o.id AS object_id, o.slug, o.owner_id, c.id, r.title, r.body
             FROM content_objects o
             JOIN chapters c ON c.id = o.id
             LEFT JOIN content_revisions r ON r.object_id = o.id
              AND r.created_at = (
                  SELECT MAX(r2.created_at) FROM content_revisions r2 WHERE r2.object_id = o.id
              )
             WHERE o.type = 'chapter' AND o.slug = ? AND c.project_id = ?",
            [$slug, $projectId]
        );
    }

    /** @return array<string, mixed>|null */
    private function findProject(string $projectId): ?array
    {
        return $this->db->one('SELECT id, title FROM projects WHERE id = ?', [$projectId]);
    }

    private function projectTitle(string $projectId): string
    {
        $project = $this->findProject($projectId);
        return $project === null ? '' : (string)$project['title'];
    }

    private function editError(
        User $user,
        string $projectId,
        ?string $slug,
        string $title,
        string $slugInput,
        string $body,
        string $error
    ): Response {
        $chapter = $slug === null ? null : [
            'slug' => $slugInput,
            'title' => $title,
            'body' => $body,
        ];
        return $this->page($user, 'modules/Writing/templates/chapter_edit.php', [
            'title' => 'New chapter',
            'projectId' => $projectId,
            'projectTitle' => $this->projectTitle($projectId),
            'chapter' => $chapter,
            'error' => $error,
        ], 422);
    }

    /** @param array<string, mixed> $data */
    private function page(User $user, string $innerTemplate, array $data, int $status = 200): Response
    {
        $data['user'] = $user;
        $data['csrf'] = $this->csrf;
        $content = $this->view->render($innerTemplate, $data);
        return Response::html($this->view->render('templates/layout.php', [
            'title' => (string)($data['title'] ?? 'Writing'),
            'user' => $user,
            'csrf' => $this->csrf,
            'content' => $content,
        ]), $status);
    }

    private function notFound(User $user): Response
    {
        return $this->page($user, 'templates/error.php', [
            'title' => '404',
            'code' => 404,
            'message' => 'Chapter not found.',
        ], 404);
    }

    private function forbidden(User $user): Response
    {
        return $this->page($user, 'templates/error.php', [
            'title' => '403',
            'code' => 403,
            'message' => 'You may only delete chapters you created yourself.',
        ], 403);
    }
}
