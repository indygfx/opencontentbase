<?php

declare(strict_types=1);

namespace Writing;

use Core\Csrf;
use Core\Database;
use Core\Response;
use Core\User;
use Core\View;

/**
 * Streams the story export bundle as a ZIP download.
 * Only users with project access (owner or member) may download it.
 *
 * Server-side failures (missing ZIP extension, unusable temp directory,
 * broken archive) never escape as a fatal error: they render a
 * translated, friendly error page instead - ExportUnavailableException
 * as 503 (server not capable), other export problems as 500.
 */
final class StoryExportController
{
    public function __construct(
        private readonly Database $db,
        private readonly ProjectAccess $access,
        private readonly ?View $view = null,
        private readonly ?Csrf $csrf = null,
        private readonly ?StoryExportProvider $exporter = null
    ) {
    }

    public function export(User $user, string $projectId): Response
    {
        if (!$this->access->canAccess($user, $projectId)) {
            return new Response('', 403);
        }
        $exporter = $this->exporter ?? new StoryExporter($this->db);
        try {
            $result = $exporter->export($projectId);
        } catch (ExportUnavailableException $e) {
            return $this->errorPage($user, t('writing', 'error_export_unavailable'), 503);
        } catch (\RuntimeException $e) {
            return $this->errorPage($user, t('writing', 'error_export_failed'), 500);
        }
        try {
            $zipData = file_get_contents($result['path']);
        } finally {
            @unlink($result['path']);
        }
        if ($zipData === false) {
            return $this->errorPage($user, t('writing', 'error_export_failed'), 500);
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

    /** Renders the shared error page with a translated message. */
    private function errorPage(User $user, string $message, int $status): Response
    {
        if ($this->view === null || $this->csrf === null) {
            // Defensive fallback: no view wired up (should not happen via
            // the module), at least answer with the bare status code.
            return new Response(e($message), $status, ['Content-Type' => 'text/html; charset=utf-8']);
        }
        $content = $this->view->render('templates/error.php', [
            'code' => $status,
            'message' => $message,
        ]);
        return Response::html($this->view->render('templates/layout.php', [
            'title' => (string)$status,
            'user' => $user,
            'csrf' => $this->csrf,
            'content' => $content,
        ]), $status);
    }
}
