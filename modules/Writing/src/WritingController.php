<?php

declare(strict_types=1);

namespace Writing;

use Core\ContentRenderer;
use Core\Csrf;
use Core\Database;
use Core\Response;
use Core\User;
use Core\View;

final class WritingController
{
    private const OUTLINE_FIELDS = [
        'idea' => 'Idea',
        'blurb' => 'Short Description',
        'synopsis_long' => 'Extended Synopsis',
    ];

    private RelationshipController $relationships;

    public function __construct(
        private Database $db,
        private View $view,
        private Csrf $csrf,
        private ProjectAccess $access,
        private ContentRenderer $renderer
    ) {
        $this->relationships = new RelationshipController($db, $view, $csrf, $access);
    }

    public function index(User $user): Response
    {
        return $this->page($user, 'modules/Writing/templates/index.php', [
            'title' => 'Stories',
            'projects' => $this->access->visibleProjects($user),
            'error' => null,
            'success' => null,
        ]);
    }

    public function store(User $user): Response
    {
        $title = trim((string)($_POST['title'] ?? ''));
        if ($title === '' || mb_strlen($title) < 2) {
            return $this->indexError($user, 'The title must be at least 2 characters long.');
        }
        if (mb_strlen($title) > 200) {
            return $this->indexError($user, 'The title must not exceed 200 characters.');
        }
        $id = $this->uuid4();
        $this->db->run(
            'INSERT INTO projects (id, owner_id, title, created_at) VALUES (?, ?, ?, ?)',
            [$id, $user->id(), $title, gmdate('Y-m-d H:i:s')]
        );
        return Response::redirect('/writing/' . $id);
    }

    public function show(User $user, string $id): Response
    {
        $project = $this->findProject($id);
        if ($project === null || !$this->access->canAccess($user, $id)) {
            return $this->notFound($user);
        }
        $isOwner = $this->access->isOwner($user, $id);
        return $this->page($user, 'modules/Writing/templates/project.php', [
            'title' => (string)$project['title'],
            'project' => $project,
            'isOwner' => $isOwner,
            'members' => $this->access->members($id),
            'shareTargets' => $isOwner ? $this->shareTargets($id, (string)$project['owner_id']) : [],
            'chapters' => $this->chapters($id),
            'synopses' => $this->synopses($id),
            'characters' => $this->characters($id),
            'backgrounds' => $this->backgrounds($id),
            'relations' => $this->relationships->list($id),
            'error' => null,
            'success' => null,
        ]);
    }

    public function setup(User $user, string $id): Response
    {
        $project = $this->findProject($id);
        if ($project === null || !$this->access->canAccess($user, $id)) {
            return $this->notFound($user);
        }
        if (!$this->access->isOwner($user, $id)) {
            return $this->forbidden($user);
        }
        return $this->page($user, 'modules/Writing/templates/setup.php', [
            'title' => 'Novel Setup: ' . (string)$project['title'],
            'project' => $project,
            'members' => $this->access->members($id),
            'shareTargets' => $this->shareTargets($id, (string)$project['owner_id']),
            'error' => null,
        ]);
    }

    public function update(User $user, string $id): Response
    {
        $project = $this->findProject($id);
        if ($project === null || !$this->access->canAccess($user, $id)) {
            return $this->notFound($user);
        }
        if (!$this->access->isOwner($user, $id)) {
            return $this->forbidden($user);
        }
        $title = trim((string)($_POST['title'] ?? ''));
        if ($title === '' || mb_strlen($title) < 2) {
            return $this->page($user, 'modules/Writing/templates/setup.php', [
                'title' => 'Novel Setup: ' . (string)$project['title'],
                'project' => $project,
                'members' => $this->access->members($id),
                'shareTargets' => $this->shareTargets($id, (string)$project['owner_id']),
                'error' => 'The title must be at least 2 characters long.',
            ], 422);
        }
        $this->db->run('UPDATE projects SET title = ? WHERE id = ?', [$title, $id]);
        return Response::redirect('/writing/' . $id . '/setup');
    }

    public function outlineEdit(User $user, string $id, string $field): Response
    {
        if (!isset(self::OUTLINE_FIELDS[$field])) {
            return $this->notFound($user);
        }
        $project = $this->findProject($id);
        if ($project === null || !$this->access->canAccess($user, $id)) {
            return $this->notFound($user);
        }
        if (!$this->access->canEdit($user, $id)) {
            return $this->forbidden($user);
        }
        return $this->page($user, 'modules/Writing/templates/outline_edit.php', [
            'title' => self::OUTLINE_FIELDS[$field] . ': ' . (string)$project['title'],
            'project' => $project,
            'field' => $field,
            'label' => self::OUTLINE_FIELDS[$field],
            'text' => (string)$project[$field],
            'error' => null,
        ]);
    }

    public function outlineUpdate(User $user, string $id, string $field): Response
    {
        if (!isset(self::OUTLINE_FIELDS[$field])) {
            return $this->notFound($user);
        }
        $project = $this->findProject($id);
        if ($project === null || !$this->access->canAccess($user, $id)) {
            return $this->notFound($user);
        }
        if (!$this->access->canEdit($user, $id)) {
            return $this->forbidden($user);
        }
        $text = trim((string)($_POST['text'] ?? ''));
        $this->db->run("UPDATE projects SET {$field} = ? WHERE id = ?", [$text, $id]);
        return Response::redirect('/writing/' . $id);
    }

    public function destroy(User $user, string $id): Response
    {
        $project = $this->findProject($id);
        if ($project === null || !$this->access->canAccess($user, $id)) {
            return $this->notFound($user);
        }
        if (!$this->access->isOwner($user, $id)) {
            return $this->forbidden($user);
        }
        $this->db->run('DELETE FROM project_members WHERE project_id = ?', [$id]);
        $this->db->run('DELETE FROM projects WHERE id = ?', [$id]);
        return Response::redirect('/writing');
    }

    public function share(User $user, string $id): Response
    {
        $project = $this->findProject($id);
        if ($project === null || !$this->access->isOwner($user, $id)) {
            return $this->notFound($user);
        }
        $username = trim((string)($_POST['username'] ?? ''));
        $target = $this->db->one('SELECT id, username FROM users WHERE username = ?', [$username]);
        if ($target === null) {
            return $this->projectError($user, $project, "User \"{$username}\" does not exist.", 422);
        }
        if ((string)$target['id'] === $user->id()) {
            return $this->projectError($user, $project, 'You cannot share a story with yourself.', 422);
        }
        $existing = $this->db->one(
            'SELECT user_id FROM project_members WHERE project_id = ? AND user_id = ?',
            [$id, (string)$target['id']]
        );
        if ($existing !== null) {
            return $this->projectError($user, $project, 'This story is already shared with that user.', 422);
        }
        $this->db->run(
            'INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, ?)',
            [$id, (string)$target['id'], 'member']
        );
        return Response::redirect('/writing/' . $id);
    }

    public function unshare(User $user, string $id): Response
    {
        $project = $this->findProject($id);
        if ($project === null || !$this->access->isOwner($user, $id)) {
            return $this->notFound($user);
        }
        $memberUserId = (string)($_POST['user_id'] ?? '');
        $this->db->run(
            'DELETE FROM project_members WHERE project_id = ? AND user_id = ?',
            [$id, $memberUserId]
        );
        return Response::redirect('/writing/' . $id);
    }

    /** @return list<array{slug: string, title: string}> */
    private function backgrounds(string $projectId): array
    {
        return $this->db->all(
            "SELECT o.slug, COALESCE(r.title, o.slug) AS title
             FROM content_objects o
             JOIN backgrounds b ON b.id = o.id
             LEFT JOIN content_revisions r ON r.object_id = o.id
              AND r.created_at = (
                  SELECT MAX(r2.created_at) FROM content_revisions r2 WHERE r2.object_id = o.id
              )
             WHERE o.type = 'background' AND b.project_id = ?
             ORDER BY r.created_at ASC, o.slug ASC",
            [$projectId]
        );
    }

    /** @return list<array{slug: string, title: string}> */
    private function characters(string $projectId): array
    {
        return $this->db->all(
            "SELECT o.slug, COALESCE(r.title, o.slug) AS title
             FROM content_objects o
             JOIN characters k ON k.id = o.id
             LEFT JOIN content_revisions r ON r.object_id = o.id
              AND r.created_at = (
                  SELECT MAX(r2.created_at) FROM content_revisions r2 WHERE r2.object_id = o.id
              )
             WHERE o.type = 'character' AND k.project_id = ?
             ORDER BY r.created_at ASC, o.slug ASC",
            [$projectId]
        );
    }

    /** @return list<array{slug: string, title: string, summary: string, body: string}> */
    private function chapters(string $projectId): array
    {
        return $this->db->all(
            "SELECT o.slug, COALESCE(s.title, o.slug) AS title,
                    COALESCE(r.body, '') AS body
             FROM content_objects o
             JOIN chapters c ON c.id = o.id
             LEFT JOIN chapter_synopses s ON s.id = c.chapter_synopsis_id
             LEFT JOIN content_revisions r ON r.object_id = o.id
              AND r.created_at = (
                  SELECT MAX(r2.created_at) FROM content_revisions r2 WHERE r2.object_id = o.id
              )
             WHERE o.type = 'chapter' AND c.project_id = ?
             ORDER BY c.created_at ASC, o.slug ASC",
            [$projectId]
        );
    }

    /** @return list<array<string, mixed>> */
    private function synopses(string $projectId): array
    {
        return $this->db->all(
            'SELECT s.slug, s.title, s.summary_text,
                    (SELECT c.slug FROM chapters c WHERE c.chapter_synopsis_id = s.id) AS chapter_slug
             FROM chapter_synopses s
             WHERE s.project_id = ?
             ORDER BY s.created_at ASC, s.title ASC',
            [$projectId]
        );
    }

    /** @return list<array{id: string, username: string}> */
    private function shareTargets(string $projectId, string $ownerId): array
    {
        return $this->db->all(
            'SELECT id, username FROM users
             WHERE id <> ?
               AND id NOT IN (SELECT user_id FROM project_members WHERE project_id = ?)
             ORDER BY username',
            [$ownerId, $projectId]
        );
    }

    /** @return array<string, mixed>|null */
    private function findProject(string $id): ?array
    {
        return $this->db->one(
            "SELECT p.id, p.owner_id, p.title, p.idea, p.blurb, p.synopsis, p.synopsis_long, p.created_at,
                    u.username AS owner_name
             FROM projects p JOIN users u ON u.id = p.owner_id WHERE p.id = ?",
            [$id]
        );
    }

    /** @param array<string, mixed> $project */
    private function projectError(User $user, array $project, string $error, int $status): Response
    {
        $id = (string)$project['id'];
        $isOwner = $this->access->isOwner($user, $id);
        return $this->page($user, 'modules/Writing/templates/project.php', [
            'title' => (string)$project['title'],
            'project' => $project,
            'isOwner' => $isOwner,
            'members' => $this->access->members($id),
            'shareTargets' => $isOwner ? $this->shareTargets($id, (string)$project['owner_id']) : [],
            'chapters' => $this->chapters($id),
            'synopses' => $this->synopses($id),
            'characters' => $this->characters($id),
            'backgrounds' => $this->backgrounds($id),
            'relations' => $this->relationships->list($id),
            'error' => $error,
            'success' => null,
        ], $status);
    }

    private function indexError(User $user, string $error): Response
    {
        return $this->page($user, 'modules/Writing/templates/index.php', [
            'title' => 'Stories',
            'projects' => $this->access->visibleProjects($user),
            'error' => $error,
            'success' => null,
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
            'message' => 'Story not found.',
        ], 404);
    }

    private function forbidden(User $user): Response
    {
        return $this->page($user, 'templates/error.php', [
            'title' => '403',
            'code' => 403,
            'message' => 'Only the story owner may do that.',
        ], 403);
    }

    private function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
