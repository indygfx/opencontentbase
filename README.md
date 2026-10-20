# ContentBase

ContentBase is a modular content platform that deliberately favors lightness: PHP 8.2 and SQLite instead of a heavy framework – Composer serves only as an autoloader. At its core the system works with generic content objects (UUID, type, slug) instead of fixed tables; actual functionality comes from modules that follow a uniform interface and bring their own tables and versioned migrations. The core knows nothing about module internals – it manages only what modules report through defined channels.

Content is written in Markdown (CommonMark with GFM), with security taking priority: raw HTML is escaped, unsafe links are neutralized. Internal references use the `[[type:slug]]` syntax and are resolved through the module registry; unresolvable links render as broken-link markers.
 
## Editor

All Markdown fields (chapter prose, outline fields, chapter synopsis summaries, page/character/background bodies) use a single-surface WYSIWYG-style editor built on Tiptap/ProseMirror (like the Nextcloud Text editor): formatting renders subtly in place – bold shows bold, headings show as headings – with no separate preview pane. Markdown remains the storage format.

- **Stack**: `@tiptap/core` directly (no Vue/React), StarterKit (includes Underline, Link, lists) plus Highlight, Table, TaskList/TaskItem, Placeholder ("Start writing...") and CharacterCount extensions, and the official `@tiptap/markdown` extension (load with `contentType: 'markdown'`, save with `editor.getMarkdown()`). Highlight round-trips as GFM `==text==`.
- **Toolbar** (top, sticky, Nextcloud-Text-style with Material Design SVG icons): undo/redo, headings dropdown (paragraph, H1–H3), bold, italic, underline, strikethrough, highlight, lists dropdown (bulleted, numbered, task), blocks dropdown (blockquote, code block), insert table, insert link. Buttons reflect the active state at the cursor via `aria-pressed`; undo/redo disable when the history is exhausted; a character counter sits at the right end of the toolbar.
- **Shortcuts**: Ctrl/Cmd+B/I, Ctrl/Cmd+K (link), Ctrl/Cmd+S saves the surrounding form.
- **Forms unchanged**: the editor writes `editor.getMarkdown()` back into the original hidden `<textarea>` on every update and on submit, so the server flow (CSRF, validation, Markdown persistence) is untouched; server-side rendering via league/commonmark stays the single source of truth for display.
- **Progressive enhancement**: without JavaScript the plain `<textarea>` keeps working as fallback.

### Build step (editor bundle)

The editor sources live in `assets/editor/main.js` and are bundled with esbuild into a single committed file `public/assets/js/editor.js`:

```bash
npm install
npm run build   # esbuild -> public/assets/js/editor.js
```

The built bundle is committed, so a plain PHP deployment needs no Node toolchain. Rebuild it whenever `assets/editor/` or the npm dependencies change.



Getting started is deliberately minimal – `composer install` and a built-in PHP server are enough. On first start the user admin/admin is created automatically (change it immediately!), and composer.lock is intentionally not checked in, so every system keeps its own locally pinned versions. Of the roadmap, the core, the content pipeline and the pages reference module are done; next up are a writing app module (projects, characters, relationships, chapters) and editor refinements such as diffs, a graph UI and per-object revisions.

## Architecture

- **Generic content objects**: `content_objects` (UUID, `type`, `slug`) instead of a fixed `pages` table in the core; modules bring their own tables.
- **ModuleInterface contract**: `id()`, `routes()`, `migrations()`, `contentTypes()`, `resolveLink()` – the core knows no module details.
- **Versioned migrations per module**: `schema_versions (module, version)`, executed by `Core\Migrator` in individual transactions.
- **Generalized internal links**: `[[type:slug]]` in content, resolved via the module registry (`ModuleRegistry::resolveLink`); unresolvable links render as broken-link markers.
- **CommonMark + GFM** via `league/commonmark` – raw HTML is escaped, unsafe links neutralized (`allow_unsafe_links: false`, `DisallowedRawHtml`).
- **Array-based i18n**: `lang/<code>/<domain>.php` files (English default + German); lookup falls back to English, then to the raw key – missing translations never break the UI.

## Stack

| Component | Choice |
| --- | --- |
| Language | PHP >= 8.2, `strict_types`, PSR-4 via Composer |
| Database | SQLite (WAL, `foreign_keys ON`, `busy_timeout 5000`) |
| Dependencies | `league/commonmark ^2.4` |
| Dev | `phpunit ^10`, `phpstan ^1.10` |
| CI | GitHub Actions: syntax check, phpstan, phpunit on every push/PR |
| Frontend | Server-rendered PHP templates, Tiptap-based WYSIWYG Markdown editor (esbuild bundle), minimal vanilla JS (Cmd+S save, auto slugs) |

## Smoke test (local, end-to-end)

`smoke-test.sh` boots the built-in PHP server on **127.0.0.1 only** with a fresh temporary database (your `data/contentbase.sqlite` is not touched) and probes the critical paths:

1. Login page renders
2. Login as `admin`/`admin` (created on first start)
3. Writing dashboard renders (`/writing`)
4. Account language switch to German via `POST /profile/language` (incl. CSRF) and German UI check
5. Language dropdown on the profile page
6. Switch back to English
7. Pages module and 404 handling

```bash
composer install
./smoke-test.sh
```

Already running your own instance? Probe it instead (uses the real database):

```bash
BASE_URL=http://localhost:8080 ./smoke-test.sh
```

The script needs `php-cli`, `php-sqlite3` and a command-line HTTP client, exits non-zero on failure and cleans up the temp instance on exit.

## Automated checks (CI)

On every push and every pull request, a check runs automatically via GitHub Actions (`.github/workflows/ci.yml`):

1. **Syntax check**: `php -l` over all PHP files in `src/`, `modules/`, `public/`
2. **Static analysis**: `phpstan` over `src/` and `modules/`
3. **Tests**: `phpunit` over `tests/`

Run the same checks locally:

```bash
vendor/bin/phpstan analyse src modules --no-progress
vendor/bin/phpunit --no-progress
```

A run takes about a minute; green check mark on the commit/PR = all good, red X = error message in the log.

## Getting started

```bash
composer install
php -S localhost:8080 -t public
```

> **Note on `composer.lock`:** The lock file is intentionally not checked in (see `.gitignore`). It is created locally by `composer install` and pins the exact package versions there. When debugging "works on my machine" situations or after `composer update`, remember that every system has its own lock file – document versions if needed.

On first start the user **admin / admin** is created automatically (change it immediately).

## Structure

```
src/Core/          Kernel, Router, Database, Migrator, Auth, Renderer, Registry, Translator
lang/              Language files: en/ (default) and de/, one file per domain (core, writing, pages)
modules/Pages/     Reference module (routes, migration, controller, templates)
modules/Writing/   Writing app: projects, chapter synopses, chapters, characters, relationships, backgrounds
templates/         Layout, Login, Profile, Users, Error
public/index.php   Front controller
```

## Languages (i18n)

The UI is fully translated via flat PHP array files – no gettext, no tooling, no build step.

- **Files**: `lang/<code>/<domain>.php` returns a `key => string` map. Domains: `core` (auth, profile, users, nav), `writing`, `pages`. English (`en/`) is the **default and source of truth**; `de/` is a complete German translation with an identical key set (enforced by `tests/TranslatorTest.php`, which fails if keys drift apart).
- **Lookup**: `t('domain', 'key', ['name' => $value])` – helper is globally available. Fallback chain: **active language → `en` → raw key**, so a half-finished language file never breaks the UI. `{name}` placeholders are replaced after lookup.
- **Language selection** (in order): per-account setting (`users.language`, set via the profile page) → `cb_lang` cookie → `Accept-Language` header → English default.
- **Account settings**: the profile page (`/profile`) has a language dropdown listing all available languages with their native names; `POST /profile/language` persists the choice (CSRF-protected, unknown codes rejected).

**Add your own language** (this is the open-source workflow):

1. `cp -r lang/en lang/fr` (copy the English default)
2. Translate the values, keep the keys unchanged – a missing key simply falls back to English
3. Done – the language automatically appears in the profile dropdown (`Translator::availableLanguages()` scans `lang/`); add a `'lang_fr' => 'Français'` entry in `core.php` for the native name

For translators there is nothing to install: plain text editor, PHP array syntax, and the parity test reports untranslated keys on the next `phpunit` run.

## Writing module: story dashboard (snowflake)

Each story has a dashboard (`/writing/{id}`) that follows the snowflake method top-down:

- **Novel Setup** (`/writing/{id}/setup`): title and album cover upload (JPG, PNG, WebP or GIF, max 5 MB). Covers are stored in `data/covers/` and delivered through an access-checked route (`GET /writing/{id}/cover`); only the story owner can upload or remove a cover. The setup page also holds the "Progress Targets" fieldset (see below), editable by the owner only.
- **Idea**: the story's core in one sentence (logline).
- **Short Description** (blurb): the story in one paragraph – setup, three turning points, ending.
- **Extended Synopsis**: the whole story line in one detailed draft.
- **Chapter Outlines** (chapter synopses): the plan for each chapter – title and a short summary of what happens; each outline shows whether the prose is already written.
- **Characters**: appearance, biography, motivation and wounds.
- **Relationship Web**: who knows whom, how they are connected – and where the conflict lies.
- **Backgrounds**: the world your story stands on – its historical, geographical, religious and political context.

## Writing module: story excerpt slider

Once a user has written a real chapter (more than three paragraphs), the writing dashboard (`/writing`) shows a header slider with excerpts from their own stories:

- **Qualifying chapters**: only chapters of projects the user owns, with more than three paragraphs (paragraphs are separated by blank lines; empty or non-text blocks do not count).
- **Excerpt**: one random paragraph per qualifying chapter, reduced to plain text (Markdown markup stripped) and truncated to 80 words – at a sentence boundary if possible, otherwise with an ellipsis.
- **Slides**: at most three excerpts, each labelled with the story title and a link to the chapter.
- **Session-stable selection**: the random pick is deterministically seeded from the session CSRF token, so the same session always shows the same slides; a new session picks fresh excerpts.
- **Rendering**: CSS cross-fade without JavaScript for a single slide; `public/assets/js/story-slider.js` adds automatic rotation (8 s) and navigation dots for two or more slides.
- **Privacy**: only the user's own chapters are ever shown; shared stories of other users do not qualify.

## Writing module: story export as a Markdown bundle (format version 1)

Every story dashboard has an **Export** button (`GET /writing/{id}/export`, owner and members only) that downloads a ZIP archive – a portable, human-readable Markdown bundle with one file per object. The format is **stable (format version 1)** and documented here as the contract for import tools.

### ZIP layout

```
story.md                       story overview
synopses/{slug}.md             one file per chapter synopsis
chapters/{slug}.md             one file per written chapter
characters/{slug}.md           one file per character
backgrounds/{slug}.md          one file per background
relations.json                 relationship web as structured data
bundle.json                    manifest: format version, story metadata, object counts
```

### File format

- Each Markdown file starts with a **YAML frontmatter** block (`---` fenced): `uuid`, `slug` (where applicable), `type`, `title`, `created_at`, `updated_at` (latest revision, where tracked) and type-specific fields – chapters carry `synopsis_uuid` (the assigned synopsis).
- Frontmatter values are always double-quoted with escaped backslashes/quotes/control characters, so titles containing colons, quotes or dashes cannot inject YAML structure.
- The body below the frontmatter is the **stored raw Markdown, unmodified** (no re-rendering).
- `story.md` contains the Novel Setup metadata in frontmatter and the Idea / Short Description / Extended Synopsis as `##` sections.
- `relations.json` holds one entry per relation: `uuid`, `from_uuid`, `to_uuid`, `kind`, `conflict` (stored description) and a `uuids` object with all three ids for convenience.
- `bundle.json` records `format_version: 1`, `story_uuid`, `story_title`, `exported_at` (UTC) and `object_counts` per entity type.

### Implementation notes

- Built on PHP's bundled `ZipArchive`, writing to a temp file (no dependency additions).
- Slugs are sanitized to `[a-z0-9-]` for archive paths; download filename is the slugified story title (`{story-slug}.zip`).
- Empty stories produce a valid ZIP containing only `story.md` + `bundle.json`; `relations.json` is omitted when no relations exist.
- Tests: `tests/StoryExportTest.php` covers ZIP structure, frontmatter escaping and round-trip of special characters, exact body preservation, relations references, access control (stranger gets 403, no ZIP) and the empty-story case.

## Writing module: progress targets and dashboard progress bars

The owner defines measurable targets per section in **Novel Setup** ("Progress Targets" fieldset, `POST /writing/{id}/targets`, CSRF-protected). The dashboard then renders a slim progress bar under each section headline, computed server-side on every render (no JS, no persistence):

- **Targets table** (`project_targets`, one row per project, created with defaults on story creation): `target_chapters` (40), `target_words_total` (genre-dependent), `target_words_blurb` (150), `target_words_synopsis` (600), `target_min_characters` (3), `target_min_relations` (2), `target_backgrounds` (4). The genre is stored on `projects.genre`; a genre `<select>` auto-fills the total-words field live (pure JS, overridable).
- **Genre word defaults**: Fantasy 110,000 · Sci-Fi 100,000 · Romance 80,000 · Thriller/Crime 90,000 · Young Adult 70,000 · General 90,000. Existing stories are backfilled with `General`/90,000.
- **Calculation** (`ProjectProgress::calculate()`): ratio = actual / target, capped at 0–1.0. Word counts use the raw Markdown (with `[[...]]` links stripped); chapter prose is counted from the latest revision per chapter. The relations target is `target_min_characters × target_min_relations` (at least N relations per main character). Table sections count rows (`12 / 40 chapters`), text sections count words (`18,450 / 90,000 words`), the Idea section shows only a "set/missing" badge.
- **Overall progress**: a single weighted bar next to the title in the header – idea 5%, blurb 10%, synopsis 10%, outlines 15%, chapters 30%, characters 10%, relations 10%, backgrounds 10%.
- **Visibility**: progress bars are visible to all story members; the targets form is owner-only. Changing targets never deletes content – the bars simply recompute.
- **Tests**: `tests/ProjectProgressTest.php` covers word counting, genre defaults and fallbacks, the relations formula, latest-revision word counting, weighted overall progress and target updates (including invalid input and stranger access).

## Writing module: chapters are created from chapter synopses

The writing module separates planning from prose:

- **Chapter outlines (chapter_synopses)**: standalone entities per story that own the chapter title and an optional summary. Managed on the story dashboard ("Chapter Outlines") and on their own pages (`/writing/{id}/synopses/...`).
- **Written chapters (chapters)**: the actual prose. Every chapter has a mandatory, UNIQUE link to exactly one chapter synopsis (`chapters.chapter_synopsis_id`); the chapter takes its title from the assigned synopsis.
- **Create flow**: "New chapter" shows a dropdown of unassigned synopses of the story (server-side validated); the selected synopsis summary is displayed read-only above the Markdown editor.
- **Edit flow**: the assigned synopsis can be changed via dropdown (unassigned synopses plus the current one); the synopsis summary stays read-only; there is no title field on the chapter page (titles are edited only on the synopsis pages).
- **Delete rules**:
  - Deleting a written chapter deletes only the chapter text and detaches it from its synopsis; the synopsis (title + summary) remains intact and becomes available for a new chapter.
  - Deleting a synopsis that is currently assigned to a written chapter is blocked; the user is told to delete the assigned chapter text first or reassign it to another (or a new) synopsis.
- **Migration safety**: existing chapters are backfilled automatically - for each pre-existing chapter a matching synopsis (title from the chapter, summary from the chapter summary) is created and linked, so no data is lost.

Routes (per story `{id}`):

```
GET  /writing/{id}/synopses/new            new outline form (POST /writing/{id}/synopses creates it)
GET  /writing/{id}/synopses/{slug}         outline page
GET  /writing/{id}/synopses/{slug}/edit    edit outline (title + summary)
POST /writing/{id}/synopses/{slug}         update outline
POST /writing/{id}/synopses/{slug}/delete  delete outline (blocked while assigned)
GET  /writing/{id}/chapters/new            new chapter (synopsis dropdown + prose editor)
POST /writing/{id}/chapters                create chapter from a synopsis
GET  /writing/{id}/chapters/{slug}         chapter page
GET  /writing/{id}/chapters/{slug}/edit    edit chapter (prose + synopsis reassignment)
POST /writing/{id}/chapters/{slug}         update chapter
POST /writing/{id}/chapters/{slug}/delete  delete chapter text (synopsis is kept)
```

## Writing module: character portrait images

Characters can have one optional portrait image each (shown as a small
portrait above the character details):

- **Storage**: files live under `data/characters/<character-uuid>.<ext>`;
  the shared `ImageStore` helper is used for story covers and character
  portraits alike. The storage directory is never exposed directly -
  all access goes through the delivery route.
- **Upload rules** (server-side validated, same rules as story covers):
  JPG, PNG, WebP or GIF only, max 5 MB; the MIME type and the actual
  image content (`getimagesize`) are checked. Only story owner/members
  may upload.
- **One image per character**: uploading a new image replaces the old
  file on disk. "Remove image" (POST, CSRF-protected) deletes the file
  and sets `characters.image_path` to NULL (NULL = no image).
- **Delivery route**: `GET /writing/{id}/characters/{slug}/image`
  serves the stored file with the correct Content-Type; 404 when no
  image exists. Only story owner/members can access it (strangers get
  404, same guard pattern as the story cover route).
- **Delete cleanup**: deleting a character (controller or the central
  `ObjectDeleter` flow) removes its image file from disk.

## Writing module: mention-based character weighting

How often a character is *mentioned* in the chapter prose determines
its weight in the relationship graph:

- **Counting** (`modules/Writing/CharacterMentions.php`): case-insensitive
  occurrences of each character title in the chapter prose. Only the
  latest revision per chapter counts; alias support is out of scope.
- **On-demand cache**: counts are stored in `character_mention_counts`
  (story_id, character_id, total_mentions). A recount runs only when
  the chapter prose changed since the last count - detected via a
  cheap fingerprint (MAX revision timestamp + chapter count, stored in
  `character_mention_meta`). Reading never recomputes.
- **Level formula** (single authoritative place, `levelFor()`):
  `level = min(10, round(sqrt(mentions) * 1.5))` - 0 means never
  mentioned, 10 means protagonist-level.

## Writing module: weighted circular relationship graph

`GET /writing/{id}/graph` renders the character web as pure server-side
SVG (no JavaScript):

- **Layout** (`modules/Writing/RelationshipGraph.php`): characters with
  level >= 7 form the protagonist zone in the center (multiple
  protagonists on a small deterministic inner circle, sorted by level
  desc, then name). All other characters sit evenly on the outer ring
  (same deterministic order). Without a protagonist, everyone stays on
  the outer ring.
- **Nodes**: the radius scales with the level (~8px at level 0 up to
  ~24px at level 10). Characters with an image render as a circular
  clipped `<image>` (via the access-checked image route), others as a
  plain circle. The label renders below the node as `Name · L<level>`
  with a subtle background rectangle so edges stay readable. Nodes link
  to the character pages.
- **Edges**: reciprocal pairs (A->B and B->A) merge into ONE
  outward-bowed Bezier curve with two labels - the A->B kind near B's
  end, the B->A kind near A's end (colors per direction). One-directional
  relations get an arrowhead pointing at the target. Unknown kinds fall
  back to a neutral stroke color; the conflict text appears as a native
  SVG `<title>` tooltip; self-references are skipped defensively.
- **Page**: SVG plus a translated kind->color legend, a short
  explanation of the level system and a link back to the relationship
  section. Friendly empty states for 0 and 1 characters.
- **Determinism**: the same characters and relations always produce an
  identical SVG (fixed sort orders, no time or randomness).

### Extending the relation kind taxonomy (data-driven)

The kind taxonomy and its color coding live in ONE place:
`modules/Writing/src/RelationKinds.php`. Dropdown, validation, graph and
legend all read from it - nowhere else is a kind or group list hardcoded.

- `GROUPS` maps each fine kind to a color group; `groupFor()` resolves
  unknown/legacy kinds to the neutral `other` group, so old data never
  breaks rendering.
- `CLASSES` maps each group to a CSS class; the actual stroke colors are
  defined in the stylesheet (`templates/layout.php`), never inline in PHP.

**Recipe - add a kind:**
1. One line in `RelationKinds::GROUPS` (`'<kind>' => '<group>'`).
2. One lang line per language: `kind_<key>` in `lang/en/writing.php`
   and `lang/de/writing.php`. The parity test
   (`TranslatorTest::testRelationKindLangParity`) enforces completeness.

**Recipe - add a group:**
1. One `GROUPS` entry (a kind assigned to the new group).
2. One CSS class with a stroke color in `templates/layout.php`
   (`.rel-<group>`).
3. `kindgroup_<group>` lang lines in both languages. The legend picks
   the group up automatically.

**Guideline:** keep at most ~9 groups total for legend readability;
prefer fitting a new kind into an existing group over creating a new one.
Edge labels and tooltips always show the fine kind (translated); only the
stroke color is group-level.

## Security (current state)

- Passwords: `password_hash()` (bcrypt)
- Sessions: 64-byte random tokens, only SHA-256 hash in the DB, `httpOnly` + `SameSite=Lax`, 30 days, expiry check per query
- Password change in the profile (`/profile`) with session revocation of all other sessions
- Login with session rotation (old session is discarded on re-login)
- CSRF protection for all POST routes (session-bound token, cookie fallback for guests, `hash_equals` comparison)
- Role guard per route (`admin > editor > user`), unauthenticated -> redirect to `/login`, 403 template
- User management (admin only): create users, change roles, reset passwords, delete users

## Known gaps (roadmap phase 5)

- No rate limiting on login
- Admin boot only for first start, no CLI `user:create`
- Router without HTTP method spoofing, without optional segments

## Roadmap

1. Core + auth + module registry (done)
2. Content pipeline with `[[type:slug]]` (done in the core)
3. pages reference module (done)
4. Writing app module: projects -> characters (n), relationships (n:m with attributes), chapters -> text via content_revisions, backgrounds (4 categories) (open)
5. Editor refinements, diffs, graph UI, board, revisions per content object (open)
6. UI languages via array files, English default + German, per-account language setting (done)

Detailed planning for step 4 (projects, sharing, visibility) is documented in [PLANNING.md](PLANNING.md).
