<?php

declare(strict_types=1);

namespace Writing;

use Core\Csrf;
use Core\Database;
use Core\Response;
use Core\User;
use Core\View;

final class RelationshipController
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
        if (count($this->characterOptions($projectId)) < 2) {
            return $this->page($user, 'modules/Writing/templates/relationship_form.php', [
                'title' => t('writing', 'new_relationship'),
                'projectId' => $projectId,
                'projectTitle' => $this->projectTitle($projectId),
                'relation' => null,
                'characterOptions' => [],
                'kinds' => RelationKinds::allKinds(),
                'stances' => Stances::allStances(),
                'error' => null,
                'notice' => t('writing', 'notice_relation_characters_missing'),
            ]);
        }
        return $this->page($user, 'modules/Writing/templates/relationship_form.php', [
            'title' => t('writing', 'new_relationship'),
            'projectId' => $projectId,
            'projectTitle' => $this->projectTitle($projectId),
            'relation' => null,
            'characterOptions' => $this->characterOptions($projectId),
            'kinds' => RelationKinds::allKinds(),
            'stances' => Stances::allStances(),
            'error' => null,
            'notice' => null,
        ]);
    }

    public function store(User $user, string $projectId): Response
    {
        if ($this->findProject($projectId) === null || !$this->access->canEdit($user, $projectId)) {
            return $this->notFound($user);
        }
        $fromId = (string)($_POST['from_character_id'] ?? '');
        $toId = (string)($_POST['to_character_id'] ?? '');
        $kind = (string)($_POST['kind'] ?? 'related');
        $stance = trim((string)($_POST['stance'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $error = $this->validate($projectId, $fromId, $toId, $kind, $stance, null);
        if ($error !== null) {
            return $this->formError($user, $projectId, null, $fromId, $toId, $kind, $stance, $description, $error);
        }
        $this->db->run(
            'INSERT INTO character_relations (id, project_id, from_character_id, to_character_id, kind, stance, description)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [\Core\Auth::uuid4(), $projectId, $fromId, $toId, $kind, $stance === '' ? null : $stance, $description]
        );
        return Response::redirect('/writing/' . $projectId . '#relationships');
    }

    public function edit(User $user, string $projectId, string $relationId): Response
    {
        if ($this->findProject($projectId) === null || !$this->access->canEdit($user, $projectId)) {
            return $this->notFound($user);
        }
        $relation = $this->findRelation($projectId, $relationId);
        if ($relation === null) {
            return $this->notFound($user);
        }
        return $this->page($user, 'modules/Writing/templates/relationship_form.php', [
            'title' => t('writing', 'edit_relationship'),
            'projectId' => $projectId,
            'projectTitle' => $this->projectTitle($projectId),
            'relation' => $relation,
            'characterOptions' => $this->characterOptions($projectId),
            'kinds' => RelationKinds::allKinds(),
            'stances' => Stances::allStances(),
            'error' => null,
            'notice' => null,
        ]);
    }

    public function update(User $user, string $projectId, string $relationId): Response
    {
        if ($this->findProject($projectId) === null || !$this->access->canEdit($user, $projectId)) {
            return $this->notFound($user);
        }
        $relation = $this->findRelation($projectId, $relationId);
        if ($relation === null) {
            return $this->notFound($user);
        }
        $fromId = (string)($_POST['from_character_id'] ?? '');
        $toId = (string)($_POST['to_character_id'] ?? '');
        $kind = (string)($_POST['kind'] ?? 'related');
        $stance = trim((string)($_POST['stance'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $error = $this->validate($projectId, $fromId, $toId, $kind, $stance, $relationId);
        if ($error !== null) {
            return $this->formError($user, $projectId, $relation, $fromId, $toId, $kind, $stance, $description, $error);
        }
        $this->db->run(
            'UPDATE character_relations
             SET from_character_id = ?, to_character_id = ?, kind = ?, stance = ?, description = ?
             WHERE id = ? AND project_id = ?',
            [$fromId, $toId, $kind, $stance === '' ? null : $stance, $description, $relationId, $projectId]
        );
        return Response::redirect('/writing/' . $projectId . '#relationships');
    }

    public function destroy(User $user, string $projectId): Response
    {
        if ($this->findProject($projectId) === null || !$this->access->canEdit($user, $projectId)) {
            return $this->notFound($user);
        }
        $relationId = (string)($_POST['relation_id'] ?? '');
        if ($this->findRelation($projectId, $relationId) === null) {
            return $this->notFound($user);
        }
        $this->db->run(
            'DELETE FROM character_relations WHERE id = ? AND project_id = ?',
            [$relationId, $projectId]
        );
        return Response::redirect('/writing/' . $projectId);
    }

    /** @return list<array{id: string, kind: string, description: string, from_id: string, from_title: string, to_id: string, to_title: string}> */
    public function list(string $projectId): array
    {
        return $this->db->all(
            "SELECT r.id, r.kind, r.stance, r.description,
                   f.id AS from_id, o1.slug AS from_slug, COALESCE(rf.title, o1.slug) AS from_title,
                   t.id AS to_id, o2.slug AS to_slug, COALESCE(rt.title, o2.slug) AS to_title
             FROM character_relations r
             JOIN characters f ON f.id = r.from_character_id
             JOIN content_objects o1 ON o1.id = f.id AND o1.type = 'character'
             JOIN characters t ON t.id = r.to_character_id
             JOIN content_objects o2 ON o2.id = t.id AND o2.type = 'character'
             LEFT JOIN content_revisions rf ON rf.object_id = f.id
              AND rf.created_at = (SELECT MAX(x.created_at) FROM content_revisions x WHERE x.object_id = f.id)
             LEFT JOIN content_revisions rt ON rt.object_id = t.id
              AND rt.created_at = (SELECT MAX(x.created_at) FROM content_revisions x WHERE x.object_id = t.id)
             WHERE r.project_id = ?
             ORDER BY from_title, to_title",
            [$projectId]
        );
    }

    /**
     * Direction-aware duplicate check: A loves B and B loves A are distinct,
     * but the exact same pair in the same direction (regardless of kind or
     * stance) is rejected. The stance is optional; an empty stance renders
     * as the neutral group.
     */
    private function validate(string $projectId, string $fromId, string $toId, string $kind, string $stance, ?string $ignoreId): ?string
    {
        if ($fromId === '' || $toId === '') {
            return t('writing', 'error_relation_characters_selected');
        }
        if ($fromId === $toId) {
            return t('writing', 'error_relation_self');
        }
        if (!in_array($kind, RelationKinds::allKinds(), true)) {
            return t('writing', 'error_relation_kind');
        }
        if ($stance !== '' && !in_array($stance, Stances::allStances(), true)) {
            return t('writing', 'error_relation_stance');
        }
        foreach ([$fromId, $toId] as $characterId) {
            if ($this->db->one(
                'SELECT id FROM characters WHERE id = ? AND project_id = ?',
                [$characterId, $projectId]
            ) === null) {
                return t('writing', 'error_relation_project');
            }
        }
        $sql = 'SELECT id FROM character_relations
                 WHERE project_id = ? AND from_character_id = ? AND to_character_id = ?';
        $params = [$projectId, $fromId, $toId];
        if ($ignoreId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $ignoreId;
        }
        $exists = $this->db->one($sql, $params);
        return $exists !== null
            ? t('writing', 'error_relation_duplicate')
            : null;
    }

    /** @return list<array{id: string, title: string}> */
    public function characterOptions(string $projectId): array
    {
        return $this->db->all(
            "SELECT k.id, COALESCE(r.title, o.slug) AS title
             FROM characters k
             JOIN content_objects o ON o.id = k.id AND o.type = 'character'
             LEFT JOIN content_revisions r ON r.object_id = k.id
              AND r.created_at = (SELECT MAX(x.created_at) FROM content_revisions x WHERE x.object_id = k.id)
             WHERE k.project_id = ?
             ORDER BY title",
            [$projectId]
        );
    }

    /** @return array<string, mixed>|null */
    private function findRelation(string $projectId, string $relationId): ?array
    {
        return $this->db->one(
            'SELECT id, from_character_id, to_character_id, kind, stance, description
             FROM character_relations WHERE id = ? AND project_id = ?',
            [$relationId, $projectId]
        );
    }

    /** @param array<string, mixed>|null $relation */
    private function formError(
        User $user,
        string $projectId,
        ?array $relation,
        string $fromId,
        string $toId,
        string $kind,
        string $stance,
        string $description,
        string $error
    ): Response {
        return $this->page($user, 'modules/Writing/templates/relationship_form.php', [
            'title' => $relation === null ? t('writing', 'new_relationship') : t('writing', 'edit_relationship'),
            'projectId' => $projectId,
            'projectTitle' => $this->projectTitle($projectId),
            'relation' => [
                'id' => $relation['id'] ?? null,
                'from_character_id' => $fromId,
                'to_character_id' => $toId,
                'kind' => $kind,
                'stance' => $stance,
                'description' => $description,
            ],
            'characterOptions' => $this->characterOptions($projectId),
            'kinds' => RelationKinds::allKinds(),
            'stances' => Stances::allStances(),
            'error' => $error,
            'notice' => null,
        ], 422);
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
            'message' => t('writing', 'relation_not_found'),
        ], 404);
    }
}
