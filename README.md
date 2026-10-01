# ContentBase

ContentBase is a modular content platform that deliberately favors lightness: PHP 8.2 and SQLite instead of a heavy framework – Composer serves only as an autoloader. At its core the system works with generic content objects (UUID, type, slug) instead of fixed tables; actual functionality comes from modules that follow a uniform interface and bring their own tables and versioned migrations. The core knows nothing about module internals – it manages only what modules report through defined channels.

Content is written in Markdown (CommonMark with GFM), with security taking priority: raw HTML is escaped, unsafe links are neutralized. Internal references use the `[[type:slug]]` syntax and are resolved through the module registry; unresolvable links render as broken-link markers. A server-side live preview ensures that editing and output always use the same rendering.


Getting started is deliberately minimal – `composer install` and a built-in PHP server are enough. On first start the user admin/admin is created automatically (change it immediately!), and composer.lock is intentionally not checked in, so every system keeps its own locally pinned versions. Of the roadmap, the core, the content pipeline and the pages reference module are done; next up are a writing app module (projects, characters, relationships, chapters) and editor refinements such as diffs, a graph UI and per-object revisions.

## Architecture

- **Generic content objects**: `content_objects` (UUID, `type`, `slug`) instead of a fixed `pages` table in the core; modules bring their own tables.
- **ModuleInterface contract**: `id()`, `routes()`, `migrations()`, `contentTypes()`, `resolveLink()` – the core knows no module details.
- **Versioned migrations per module**: `schema_versions (module, version)`, executed by `Core\Migrator` in individual transactions.
- **Generalized internal links**: `[[type:slug]]` in content, resolved via the module registry (`ModuleRegistry::resolveLink`); unresolvable links render as broken-link markers.
- **CommonMark + GFM** via `league/commonmark` – raw HTML is escaped, unsafe links neutralized (`allow_unsafe_links: false`, `DisallowedRawHtml`).
- **Server-side live preview**: one parser on the server, no JS parser mismatch.

## Stack

| Component | Choice |
| --- | --- |
| Language | PHP >= 8.2, `strict_types`, PSR-4 via Composer |
| Database | SQLite (WAL, `foreign_keys ON`, `busy_timeout 5000`) |
| Dependencies | `league/commonmark ^2.4` |
| Dev | `phpunit ^10`, `phpstan ^1.10` |
| CI | GitHub Actions: syntax check, phpstan, phpunit on every push/PR |
| Frontend | Server-rendered PHP templates, minimal vanilla JS (debounce, fetch preview, Cmd+S) |

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
templates/         Layout, Login, Error
public/index.php   Front controller
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
