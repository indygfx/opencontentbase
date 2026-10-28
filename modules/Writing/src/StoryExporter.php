<?php

declare(strict_types=1);

namespace Writing;

use Core\Database;

/**
 * Exports a story as a Markdown bundle (ZIP archive, format version 1):
 * one file per object with YAML frontmatter, raw Markdown bodies,
 * relations.json for the relationship web and bundle.json as manifest.
 */
final class StoryExporter implements StoryExportProvider
{
    public const FORMAT_VERSION = 1;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Builds the export ZIP and returns archive path plus download filename.
     * The caller is responsible for deleting the temp file after streaming.
     *
     * @return array{path: string, filename: string}
     */
    public function export(string $projectId): array
    {
        if (!class_exists('ZipArchive')) {
            throw new ExportUnavailableException('The PHP ZIP extension (ext-zip) is not installed.');
        }
        $project = $this->project($projectId);
        if ($project === null) {
            throw new \RuntimeException('Story not found.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'cb_export_');
        if ($tmp === false) {
            throw new \RuntimeException('Could not create a temporary file.');
        }
        $zip = new \ZipArchive();
        $opened = $zip->open($tmp, \ZipArchive::OVERWRITE);
        if ($opened !== true) {
            @unlink($tmp);
            throw new \RuntimeException('Could not create the export archive.');
        }

        $synopses = $this->synopses($projectId);
        $chapters = $this->chapters($projectId);
        $characters = $this->characters($projectId);
        $backgrounds = $this->backgrounds($projectId);
        $relations = $this->relations($projectId);

        $slug = $this->safeSlug((string)$project['title']);
        $filename = ($slug !== '' ? $slug : 'story') . '.zip';

        $zip->addFromString('story.md', $this->storyFile($project));
        foreach ($synopses as $row) {
            $zip->addFromString('synopses/' . $this->safeSlug($row['slug']) . '.md', $this->synopsisFile($row));
        }
        foreach ($chapters as $row) {
            $zip->addFromString('chapters/' . $this->safeSlug($row['slug']) . '.md', $this->chapterFile($row));
        }
        foreach ($characters as $row) {
            $zip->addFromString('characters/' . $this->safeSlug($row['slug']) . '.md', $this->objectFile('character', $row));
        }
        foreach ($backgrounds as $row) {
            $zip->addFromString('backgrounds/' . $this->safeSlug($row['slug']) . '.md', $this->objectFile('background', $row));
        }
        if ($relations !== []) {
            $zip->addFromString('relations.json', $this->relationsJson($relations));
        }
        $zip->addFromString('bundle.json', $this->bundleJson(
            $project,
            count($synopses),
            count($chapters),
            count($characters),
            count($backgrounds),
            count($relations)
        ));
        $zip->close();

        return ['path' => (string)$tmp, 'filename' => $filename];
    }

    /** @param array<string, mixed> $project */
    private function storyFile(array $project): string
    {
        $frontmatter = [
            'uuid' => (string)$project['id'],
            'type' => 'story',
            'title' => (string)$project['title'],
            'created_at' => (string)$project['created_at'],
        ];
        $body = '## Idea' . "\n\n" . ((string)$project['idea'] !== '' ? (string)$project['idea'] : '_Not written yet._')
            . "\n\n" . '## Short Description' . "\n\n" . ((string)$project['blurb'] !== '' ? (string)$project['blurb'] : '_Not written yet._')
            . "\n\n" . '## Extended Synopsis' . "\n\n" . ((string)$project['synopsis_long'] !== '' ? (string)$project['synopsis_long'] : '_Not written yet._');
        return $this->file($frontmatter, $body);
    }

    /** @param array<string, mixed> $row */
    private function synopsisFile(array $row): string
    {
        return $this->file([
            'uuid' => (string)$row['id'],
            'slug' => (string)$row['slug'],
            'type' => 'chapter_synopsis',
            'title' => (string)$row['title'],
            'created_at' => (string)$row['created_at'],
        ], (string)$row['summary_text']);
    }

    /** @param array<string, mixed> $row */
    private function chapterFile(array $row): string
    {
        return $this->file([
            'uuid' => (string)$row['id'],
            'slug' => (string)$row['slug'],
            'type' => 'chapter',
            'title' => (string)$row['title'],
            'created_at' => (string)$row['created_at'],
            'synopsis_uuid' => (string)$row['synopsis_id'],
            'updated_at' => (string)$row['updated_at'],
        ], (string)$row['body']);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function objectFile(string $type, array $row): string
    {
        return $this->file([
            'uuid' => (string)$row['id'],
            'slug' => (string)$row['slug'],
            'type' => $type,
            'title' => (string)$row['title'],
            'created_at' => (string)$row['created_at'],
            'updated_at' => (string)$row['updated_at'],
        ], (string)$row['body']);
    }

    /** @param list<array<string, mixed>> $relations */
    private function relationsJson(array $relations): string
    {
        $entries = [];
        foreach ($relations as $r) {
            $entries[] = [
                'uuid' => (string)$r['id'],
                'from_uuid' => (string)$r['from_id'],
                'to_uuid' => (string)$r['to_id'],
                'kind' => (string)$r['kind'],
                'conflict' => (string)$r['description'],
                'uuids' => [
                    'relation' => (string)$r['id'],
                    'from' => (string)$r['from_id'],
                    'to' => (string)$r['to_id'],
                ],
            ];
        }
        return (string)json_encode(
            ['relations' => $entries],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * @param array<string, mixed> $project
     * @param int $synopses $chapters $characters $backgrounds $relations
     */
    private function bundleJson(
        array $project,
        int $synopses,
        int $chapters,
        int $characters,
        int $backgrounds,
        int $relations
    ): string {
        return (string)json_encode([
            'format_version' => self::FORMAT_VERSION,
            'story_uuid' => (string)$project['id'],
            'story_title' => (string)$project['title'],
            'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'object_counts' => [
                'synopses' => $synopses,
                'chapters' => $chapters,
                'characters' => $characters,
                'backgrounds' => $backgrounds,
                'relations' => $relations,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Renders a Markdown file: YAML frontmatter block, blank line, raw body.
     *
     * @param array<string, string> $fields ordered frontmatter fields
     */
    private function file(array $fields, string $body): string
    {
        $lines = ['---'];
        foreach ($fields as $key => $value) {
            $lines[] = $key . ': ' . $this->yamlScalar($value);
        }
        $lines[] = '---';
        $lines[] = '';
        $text = implode("\n", $lines) . "\n";
        if ($body !== '') {
            $text .= $body;
            if (!str_ends_with($body, "\n")) {
                $text .= "\n";
            }
        }
        return $text;
    }

    /**
     * Escapes a scalar for YAML so titles with colons, quotes, dashes or a
     * leading indicator character cannot inject structure (wrap in double
     * quotes, escape backslashes and double quotes, escape non-printables).
     */
    private function yamlScalar(string $value): string
    {
        $escaped = str_replace(
            ['\\', '"', "\n", "\r", "\t", "\x00"],
            ['\\\\', '\\"', '\\n', '\\r', '\\t', '\\0'],
            $value
        );
        return '"' . $escaped . '"';
    }

    /**
     * Sanitizes a slug for use as a filename inside the archive
     * (lowercase ASCII letters, digits, hyphen; no traversal).
     */
    private function safeSlug(string $slug): string
    {
        $slug = strtolower($slug);
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'untitled';
        }
        return mb_substr($slug, 0, 100);
    }

    /** @return array<string, mixed>|null */
    private function project(string $projectId): ?array
    {
        return $this->db->one(
            'SELECT id, title, idea, blurb, synopsis_long, created_at FROM projects WHERE id = ?',
            [$projectId]
        );
    }

    /** @return list<array<string, mixed>> */
    private function synopses(string $projectId): array
    {
        return $this->db->all(
            'SELECT id, slug, title, summary_text, created_at
             FROM chapter_synopses WHERE project_id = ?
             ORDER BY created_at ASC, slug ASC',
            [$projectId]
        );
    }

    /** @return list<array<string, mixed>> */
    private function chapters(string $projectId): array
    {
        return $this->db->all(
            "SELECT c.id, c.slug, c.created_at, c.chapter_synopsis_id AS synopsis_id,
                    COALESCE(s.title, c.slug) AS title,
                    COALESCE(r.body, '') AS body,
                    r.created_at AS updated_at
             FROM chapters c
             JOIN content_objects o ON o.id = c.id AND o.type = 'chapter'
             LEFT JOIN chapter_synopses s ON s.id = c.chapter_synopsis_id
             LEFT JOIN content_revisions r ON r.object_id = c.id
              AND r.created_at = (
                  SELECT MAX(r2.created_at) FROM content_revisions r2 WHERE r2.object_id = c.id
              )
             WHERE c.project_id = ?
             ORDER BY c.created_at ASC, c.slug ASC",
            [$projectId]
        );
    }

    /** @return list<array<string, mixed>> */
    private function characters(string $projectId): array
    {
        return $this->objects('character', $projectId);
    }

    /** @return list<array<string, mixed>> */
    private function backgrounds(string $projectId): array
    {
        return $this->objects('background', $projectId);
    }

    /** @return list<array<string, mixed>> */
    private function objects(string $type, string $projectId): array
    {
        return $this->db->all(
            "SELECT o.id, o.slug, COALESCE(r.title, o.slug) AS title,
                    COALESCE(r.body, '') AS body, o.created_at,
                    r.created_at AS updated_at
             FROM content_objects o
             JOIN {$type}s x ON x.id = o.id
             LEFT JOIN content_revisions r ON r.object_id = o.id
              AND r.created_at = (
                  SELECT MAX(r2.created_at) FROM content_revisions r2 WHERE r2.object_id = o.id
              )
             WHERE o.type = ? AND x.project_id = ?
             ORDER BY o.created_at ASC, o.slug ASC",
            [$type, $projectId]
        );
    }

    /** @return list<array<string, mixed>> */
    private function relations(string $projectId): array
    {
        return $this->db->all(
            'SELECT id, from_character_id AS from_id, to_character_id AS to_id,
                    kind, description
             FROM character_relations
             WHERE project_id = ?
             ORDER BY created_at ASC, id ASC',
            [$projectId]
        );
    }
}
