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
        private ContentRenderer $rendererService,
        private ChapterSynopsisController $synopses
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
            'synopsis' => null,
            'synopses' => $this->synopses->unassigned($projectId),
            'isEdit' => false,
            'error' => null,
        ]);
    }

    public function store(User $user, string $projectId): Response
    {
        if ($this->findProject($projectId) === null || !$this->access->canEdit($user, $projectId)) {
            return $this->notFound($user);
        }
        $synopsisId = trim((string)($_POST['synopsis_id'] ?? ''));
        $slugInput = trim((string)($_POST['slug'] ?? ''));
        $body = (string)($_POST['body'] ?? '');
        $synopsis = $synopsisId === '' ? null : $this->synopses->findSynopsisRow($projectId, $synopsisId);
        if ($synopsis === null) {
            return $this->editError($user, $projectId, null, $slugInput, $body,
                'Pick a chapter outline for this chapter.');
        }
        $assigned = $this->assignedChapter($projectId, $synopsis['id']);
        if ($assigned !== null) {
            return $this->editError($user, $projectId, null, $slugInput, $body,
                'That chapter outline is already assigned to a written chapter.');
        }
        $slug = slugify($slugInput ?: $synopsis['title']);
        $error = $this->validate($slug);
        if ($error === null && $slugInput !== '' && $this->slugTaken($projectId, $slug)) {
            $error = 'A chapter with that slug already exists in this story.';
        }
        if ($error !== null) {
            return $this->editError($user, $projectId, null, $slugInput, $body, $error, $synopsis);
        }
        if ($slugInput === '') {
            $slug = $this->uniqueSlug($projectId, $slug);
        }
        $this->insertChapter($user, $projectId, $slug, $synopsis['title'], $synopsis['id'], $body);
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
        $synopsis = $this->synopses->findSynopsisRow($projectId, (string)$chapter['chapter_synopsis_id']);
        return $this->page($user, 'modules/Writing/templates/chapter_edit.php', [
            'title' => 'Edit: ' . (string)$chapter['title'],
            'projectId' => $projectId,
            'projectTitle' => $this->projectTitle($projectId),
            'chapter' => $chapter,
            'synopsis' => $synopsis,
            'synopses' => $this->synopses->unassigned($projectId, $synopsis['id'] ?? null),
            'isEdit' => true,
            'error' => null,
        ]);
    }

    public function update(User $user, string $projectId, string $slug): Response
    {
        $chapter = $this->findChapter($projectId, $slug);
        if ($chapter === null || !$this->access->canEdit($user, $projectId)) {
            return $this->notFound($user);
        }
        $currentSynopsisId = (string)$chapter['chapter_synopsis_id'];
        $synopsisId = trim((string)($_POST['synopsis_id'] ?? ''));
        $newSlug = slugify(trim((string)($_POST['slug'] ?? '')) ?: (string)$chapter['slug']);
        $body = (string)($_POST['body'] ?? '');
        if ($synopsisId !== $currentSynopsisId) {
            $synopsis = $synopsisId === '' ? null : $this->synopses->findSynopsisRow($projectId, $synopsisId);
            if ($synopsis === null) {
                return $this->editError($user, $projectId, $slug, $newSlug, $body,
                    'Pick a chapter outline for this chapter.', null, true);
            }
            $assigned = $this->assignedChapter($projectId, $synopsis['id']);
            if ($assigned !== null) {
                return $this->editError($user, $projectId, $slug, $newSlug, $body,
                    'That chapter outline is already assigned to a written chapter.', $synopsis, true);
            }
            $newSynopsisId = $synopsis['id'];
            $newTitle = $synopsis['title'];
        } else {
            $newSynopsisId = $currentSynopsisId;
            $newTitle = (string)$chapter['title'];
        }
        if ($newSlug !== $slug) {
            $error = $this->validate($newSlug);
            if ($error === null && $this->slugTaken($projectId, $newSlug)) {
                $error = 'A chapter with that slug already exists in this story.';
            }
        } else {
            $error = null;
        }
        if ($error !== null) {
            return $this->editError($user, $projectId, $slug, $newSlug, $body, $error, null, true);
        }
        $currentSynopsis = $this->synopses->findSynopsisRow($projectId, $currentSynopsisId);
        $summaryText = (string)($currentSynopsis['summary_text'] ?? '');
        $this->db->transaction(function (Database $db) use ($chapter, $newSlug, $newSynopsisId, $newTitle, $summaryText, $body, $user): void {
            if ($newSlug !== (string)$chapter['slug']) {
                $db->run(
                    'UPDATE content_objects SET slug = ? WHERE id = ?',
                    [$newSlug, (string)$chapter['object_id']]
                );
                $db->run('UPDATE chapters SET slug = ? WHERE id = ?', [$newSlug, (string)$chapter['id']]);
            }
            $db->run(
                'UPDATE chapters SET chapter_synopsis_id = ? WHERE id = ?',
                [$newSynopsisId, (string)$chapter['id']]
            );
            $db->run(
                'INSERT INTO content_revisions (id, object_id, title, summary, body, author_id) VALUES (?, ?, ?, ?, ?, ?)',
                [\Core\Auth::uuid4(), (string)$chapter['object_id'], $newTitle,
                    $summaryText, $body, $user->id()]
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

    private function validate(string $slug): ?string
    {
        if ($slug === '' || preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
            return 'Could not derive a slug from the title (a-z, 0-9, hyphen).';
        }
        return null;
    }

    private function uniqueSlug(string $projectId, string $slug): string
    {
        $base = $slug;
        $n = 2;
        while ($this->slugTaken($projectId, $slug)) {
            $slug = $base . '-' . $n;
            $n++;
        }
        return $slug;
    }

    private function slugTaken(string $projectId, string $slug): bool
    {
        return $this->db->one(
            'SELECT 1 FROM content_objects o JOIN chapters c ON c.id = o.id
             WHERE o.type = \'chapter\' AND o.slug = ? AND c.project_id = ?',
            [$slug, $projectId]
        ) !== null;
    }

    private function insertChapter(
        User $user,
        string $projectId,
        string $slug,
        string $title,
        string $synopsisId,
        string $body
    ): void {
        $this->db->transaction(function (Database $db) use ($user, $projectId, $slug, $title, $synopsisId, $body): void {
            $objectId = \Core\Auth::uuid4();
            $db->run(
                "INSERT INTO content_objects (id, type, slug, owner_id) VALUES (?, 'chapter', ?, ?)",
                [$objectId, $slug, $user->id()]
            );
            $db->run(
                'INSERT INTO chapters (id, project_id, chapter_synopsis_id, slug) VALUES (?, ?, ?, ?)',
                [$objectId, $projectId, $synopsisId, $slug]
            );
            $summary = (string)($db->one(
                'SELECT summary_text FROM chapter_synopses WHERE id = ?',
                [$synopsisId]
            )['summary_text'] ?? '');
            $db->run(
                'INSERT INTO content_revisions (id, object_id, title, summary, body, author_id) VALUES (?, ?, ?, ?, ?, ?)',
                [\Core\Auth::uuid4(), $objectId, $title, $summary, $body, $user->id()]
            );
        });
    }

    /** @return array<string, mixed>|null */
    private function assignedChapter(string $projectId, string $synopsisId): ?array
    {
        return $this->db->one(
            'SELECT c.slug, s.title FROM chapters c
             JOIN chapter_synopses s ON s.id = c.chapter_synopsis_id
             WHERE c.project_id = ? AND c.chapter_synopsis_id = ?',
            [$projectId, $synopsisId]
        );
    }

    /** @return array<string, mixed>|null */
    private function findChapter(string $projectId, string $slug): ?array
    {
        return $this->db->one(
            "SELECT o.id AS object_id, o.slug, o.owner_id, c.id, c.chapter_synopsis_id,
                    COALESCE(s.title, r.title, o.slug) AS title,
                    COALESCE(s.summary_text, r.summary, '') AS summary, r.body
             FROM content_objects o
             JOIN chapters c ON c.id = o.id
             LEFT JOIN chapter_synopses s ON s.id = c.chapter_synopsis_id
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
        string $slugInput,
        string $body,
        string $error,
        ?array $synopsis = null,
        bool $isEdit = false
    ): Response {
        $chapter = [
            'slug' => $slugInput,
            'title' => $synopsis['title'] ?? '',
            'body' => $body,
        ];
        $currentId = $synopsis['id'] ?? '';
        $synopses = $this->synopses->unassigned($projectId, $currentId !== '' ? $currentId : null);
        return $this->page($user, 'modules/Writing/templates/chapter_edit.php', [
            'title' => $isEdit ? 'Edit: ' . (string)($chapter['title'] ?? '') : 'New chapter',
            'projectId' => $projectId,
            'projectTitle' => $this->projectTitle($projectId),
            'chapter' => $chapter,
            'synopsis' => $synopsis,
            'synopses' => $synopses,
            'isEdit' => $isEdit || $slug !== null,
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
