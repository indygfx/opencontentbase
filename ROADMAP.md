# Roadmap & project status

This document is the continuously updated project status. It complements
[PLANNING.md](PLANNING.md), which records the binding architecture decisions
for the writing app (roadmap phase 4).

**Status:** after the smoke-test fix (`cdc7474`) – all implementation steps
done, branch `dev`, CI green, 342 tests / 2210 assertions.

## Standing rules for every implementation prompt

- English only: code, comments, UI strings, error messages; i18n parity in
  en/de/es/fr stays green
- Update `README.md` with every change
- Add PHPUnit tests for every behavior; suite + phpstan stay green
- Commit on `dev`; CI (syntax check, phpstan, phpunit) runs on every push

## Done (summary)

The full commit history is in `git log` on `dev`. In short:

- Core + auth + module registry; content pipeline with `[[type:slug]]` links
- Pages reference module (admin-only; the root URL opens the writing app)
- Writing app: projects + sharing, chapter synopses -> chapters, characters
  with portrait images, two-layer relationships (structural role kind +
  emotional stance) with a weighted circular SVG graph, backgrounds,
  progress targets + dashboard bars, excerpt slider, Tiptap WYSIWYG Markdown
  editor (esbuild bundle committed), version history with diffs
  (reference-only, capped at the 10 newest revisions, embedded recent list,
  no restore), story export/import as a Markdown bundle (format version 1),
  first-start setup wizard (no hardcoded credentials anywhere), login
  rate-limiting, CLI `bin/user`
- Writing overview (`/writing`) shows the sharing state on each project row:
  avatar stack (cap 3 + `+N` counter + full names in the tooltip) for own
  projects, a "shared by" hint for shared ones; members fetched in one
  grouped query (`ProjectAccess::membersForProjects`, no N+1). The stack
  links to the owner-only setup page, whose Sharing block lists all shared
  accounts with avatars - this resolves the `+N` counter on touch devices

## Open items (priority order, decided 2026-10-30)

The **board** is paused in favor of **mobile-first + read-only PWA**.

**Done:** theme extraction (step 1) - all inline CSS/JS moved to
`public/assets/css/master.css` (`:root` custom properties incl. the stance
group variables) and `public/assets/js/app.js`; the `theme.css` child-theme
override slot is wired in the layout (file not shipped). See the "Theming"
section in [README.md](README.md).

**Done:** child-theme readiness (audit prompt 19 follow-up) - the audit verdict
READY WITH GAPS is closed: new `:root` variables `--font-body`, `--font-mono`,
`--code-bg`, `--highlight`, `--accent-soft`, `--shadow`, `--avatar-ink`; zero
color/font literals outside `:root` in master.css AND editor.css (default
values identical, so the default theme is pixel-identical); enforced by
`tests/ThemeExtractionTest.php`. A child theme overriding only variables now
restyles the complete app including the editor and typography.

**Done:** first child theme "Nerdline" (green brand variant by nerdline media
solutions) - variables only, accepted by `tests/NerdlineThemeTest.php` (single
`:root` block, key values, WCAG AA contrast >= 4.5:1 on all theme pairs,
pixel-identical default theme). Dogfooding notes: content links were
ink-colored and only the topbar nav links used the accent, so links got their
own `--link` (default = ink) while the topbar nav uses `--topbar-ink` (default
= old accent); phone-width buttons are routed through `--button-width`
redefined inside the 480 px block.

**Done:** multi-theme system (prompt 22) - themes are static folders under
`public/assets/themes/<slug>/` with a `theme.json` (name/version required;
developer, theme_color, background_color, icons optional) + `css/theme.css`
(variables only, test-enforced) + optional `js/theme.js`. `Core\ThemeRegistry`
scans the live folder and validates slugs strictly (no path traversal).
Nerdline ships at `themes/nerdline/` as a selectable example theme; the
DEFAULT stays plain master.css (`users.theme` defaults to `'default'` =
no theme; v9 normalizes databases that v8 briefly defaulted to 'nerdline').
The `/profile` dropdown (next to the language) lists only installed theme
folders and shows an info message instead when none exist. Bugfix on top:
`Auth::user()`/`login()` never selected the `theme` column, so saved theme
changes were never read back - both SELECTs now include it and the POST
response re-reads the user (`Auth::refreshUser`). Themes are deployment artifacts and
gitignored (`/public/assets/themes/*` except nerdline); the gitignored
`public/assets/overrides/` dir (css/js) is loaded last and always wins, so
users never edit tracked files and updates never touch their overrides.
Layout load order: master.css -> editor.css -> active theme -> overrides (with
`?v=filemtime` cache-busters because the SW caches `/assets/` cache-first);
`theme-color` meta follows the active theme. Tests: `ThemeRegistryTest`,
`ThemeSelectionTest`, updated `ThemeExtractionTest`/`NerdlineThemeTest`.
Follow-up: dynamic per-theme manifest/icons from `theme.json`.

**Done:** mobile-first audit (step 2) - findings in
[docs/mobile-audit.md](docs/mobile-audit.md).

**Done:** mobile-first fixes phase 1 - F2 (breakpoints: `@media
(max-width: 768px)` / `(max-width: 480px)` blocks at the end of master.css),
F1 (topbar flex-wrap), F6 (`.table-scroll` wrapper around all 13 tables),
F3 (44 px touch targets), F11 (graph scrolls sideways below 768 px with a
translated swipe hint), F5 (slider no longer clips excerpts on phones).
Desktop rendering above 768 px is unchanged.

**Done:** audit finding F8 (editor toolbar on small screens) - **collapse
pattern**: below 768 px the toolbar wraps and shows only the first row, a
measured chevron toggle (`app.js`, CSS in `editor.css`) reveals the rest,
44 px touch targets, bottom-sheet dropdowns. The earlier scrollable-single-row
approach was superseded by wrap+collapse. Editor bundle untouched, no rebuild.
**Done:** mobile-first fixes phase 2 - F4 (dashboard actions grouped; secondary
actions in a labeled group with full-width buttons below 768 px), F7 (selects
full-width below 768 px), F10 (verified OK - the textarea fallback is already
full-width), F12 (inline action forms in the users table stack vertically below
768 px inside the `.table-scroll` wrapper), F13 (`.content` img/pre/table
overflow safety - base rule, all viewports), F15 (progress label wraps below
the bar below 768 px), F16 (long words wrap via `overflow-wrap: break-word` -
base rule). F9 (editor toolbar compact) is covered by the collapse-pattern
entry above. **Open (cosmetic only):** file-upload cosmetics on the setup page.

**Done:** default-theme polish round 1 (prompt 23) - slideshow pacing
(`SLIDE_INTERVAL_MS` 16 s + hover pause + reduced-motion opt-out), button
vertical rhythm (`margin-block: 16px`, chrome exceptions, horizontal gaps,
mobile `width: auto` with the 44 px target kept), story header actions menu
(graph/export/setup, bottom sheet on mobile), genre preselection bug fixed
(`findProject` now selects `p.genre`), unified select styling (44 px, pure-CSS
chevron), detail-page edit/history actions as `.button`. Further polish rounds
may follow.

**Done:** prompt 27 - edit-mode polish: Cancel button (quiet `.button-secondary`
GET link) in every edit view, `body.editing` marker on edit views only, mobile
full-bleed scoped to the marker (16px inner spacing for all controls except the
writing surface), SW VERSION bumped in the same commit.

**Done:** prompt 27b - edit-mode mobile spacing: all flagged elements (h1/h2,
breadcrumb .muted, .error/.success notices, .outline-box, the setup .table-scroll)
keep margin-inline 16px under body.editing in the 768px block (writing surface
stays full-bleed; no width calc needed for block elements); editor comfort
typography in editor.css base (.editor-prose: line-height 1.8, font-size 120%,
min-height 9rem, outline none - caret is the focus indicator); SW VERSION v4.

**Done:** prompt 28 - PWA stage 2 (stories offline): per-story on-demand
precache via `GET /writing/{id}/offline-manifest.json` (auth-guarded; read
pages + portraits/cover/avatars), SW cache `offline-stories-v1` with
PRECACHE_STORY/REMOVE_STORY messages, SWR serving for cached story pages,
dashboard toggle button (localStorage index `offline-stories`, quota hint,
busy state, toasts), offline.html as the offline library. SW VERSION v5.

**Done:** prompt 29 - PWA stage 2b: writing dashboard is the offline entry point
(SW network-first for /writing with story-cache fallback, auto-warm of the
dashboard + rendered avatars on controller ready, 60-min timer with 12-h
per-story refresh throttle), /avatars/* SWR (?v=filemtime cache-busters),
per-story offline moved from a dashboard button to a switch in the story header
dropdown (localStorage index + ts, busy state, no menu close), offline dashboard
rows muted with an 'online only' hint while offline (body.is-offline). SW
VERSION v6.

**Done:** prompt 24 - story header shows the sharing avatar stack next to the
title (same partial/mechanics as the overview; links to setup for the owner,
no stack without members) and users can upload their own avatar on `/profile`
(same ImageStore validation/storage as character portraits, `data/avatars/`,
`GET /avatars/{user}`, migration v10 `users.avatar`); single-source rendering
via `Core\Avatar::render`/`avatar_tag()` - image with cache-buster when set,
initials circle otherwise - in overview stack, setup block, header stack and
profile preview.

### 1. Theme extraction (next step)

Goal: no inline CSS/JS anymore; a master/default theme as the base, custom
theming later via child-theme overrides.

Decisions:

- **Vanilla CSS with custom properties** – no Tailwind/Bootstrap (contradicts
  the lightweight philosophy, would add a build step). CSS variables are the
  native master/child mechanism: the master defines `:root` variables, a
  child theme overrides only variables.
- **Inline JS moves to static files** (`public/assets/js/app.js`) – same
  caching logic as CSS.
- **Tiptap bundle styles are deliberately NOT themed** – a third-party bundle
  with its own assets, explicitly excluded and documented as such.

Theming risks to encapsulate during the extraction:

1. **Graph colors**: the `rel-*` classes (7 stance groups, 3 contexts each:
   `stroke` for edges, `fill` for edge labels, `background` for legend
   swatches) must be kept as one coherent variable set – otherwise the
   legend breaks in child themes.
2. **Diff colors** (green/red) likewise as variables.
3. **Contrast rule** documented: a child theme may change colors but must not
   break graph/diff readability.

Order (important – the extraction is the foundation for everything else):

1. **Theme extraction** (small): inline CSS -> `public/assets/css/master.css`
   with `:root` variables; inline JS -> static files. MUST happen before
   mobile-first and PWA (service-worker caching needs external assets).
   **Done** - no inline CSS/JS in templates anymore, zero visual change.
2. **Mobile-first audit** on the new CSS base. **Done** - see
   [docs/mobile-audit.md](docs/mobile-audit.md); fixes phase 1 done
   (top 5 + enabler), phase 2 findings pending.
3. **Build the board mobile-ready.**
4. **PWA stage 1**, then **stage 2**.
5. **Child-theme mechanism last**: theme loader (config entry, e.g.
   `theme=child-xyz`), first child theme as proof of concept, all views
   consistently adapted.

### 2. Mobile-first + PWA (read-only)

Goal: mobile-first with usable desktop, an installable reading PWA.
Deliberately **out of scope**: offline writing, sync, local data storage.

**Stage 1 – reading PWA base (small effort):**

- **App icons (prep done)**: code-generated with GD via `php scripts/generate-pwa-icons.php`; committed PNGs in `public/assets/icons/` (`icon-192.png`, `icon-512.png`, `icon-512-maskable.png`); colors/proportions are constants in the script header, re-run to regenerate
- `manifest.json` (name, icons, theme color) -> installable on phone/tablet
- Service worker: **cache-first for GET pages** – visited pages are readable
  offline; POSTs stay online
- **Edit UI disappears offline**: a mini-JS sets `body.offline`
  (`navigator.onLine`), CSS hides everything with the class
  `requires-online` (distributed systematically across the templates – the
  actual work)
- Offline fallback page of the service worker for unvisited URLs ("only
  previously visited pages available") instead of a browser error

**Done:** PWA stage 1 - installable app + offline reading. `public/manifest.webmanifest`
(name OpenContentBase / OCB, start_url `/writing`, standalone, theme/background color from
the icon palette `#16a34a`/`#14532d`, the three code-generated icons incl. maskable),
`public/sw.js` at root scope (`VERSION = 'v1'`; cache-first for `/assets/`+manifest,
stale-while-revalidate for public reading pages, network-first for other app pages,
network-only for `/writing`, non-GET never cached, `/offline.html` fallback in en/de/es/fr,
skipWaiting + clients.claim update flow), guarded registration + update toast in `app.js`
(`isSecureContext` guard - HTTP dev host untouched). Verified by `tests/PwaStage1Test.php`;
manual HTTPS checklist in README.md. Known limitations: offline pages show the guest topbar,
app pages show the last-seen state.

**Stage 2 – make a story available offline (medium effort) - deferred; candidates:**
background sync for saves, push notifications.

- **On-demand precache per story**: an "Make available offline" button on
  the story dashboard; the service worker caches all chapters, characters
  and backgrounds plus the story dashboard (URL list via a small JSON route
  on the server)
- **Expiry/invalidation**: on an online visit, check whether revisions
  changed (ETag/fingerprint) -> update the cache
- **Management**: overview of offline-available stories, individual caches
  deletable, storage-quota check with a friendly message
- Implementation option open: use the Markdown bundle (export) as the
  download data source

**Stage 3 – polish (optional):**

- iOS icons/splash screens, update notice on a new service-worker version

### 3. Board (last open roadmap phase-5 item – paused)

Not yet conceptualized. To clarify before the prompt: what is the board?
Kanban-like chapter planning (outlines in columns: planned / writing / done)
or a story-board overview across all projects? A status data basis would have
to be added – chapter synopses currently have no status field.

### 4. Pages module purpose (undecided, deliberately postponed)

Pages are the initial product without priority; they stay admin-only and the
root URL opens the writing app.

## Small open points (non-blocking)

- `UserAdminControllerTest` checks only DB state, not response status codes –
  optional addition
- Editor refinements: typeahead for `[[type:slug]]` links (considered early,
  never requested); the editor bundle only needs a rebuild on asset changes
