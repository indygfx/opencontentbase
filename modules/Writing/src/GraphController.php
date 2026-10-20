<?php
declare(strict_types=1);

namespace Writing;

use Core\Csrf;
use Core\Database;
use Core\Response;
use Core\User;
use Core\View;

final class GraphController
{
    public function __construct(
        private Database $db,
        private View $view,
        private Csrf $csrf,
        private ProjectAccess $access,
        private CharacterMentions $mentions,
        private RelationshipController $relationships
    ) {
    }

    public function show(User $user, string $projectId): Response
    {
        $project = $this->findProject($projectId);
        if ($project === null || !$this->access->canAccess($user, $projectId)) {
            return $this->notFound($user);
        }
        $characters = $this->characters($projectId);
        $levels = $this->mentions->levels($projectId);
        $graphCharacters = [];
        foreach ($characters as $character) {
            $graphCharacters[] = [
                'id' => (string)$character['id'],
                'slug' => (string)$character['slug'],
                'title' => (string)$character['title'],
                'level' => $levels[(string)$character['id']] ?? 0,
                'has_image' => (string)($character['image_path'] ?? '') !== '',
            ];
        }
        $graph = new RelationshipGraph();
        $svg = $graph->render($projectId, $graphCharacters, $this->relationships->list($projectId));
        return $this->page($user, 'modules/Writing/templates/graph.php', [
            'title' => t('writing', 'graph_title') . ': ' . (string)$project['title'],
            'projectId' => $projectId,
            'projectTitle' => (string)$project['title'],
            'svg' => $svg,
            'characterCount' => count($graphCharacters),
            'relationCount' => count($this->relationships->list($projectId)),
            'kindGroups' => RelationKinds::groups(),
            'levels' => $levels,
        ]);
    }

    /**
     * All characters of the story with current title and image flag,
     * deterministic order (title ascending).
     *
     * @return list<array{id: string, slug: string, title: string, image_path: ?string}>
     */
    private function characters(string $projectId): array
    {
        return $this->db->all(
            "SELECT k.id, o.slug, k.image_path, COALESCE(r.title, o.slug) AS title
             FROM characters k
             JOIN content_objects o ON o.id = k.id AND o.type = 'character'
             LEFT JOIN content_revisions r ON r.object_id = k.id
              AND r.created_at = (
                  SELECT MAX(r2.created_at) FROM content_revisions r2 WHERE r2.object_id = k.id
              )
             WHERE k.project_id = ?
             ORDER BY title",
            [$projectId]
        );
    }

    /** @return array<string, mixed>|null */
    private function findProject(string $projectId): ?array
    {
        return $this->db->one('SELECT id, title FROM projects WHERE id = ?', [$projectId]);
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
}
