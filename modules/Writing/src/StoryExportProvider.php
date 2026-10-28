<?php

declare(strict_types=1);

namespace Writing;

/**
 * Contract for building the story export bundle. StoryExporter is the
 * production implementation; tests substitute a failing provider to
 * cover the server-side error pages.
 */
interface StoryExportProvider
{
    /**
     * Builds the export ZIP and returns archive path plus download filename.
     * The caller is responsible for deleting the temp file after streaming.
     *
     * @return array{path: string, filename: string}
     */
    public function export(string $projectId): array;
}
