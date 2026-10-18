<?php

declare(strict_types=1);

namespace Writing;

use Core\Database;
use Core\Response;
use Core\User;

/**
 * Streams the story export bundle as a ZIP download.
 * Only users with project access (owner or member) may download it.
 */
final class StoryExportController
{
    public function __construct(
        private readonly Database $db,
        private readonly ProjectAccess $access
    ) {
    }

    public function export(User $user, string $projectId): Response
    {
        if (!$this->access->canAccess($user, $projectId)) {
            return new Response('', 403);
        }
        $exporter = new StoryExporter($this->db);
        $result = $exporter->export($projectId);
        try {
            $zipData = file_get_contents($result['path']);
        } finally {
            @unlink($result['path']);
        }
        if ($zipData === false) {
            return new Response('', 500);
        }
        $filename = $result['filename'];
        return new Response(
            $zipData,
            200,
            [
                'Content-Type' => 'application/zip',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Content-Length' => (string)strlen($zipData),
                'Cache-Control' => 'no-store',
            ]
        );
    }
}
