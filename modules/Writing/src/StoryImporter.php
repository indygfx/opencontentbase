<?php

declare(strict_types=1);

namespace Writing;

use Core\Auth;
use Core\Database;

/**
 * Imports a format-v1 Markdown bundle ZIP as a NEW story (the export
 * contract in the README is the specification).
 *
 * Design rules:
 * - An import ALWAYS creates a new story owned by the importing user.
 *   Never merges into existing data, never overwrites - if the source
 *   story still exists elsewhere, both coexist with different UUIDs.
 * - Conservative: the full bundle is validated BEFORE anything is
 *   written; on any inconsistency (missing bundle.json, unsupported
 *   format version, broken frontmatter, dangling synopsis/relation
 *   references, path traversal entries) an ImportException aborts and
 *   nothing is written. Inserts happen inside one SQLite transaction.
 * - Source slugs are preserved but deduplicated within the new story
 *   (-2, -3 ... on collision); source UUIDs are remapped to fresh
 *   UUIDs via a map, so relations keep pointing at the right
 *   characters.
 * - v1 bundles carry no stance; imported relations get stance NULL
 *   (neutral rendering). Kinds unknown to RelationKinds are allowed -
 *   the graph falls back to the neutral 'other' group.
 */
final class StoryImporter
{
    private const MAX_ZIP_ENTRIES = 1000;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{id: string, counts: array{synopses: int, chapters: int, characters: int, backgrounds: int, relations: int}}
     * @throws ImportException translated message, nothing written
     */
    public function import(string $zipPath, string $ownerId): array
    {
        if (!class_exists('ZipArchive')) {
            throw new ImportException(t('writing', 'error_export_unavailable'));
        }
        $bundle = $this->readBundle($zipPath);
        $counts = [
            'synopses' => 0,
            'chapters' => 0,
            'characters' => 0,
            'backgrounds' => 0,
            'relations' => 0,
        ];
        $newId = '';

        $this->db->transaction(function (Database $db) use ($bundle, $ownerId, &$counts, &$newId): void {
            $now = gmdate('Y-m-d H:i:s');
            $uuidMap = [];
            $usedSlugs = [];

            // ---- story.md -> new project ----
            $story = $bundle['story.md'];
            $fm = $story['frontmatter'];
            $newId = Auth::uuid4();
            $db->run(
                'INSERT INTO projects (id, owner_id, title, idea, blurb, synopsis_long, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$newId, $ownerId, $fm['title'], $fm['idea'], $fm['blurb'], $fm['synopsis_long'], $now]
            );
            $db->run(
                'INSERT INTO project_targets (project_id, target_words_total) VALUES (?, ?)',
                [$newId, ProjectProgress::genreWordTarget('General')]
            );

            // ---- synopses/*.md -> chapter_synopses ----
            $synopsisIdBySourceUuid = [];
            foreach ($bundle['synopses'] as $entry) {
                $fm = $entry['frontmatter'];
                $newUuid = Auth::uuid4();
                $uuidMap[(string)$fm['uuid']] = $newUuid;
                $slug = $this->uniqueSlug((string)$fm['slug'], $usedSlugs);
                $this->insertContentObject($db, $newUuid, 'chapter_synopsis', $slug, $ownerId);
                $db->run(
                    'INSERT INTO chapter_synopses (id, project_id, slug, title, summary_text, created_at)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [$newUuid, $newId, $slug, (string)$fm['title'], $entry['body'], $now]
                );
                $synopsisIdBySourceUuid[(string)$fm['uuid']] = $newUuid;
                $counts['synopses']++;
            }

            // ---- chapters/*.md -> chapters ----
            foreach ($bundle['chapters'] as $entry) {
                $fm = $entry['frontmatter'];
                if (!isset($synopsisIdBySourceUuid[(string)$fm['synopsis_uuid']])) {
                    throw new ImportException(t('writing', 'error_import_dangling_synopsis'));
                }
                $newUuid = Auth::uuid4();
                $uuidMap[(string)$fm['uuid']] = $newUuid;
                $slug = $this->uniqueSlug((string)$fm['slug'], $usedSlugs);
                $this->insertContentObject($db, $newUuid, 'chapter', $slug, $ownerId);
                $db->run(
                    'INSERT INTO chapters (id, project_id, chapter_synopsis_id, slug, created_at)
                     VALUES (?, ?, ?, ?, ?)',
                    [$newUuid, $newId, $synopsisIdBySourceUuid[(string)$fm['synopsis_uuid']], $slug, $now]
                );
                $this->insertRevision($db, $newUuid, (string)$fm['title'], $entry['body'], $ownerId);
                $counts['chapters']++;
            }

            // ---- characters/*.md and backgrounds/*.md ----
            foreach (['characters' => 'character', 'backgrounds' => 'background'] as $dir => $type) {
                foreach ($bundle[$dir] as $entry) {
                    $fm = $entry['frontmatter'];
                    $newUuid = Auth::uuid4();
                    $uuidMap[(string)$fm['uuid']] = $newUuid;
                    $slug = $this->uniqueSlug((string)$fm['slug'], $usedSlugs);
                    $this->insertContentObject($db, $newUuid, $type, $slug, $ownerId);
                    $db->run(
                        "INSERT INTO {$type}s (id, project_id, slug) VALUES (?, ?, ?)",
                        [$newUuid, $newId, $slug]
                    );
                    $this->insertRevision($db, $newUuid, (string)$fm['title'], $entry['body'], $ownerId);
                    $counts[$dir]++;
                }
            }

            // ---- relations.json -> character_relations ----
            foreach ($bundle['relations'] as $relation) {
                if (!is_array($relation)) {
                    throw new ImportException(t('writing', 'error_import_invalid'));
                }
                $fromOld = (string)($relation['from_uuid'] ?? '');
                $toOld = (string)($relation['to_uuid'] ?? '');
                if (!isset($uuidMap[$fromOld]) || !isset($uuidMap[$toOld])) {
                    throw new ImportException(t('writing', 'error_import_dangling_relation'));
                }
                $db->run(
                    'INSERT INTO character_relations (id, project_id, from_character_id, to_character_id, kind, stance, description)
                     VALUES (?, ?, ?, ?, ?, NULL, ?)',
                    [
                        Auth::uuid4(),
                        $newId,
                        $uuidMap[$fromOld],
                        $uuidMap[$toOld],
                        (string)($relation['kind'] ?? 'related'),
                        (string)($relation['conflict'] ?? ''),
                    ]
                );
                $counts['relations']++;
            }
        });

        return ['id' => $newId, 'counts' => $counts];
    }

    /**
     * Reads and fully validates the bundle: ZIP openable, entry names
     * safe (no traversal, known layout only), bundle.json present with
     * format_version 1, story.md parseable. Returns the parsed bundle.
     *
     * @return array<string, mixed> parsed bundle
     */
    private function readBundle(string $zipPath): array
    {
        $zip = new \ZipArchive();
        $opened = $zip->open($zipPath);
        if ($opened !== true) {
            throw new ImportException(t('writing', 'error_import_not_zip'));
        }
        try {
            if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
                throw new ImportException(t('writing', 'error_import_invalid'));
            }
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string)$zip->getNameIndex($i);
                if (!$this->isSafeEntryName($name)) {
                    throw new ImportException(t('writing', 'error_import_invalid'));
                }
                $entries[$name] = (string)$zip->getFromIndex($i);
            }
        } finally {
            $zip->close();
        }

        // bundle.json
        if (!isset($entries['bundle.json'])) {
            throw new ImportException(t('writing', 'error_import_missing_manifest'));
        }
        $manifest = json_decode($entries['bundle.json'], true);
        if (!is_array($manifest)) {
            throw new ImportException(t('writing', 'error_import_missing_manifest'));
        }
        if (($manifest['format_version'] ?? null) !== StoryExporter::FORMAT_VERSION) {
            throw new ImportException(t('writing', 'error_import_unsupported_version'));
        }
        if (!is_string($manifest['story_uuid'] ?? null) || $manifest['story_uuid'] === '') {
            throw new ImportException(t('writing', 'error_import_missing_manifest'));
        }

        // story.md (required)
        if (!isset($entries['story.md'])) {
            throw new ImportException(t('writing', 'error_import_missing_story'));
        }
        $story = $this->parseFile($entries['story.md']);
        if ((string)$story['frontmatter']['title'] === '') {
            throw new ImportException(t('writing', 'error_import_missing_story'));
        }
        $sections = $this->splitSections($story['body']);
        $story['frontmatter']['idea'] = $sections['idea'] ?? '';
        $story['frontmatter']['blurb'] = $sections['short description'] ?? '';
        $story['frontmatter']['synopsis_long'] = $sections['extended synopsis'] ?? '';

        $bundle = [
            'story.md' => $story,
            'synopses' => [],
            'chapters' => [],
            'characters' => [],
            'backgrounds' => [],
            'relations' => [],
        ];
        foreach ($entries as $name => $content) {
            if ($name === 'bundle.json' || $name === 'story.md' || $name === 'relations.json') {
                continue;
            }
            if (preg_match('#^(synopses|chapters|characters|backgrounds)/([^/]+)\.md$#', $name, $m) !== 1) {
                throw new ImportException(t('writing', 'error_import_invalid'));
            }
            $bundle[$m[1]][] = $this->parseFile($content);
        }

        // relations.json (optional)
        if (isset($entries['relations.json'])) {
            $relations = json_decode($entries['relations.json'], true);
            if (!is_array($relations) || !is_array($relations['relations'] ?? null)) {
                throw new ImportException(t('writing', 'error_import_invalid'));
            }
            $bundle['relations'] = $relations['relations'];
        }
        return $bundle;
    }

    /**
     * Entry names must stay inside the known bundle layout: relative,
     * no "..", no backslash, no leading slash, known paths only.
     */
    private function isSafeEntryName(string $name): bool
    {
        if ($name === '' || str_contains($name, '..') || str_contains($name, '\\') || str_starts_with($name, '/')) {
            return false;
        }
        return preg_match(
            '#^(bundle\.json|story\.md|relations\.json|(synopses|chapters|characters|backgrounds)/[A-Za-z0-9._-]+\.md)$#',
            $name
        ) === 1;
    }

    /**
     * Minimal YAML frontmatter reader: strip the --- fences, split each
     * line on the first ": ", trim surrounding double quotes, unescape
     * \" \\ \n \r \t \0. Everything after the closing fence is the raw
     * Markdown body (one leading blank line removed).
     *
     * @return array{frontmatter: array<string, string>, body: string}
     */
    private function parseFile(string $content): array
    {
        $content = str_replace("\r\n", "\n", $content);
        if (!str_starts_with($content, "---\n")) {
            throw new ImportException(t('writing', 'error_import_invalid_frontmatter'));
        }
        $end = strpos($content, "\n---\n", 4);
        if ($end === false) {
            throw new ImportException(t('writing', 'error_import_invalid_frontmatter'));
        }
        $block = substr($content, 4, $end - 4);
        $body = substr($content, $end + 5);
        // The exporter writes one blank line between the closing fence
        // and the body; drop it so bodies round-trip byte-exactly.
        if (str_starts_with($body, "\n")) {
            $body = substr($body, 1);
        }
        // Normalize the trailing newline: the exporter guarantees
        // exactly one; the chapter bodies in the DB have none.
        $body = rtrim($body, "\n");

        $frontmatter = [];
        foreach (explode("\n", $block) as $line) {
            if ($line === '') {
                continue;
            }
            $pos = strpos($line, ': ');
            if ($pos === false) {
                throw new ImportException(t('writing', 'error_import_invalid_frontmatter'));
            }
            $frontmatter[substr($line, 0, $pos)] = $this->yamlScalar(substr($line, $pos + 2));
        }
        $frontmatter['title'] = (string)($frontmatter['title'] ?? '');
        $frontmatter['slug'] = (string)($frontmatter['slug'] ?? '');
        $frontmatter['uuid'] = (string)($frontmatter['uuid'] ?? '');
        $frontmatter['synopsis_uuid'] = (string)($frontmatter['synopsis_uuid'] ?? '');
        return ['frontmatter' => $frontmatter, 'body' => $body];
    }

    /** Unwraps and unescapes a double-quoted YAML scalar. */
    private function yamlScalar(string $value): string
    {
        $value = trim($value);
        if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
            $value = substr($value, 1, -1);
            $value = str_replace(
                ['\\"', '\\\\', '\\n', '\\r', '\\t', '\\0'],
                ['"', '\\', "\n", "\r", "\t", "\x00"],
                $value
            );
        }
        return $value;
    }

    /**
     * Splits the story body into its "## Section" parts (lowercased
     * heading text as key, section text without the heading as value).
     *
     * @return array<string, string>
     */
    private function splitSections(string $body): array
    {
        $sections = [];
        $current = null;
        $buffer = [];
        foreach (explode("\n", $body) as $line) {
            if (preg_match('/^## (.+)$/', $line, $m) === 1) {
                if ($current !== null) {
                    $sections[strtolower($current)] = trim(implode("\n", $buffer));
                }
                $current = trim($m[1]);
                $buffer = [];
                continue;
            }
            $buffer[] = $line;
        }
        if ($current !== null) {
            $sections[strtolower($current)] = trim(implode("\n", $buffer));
        }
        return $sections;
    }

    /** Guarantees slug uniqueness within the import (-2, -3 ... on collision). */
    private function uniqueSlug(string $slug, array &$used): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            $slug = 'untitled';
        }
        if (!isset($used[$slug])) {
            $used[$slug] = true;
            return $slug;
        }
        $i = 2;
        while (isset($used[$slug . '-' . $i])) {
            $i++;
        }
        $used[$slug . '-' . $i] = true;
        return $slug . '-' . $i;
    }

    private function insertContentObject(Database $db, string $id, string $type, string $slug, string $ownerId): void
    {
        $db->run(
            'INSERT INTO content_objects (id, type, slug, owner_id) VALUES (?, ?, ?, ?)',
            [$id, $type, $slug, $ownerId]
        );
    }

    private function insertRevision(Database $db, string $objectId, string $title, string $body, string $authorId): void
    {
        $db->run(
            'INSERT INTO content_revisions (id, object_id, title, body, author_id) VALUES (?, ?, ?, ?, ?)',
            [Auth::uuid4(), $objectId, $title, $body, $authorId]
        );
    }
}
