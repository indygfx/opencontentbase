<?php

declare(strict_types=1);

namespace Writing;

use Core\Database;
use Core\Response;
use Core\User;
use Core\View;

/**
 * Upload form for the story bundle import (GET/POST /writing/import).
 * Accepts a format-v1 ZIP (max 10 MB); on success redirects to the new
 * story dashboard with a flash message, on failure back to the form
 * with the translated error - nothing is written (the importer
 * validates fully before inserting).
 */
final class StoryImportController
{
    private const MAX_UPLOAD_BYTES = 10485760; // 10 MB

    public function __construct(
        private readonly Database $db,
        private readonly View $view,
        private readonly \Core\Csrf $csrf
    ) {
    }

    public function show(User $user, ?string $error = null, ?string $success = null): Response
    {
        return $this->page($user, $error, $success);
    }

    public function import(User $user): Response
    {
        $file = $_FILES['bundle'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return $this->page($user, t('writing', 'error_import_no_file'), null, 422);
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->page($user, t('writing', 'error_import_upload_failed'), null, 422);
        }
        if ((int)($file['size'] ?? 0) > self::MAX_UPLOAD_BYTES) {
            return $this->page($user, t('writing', 'error_import_too_large'), null, 422);
        }
        $name = (string)($file['name'] ?? '');
        if (!preg_match('/\.zip$/i', $name) || !in_array((string)($file['type'] ?? ''), ['application/zip', 'application/x-zip-compressed', ''], true)) {
            return $this->page($user, t('writing', 'error_import_not_zip'), null, 422);
        }

        $tmpPath = (string)$file['tmp_name'];
        try {
            $importer = new StoryImporter($this->db);
            $result = $importer->import($tmpPath, $user->id());
        } catch (ImportException $e) {
            return $this->page($user, $e->getMessage(), null, 422);
        }
        $message = t('writing', 'import_success', [
            'synopses' => (string)$result['counts']['synopses'],
            'chapters' => (string)$result['counts']['chapters'],
            'characters' => (string)$result['counts']['characters'],
            'backgrounds' => (string)$result['counts']['backgrounds'],
            'relations' => (string)$result['counts']['relations'],
        ]);
        return Response::redirect('/writing/' . rawurlencode($result['id']) . '?import=' . urlencode($message));
    }

    private function page(User $user, ?string $error, ?string $success, int $status = 200): Response
    {
        $content = $this->view->render('modules/Writing/templates/import.php', [
            'csrf' => $this->csrf,
            'error' => $error,
            'success' => $success,
        ]);
        return Response::html($this->view->render('templates/layout.php', [
            'title' => t('writing', 'import_title'),
            'user' => $user,
            'csrf' => $this->csrf,
            'content' => $content,
        ]), $status);
    }
}
