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
use Writing\ExportUnavailableException;
use Writing\ProjectAccess;
use Writing\StoryExportProvider;
use Writing\StoryExportController;
use Writing\StoryExporter;
use Writing\WritingModule;

final class StoryExportTest extends TestCase
{
    private Database $db;
    private StoryExporter $exporter;
    private StoryExportController $controller;
    private User $owner;
    private User $member;
    private User $stranger;

    protected function setUp(): void
    {
        $base = dirname(__DIR__);
        $path = sys_get_temp_dir() . '/cb_test_' . uniqid() . '.sqlite';
        $this->db = new Database($path);
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        $auth = new Auth($this->db);
        $module = new WritingModule(
            $this->db,
            new \Core\View($base),
            new Csrf($this->db, $auth),
            new \Core\ContentRenderer($this->db, new \Core\ModuleRegistry())
        );
        (new Migrator($this->db))->migrate('writing', $module->migrations());
        $this->exporter = new StoryExporter($this->db);
        $this->controller = new StoryExportController($this->db, new ProjectAccess($this->db));
        foreach ([['o1', 'owner'], ['m1', 'member'], ['s1', 'stranger']] as [$id, $name]) {
            $this->db->run(
                'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
                [$id, $name, password_hash('x1234567', PASSWORD_BCRYPT), 'user']
            );
        }
        $this->owner = $this->user('o1');
        $this->member = $this->user('m1');
        $this->stranger = $this->user('s1');
        $this->db->run(
            "INSERT INTO projects (id, owner_id, title, idea, blurb, synopsis_long) VALUES ('p1', 'o1', 'My novel', 'A dark tale.', 'Setup and ending.', 'The full story.')"
        );
        $this->db->run("INSERT INTO project_members (project_id, user_id, role) VALUES ('p1', 'm1', 'member')");
        $_POST = [];
    }

    private function user(string $id): User
    {
        return User::fromRow($this->db->one('SELECT id, username, role FROM users WHERE id = ?', [$id]));
    }

    private function addSynopsis(string $id, string $slug, string $title, string $summary): void
    {
        $this->db->run(
            "INSERT INTO chapter_synopses (id, project_id, slug, title, summary_text) VALUES (?, 'p1', ?, ?, ?)",
            [$id, $slug, $title, $summary]
        );
    }

    private function addChapter(string $id, string $slug, string $title, string $body, string $synopsisId): void
    {
        $this->db->run(
            "INSERT INTO content_objects (id, type, slug, owner_id) VALUES (?, 'chapter', ?, 'o1')",
            [$id, $slug]
        );
        $this->db->run(
            "INSERT INTO chapters (id, project_id, chapter_synopsis_id, slug) VALUES (?, 'p1', ?, ?)",
            [$id, $synopsisId, $slug]
        );
        $this->db->run(
            'INSERT INTO content_revisions (id, object_id, title, summary, body, author_id) VALUES (?, ?, ?, ?, ?, ?)',
            [Auth::uuid4(), $id, $title, '', $body, 'o1']
        );
    }

    private function addTypedObject(string $type, string $id, string $slug, string $title, string $body): void
    {
        $this->db->run(
            "INSERT INTO content_objects (id, type, slug, owner_id) VALUES (?, ?, ?, 'o1')",
            [$id, $type, $slug]
        );
        $this->db->run(
            "INSERT INTO {$type}s (id, project_id, slug) VALUES (?, 'p1', ?)",
            [$id, $slug]
        );
        $this->db->run(
            'INSERT INTO content_revisions (id, object_id, title, summary, body, author_id) VALUES (?, ?, ?, ?, ?, ?)',
            [Auth::uuid4(), $id, $title, '', $body, 'o1']
        );
    }

    private function addRelation(string $from, string $to, string $kind, string $conflict): void
    {
        $this->db->run(
            "INSERT INTO character_relations (id, project_id, from_character_id, to_character_id, kind, description) VALUES (?, 'p1', ?, ?, ?, ?)",
            [Auth::uuid4(), $from, $to, $kind, $conflict]
        );
    }

    private function populate(): void
    {
        $this->addSynopsis('syn1', 'syn-chapter-one', 'Chapter one: The Beginning', 'The hero arrives.');
        $this->addChapter('ch1', 'chapter-one', 'Chapter one', "# Chapter one\n\nIt begins.", 'syn1');
        $this->addTypedObject('character', 'c1', 'hero', 'Hero: A "Colon" Case', 'Biography of the hero.');
        $this->addTypedObject('character', 'c2', 'villain', 'Villain', 'Biography of the villain.');
        $this->addTypedObject('background', 'b1', 'old-town', 'Old Town - 1923', 'A smoky city.');
        $this->addRelation('c1', 'c2', 'rival', 'old feud: hatred');
    }

    /** @return array<string, string> zip entry name => content */
    private function unzip(string $binary): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cb_export_test_');
        file_put_contents($tmp, $binary);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($tmp) === true);
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $this->assertIsString($name);
            $content = $zip->getFromName($name);
            $entries[$name] = (string)$content;
        }
        $zip->close();
        @unlink($tmp);
        return $entries;
    }

    /** @return array<string, string> parsed frontmatter of a markdown file */
    private function frontmatter(string $file): array
    {
        $this->assertStringStartsWith("---\n", $file);
        $end = strpos($file, "\n---\n", 4);
        $this->assertIsInt($end);
        $block = substr($file, 4, $end - 4);
        $map = [];
        foreach (explode("\n", $block) as $line) {
            $pos = strpos($line, ': ');
            if ($pos === false) {
                continue;
            }
            $key = substr($line, 0, $pos);
            $value = substr($line, $pos + 2);
            $map[$key] = $value;
        }
        return $map;
    }

    /** strips the frontmatter block and returns the raw body */
    private function bodyOf(string $file): string
    {
        $end = strpos($file, "\n---\n", 4);
        $this->assertIsInt($end);
        // file() inserts one blank line after the closing --- separator;
        // the raw body starts after that newline.
        return (string)substr($file, $end + 6);
    }

    public function testZipContainsAllEntityFiles(): void
    {
        $this->populate();
        $result = $this->exporter->export('p1');
        $binary = (string)file_get_contents($result['path']);
        @unlink($result['path']);
        $entries = $this->unzip($binary);
        $this->assertArrayHasKey('story.md', $entries);
        $this->assertArrayHasKey('synopses/syn-chapter-one.md', $entries);
        $this->assertArrayHasKey('chapters/chapter-one.md', $entries);
        $this->assertArrayHasKey('characters/hero.md', $entries);
        $this->assertArrayHasKey('characters/villain.md', $entries);
        $this->assertArrayHasKey('backgrounds/old-town.md', $entries);
        $this->assertArrayHasKey('relations.json', $entries);
        $this->assertArrayHasKey('bundle.json', $entries);
    }

    public function testFrontmatterIsDoubleQuotedYamlAndRoundTripsSpecialCharacters(): void
    {
        $this->populate();
        $result = $this->exporter->export('p1');
        $binary = (string)file_get_contents($result['path']);
        @unlink($result['path']);
        $entries = $this->unzip($binary);

        $hero = $this->frontmatter($entries['characters/hero.md']);
        $this->assertSame('"Hero: A \\"Colon\\" Case"', $hero['title']);
        $unquoted = json_decode('"' . substr($hero['title'], 1, -1) . '"');
        $this->assertSame('Hero: A "Colon" Case', $unquoted);

        $background = $this->frontmatter($entries['backgrounds/old-town.md']);
        $this->assertSame('"Old Town - 1923"', $background['title']);

        $chapter = $this->frontmatter($entries['chapters/chapter-one.md']);
        $this->assertSame('"chapter-one"', $chapter['slug']);
        $this->assertSame('"syn1"', $chapter['synopsis_uuid']);
        $this->assertArrayHasKey('updated_at', $chapter);

        $story = $this->frontmatter($entries['story.md']);
        $this->assertSame('"My novel"', $story['title']);
        $this->assertSame('"p1"', $story['uuid']);
    }

    public function testBodyEqualsStoredMarkdownExactly(): void
    {
        $this->populate();
        $result = $this->exporter->export('p1');
        $binary = (string)file_get_contents($result['path']);
        @unlink($result['path']);
        $entries = $this->unzip($binary);

        $body = $this->bodyOf($entries['chapters/chapter-one.md']);
        $this->assertSame("# Chapter one\n\nIt begins.\n", $body);
        $synopsisBody = $this->bodyOf($entries['synopses/syn-chapter-one.md']);
        $this->assertSame("The hero arrives.\n", $synopsisBody);
        $this->assertStringContainsString('A dark tale.', $this->bodyOf($entries['story.md']));
    }

    public function testRelationsJsonContainsAllRelationsWithUuidReferences(): void
    {
        $this->populate();
        $result = $this->exporter->export('p1');
        $binary = (string)file_get_contents($result['path']);
        @unlink($result['path']);
        $entries = $this->unzip($binary);

        $data = json_decode($entries['relations.json'], true);
        $this->assertIsArray($data);
        $relations = $data['relations'] ?? null;
        $this->assertIsArray($relations);
        $this->assertCount(1, $relations);
        $this->assertSame('c1', $relations[0]['from_uuid']);
        $this->assertSame('c2', $relations[0]['to_uuid']);
        $this->assertSame('rival', $relations[0]['kind']);
        $this->assertSame('old feud: hatred', $relations[0]['conflict']);
        $this->assertNotSame('', (string)$relations[0]['uuid']);
    }

    public function testBundleJsonCountsAndVersion(): void
    {
        $this->populate();
        $result = $this->exporter->export('p1');
        $binary = (string)file_get_contents($result['path']);
        @unlink($result['path']);
        $entries = $this->unzip($binary);

        $bundle = json_decode($entries['bundle.json'], true);
        $this->assertIsArray($bundle);
        $this->assertSame(1, $bundle['format_version']);
        $this->assertSame('p1', $bundle['story_uuid']);
        $this->assertSame('My novel', $bundle['story_title']);
        $this->assertSame(
            ['synopses' => 1, 'chapters' => 1, 'characters' => 2, 'backgrounds' => 1, 'relations' => 1],
            $bundle['object_counts']
        );
    }

    public function testOwnerCanDownloadViaController(): void
    {
        $this->populate();
        $response = $this->controller->export($this->owner, 'p1');
        $this->assertSame(200, $response->status());
        $this->assertSame('application/zip', $response->header('Content-Type'));
        $disposition = $response->header('Content-Disposition');
        $this->assertIsString($disposition);
        $this->assertStringStartsWith('attachment; filename="', $disposition);
        $this->assertStringEndsWith('.zip"', $disposition);
    }

    public function testMemberCanDownload(): void
    {
        $this->populate();
        $response = $this->controller->export($this->member, 'p1');
        $this->assertSame(200, $response->status());
    }

    public function testStrangerGetsNoZip(): void
    {
        $this->populate();
        $response = $this->controller->export($this->stranger, 'p1');
        $this->assertSame(403, $response->status());
        $this->assertSame('', $response->body());
    }

    public function testEmptyStoryProducesMinimalValidZip(): void
    {
        $result = $this->exporter->export('p1');
        $binary = (string)file_get_contents($result['path']);
        @unlink($result['path']);
        $entries = $this->unzip($binary);
        $this->assertSame(['story.md', 'bundle.json'], array_keys($entries));
        $bundle = json_decode($entries['bundle.json'], true);
        $this->assertSame(0, $bundle['object_counts']['chapters']);
        $story = $this->frontmatter($entries['story.md']);
        $this->assertSame('"My novel"', $story['title']);
    }

    public function testDownloadFilenameIsSlugifiedStoryTitle(): void
    {
        $this->populate();
        $result = $this->exporter->export('p1');
        @unlink($result['path']);
        $this->assertSame('my-novel.zip', $result['filename']);
    }

    public function testUnsafeSlugsAreSanitizedInArchivePaths(): void
    {
        $this->addSynopsis('syn-x', 'weird slug/../walk', 'X', 'x');
        $this->addChapter('ch-x', 'bad/slug', 'X', 'body', 'syn-x');
        $result = $this->exporter->export('p1');
        $binary = (string)file_get_contents($result['path']);
        @unlink($result['path']);
        $entries = $this->unzip($binary);
        $this->assertArrayHasKey('synopses/weird-slug-walk.md', $entries);
        $this->assertArrayHasKey('chapters/bad-slug.md', $entries);
        foreach (array_keys($entries) as $name) {
            $this->assertStringNotContainsString('..', $name);
        }
    }


    public function testMissingZipExtensionRendersFriendlyErrorPageNotFatal(): void
    {
        // A server without ext-zip cannot build the archive; the export
        // must render a translated error page (503) instead of a fatal
        // "Class ZipArchive not found".
        $failing = new class implements StoryExportProvider {
            public function export(string $projectId): array
            {
                throw new ExportUnavailableException('The PHP ZIP extension (ext-zip) is not installed.');
            }
        };
        $base = dirname(__DIR__);
        $auth = new Auth($this->db);
        $csrf = new Csrf($this->db, $auth);
        $controller = new StoryExportController($this->db, new ProjectAccess($this->db), new \Core\View($base), $csrf, $failing);
        $response = $controller->export($this->owner, 'p1');
        $this->assertSame(503, $response->status());
        $body = $response->body();
        $this->assertStringContainsString(t('writing', 'error_export_unavailable'), $body);
        $this->assertStringNotContainsString('Fatal error', $body);
        $this->assertStringNotContainsString('ZipArchive', $body);
    }

    public function testBrokenExportRendersFriendlyErrorPage(): void
    {
        // Other server-side export problems (temp dir, disk space)
        // render a translated 500 page instead of escaping.
        $failing = new class implements StoryExportProvider {
            public function export(string $projectId): array
            {
                throw new \RuntimeException('Could not create a temporary file.');
            }
        };
        $base = dirname(__DIR__);
        $auth = new Auth($this->db);
        $csrf = new Csrf($this->db, $auth);
        $controller = new StoryExportController($this->db, new ProjectAccess($this->db), new \Core\View($base), $csrf, $failing);
        $response = $controller->export($this->owner, 'p1');
        $this->assertSame(500, $response->status());
        $this->assertStringContainsString(t('writing', 'error_export_failed'), $response->body());
    }

    public function testExporterGuardsZipArchiveBeforeWorking(): void
    {
        // The exporter itself must check the extension first: a missing
        // ZipArchive class yields ExportUnavailableException, never a
        // fatal Error. Simulated by the anonymous subclass; the guard
        // order in StoryExporter::export() is additionally asserted by
        // reflection so a reorder cannot slip through unnoticed.
        $method = new \ReflectionMethod(StoryExporter::class, 'export');
        $source = (string)file_get_contents($method->getFileName());
        $offset = $method->getStartLine();
        $end = $method->getEndLine();
        $lines = explode("\n", $source);
        $body = implode("\n", array_slice($lines, $offset - 1, $end - $offset + 1));
        $this->assertStringContainsString("class_exists('ZipArchive')", $body);
        $guardPos = strpos($body, "class_exists('ZipArchive')");
        $newPos = strpos($body, 'new \ZipArchive');
        $this->assertNotFalse($newPos);
        $this->assertLessThan($newPos, $guardPos, 'the extension guard must run before the ZipArchive is built');
    }
}
