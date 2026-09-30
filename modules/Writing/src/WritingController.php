<?php
declare(strict_types=1);

namespace Writing;

use Core\Csrf;
use Core\Database;
use Core\Response;
use Core\User;
use Core\View;

final class WritingController
{
    public function __construct(
        private Database $db,
        private View $view,
        private Csrf $csrf,
        private ProjectAccess $access
    ) {
    }

    public function index(User $user): Response
    {
        return $this->page($user, 'modules/Writing/templates/index.php', [
            'title' => 'Projects',
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
            'error' => null,
            'success' => null,
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
            return $this->projectError($user, $project, 'The title must be at least 2 characters long.', 422);
        }
        $this->db->run('UPDATE projects SET title = ? WHERE id = ?', [$title, $id]);
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
            return $this->projectError($user, $project, 'You cannot share a project with yourself.', 422);
        }
        $existing = $this->db->one(
            'SELECT user_id FROM project_members WHERE project_id = ? AND user_id = ?',
            [$id, (string)$target['id']]
        );
        if ($existing !== null) {
            return $this->projectError($user, $project, 'This project is already shared with that user.', 422);
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

    /** @return array<string, mixed>|null */
    private function findProject(string $id): ?array
    {
        return $this->db->one(
            'SELECT id, owner_id, title, created_at FROM projects WHERE id = ?',
            [$id]
        );
    }

    /** @param array<string, mixed> $project */
    private function projectError(User $user, array $project, string $error, int $status): Response
    {
        return $this->page($user, 'modules/Writing/templates/project.php', [
            'title' => (string)$project['title'],
            'project' => $project,
            'isOwner' => $this->access->isOwner($user, (string)$project['id']),
            'members' => $this->access->members((string)$project['id']),
            'error' => $error,
            'success' => null,
        ], $status);
    }

    private function indexError(User $user, string $error): Response
    {
        return $this->page($user, 'modules/Writing/templates/index.php', [
            'title' => 'Projects',
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
            'message' => 'Project not found.',
        ], 404);
    }

    private function forbidden(User $user): Response
    {
        return $this->page($user, 'templates/error.php', [
            'title' => '403',
            'code' => 403,
            'message' => 'Only the project owner may do that.',
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
