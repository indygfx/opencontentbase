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

## Stack

| Component | Choice |
| --- | --- |
| Language | PHP >= 8.2, `strict_types`, PSR-4 via Composer |
| Database | SQLite (WAL, `foreign_keys ON`, `busy_timeout 5000`) |
| Dependencies | `league/commonmark ^2.4` |
| Dev | `phpunit ^10`, `phpstan ^1.10` |
| CI | GitHub Actions: syntax check, phpstan, phpunit on every push/PR |
| Frontend | Server-rendered PHP templates, Tiptap-based WYSIWYG Markdown editor (esbuild bundle), minimal vanilla JS (Cmd+S save, auto slugs) |

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
src/Core/          Kernel, Router, Database, Migrator, Auth, Renderer, Registry
modules/Pages/     Reference module (routes, migration, controller, templates)
modules/Writing/   Writing app: projects, chapter synopses, chapters, characters, relationships, backgrounds
templates/         Layout, Login, Error
public/index.php   Front controller
```

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

Detailed planning for step 4 (projects, sharing, visibility) is documented in [PLANNING.md](PLANNING.md).
