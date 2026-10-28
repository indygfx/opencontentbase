<?php

declare(strict_types=1);

namespace Tests\Writing;

use Core\Auth;
use Core\CoreMigrations;
use Core\Csrf;
use Core\Database;
use Core\Migrator;
use Core\ModuleRegistry;
use Core\User;
use PHPUnit\Framework\TestCase;
use Writing\BackgroundController;
use Writing\ChapterController;
use Writing\ChapterSynopsisController;
use Writing\CharacterController;
use Writing\ImportException;
use Writing\ProjectAccess;
use Writing\RelationshipController;
use Writing\StoryExportController;
use Writing\StoryExporter;
use Writing\StoryImportController;
use Writing\StoryImporter;
use Writing\WritingModule;

/**
 * Story bundle import (format version 1): golden path via export ->
 * import, the roundtrip export -> import -> export, and all error
 * cases (no ZIP, missing manifest, unsupported version, dangling
 * references, path traversal, oversize).
 */
final class StoryImportTest extends TestCase
{
    private Database $db;
    private StoryImporter $importer;
    private StoryExporter $exporter;
    private StoryImportController $importController;
    private ChapterController $chapters;
    private ChapterSynopsisController $synopses;
    private CharacterController $characters;
    private BackgroundController $backgrounds;
    private RelationshipController $relationships;
    private User $owner;

    protected function setUp(): void
    {
        $base = dirname(__DIR__);
        $path = sys_get_temp_dir() . '/cb_import_' . uniqid() . '.sqlite';
        $this->db = new Database($path);
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        $auth = new Auth($this->db);
        $registry = new ModuleRegistry();
        $renderer = new \Core\ContentRenderer($this->db, $registry);
        $csrf = new Csrf($this->db, $auth);
        $module = new WritingModule($this->db, new \Core\View($base), $csrf, $renderer, $base);
        $registry->register($module);
        (new Migrator($this->db))->migrate('writing', $module->migrations());
        $access = new ProjectAccess($this->db);
        $this->synopses = new ChapterSynopsisController($this->db, new \Core\View($base), $csrf, $access, $renderer);
        $this->chapters = new ChapterController($this->db, new \Core\View($base), $csrf, $access, $renderer, $this->synopses);
        $this->characters = new CharacterController($this->db, new \Core\View($base), $csrf, $access, $renderer, $base);
        $this->backgrounds = new BackgroundController($this->db, new \Core\View($base), $csrf, $access, $renderer);
        $this->relationships = new RelationshipController($this->db, new \Core\View($base), $csrf, $access);
        $this->exporter = new StoryExporter($this->db);
        $this->importer = new StoryImporter($this->db);
        $this->importController = new StoryImportController($this->db, new \Core\View($base), $csrf);

        $this->db->run(
            'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
            ['o1', 'owner', password_hash('x1234567', PASSWORD_BCRYPT), 'user']
        );
        $this->owner = User::fromRow($this->db->one('SELECT id, username, role FROM users WHERE id = ?', ['o1']));
        $_POST = [];
        $_FILES = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        unset($this->db, $this->importer, $this->exporter, $this->importController);
        $_POST = [];
        $_FILES = [];
        $_GET = [];
    }

    /** Builds a fully populated story; returns its project id. */
    private function buildStory(): string
    {
        $base = dirname(__DIR__);
        $auth = new Auth($this->db);
        $csrf = new Csrf($this->db, $auth);
        $access = new ProjectAccess($this->db);
        $renderer = new \Core\ContentRenderer($this->db, new ModuleRegistry());
        $controller = new \Writing\WritingController(
            $this->db,
            new \Core\View($base),
            $csrf,
            $access,
            $renderer,
            $base
        );
        $_POST = ['title' => 'My novel'];
        $controller->store($this->owner);
        $_POST = [];
        return (string)$this->db->one("SELECT id FROM projects WHERE title = 'My novel'")['id'];
    }

    private function populate(string $projectId): void
    {
        // outline
        $_POST = ['title' => 'Chapter one', 'summary' => 'The hero arrives.'];
        $this->synopses->store($this->owner, $projectId);
        $_POST = [];
        $synopsisId = (string)$this->db->one(
            'SELECT id FROM chapter_synopses WHERE project_id = ? ORDER BY created_at DESC, rowid DESC LIMIT 1',
            [$projectId]
        )['id'];

        // chapter
        $_POST = ['slug' => 'chapter-1', 'synopsis_id' => $synopsisId, 'body' => "First line.\n\nSecond **bold** line with \"quotes\" and \\ backslash."];
        $this->chapters->store($this->owner, $projectId);
        $_POST = [];

        // characters
        foreach ([['hero', 'Hero'], ['villain', 'Villain']] as [$slug, $title]) {
            $_POST = ['slug' => $slug, 'title' => $title, 'body' => "Body of {$title}."];
            $this->characters->store($this->owner, $projectId);
            $_POST = [];
        }
        $heroId = (string)$this->db->one("SELECT k.id FROM characters k JOIN content_objects o ON o.id = k.id WHERE o.slug = 'hero' AND k.project_id = ?", [$projectId])['id'];
        $villainId = (string)$this->db->one("SELECT k.id FROM characters k JOIN content_objects o ON o.id = k.id WHERE o.slug = 'villain' AND k.project_id = ?", [$projectId])['id'];

        // background
        $_POST = ['slug' => 'world', 'title' => 'The World', 'body' => 'A dark place.'];
        $this->backgrounds->store($this->owner, $projectId);
        $_POST = [];

        // relation
        $_POST = ['from_character_id' => $heroId, 'to_character_id' => $villainId, 'kind' => 'sibling', 'stance' => 'hatred', 'description' => 'old feud'];
        $this->relationships->store($this->owner, $projectId);
        $_POST = [];
    }

    public function testGoldenPath(): void
    {
        $projectId = $this->buildStory();
        $this->populate($projectId);
        $export = $this->exporter->export($projectId);

        $result = $this->importer->import($export['path'], 'o1');
        @unlink($export['path']);

        $newId = $result['id'];
        $this->assertNotSame($projectId, $newId, 'import must create a NEW story');
        $this->assertSame([
            'synopses' => 1,
            'chapters' => 1,
            'characters' => 2,
            'backgrounds' => 1,
            'relations' => 1,
        ], $result['counts']);

        // new project exists, owned by the importer
        $project = $this->db->one('SELECT owner_id, title FROM projects WHERE id = ?', [$newId]);
        $this->assertSame('o1', (string)$project['owner_id']);
        $this->assertSame('My novel', (string)$project['title']);

        // chapter body identical
        $originalBody = (string)$this->db->one(
            'SELECT r.body FROM content_revisions r JOIN chapters c ON c.id = r.object_id WHERE c.project_id = ?',
            [$projectId]
        )['body'];
        $importedBody = (string)$this->db->one(
            'SELECT r.body FROM content_revisions r JOIN chapters c ON c.id = r.object_id WHERE c.project_id = ?',
            [$newId]
        )['body'];
        $this->assertSame($originalBody, $importedBody);

        // synopsis remapping: the imported chapter points at the imported synopsis
        $pair = $this->db->one(
            'SELECT c.chapter_synopsis_id, s.title
             FROM chapters c
             JOIN chapter_synopses s ON s.id = c.chapter_synopsis_id
             WHERE c.project_id = ?',
            [$newId]
        );
        $this->assertSame('Chapter one', (string)$pair['title']);

        // relations point at the NEW character uuids
        $relation = $this->db->one(
            'SELECT r.from_character_id, r.to_character_id, r.kind, r.stance, r.description
             FROM character_relations r WHERE r.project_id = ?',
            [$newId]
        );
        $newHero = (string)$this->db->one(
            "SELECT k.id FROM characters k JOIN content_objects o ON o.id = k.id WHERE o.slug = 'hero' AND k.project_id = ?",
            [$newId]
        )['id'];
        $newVillain = (string)$this->db->one(
            "SELECT k.id FROM characters k JOIN content_objects o ON o.id = k.id WHERE o.slug = 'villain' AND k.project_id = ?",
            [$newId]
        )['id'];
        $this->assertSame($newHero, (string)$relation['from_character_id']);
        $this->assertSame($newVillain, (string)$relation['to_character_id']);
        $this->assertSame('sibling', (string)$relation['kind']);
        $this->assertNull($relation['stance'], 'v1 bundles carry no stance; imported as NULL');
        $this->assertSame('old feud', (string)$relation['description']);
    }

    public function testRoundtripBundleJsonIdentical(): void
    {
        $projectId = $this->buildStory();
        $this->populate($projectId);
        $first = $this->exporter->export($projectId);
        $firstZip = new \ZipArchive();
        $firstZip->open($first['path']);
        $firstManifest = json_decode((string)$firstZip->getFromName('bundle.json'), true);
        $firstZip->close();

        $imported = $this->importer->import($first['path'], 'o1');
        @unlink($first['path']);

        $second = $this->exporter->export($imported['id']);
        $secondZip = new \ZipArchive();
        $secondZip->open($second['path']);
        $secondManifest = json_decode((string)$secondZip->getFromName('bundle.json'), true);
        $secondZip->close();
        @unlink($second['path']);

        // identical except story_uuid + exported_at
        $a = $firstManifest;
        $b = $secondManifest;
        unset($a['story_uuid'], $a['exported_at'], $b['story_uuid'], $b['exported_at']);
        $this->assertSame($a, $b);
    }

    public function testNotAZipAborts(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cb_notzip_');
        file_put_contents((string)$tmp, 'this is not a zip');
        try {
            $this->importer->import((string)$tmp, 'o1');
            $this->fail('expected ImportException');
        } catch (ImportException $e) {
            $this->assertSame(t('writing', 'error_import_not_zip'), $e->getMessage());
        } finally {
            @unlink((string)$tmp);
        }
        $this->assertSame(0, (int)$this->db->one('SELECT COUNT(*) AS c FROM projects')['c'], 'nothing written');
    }

    /** Builds a minimal valid bundle zip with optional overrides. */
    private function bundleZip(array $overrides = [], array $remove = []): string
    {
        $entries = [
            'bundle.json' => (string)json_encode([
                'format_version' => 1,
                'story_uuid' => 'src-story-uuid',
                'story_title' => 'Bundle story',
                'exported_at' => '2024-01-01T00:00:00Z',
                'object_counts' => [],
            ]),
            'story.md' => "---\nuuid: \"src-story-uuid\"\ntype: \"story\"\ntitle: \"Bundle story\"\ncreated_at: \"2024-01-01 00:00:00\"\n---\n\n## Idea\n\nAn idea.\n\n## Short Description\n\nA blurb.\n\n## Extended Synopsis\n\nA synopsis.\n",
            'synopses/outline.md' => "---\nuuid: \"src-syn-1\"\nslug: \"outline\"\ntype: \"chapter_synopsis\"\ntitle: \"Outline one\"\ncreated_at: \"2024-01-01 00:00:00\"\n---\n\nThe summary text.\n",
            'chapters/chapter-1.md' => "---\nuuid: \"src-ch-1\"\nslug: \"chapter-1\"\ntype: \"chapter\"\ntitle: \"Chapter one\"\ncreated_at: \"2024-01-01 00:00:00\"\nsynopsis_uuid: \"src-syn-1\"\nupdated_at: \"2024-01-01 00:00:00\"\n---\n\nChapter body.\n",
            'characters/hero.md' => "---\nuuid: \"src-ca-1\"\nslug: \"hero\"\ntype: \"character\"\ntitle: \"Hero\"\ncreated_at: \"2024-01-01 00:00:00\"\nupdated_at: \"2024-01-01 00:00:00\"\n---\n\nHero body.\n",
            'characters/villain.md' => "---\nuuid: \"src-ca-2\"\nslug: \"villain\"\ntype: \"character\"\ntitle: \"Villain\"\ncreated_at: \"2024-01-01 00:00:00\"\nupdated_at: \"2024-01-01 00:00:00\"\n---\n\nVillain body.\n",
            'relations.json' => (string)json_encode(['relations' => [
                ['uuid' => 'src-rel-1', 'from_uuid' => 'src-ca-1', 'to_uuid' => 'src-ca-2', 'kind' => 'sibling', 'conflict' => 'feud'],
            ]]),
        ];
        foreach ($remove as $name) {
            unset($entries[$name]);
        }
        $entries = array_merge($entries, $overrides);
        $tmp = tempnam(sys_get_temp_dir(), 'cb_bundle_') . '.zip';
        $zip = new \ZipArchive();
        $opened = $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new \RuntimeException('could not create test bundle zip');
        }
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        return $tmp;
    }

    public function testMissingManifestAborts(): void
    {
        $zipPath = $this->bundleZip([], ['bundle.json']);
        try {
            $this->importer->import($zipPath, 'o1');
            $this->fail('expected ImportException');
        } catch (ImportException $e) {
            $this->assertSame(t('writing', 'error_import_missing_manifest'), $e->getMessage());
        } finally {
            @unlink($zipPath);
        }
        $this->assertSame(0, (int)$this->db->one('SELECT COUNT(*) AS c FROM projects')['c']);
    }

    public function testUnsupportedVersionAborts(): void
    {
        $zipPath = $this->bundleZip([
            'bundle.json' => (string)json_encode(['format_version' => 2, 'story_uuid' => 'x']),
        ]);
        try {
            $this->importer->import($zipPath, 'o1');
            $this->fail('expected ImportException');
        } catch (ImportException $e) {
            $this->assertSame(t('writing', 'error_import_unsupported_version'), $e->getMessage());
        } finally {
            @unlink($zipPath);
        }
        $this->assertSame(0, (int)$this->db->one('SELECT COUNT(*) AS c FROM projects')['c']);
    }

    public function testMissingStoryAborts(): void
    {
        $zipPath = $this->bundleZip([], ['story.md']);
        try {
            $this->importer->import($zipPath, 'o1');
            $this->fail('expected ImportException');
        } catch (ImportException $e) {
            $this->assertSame(t('writing', 'error_import_missing_story'), $e->getMessage());
        } finally {
            @unlink($zipPath);
        }
        $this->assertSame(0, (int)$this->db->one('SELECT COUNT(*) AS c FROM projects')['c']);
    }

    public function testDanglingSynopsisAborts(): void
    {
        $zipPath = $this->bundleZip([], ['synopses/outline.md']);
        try {
            $this->importer->import($zipPath, 'o1');
            $this->fail('expected ImportException');
        } catch (ImportException $e) {
            $this->assertSame(t('writing', 'error_import_dangling_synopsis'), $e->getMessage());
        } finally {
            @unlink($zipPath);
        }
        $this->assertSame(0, (int)$this->db->one('SELECT COUNT(*) AS c FROM projects')['c'], 'transaction rolled back');
    }

    public function testDanglingRelationAborts(): void
    {
        $zipPath = $this->bundleZip([
            'relations.json' => (string)json_encode(['relations' => [
                ['from_uuid' => 'src-ca-1', 'to_uuid' => 'unknown-character', 'kind' => 'sibling', 'conflict' => ''],
            ]]),
        ]);
        try {
            $this->importer->import($zipPath, 'o1');
            $this->fail('expected ImportException');
        } catch (ImportException $e) {
            $this->assertSame(t('writing', 'error_import_dangling_relation'), $e->getMessage());
        } finally {
            @unlink($zipPath);
        }
        $this->assertSame(0, (int)$this->db->one('SELECT COUNT(*) AS c FROM projects')['c'], 'transaction rolled back');
    }

    public function testPathTraversalEntryAborts(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cb_bundle_') . '.zip';
        $zip = new \ZipArchive();
        $opened = $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new \RuntimeException('could not create test bundle zip');
        }
        $zip->addFromString('bundle.json', (string)json_encode(['format_version' => 1, 'story_uuid' => 'x']));
        $zip->addFromString('story.md', "---\ntitle: \"T\"\n---\n\n## Idea\n\nx\n");
        $zip->addFromString('../evil.md', 'evil');
        $zip->close();
        try {
            $this->importer->import($tmp, 'o1');
            $this->fail('expected ImportException');
        } catch (ImportException $e) {
            $this->assertSame(t('writing', 'error_import_invalid'), $e->getMessage());
        } finally {
            @unlink($tmp);
        }
        $this->assertSame(0, (int)$this->db->one('SELECT COUNT(*) AS c FROM projects')['c']);
    }

    public function testBrokenFrontmatterAborts(): void
    {
        $zipPath = $this->bundleZip([
            'chapters/chapter-1.md' => "---\nuuid \"missing colon\"\n---\n\nbody\n",
        ]);
        try {
            $this->importer->import($zipPath, 'o1');
            $this->fail('expected ImportException');
        } catch (ImportException $e) {
            $this->assertSame(t('writing', 'error_import_invalid_frontmatter'), $e->getMessage());
        } finally {
            @unlink($zipPath);
        }
        $this->assertSame(0, (int)$this->db->one('SELECT COUNT(*) AS c FROM projects')['c']);
    }

    public function testSlugCollisionDeduplicated(): void
    {
        // Two chapters with the same source slug in one bundle (each
        // with its own synopsis - the schema enforces one chapter per
        // synopsis).
        $zipPath = $this->bundleZip([
            'synopses/outline-2.md' => "---\nuuid: \"src-syn-2\"\nslug: \"outline-2\"\ntype: \"chapter_synopsis\"\ntitle: \"Outline two\"\ncreated_at: \"2024-01-01 00:00:00\"\n---\n\nSecond summary.\n",
            'chapters/chapter-1.md' => "---\nuuid: \"src-ch-1\"\nslug: \"same-slug\"\ntype: \"chapter\"\ntitle: \"Chapter one\"\ncreated_at: \"2024-01-01 00:00:00\"\nsynopsis_uuid: \"src-syn-1\"\nupdated_at: \"2024-01-01 00:00:00\"\n---\n\nBody one.\n",
            'chapters/chapter-2.md' => "---\nuuid: \"src-ch-2\"\nslug: \"same-slug\"\ntype: \"chapter\"\ntitle: \"Chapter two\"\ncreated_at: \"2024-01-01 00:00:00\"\nsynopsis_uuid: \"src-syn-2\"\nupdated_at: \"2024-01-01 00:00:00\"\n---\n\nBody two.\n",
        ]);
        $result = $this->importer->import($zipPath, 'o1');
        @unlink($zipPath);
        $slugs = array_column($this->db->all(
            'SELECT slug FROM chapters WHERE project_id = ? ORDER BY slug',
            [$result['id']]
        ), 'slug');
        $this->assertSame(['same-slug', 'same-slug-2'], $slugs);
    }

    public function testUnknownKindAllowed(): void
    {
        $zipPath = $this->bundleZip([
            'relations.json' => (string)json_encode(['relations' => [
                ['from_uuid' => 'src-ca-1', 'to_uuid' => 'src-ca-2', 'kind' => 'legacy_custom_kind', 'conflict' => ''],
            ]]),
        ]);
        $result = $this->importer->import($zipPath, 'o1');
        @unlink($zipPath);
        $relation = $this->db->one('SELECT kind FROM character_relations WHERE project_id = ?', [$result['id']]);
        $this->assertSame('legacy_custom_kind', (string)$relation['kind']);
    }

    public function testFormRenders(): void
    {
        $response = $this->importController->show($this->owner);
        $this->assertSame(200, $response->status());
        $body = $response->body();
        $this->assertStringContainsString(t('writing', 'import_title'), $body);
        $this->assertStringContainsString('name="bundle"', $body);
        $this->assertStringContainsString('enctype="multipart/form-data"', $body);
    }

    public function testPostWithoutFileIs422(): void
    {
        $_FILES = [];
        $response = $this->importController->import($this->owner);
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString(t('writing', 'error_import_no_file'), $response->body());
    }

    public function testPostWithValidBundleRedirectsToNewDashboard(): void
    {
        $projectId = $this->buildStory();
        $this->populate($projectId);
        $export = $this->exporter->export($projectId);

        $_FILES = [
            'bundle' => [
                'name' => 'bundle.zip',
                'type' => 'application/zip',
                'tmp_name' => $export['path'],
                'error' => UPLOAD_ERR_OK,
                'size' => (int)filesize($export['path']),
            ],
        ];
        $response = $this->importController->import($this->owner);
        $this->assertSame(302, $response->status());
        $location = (string)$response->header('Location');
        $this->assertStringContainsString('/writing/', $location);
        $this->assertStringContainsString('import=', $location);
        // The new story exists.
        $newId = (string)preg_replace('#^/writing/([^?]+).*$#', '$1', $location);
        $this->assertNotNull($this->db->one('SELECT id FROM projects WHERE id = ?', [rawurldecode($newId)]));
        @unlink($export['path']);
    }

    public function testImportRouteReserved(): void
    {
        // /writing/import must not be swallowed by /writing/{id}.
        $base = dirname(__DIR__);
        $auth = new Auth($this->db);
        $module = new WritingModule(
            $this->db,
            new \Core\View($base),
            new Csrf($this->db, $auth),
            new \Core\ContentRenderer($this->db, new ModuleRegistry())
        );
        $router = new \Core\Router();
        foreach ($module->routes($router) as $route) {
            $router->{$route['method'] === 'GET' ? 'get' : 'post'}($route['pattern'], $route['handler'], $route['roles']);
        }
        $route = $router->dispatch('GET', '/writing/import');
        $this->assertSame(['user'], $route['roles']);
    }
}
