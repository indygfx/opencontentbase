<?php
declare(strict_types=1);

namespace Writing;

use Core\Auth;
use Core\Csrf;
use Core\Database;
use Core\Diff;
use Core\Response;
use Core\User;
use Core\View;

/**
 * Revision history for Writing objects (chapters, characters,
 * backgrounds): newest-first list, inline diff against the predecessor
 * (or any other revision via the compare form), and restore.
 *
 * Every save already creates a row in content_revisions; this
 * controller only reads that data. Restore NEVER rewrites history - it
 * inserts a NEW revision with the old content, authored by the
 * restorer, so the restore action itself stays visible in the history.
 */
final class RevisionController
{
    private const TYPES = ['chapter', 'character', 'background'];

    public function __construct(
        private Database $db,
        private View $view,
        private Csrf $csrf,
        private ProjectAccess $access
    ) {
    }

    /**
     * GET /writing/{id}/{type}s/{slug}/revisions
     * Newest-first table: revision, author, timestamp, +/- counts vs.
     * the predecessor, "current" badge on the latest revision.
     */
    public function index(User $user, string $projectId, string $type, string $slug): Response
    {
        $object = $this->findObject($projectId, $type, $slug);
        if ($object === null || !$this->access->canAccess($user, $projectId)) {
            return $this->notFound($user);
        }

        $revisions = $this->revisions((string)$object['object_id']);
        $rows = [];
        $count = count($revisions);
        foreach ($revisions as $i => $revision) {
            $isLatest = $i === 0;
            if ($isLatest) {
                $added = 0;
                $removed = 0;
            } else {
                $stats = Diff::stats((string)$revisions[$i - 1]['body'], (string)$revision['body']);
                $added = $stats['added'];
                $removed = $stats['removed'];
            }
            $rows[] = [
                'id' => (string)$revision['id'],
                'title' => (string)$revision['title'],
                'author' => (string)($revision['author'] ?? ''),
                'created_at' => (string)$revision['created_at'],
                'added' => $added,
                'removed' => $removed,
                'isLatest' => $isLatest,
                'isOldest' => $i === $count - 1,
            ];
        }

        $notice = trim((string)($_GET['notice'] ?? ''));

        return $this->page($user, 'modules/Writing/templates/revisions.php', [
            'title' => t('writing', 'history_title', ['title' => (string)$object['title']]),
            'projectId' => $projectId,
            'projectTitle' => $this->projectTitle($projectId),
            'type' => $type,
            'slug' => $slug,
            'objectTitle' => (string)$object['title'],
            'rows' => $rows,
            'revisionIds' => array_column($rows, 'id'),
            'canEdit' => $this->access->canEdit($user, $projectId),
            'notice' => $notice === '' ? null : $notice,
        ]);
    }

    /**
     * GET .../revisions/{revisionId} - inline diff vs. the predecessor
     * (the oldest revision diffs against empty text).
     */
    public function show(User $user, string $projectId, string $type, string $slug, string $revisionId): Response
    {
        $object = $this->findObject($projectId, $type, $slug);
        if ($object === null || !$this->access->canAccess($user, $projectId)) {
            return $this->notFound($user);
        }
        $revision = $this->findRevision((string)$object['object_id'], $revisionId);
        if ($revision === null) {
            return $this->notFound($user);
        }
        $predecessor = $this->predecessor((string)$object['object_id'], $revision);
        $from = $predecessor ?? [
            'id' => '',
            'title' => '',
            'body' => '',
            'created_at' => '',
            'author' => null,
        ];
        return $this->diffResponse($user, $projectId, $type, $slug, $object, $from, $revision);
    }

    /**
     * GET .../revisions/compare?from=A&to=B - both ids must belong to
     * the same object, otherwise 404.
     */
    public function compare(User $user, string $projectId, string $type, string $slug): Response
    {
        $object = $this->findObject($projectId, $type, $slug);
        if ($object === null || !$this->access->canAccess($user, $projectId)) {
            return $this->notFound($user);
        }
        $fromId = trim((string)($_GET['from'] ?? ''));
        $toId = trim((string)($_GET['to'] ?? ''));
        if ($fromId === '' || $toId === '') {
            return $this->notFound($user);
        }
        $from = $this->findRevision((string)$object['object_id'], $fromId);
        $to = $this->findRevision((string)$object['object_id'], $toId);
        if ($from === null || $to === null) {
            return $this->notFound($user);
        }
        return $this->diffResponse($user, $projectId, $type, $slug, $object, $from, $to);
    }

    /**
     * POST .../revisions/{revisionId}/restore - creates a NEW revision
     * with the old content, authored by the restorer. Restoring the
     * latest revision is a no-op redirect with an info message.
     * CSRF is enforced globally by App for every POST route.
     */
    public function restore(User $user, string $projectId, string $type, string $slug, string $revisionId): Response
    {
        $object = $this->findObject($projectId, $type, $slug);
        if ($object === null || !$this->access->canEdit($user, $projectId)) {
            return $this->notFound($user);
        }
        $revision = $this->findRevision((string)$object['object_id'], $revisionId);
        if ($revision === null) {
            return $this->notFound($user);
        }

        if ((string)$revision['id'] === $this->latestRevisionId((string)$object['object_id'])) {
            return Response::redirect($this->historyUrl($projectId, $type, $slug, t('writing', 'history_restore_noop')));
        }

        $this->db->run(
            'INSERT INTO content_revisions (id, object_id, title, summary, body, author_id)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                Auth::uuid4(),
                (string)$object['object_id'],
                (string)$revision['title'],
                (string)($revision['summary'] ?? ''),
                (string)$revision['body'],
                $user->id(),
            ]
        );
        return Response::redirect($this->historyUrl($projectId, $type, $slug, t('writing', 'history_restore_done')));
    }

    private function historyUrl(string $projectId, string $type, string $slug, ?string $notice = null): string
    {
        $url = '/writing/' . rawurlencode($projectId) . '/' . $type . 's/' . rawurlencode($slug) . '/revisions';
        return $notice === null ? $url : $url . '?notice=' . urlencode($notice);
    }

    /**
     * Shared renderer for the diff view (predecessor diff and compare).
     *
     * @param array<string, mixed> $object
     * @param array<string, mixed> $from
     * @param array<string, mixed> $to
     */
    private function diffResponse(
        User $user,
        string $projectId,
        string $type,
        string $slug,
        array $object,
        array $from,
        array $to
    ): Response {
        $titleChanged = (string)$from['title'] !== (string)$to['title'];
        return $this->page($user, 'modules/Writing/templates/revision_diff.php', [
            'title' => t('writing', 'history_diff_title', ['title' => (string)$object['title']]),
            'projectId' => $projectId,
            'projectTitle' => $this->projectTitle($projectId),
            'type' => $type,
            'slug' => $slug,
            'objectTitle' => (string)$object['title'],
            'from' => $this->meta($from),
            'to' => $this->meta($to),
            'segments' => Diff::compare((string)$from['body'], (string)$to['body']),
            'titleChanged' => $titleChanged,
            'fromTitle' => (string)$from['title'],
            'toTitle' => (string)$to['title'],
        ]);
    }

    /** @param array<string, mixed> $revision @return array<string, string> */
    private function meta(array $revision): array
    {
        return [
            'id' => (string)$revision['id'],
            'title' => (string)$revision['title'],
            'author' => (string)($revision['author'] ?? ''),
            'created_at' => (string)$revision['created_at'],
        ];
    }

    /**
     * The revision directly before the given one (rowid tiebreak for
     * identical timestamps), or null for the oldest revision.
     *
     * @param array<string, mixed> $revision
     * @return array<string, mixed>|null
     */
    private function predecessor(string $objectId, array $revision): ?array
    {
        return $this->db->one(
            'SELECT r.id, r.title, r.body, r.created_at, u.username AS author
             FROM content_revisions r
             LEFT JOIN users u ON u.id = r.author_id
             WHERE r.object_id = ?
               AND (r.created_at < ? OR (r.created_at = ? AND r.rowid < ?))
             ORDER BY r.created_at DESC, r.rowid DESC
             LIMIT 1',
            [$objectId, $revision['created_at'], $revision['created_at'], $revision['rowid']]
        );
    }

    /** @return list<array<string, mixed>> revisions newest-first */
    private function revisions(string $objectId): array
    {
        return $this->db->all(
            'SELECT r.id, r.title, r.body, r.created_at, r.rowid, u.username AS author
             FROM content_revisions r
             LEFT JOIN users u ON u.id = r.author_id
             WHERE r.object_id = ?
             ORDER BY r.created_at DESC, r.rowid DESC',
            [$objectId]
        );
    }

    /** @return array<string, mixed>|null */
    private function findRevision(string $objectId, string $revisionId): ?array
    {
        return $this->db->one(
            'SELECT r.id, r.title, r.summary, r.body, r.created_at, r.rowid, u.username AS author
             FROM content_revisions r
             LEFT JOIN users u ON u.id = r.author_id
             WHERE r.object_id = ? AND r.id = ?',
            [$objectId, $revisionId]
        );
    }

    private function latestRevisionId(string $objectId): string
    {
        $row = $this->db->one(
            'SELECT id FROM content_revisions WHERE object_id = ?
             ORDER BY created_at DESC, rowid DESC LIMIT 1',
            [$objectId]
        );
        return $row === null ? '' : (string)$row['id'];
    }

    /**
     * Resolves a Writing object (chapter/character/background) by type,
     * slug and project, with its object id and current title. Synopses
     * are excluded (no history route for them).
     *
     * @return array<string, mixed>|null
     */
    private function findObject(string $projectId, string $type, string $slug): ?array
    {
        if (!in_array($type, self::TYPES, true)) {
            return null;
        }
        $table = $type === 'chapter' ? 'chapters' : ($type === 'character' ? 'characters' : 'backgrounds');
        return $this->db->one(
            "SELECT o.id AS object_id, o.slug,
                    COALESCE(r.title, o.slug) AS title
             FROM content_objects o
             JOIN {$table} t ON t.id = o.id
             LEFT JOIN content_revisions r ON r.object_id = o.id
              AND r.created_at = (
                  SELECT MAX(r2.created_at) FROM content_revisions r2 WHERE r2.object_id = o.id
              )
             WHERE o.type = ? AND o.slug = ? AND t.project_id = ?",
            [$type, $slug, $projectId]
        );
    }

    private function projectTitle(string $projectId): string
    {
        $project = $this->db->one('SELECT title FROM projects WHERE id = ?', [$projectId]);
        return $project === null ? '' : (string)$project['title'];
    }

    private function notFound(User $user): Response
    {
        return $this->page($user, 'templates/error.php', [
            'title' => '404',
            'code' => 404,
            'message' => t('writing', 'history_not_found'),
        ], 404);
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
}
