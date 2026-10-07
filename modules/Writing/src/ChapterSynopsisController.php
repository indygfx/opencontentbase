<?php
declare(strict_types=1);

namespace Writing;

use Core\Csrf;
use Core\Database;
use Core\Response;
use Core\User;
use Core\View;

final class ChapterSynopsisController
{
    public function __construct(
        private Database $db,
        private View $view,
        private Csrf $csrf,
        private ProjectAccess $access
    ) {
    }

    public function create(User $user, string $projectId): Response
    {
        if ($this->findProject($projectId) === null || !$this->access->canEdit($user, $projectId)) {
            return $this->notFound($user);
        }
        return $this->page($user, 'modules/Writing/templates/synopsis_edit.php', [
            'title' => 'New chapter outline',
            'projectId' => $projectId,
            'projectTitle' => $this->projectTitle($projectId),
            'synopsis' => null,
            'isEdit' => false,
            'error' => null,
        ]);
    }

    public function store(User $user, string $projectId): Response
    {
        if ($this->findProject($projectId) === null || !$this->access->canEdit($user, $projectId)) {
            return $this->notFound($user);
        }
        $title = trim((string)($_POST['title'] ?? ''));
        $summary = trim((string)($_POST['summary'] ?? ''));
        $slugInput = trim((string)($_POST['slug'] ?? ''));
        $slug = slugify($slugInput ?: $title);
        $error = $this->validate($title, $slug);
        if ($error === null && $slugInput !== '' && $this->slugTaken($projectId, $slug)) {
            $error = 'A chapter outline with that slug already exists in this story.';
        }
        if ($error !== null) {
            return $this->formError($user, $projectId, null, $slugInput, $title, $summary, $error);
        }
        if ($slugInput === '') {
            $slug = $this->uniqueSlug($projectId, $slug);
        }
        $synopsisId = \Core\Auth::uuid4();
        $this->db->transaction(function (Database $db) use ($user, $projectId, $synopsisId, $slug, $title, $summary): void {
            $db->run(
                "INSERT INTO content_objects (id, type, slug, owner_id) VALUES (?, 'chapter_synopsis', ?, ?)",
                [$synopsisId, $slug, $user->id()]
            );
            $db->run(
                'INSERT INTO chapter_synopses (id, project_id, slug, title, summary_text) VALUES (?, ?, ?, ?, ?)',
                [$synopsisId, $projectId, $slug, $title, $summary]
            );
        });
        return Response::redirect('/writing/' . $projectId . '/synopses/' . rawurlencode($slug));
    }

    public function show(User $user, string $projectId, string $slug): Response
    {
        $synopsis = $this->findSynopsis($projectId, $slug);
        if ($synopsis === null || !$this->access->canAccess($user, $projectId)) {
            return $this->notFound($user);
        }
        return $this->page($user, 'modules/Writing/templates/synopsis.php', [
            'title' => 'Chapter outline: ' . (string)$synopsis['title'],
            'projectId' => $projectId,
            'projectTitle' => $this->projectTitle($projectId),
            'synopsis' => $synopsis,
            'chapter' => $this->assignedChapter($projectId, (string)$synopsis['id']),
            'canEdit' => $this->access->canEdit($user, $projectId),
            'error' => null,
        ]);
    }

    public function edit(User $user, string $projectId, string $slug): Response
    {
        $synopsis = $this->findSynopsis($projectId, $slug);
        if ($synopsis === null || !$this->access->canEdit($user, $projectId)) {
            return $this->notFound($user);
        }
        return $this->page($user, 'modules/Writing/templates/synopsis_edit.php', [
            'title' => 'Edit outline: ' . (string)$synopsis['title'],
            'projectId' => $projectId,
            'projectTitle' => $this->projectTitle($projectId),
            'synopsis' => $synopsis,
            'isEdit' => true,
            'error' => null,
        ]);
    }

    public function update(User $user, string $projectId, string $slug): Response
    {
        $synopsis = $this->findSynopsis($projectId, $slug);
        if ($synopsis === null || !$this->access->canEdit($user, $projectId)) {
            return $this->notFound($user);
        }
        $title = trim((string)($_POST['title'] ?? ''));
        $summary = trim((string)($_POST['summary'] ?? ''));
        if ($title === '') {
            return $this->formError($user, $projectId, $slug, (string)$synopsis['slug'], $title, $summary,
                'The title must not be empty.');
        }
        $this->db->run(
            'UPDATE chapter_synopses SET title = ?, summary_text = ? WHERE id = ?',
            [$title, $summary, (string)$synopsis['id']]
        );
        return Response::redirect('/writing/' . $projectId . '/synopses/' . rawurlencode((string)$synopsis['slug']));
    }

    public function destroy(User $user, string $projectId, string $slug): Response
    {
        $synopsis = $this->findSynopsis($projectId, $slug);
        if ($synopsis === null || !$this->access->canAccess($user, $projectId)) {
            return $this->notFound($user);
        }
        if (!$this->access->isOwner($user, $projectId)
            && (string)($synopsis['owner_id'] ?? '') !== $user->id()) {
            return $this->forbidden($user);
        }
        $chapter = $this->assignedChapter($projectId, (string)$synopsis['id']);
        if ($chapter !== null) {
            return $this->page($user, 'modules/Writing/templates/synopsis.php', [
                'title' => 'Chapter outline: ' . (string)$synopsis['title'],
                'projectId' => $projectId,
                'projectTitle' => $this->projectTitle($projectId),
                'synopsis' => $synopsis,
                'chapter' => $chapter,
                'canEdit' => $this->access->canEdit($user, $projectId),
                'error' => 'This outline is assigned to the written chapter "'
                    . (string)$chapter['title']
                    . '". Delete that chapter text first (or reassign it to another or a new outline) '
                    . 'before the outline can be deleted.',
            ], 422);
        }
        $this->db->transaction(function (Database $db) use ($synopsis): void {
            $db->run('DELETE FROM chapter_synopses WHERE id = ?', [(string)$synopsis['id']]);
            $db->run('DELETE FROM content_objects WHERE id = ?', [(string)$synopsis['id']]);
        });
        return Response::redirect('/writing/' . $projectId);
    }

    /**
     * Unassigned synopses of the project; when $extraId is given it is
     * included even if currently assigned (for the chapter edit dropdown).
     *
     * @return list<array{id: string, title: string}>
     */
    public function unassigned(string $projectId, ?string $extraId = null): array
    {
        return array_map(
            fn (array $row): array => ['id' => (string)$row['id'], 'title' => (string)$row['title']],
            $this->db->all(
                'SELECT s.id, s.title FROM chapter_synopses s
                 WHERE s.project_id = ?
                   AND (s.id = ? OR NOT EXISTS (SELECT 1 FROM chapters c WHERE c.chapter_synopsis_id = s.id))
                 ORDER BY s.created_at ASC, s.title ASC',
                [$projectId, $extraId ?? '']
            )
        );
    }

    /** @return array{id: string, title: string, summary_text: string}|null */
    public function findSynopsisRow(string $projectId, string $synopsisId): ?array
    {
        $row = $this->db->one(
            'SELECT id, title, summary_text FROM chapter_synopses WHERE project_id = ? AND id = ?',
            [$projectId, $synopsisId]
        );
        return $row === null ? null : [
            'id' => (string)$row['id'],
            'title' => (string)$row['title'],
            'summary_text' => (string)$row['summary_text'],
        ];
    }

    private function validate(string $title, string $slug): ?string
    {
        if ($title === '') {
            return 'The title must not be empty.';
        }
        if ($slug === '' || preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
            return 'Could not derive a slug from the title (a-z, 0-9, hyphen).';
        }
        return null;
    }

    private function slugTaken(string $projectId, string $slug): bool
    {
        return $this->db->one(
            'SELECT 1 FROM chapter_synopses WHERE project_id = ? AND slug = ?',
            [$projectId, $slug]
        ) !== null;
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

    /** @return array<string, mixed>|null */
    private function findSynopsis(string $projectId, string $slug): ?array
    {
        return $this->db->one(
            'SELECT s.id, s.slug, s.title, s.summary_text, o.owner_id
             FROM chapter_synopses s
             JOIN content_objects o ON o.id = s.id
             WHERE s.project_id = ? AND s.slug = ?',
            [$projectId, $slug]
        );
    }

    /** @return array<string, mixed>|null */
    private function assignedChapter(string $projectId, string $synopsisId): ?array
    {
        return $this->db->one(
            'SELECT c.slug, s.title
             FROM chapters c
             JOIN chapter_synopses s ON s.id = c.chapter_synopsis_id
             WHERE c.project_id = ? AND c.chapter_synopsis_id = ?',
            [$projectId, $synopsisId]
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

    private function formError(
        User $user,
        string $projectId,
        ?string $slug,
        string $slugInput,
        string $title,
        string $summary,
        string $error
    ): Response {
        return $this->page($user, 'modules/Writing/templates/synopsis_edit.php', [
            'title' => $slug !== null ? 'Edit outline' : 'New chapter outline',
            'projectId' => $projectId,
            'projectTitle' => $this->projectTitle($projectId),
            'synopsis' => ['slug' => $slugInput, 'title' => $title, 'summary_text' => $summary],
            'isEdit' => $slug !== null,
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
            'message' => 'Chapter outline not found.',
        ], 404);
    }

    private function forbidden(User $user): Response
    {
        return $this->page($user, 'templates/error.php', [
            'title' => '403',
            'code' => 403,
            'message' => 'You may only delete chapter outlines you created yourself.',
        ], 403);
    }
}
