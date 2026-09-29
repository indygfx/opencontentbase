# ContentBase

Modulare Content-Plattform: PHP 8.2 + SQLite, Composer nur als Autoloader, kein Framework.

## Architektur

- **Generische Content-Objekte**: `content_objects` (UUID, `type`, `slug`) statt fixer `pages`-Tabelle im Core; Module bringen eigene Tabellen mit.
- **ModuleInterface-Vertrag**: `id()`, `routes()`, `migrations()`, `contentTypes()`, `resolveLink()` — der Core kennt keine Modul-Details.
- **Versionierte Migrationen pro Modul**: `schema_versions (module, version)`, ausgeführt durch `Core\Migrator` in Einzeltransaktionen.
- **Verallgemeinerte interne Links**: `[[type:slug]]` im Content, Auflösung über die Modul-Registry (`ModuleRegistry::resolveLink`); unauflösbare Links werden als Broken-Link-Marker gerendert.
- **CommonMark + GFM** via `league/commonmark` — Roh-HTML wird escaped, unsichere Links neutralisiert (`allow_unsafe_links: false`, `DisallowedRawHtml`).
- **Serverseitige Live-Preview**: ein Parser auf dem Server, kein JS-Parser-Duell.

## Stack

| Baustein | Wahl |
| --- | --- |
| Sprache | PHP >= 8.2, `strict_types`, PSR-4 via Composer |
| Datenbank | SQLite (WAL, `foreign_keys ON`, `busy_timeout 5000`) |
| Abhängigkeiten | `league/commonmark ^2.4` |
| Dev | `phpunit ^10`, `phpstan ^1.10` |
| CI | GitHub Actions: Syntax-Check, phpstan, phpunit bei jedem Push/PR |
| Frontend | Server-gerenderte PHP-Templates, minimales Vanilla-JS (Debounce, fetch-Preview, Cmd+S) |

## Automatische Prüfungen (CI)

Bei jedem Push und jedem Pull-Request läuft automatisch eine Prüfung über GitHub Actions (`.github/workflows/ci.yml`):

1. **Syntax-Check**: `php -l` über alle PHP-Dateien in `src/`, `modules/`, `public/`
2. **Statische Analyse**: `phpstan` über `src/` und `modules/`
3. **Tests**: `phpunit` über `tests/`

Lokal die gleichen Prüfungen ausführen:

```bash
vendor/bin/phpstan analyse src modules --no-progress
vendor/bin/phpunit --no-progress
```

Der Prüflauf dauert ~1 Minute; grüner Haken am Commit/PR = alles gut, rotes X = Fehlermeldung im Log.

## Start

```bash
composer install
php -S localhost:8080 -t public
```

> **Hinweis `composer.lock`:** Die Lock-Datei ist bewusst nicht eingecheckt (siehe `.gitignore`). Sie entsteht lokal bei `composer install` und fixiert dort die exakten Paketversionen. Bei „läuft bei mir nicht"-Situationen oder nach `composer update` daran denken, dass jedes System seine eigene Lock-Datei hat — Versionen ggf. dokumentieren.

Erststart legt automatisch den Benutzer **admin / admin** an (bitte sofort ändern).

## Struktur

```
src/Core/          Kernel, Router, Database, Migrator, Auth, Renderer, Registry
modules/Pages/     Referenzmodul (Routen, Migration, Controller, Templates)
templates/         Layout, Login, Error
public/index.php   Front Controller
```

## Sicherheit (aktuell)

- Passwörter: `password_hash()` (bcrypt)
- Sessions: 64-Byte-Random-Tokens, nur SHA-256-Hash in der DB, `httpOnly` + `SameSite=Lax`, 30 Tage, Expiry-Check per Query
- Content: `html_input=escape`, `allow_unsafe_links=false`, `DisallowedRawHtml`
- Rollen-Guard pro Route (`admin > editor > user`), Unauth -> Redirect auf `/login`, 403-Template
- CSRF-Schutz für alle POST-Routen (Session-gebundenes Token, Cookie-Fallback für Gäste, `hash_equals`-Vergleich)
- Passwort-Ãnderung im Profil (`/profile`) mit Session-Revocation aller anderen Sitzungen
- Login mit Session-Rotation (alte Sitzung wird bei Re-Login verworfen)

## Bekannte Lücken (Roadmap Phase 5)

- Kein Rate-Limiting beim Login
- Admin-Boot nur für Erststart, kein CLI `user:create`
- Router ohne HTTP-Method-Spoofing, ohne optionale Segmente

## Roadmap

1. Core + Auth + Modul-Registry (fertig)
2. Content-Pipeline mit `[[type:slug]]` (fertig im Core)
3. pages-Referenzmodul (fertig)
4. Schreib-App-Modul: projects -> characters (n), relationships (n:m mit Attributen), chapters -> Text via content_revisions, backgrounds (4 Kategorien) (offen)
5. Editor-Feinheiten, Diffs, Graph-UI, Board, Revisionen pro Content-Objekt (offen)
