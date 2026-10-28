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
    private ProjectProgress $progress;
    private ImageStore $images;

    public function __construct(
        private Database $db,
        private View $view,
        private Csrf $csrf,
        private ProjectAccess $access,
        private ContentRenderer $renderer,
        private string $basePath = ''
    ) {
        $this->relationships = new RelationshipController($db, $view, $csrf, $access);
        $this->progress = new ProjectProgress($db);
        $this->images = new ImageStore($basePath);
    }

    public function index(User $user): Response
    {
        $slider = new StorySlider($this->db);
        return $this->page($user, 'modules/Writing/templates/index.php', [
            'title' => t('writing', 'stories_title'),
            'projects' => $this->access->visibleProjects($user),
            'slides' => $slider->slides($user, (string)($_COOKIE['cb_csrf'] ?? '')),
            'error' => null,
            'success' => null,
        ]);
    }

    public function store(User $user): Response
    {
        $title = trim((string)($_POST['title'] ?? ''));
        if ($title === '' || mb_strlen($title) < 2) {
            return $this->indexError($user, t('writing', 'error_title_length'));
        }
        if (mb_strlen($title) > 200) {
            return $this->indexError($user, t('writing', 'error_title_max'));
        }
        $id = $this->uuid4();
        $this->db->run(
            'INSERT INTO projects (id, owner_id, title, created_at) VALUES (?, ?, ?, ?)',
            [$id, $user->id(), $title, gmdate('Y-m-d H:i:s')]
        );
        $this->db->run(
            'INSERT INTO project_targets (project_id, target_words_total) VALUES (?, ?)',
            [$id, ProjectProgress::genreWordTarget('General')]
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
        $importSuccess = trim((string)($_GET['import'] ?? ''));
        return $this->page($user, 'modules/Writing/templates/project.php', [
            'title' => (string)$project['title'],
            'project' => $project,
            'importSuccess' => $importSuccess === '' ? null : $importSuccess,
            'isOwner' => $isOwner,
            'members' => $this->access->members($id),
            'shareTargets' => $isOwner ? $this->shareTargets($id, (string)$project['owner_id']) : [],
            'chapters' => $this->chapters($id),
            'synopses' => $this->synopses($id),
            'characters' => $this->characters($id),
            'backgrounds' => $this->backgrounds($id),
            'relations' => $this->relationships->list($id),
            'progress' => $this->progress->calculate($id),
            'overall' => $this->progress->overall($id),
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
            'title' => t('writing', 'setup_title', ['title' => (string)$project['title']]),
            'project' => $project,
            'members' => $this->access->members($id),
            'shareTargets' => $this->shareTargets($id, (string)$project['owner_id']),
            'targets' => $this->progress->targets($id),
            'genres' => ProjectProgress::GENRES,
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
                'title' => t('writing', 'setup_title', ['title' => (string)$project['title']]),
                'project' => $project,
                'members' => $this->access->members($id),
                'shareTargets' => $this->shareTargets($id, (string)$project['owner_id']),
                'targets' => $this->progress->targets($id),
                'genres' => ProjectProgress::GENRES,
                'error' => t('writing', 'error_title_length'),
            ], 422);
        }
        $this->db->run('UPDATE projects SET title = ? WHERE id = ?', [$title, $id]);
        return Response::redirect('/writing/' . $id . '/setup');
    }

    public function updateTargets(User $user, string $id): Response
    {
        $project = $this->findProject($id);
        if ($project === null) {
            return $this->notFound($user);
        }
        if (!$this->access->isOwner($user, $id)) {
            return $this->forbidden($user);
        }
        $genre = (string)($_POST['genre'] ?? 'General');
        if (!in_array($genre, ProjectProgress::GENRES, true)) {
            $genre = 'General';
        }
        $fields = [
            'target_chapters' => 1,
            'target_words_total' => 1,
            'target_words_blurb' => 1,
            'target_words_synopsis' => 1,
            'target_min_characters' => 1,
            'target_min_relations' => 1,
            'target_backgrounds' => 1,
        ];
        $values = [];
        $error = null;
        foreach ($fields as $name => $min) {
            $value = filter_var($_POST[$name] ?? null, FILTER_VALIDATE_INT);
            if ($value === false || $value < $min || $value > 10000000) {
                $error = t('writing', 'error_targets_positive');
                break;
            }
            $values[$name] = $value;
        }
        if ($error !== null) {
            return $this->page($user, 'modules/Writing/templates/setup.php', [
                'title' => t('writing', 'setup_title', ['title' => (string)$project['title']]),
                'project' => $project,
                'members' => $this->access->members($id),
                'shareTargets' => $this->shareTargets($id, (string)$project['owner_id']),
                'targets' => $this->progress->targets($id),
                'genres' => ProjectProgress::GENRES,
                'error' => $error,
            ], 422);
        }
        $this->db->run('UPDATE projects SET genre = ? WHERE id = ?', [$genre, $id]);
        $sets = implode(' = ?, ', array_keys($values)) . ' = ?';
        $columns = implode(', ', array_keys($values));
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $this->db->run(
            "INSERT INTO project_targets (project_id, {$columns}, updated_at)
             VALUES (?, {$placeholders}, CURRENT_TIMESTAMP)
             ON CONFLICT(project_id) DO UPDATE SET {$sets}, updated_at = CURRENT_TIMESTAMP",
            [$id, ...array_values($values), ...array_values($values)]
        );
        return Response::redirect('/writing/' . $id);
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
            return $this->projectError($user, $project, t('writing', 'error_share_user_missing', ['username' => $username]), 422);
        }
        if ((string)$target['id'] === $user->id()) {
            return $this->projectError($user, $project, t('writing', 'error_share_self'), 422);
        }
        $existing = $this->db->one(
            'SELECT user_id FROM project_members WHERE project_id = ? AND user_id = ?',
            [$id, (string)$target['id']]
        );
        if ($existing !== null) {
            return $this->projectError($user, $project, t('writing', 'error_share_duplicate'), 422);
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
            "SELECT p.id, p.owner_id, p.title, p.idea, p.blurb, p.synopsis, p.synopsis_long, p.cover, p.created_at,
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
        $importSuccess = trim((string)($_GET['import'] ?? ''));
        return $this->page($user, 'modules/Writing/templates/project.php', [
            'title' => (string)$project['title'],
            'project' => $project,
            'importSuccess' => $importSuccess === '' ? null : $importSuccess,
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
        $slider = new StorySlider($this->db);
        return $this->page($user, 'modules/Writing/templates/index.php', [
            'title' => t('writing', 'stories_title'),
            'projects' => $this->access->visibleProjects($user),
            'slides' => $slider->slides($user, (string)($_COOKIE['cb_csrf'] ?? '')),
            'error' => $error,
            'success' => null,
        ], 422);
    }

    public function uploadCover(User $user, string $id): Response
    {
        $project = $this->findProject($id);
        if ($project === null) {
            return $this->notFound($user);
        }
        if (!$this->access->isOwner($user, $id)) {
            return $this->forbidden($user);
        }
        $file = $_FILES['cover'] ?? null;
        $ext = $file !== null && is_array($file) ? $this->images->validateUpload($file) : null;
        $error = match (true) {
            $file === null || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                => t('writing', 'error_no_file'),
            ($file['size'] ?? 0) > ImageStore::MAX_BYTES
                => t('writing', 'error_cover_too_big'),
            $ext === null
                => t('writing', 'error_cover_type'),
            default => null,
        };
        if ($error !== null) {
            return $this->page($user, 'modules/Writing/templates/setup.php', [
                'title' => t('writing', 'setup_title', ['title' => (string)$project['title']]),
                'project' => $project,
                'members' => $this->access->members($id),
                'shareTargets' => $this->shareTargets($id, (string)$project['owner_id']),
                'targets' => $this->progress->targets($id),
                'genres' => ProjectProgress::GENRES,
                'error' => $error,
            ], 422);
        }
        $this->deleteCoverFile($id);
        $name = $id . '.' . $ext;
        $this->images->store($file, 'covers', $name);
        $this->db->run('UPDATE projects SET cover = ? WHERE id = ?', [$name, $id]);
        return Response::redirect('/writing/' . $id . '/setup');
    }

    public function removeCover(User $user, string $id): Response
    {
        $project = $this->findProject($id);
        if ($project === null || !$this->access->canAccess($user, $id)) {
            return $this->notFound($user);
        }
        if (!$this->access->isOwner($user, $id)) {
            return $this->forbidden($user);
        }
        $this->deleteCoverFile($id);
        $this->db->run("UPDATE projects SET cover = '' WHERE id = ?", [$id]);
        return Response::redirect('/writing/' . $id . '/setup');
    }

    public function cover(User $user, string $id): Response
    {
        $project = $this->findProject($id);
        if ($project === null || !$this->access->canAccess($user, $id)) {
            return $this->notFound($user);
        }
        $name = (string)$project['cover'];
        if ($name === '') {
            return $this->notFound($user);
        }
        $file = $this->images->path('covers', $name);
        $mime = $file === null ? null : $this->images->contentType('covers', $name);
        if ($mime === null) {
            return $this->notFound($user);
        }
        return new Response(
            (string)file_get_contents($file),
            200,
            ['Content-Type' => $mime]
        );
    }

    public function coverUrl(string $projectId, string $cover): string
    {
        return $cover === '' ? '' : '/writing/' . rawurlencode($projectId) . '/cover';
    }

    private function deleteCoverFile(string $id): void
    {
        $row = $this->db->one('SELECT cover FROM projects WHERE id = ?', [$id]);
        $name = (string)($row['cover'] ?? '');
        if ($name !== '') {
            $this->images->delete('covers', $name);
        }
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
            'message' => t('writing', 'story_not_found'),
        ], 404);
    }

    private function forbidden(User $user): Response
    {
        return $this->page($user, 'templates/error.php', [
            'title' => '403',
            'code' => 403,
            'message' => t('writing', 'owner_only'),
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
