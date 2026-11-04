<?php
declare(strict_types=1);

namespace Writing;

use Core\Database;
use Core\User;

final class ProjectAccess
{
    public function __construct(private Database $db)
    {
    }

    public function isOwner(User $user, string $projectId): bool
    {
        $row = $this->db->one(
            'SELECT id FROM projects WHERE id = ? AND owner_id = ?',
            [$projectId, $user->id()]
        );
        return $row !== null;
    }

    public function canAccess(User $user, string $projectId): bool
    {
        $row = $this->db->one(
            'SELECT p.id
             FROM projects p
             LEFT JOIN project_members m ON m.project_id = p.id AND m.user_id = ?
             WHERE p.id = ? AND (p.owner_id = ? OR m.user_id IS NOT NULL)',
            [$user->id(), $projectId, $user->id()]
        );
        return $row !== null;
    }

    public function canEdit(User $user, string $projectId): bool
    {
        return $this->canAccess($user, $projectId);
    }

    /** @return list<array{id: string, title: string, owner_id: string, owner_name: string, shared: bool, created_at: string}> */
    public function visibleProjects(User $user): array
    {
        $rows = $this->db->all(
            "SELECT p.id, p.title, p.owner_id, u.username AS owner_name, p.created_at,
                    CASE WHEN p.owner_id = ? THEN 0 ELSE 1 END AS shared
             FROM projects p
             JOIN users u ON u.id = p.owner_id
             LEFT JOIN project_members m ON m.project_id = p.id AND m.user_id = ?
             WHERE p.owner_id = ? OR m.user_id IS NOT NULL
             ORDER BY shared ASC, p.title ASC",
            [$user->id(), $user->id(), $user->id()]
        );
        return $rows;
    }

    /** @return list<array{user_id: string, username: string, role: string}> */
    public function members(string $projectId): array
    {
        return $this->db->all(
            'SELECT m.user_id, u.username, m.role
             FROM project_members m
             JOIN users u ON u.id = m.user_id
             WHERE m.project_id = ?
             ORDER BY u.username',
            [$projectId]
        );
    }

    /**
     * All member rows (owner + member roles) for the given projects in ONE
     * grouped query, grouped in PHP by project id - the overview must not
     * run one members() query per row (no N+1).
     *
     * @param list<string> $projectIds
     * @return array<string, list<array{user_id: string, username: string, role: string}>>
     */
    public function membersForProjects(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        $rows = $this->db->all(
            "SELECT m.project_id, m.user_id, u.username, m.role
             FROM project_members m
             JOIN users u ON u.id = m.user_id
             WHERE m.project_id IN ($placeholders)
             ORDER BY u.username",
            array_values($projectIds)
        );
        $byProject = [];
        foreach ($rows as $row) {
            $byProject[(string)$row['project_id']][] = [
                'user_id' => (string)$row['user_id'],
                'username' => (string)$row['username'],
                'role' => (string)$row['role'],
            ];
        }
        return $byProject;
    }
}
