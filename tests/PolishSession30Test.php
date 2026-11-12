<?php

declare(strict_types=1);

namespace Tests\Polish;

use Core\Auth;
use Core\ContentRenderer;
use Core\CoreMigrations;
use Core\Csrf;
use Core\Database;
use Core\Migrator;
use Core\ModuleRegistry;
use Core\Translator;
use Core\User;
use Core\View;
use PHPUnit\Framework\TestCase;
use Writing\ProjectAccess;
use Writing\WritingController;
use Writing\WritingModule;

/**
 * Prompt 30 - polish session: (1) spellcheck off on coarse pointers, (2) dashboard
 * idea/blurb/synopsis as full Markdown with read-more at the personal target,
 * (3) safe server-side Markdown via league/commonmark, (4) story menu as anchored
 * dropdown on all viewports (bottom sheet removed).
 */
final class PolishSession30Test extends TestCase
{
    private string $base;
    private bool $translatorWasInitialized = false;
    private Database $db;
    private Auth $auth;
    private User $admin;

    protected function setUp(): void
    {
        $this->base = dirname(__DIR__);
        $this->translatorWasInitialized = Translator::instance() !== null;
        Translator::init($this->base . '/lang');
        Translator::instance()?->setLang('en');
        $path = sys_get_temp_dir() . '/cb_p30_' . uniqid() . '.sqlite';
        $this->db = new Database($path);
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        $module = new WritingModule($this->db, new View($this->base), new Csrf($this->db, new Auth($this->db)), new ContentRenderer($this->db, new ModuleRegistry()));
        (new Migrator($this->db))->migrate('writing', $module->migrations());
        $this->auth = new Auth($this->db);
        $this->db->run(
            'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
            ['a1', 'rootuser', password_hash('adminpw123', PASSWORD_BCRYPT), 'admin']
        );
        $this->admin = User::fromRow($this->db->one("SELECT id, username, role, language, theme, avatar FROM users WHERE id = 'a1'"));
    }

    protected function tearDown(): void
    {
        unset($this->db, $this->auth, $this->admin);
        if (!$this->translatorWasInitialized) {
            $prop = new \ReflectionProperty(Translator::class, 'instance');
            $prop->setValue(null, null);
        }
    }

    // ---- Part 3: markdown rendering + security ----

    public function testMarkdownRendersCommonSubset(): void
    {
        $html = markdown_html("# Head\n\nSome **bold** and *italic* and `code`.\n\n- one\n- two\n\n1. first\n\n> quoted\n\n[link](https://example.com)");
        foreach (['<h1>Head</h1>', '<strong>bold</strong>', '<em>italic</em>', '<code>code</code>', '<ul>', '<li>one</li>', '<ol>', '<blockquote>', 'href="https://example.com"', 'rel="noopener"', '>link</a>'] as $needle) {
            $this->assertStringContainsString($needle, $html, 'markdown output missing: ' . $needle);
        }
    }

    public function testMarkdownEscapesRawHtmlAndBlocksUnsafeLinks(): void
    {
        $html = markdown_html('<script>alert(1)</script>');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $js = markdown_html('[x](javascript:alert(1))');
        $this->assertStringNotContainsString('javascript:', $js);
        $this->assertStringNotContainsString('<a ', $js, 'unsafe scheme must not become a link');
        $data = markdown_html('[x](data:text/html,<script>alert(1)</script>)');
        $this->assertStringNotContainsString('data:text/html', $data);
    }

    public function testReadMoreBlockThresholds(): void
    {
        $short = read_more_block('short text', 150);
        $this->assertStringNotContainsString('read-more__toggle', $short, '<= target shows fully, no toggle');
        $this->assertStringContainsString('short text', $short);
        $long = read_more_block(str_repeat('word ', 200), 150);
        $this->assertStringContainsString('read-more__toggle', $long, '> target gets the toggle');
        $this->assertStringContainsString('aria-expanded="false"', $long);
        $this->assertStringContainsString('data-collapsed="1"', $long);
        $this->assertSame(200, substr_count($long, 'word'), 'full text is in the HTML before any toggle');
        $this->assertStringContainsString('Show more', $long);
        $full = read_more_block('any text at all', 0);
        $this->assertStringNotContainsString('read-more__toggle', $full, 'target 0 = always full (idea)');
    }

    // ---- Part 2: dashboard render ----

    /**
     * project.php declares functions - render at most once per process.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testDashboardRendersFullMarkdownWithReadMoreAtTarget(): void
    {
        $longBlurb = '**Bold** intro. ' . str_repeat('lorem ipsum dolor ', 30);
        $idea = 'First sentence here. Second sentence with **markdown** stays.';
        $this->db->run("INSERT INTO projects (id, owner_id, title, idea, blurb) VALUES ('p1', 'a1', 'Novel', ?, ?)", [$idea, $longBlurb]);
        $this->db->run("UPDATE project_targets SET target_words_blurb = 20 WHERE project_id = 'p1'");
        $controller = new WritingController($this->db, new View($this->base), new Csrf($this->db, $this->auth), new ProjectAccess($this->db), new ContentRenderer($this->db, new ModuleRegistry()));
        $body = $controller->show($this->admin, 'p1')->body();
        // idea: full text, no first-sentence truncation, markdown rendered
        $this->assertStringContainsString('Second sentence with <strong>markdown</strong> stays.', $body);
        // blurb: markdown rendered + full text present + collapsed with toggle (target 20 words)
        $this->assertStringContainsString('<strong>Bold</strong> intro.', $body);
        $this->assertSame(30, substr_count($body, 'lorem ipsum dolor'), 'full blurb in HTML');
        $this->assertStringContainsString('read-more__toggle', $body);
        $this->assertStringContainsString('aria-expanded="false"', $body);
        $this->assertStringContainsString('Show more', $body);
        $this->assertStringNotContainsString('clampWords', $body);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testDashboardShortBlurbHasNoToggle(): void
    {
        $this->db->run("INSERT INTO projects (id, owner_id, title, blurb) VALUES ('p1', 'a1', 'Novel', 'tiny blurb')");
        $this->db->run("UPDATE project_targets SET target_words_blurb = 150 WHERE project_id = 'p1'");
        $controller = new WritingController($this->db, new View($this->base), new Csrf($this->db, $this->auth), new ProjectAccess($this->db), new ContentRenderer($this->db, new ModuleRegistry()));
        $body = $controller->show($this->admin, 'p1')->body();
        $this->assertStringContainsString('tiny blurb', $body);
        $this->assertStringNotContainsString('read-more__toggle', $body);
    }

    /**
     * index.php slider stays plain text (documented exception).
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSliderStaysPlainText(): void
    {
        $this->db->run("INSERT INTO projects (id, owner_id, title) VALUES ('p1', 'a1', 'Novel')");
        $this->db->run("INSERT INTO chapter_synopses (id, project_id, slug, title) VALUES ('s1', 'p1', 'o', 'O')");
        $this->db->run("INSERT INTO chapters (id, project_id, chapter_synopsis_id, slug) VALUES ('c1', 'p1', 's1', 'ch1')");
        $this->db->run("INSERT INTO content_objects (id, type, slug, owner_id) VALUES ('c1', 'chapter', 'ch1', 'a1')");
        $this->db->run("INSERT INTO content_revisions (id, object_id, title, body, author_id) VALUES ('r1', 'c1', 'Ch1', '**not markdown** in slider' || char(10) || char(10) || 'second paragraph to pass MIN_PARAGRAPHS', 'a1')");
        $db2 = $this->db;
        $controller = new WritingController($db2, new View($this->base), new Csrf($db2, $this->auth), new ProjectAccess($db2), new ContentRenderer($db2, new ModuleRegistry()));
        $body = $controller->index($this->admin)->body();
        $this->assertStringContainsString('**not markdown** in slider', $body, 'slider shows escaped plain text, no <strong>');
        $this->assertStringNotContainsString('<strong>not markdown</strong>', $body);
    }

    // ---- Part 1 + Part 4: static assertions ----

    public function testSpellcheckDisabledOnCoarsePointersOnly(): void
    {
        $js = (string)file_get_contents($this->base . '/public/assets/js/app.js');
        $this->assertStringContainsString("matchMedia('(pointer: coarse)')", $js);
        $this->assertStringContainsString("spellcheck', 'false'", $js);
        $this->assertStringContainsString('.editor-prose', $js);
        $this->assertStringContainsString('MutationObserver', $js, 'bundle creates .editor-prose after load - one-shot observer');
        $this->assertStringNotContainsString("matchMedia('(pointer: fine)')", $js, 'no fine-pointer branch - desktop untouched');
    }

    public function testAppJsHasReadMoreToggleHandler(): void
    {
        $js = (string)file_get_contents($this->base . '/public/assets/js/app.js');
        $this->assertStringContainsString('.read-more__toggle', $js);
        $this->assertStringContainsString("aria-expanded", $js);
        $this->assertStringContainsString('dataset.less', $js);
        $this->assertStringContainsString('dataset.more', $js);
    }

    public function testStoryMenuIsAnchoredDropdownEverywhere(): void
    {
        $css = (string)file_get_contents($this->base . '/public/assets/css/master.css');
        $this->assertStringNotContainsString('.story-menu { position: fixed', $css, 'bottom sheet removed project-wide');
        $this->assertStringContainsString('.story-menu { position: absolute; right: 0; top: calc(100% + 0.25rem);', $css);
        $this->assertStringContainsString('.story-menu-wrap { position: relative;', $css);
        // read-more clamp rules + whitelist
        $this->assertStringContainsString('-webkit-line-clamp: 6;', $css);
        $this->assertStringContainsString('.read-more[data-collapsed="0"] .read-more__clamp', $css);
        $rootEnd = strpos($css, '}', (int)strpos($css, ':root'));
        $outside = substr($css, 0, (int)strpos($css, ':root')) . substr($css, (int)$rootEnd + 1);
        $this->assertSame(0, (int)preg_match('/#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\(/', $outside));
    }

    public function testSwVersionV8AndI18nParity(): void
    {
        $sw = (string)file_get_contents($this->base . '/public/sw.js');
        $this->assertStringContainsString("const VERSION = 'v8'", $sw);
        foreach (['en', 'de', 'es', 'fr'] as $code) {
            $src = (string)file_get_contents($this->base . '/lang/' . $code . '/writing.php');
            $this->assertStringContainsString("'show_more'", $src, $code);
            $this->assertStringContainsString("'show_less'", $src, $code);
        }
    }
}
