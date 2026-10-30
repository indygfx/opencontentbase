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

## Open items (priority order, decided 2026-10-30)

The **board** is paused in favor of **mobile-first + read-only PWA**. The
next concrete step is the **theme extraction**.

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
2. **Mobile-first audit** on the new CSS base.
3. **Build the board mobile-ready.**
4. **PWA stage 1**, then **stage 2**.
5. **Child-theme mechanism last**: theme loader (config entry, e.g.
   `theme=child-xyz`), first child theme as proof of concept, all views
   consistently adapted.

### 2. Mobile-first + PWA (read-only)

Goal: mobile-first with usable desktop, an installable reading PWA.
Deliberately **out of scope**: offline writing, sync, local data storage.

**Stage 1 – reading PWA base (small effort):**

- `manifest.json` (name, icons, theme color) -> installable on phone/tablet
- Service worker: **cache-first for GET pages** – visited pages are readable
  offline; POSTs stay online
- **Edit UI disappears offline**: a mini-JS sets `body.offline`
  (`navigator.onLine`), CSS hides everything with the class
  `requires-online` (distributed systematically across the templates – the
  actual work)
- Offline fallback page of the service worker for unvisited URLs ("only
  previously visited pages available") instead of a browser error

**Stage 2 – make a story available offline (medium effort):**

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
