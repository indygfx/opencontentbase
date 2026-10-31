# Mobile-first audit (analysis only)

**Date:** 2026-10-30 · **Base:** `dev` @ `bde20a5` (theme extraction done, CI green: 352 tests / 2329 assertions)
**Scope:** smartphone-first (360 px), tablet second (768 px). This document is
the planning basis for the mobile-first fix prompt. **Nothing was changed** -
this audit records findings, it does not repair them.

Verified against: `templates/layout.php`, `templates/*.php`,
`modules/Pages/templates/*.php`, `modules/Writing/templates/*.php`,
`public/assets/css/master.css`, `public/assets/css/editor.css`,
`assets/editor/main.js`, `modules/Writing/src/RelationshipGraph.php`.

## Quick answers to the checklist

- **Meta viewport**: present (`templates/layout.php:5`,
  `width=device-width, initial-scale=1`) - OK, no finding.
- **Media queries**: `master.css` contains **zero** `@media` rules,
  `editor.css` also zero. The CSS is desktop-only by construction.
- **Fixed widths > 360 px**: none in `master.css` (only `max-width` on
  `main` 52rem, `.narrow` 24rem, images). The graph SVG uses
  `viewBox="0 0 900 700"` with `.relationship-graph { width: 100% }` -
  it scales, but the 12px/11px labels shrink to ~5 px effective at 360 px.
- **Tables**: 12 `<table>` occurrences (see F3) - none has a scrollable
  wrapper; at 360 px they squeeze and clip.

## Findings

### Global layout

- **F1 (A) - Topbar overflows at 360 px.** `templates/layout.php` header +
  `.topbar` (master.css:70) / `.topbar nav` (line 72) are `display:flex`
  with **no `flex-wrap`**. An admin sees 6 nav items (Pages, New page, Users,
  Writing, username, logout button); at 360 px they overflow horizontally or
  get clipped - navigation becomes unusable.
  *Fix hint:* `flex-wrap: wrap` on `.topbar`/`.topbar nav`, smaller gap and
  padding under a `@media (max-width: …)` breakpoint.
- **F2 (C) - No `@media` rule anywhere.** `master.css` and `editor.css`
  contain 0 media queries; every rule is fixed. All mobile fixes need a
  first breakpoint set.
  *Fix hint:* introduce `@media (max-width: 480px)` (phone) and
  `(max-width: 768px)` (tablet) blocks in `master.css`.
- **F3 (B) - Touch targets under ~44 px.** `button, .button`
  (master.css:81) have `padding: 0.5rem 1rem` with inherited 16 px font →
  roughly 37 px high; `.topbar nav a` (line 73) have no padding at all;
  `.story-slider-nav button` (line 161) are `1.6rem` = **25.6 px** circles;
  `.editor-toolbar-btn` (editor.css:23) ~`0.15rem 0.45rem` + 0.8rem font.
  *Fix hint:* min-height/min-width 44 px on buttons and nav links, larger
  slider dots (editor bundle stays untouched - toolbar button size is
  noted for a later editor pass).

### Dashboard & lists

- **F4 (A) - Story dashboard (`modules/Writing/templates/project.php`) is
  button-heavy.** 24 `button`/`<a>` elements on one page (setup, export,
  5 × "new …" buttons, per-section edit links); combined with F1/F3 the
  page at 360 px is a long, cramped tap-target desert.
  *Fix hint:* group action links, make `.button` full-width on phones.
- **F5 (B) - Excerpt slider at 360 px.** `modules/Writing/templates/index.php`
  + `.story-slider` (master.css:150): `padding: 2.25rem 2rem` eats ~64 px of
  360 px; `.story-slides` has fixed `min-height: 9rem` and `.story-slider`
  has `overflow: hidden` - excerpts up to 80 words (blockquote 1.15rem) can
  be **clipped** with no scroll; nav dots are 25.6 px (F3). 1 slide works
  (`data-count="1"` makes it static), 2-3 slides rotate but text may cut off.
  *Fix hint:* reduce slider padding on phones, `min-height: auto` +
  `overflow: visible` (or larger min-height), bigger dots.

### Tables

- **F6 (A) - No table scrolls at 360 px.** 12 `<table>` lists:
  `templates/users.php` (4 cols incl. inline role form + reset `<details>`
  + delete button per row - worst case), `templates/profile.php`,
  `modules/Writing/templates/index.php` (story list), `project.php` (5
  tables: synopses, characters, relations, backgrounds, chapters - the
  relations table has 5 columns incl. free-text conflict),
  `modules/Writing/templates/setup.php` (members), `revisions.php`
  (Writing + Pages), `modules/Pages/templates/index.php`, `show.php`.
  `table { width: 100% }` (master.css:76) with `th, td` padding 0.5/0.75rem
  and no `overflow-x` wrapper → columns squeeze, text clips, delete forms
  become unreachable. Only `.diff` has `overflow-x: auto` (line 137).
  *Fix hint:* wrap tables in a `.table-wrap { overflow-x: auto }` (or
  `display:block; overflow-x:auto` on `table` under a breakpoint) and/or
  card-list layout for the narrowest tables.

### Forms & editor

- **F7 (B) - Inputs are full-width (good) but inherit 16 px (good, no iOS
  zoom)** - `input, textarea { width: 100%; font: inherit }` (master.css:79).
  Positivbefund; Problem: `select` elements (role, genre, language,
  synopsis dropdowns) are NOT in that rule → default width, can overflow
  with long translated option labels (de/fr).
  *Fix hint:* add `select` to the full-width input rule + `max-width: 100%`.
- **F8 (B) - File upload on setup page.** `modules/Writing/templates/setup.php`
  cover upload (`input type=file`) is full-width OK, but the label hint
  (`image_rules`) plus button stack long on 360 px; acceptable but cramped.
  *Fix hint:* cosmetic - smaller hint, button full-width on phones.
- **F9 (B) - Tiptap toolbar at 360 px (analysis only, bundle untouched).**
  `.editor-toolbar` (editor.css:11) is `position: sticky; top: 0` with
  `flex-wrap: wrap` - it wraps, good; but ~15 buttons + 3 dropdowns at
  0.8rem font with tiny padding wrap into 3-4 rows and push content down.
  Dropdowns `.editor-menu__dropdown` (editor.css:173) are
  `position:absolute; min-width: 10rem` - a dropdown opened near the right
  edge can overflow the viewport at 360 px (no viewport clamping).
  *Fix hint (later editor pass):* clamp dropdown `left` to viewport,
  consider a compact toolbar under a breakpoint in editor.css only.
- **F10 (C) - Textarea fallback.** Without JS the plain `textarea`
  (`rows="14"`, monospace) is full-width and usable at 360 px - OK.

### Graph

- **F11 (B) - Graph labels unreadable at 360 px.** `RelationshipGraph.php`
  emits `viewBox="0 0 900 700"`, `.relationship-graph { width: 100%;
  height: auto }` (master.css:96) → SVG scales down to ~328 px, so the
  12 px node labels and 11 px edge labels render at ~4.4 px - effectively
  unreadable; edges/nodes remain tappable-ish but meaningless. Legend
  `.graph-legend` (line 123) wraps fine (`flex-wrap`).
  *Fix hint:* under a breakpoint switch to a larger relative label size
  (SVG-side: media-query-independent `vector-effect` won't help text) -
  pragmatic: render the SVG at a smaller fixed viewBox on mobile via a
  CSS-scaled min-width + horizontal scroll inside `.graph-wrap`
  (`overflow-x: auto`, `min-width: 560px` on the svg) so labels stay ~8 px.

### Slider

- Covered by F5.

### Admin

- **F12 (A) - Users table (`templates/users.php`) at 360 px.** 4 columns,
  the actions column contains an inline role `<select>` + submit button,
  a `<details>` password-reset form and a delete button per row - at 360 px
  this is unusable (see F6). Admin-only, but still broken on a phone.
  *Fix hint:* same table-wrap fix + stack action forms vertically on
  phones.

### Reading views & content

- **F13 (C) - `.content` reading view is OK at 360 px.** `main` padding
  `0 1rem` + `.content` padding 1.5rem → ~296 px text column, readable.
  But rendered Markdown images (`<img>` in `.content`, e.g. pasted cover
  links) have no `max-width: 100%` → wide images overflow the card.
  Code blocks (`<pre>`) in `.content` have no `overflow-x` in master.css
  (only `.editor-prose pre` in editor.css has it) → long lines push the
  page wider than the viewport.
  *Fix hint:* `.content img, .content pre, .content table { max-width: 100% }`
  + `.content pre { overflow-x: auto }`.
- **F14 (C) - Covers/portraits scale fine** (`.cover-preview` max-width
  22rem, `.character-portrait` 10rem, both `max-height` capped) - OK.

### Flash / badges / progress

- **F15 (C) - Progress bars squeeze at 360 px with long de/fr labels.**
  `.progress` flex (master.css:126): `.progress-label` is `flex: 0 0 auto`
  with e.g. German "18.450 / 90.000 Wörter" → track shrinks to a sliver.
  *Fix hint:* allow label wrap or move label under the bar on phones.
- **F16 (C) - `.badge` / `.notice` / `.success` / `.error`** are plain
  inline/block text and wrap fine; long German compound words can still
  overflow a narrow cell - `overflow-wrap: anywhere` on `.card` would fix.
  *Fix hint:* `overflow-wrap: break-word` on `main`/`.card`.

## Priority matrix

Severity × effort → recommended order for the follow-up prompt.

| # | Finding | Severity | Effort | Class |
|---|---------|----------|--------|-------|
| 1 | F1 topbar overflow/wrap | A | S | **must** |
| 2 | F6 table scroll wrapper (+F12 users table) | A | M | **must** |
| 3 | F3 touch targets ≥44 px (buttons, nav, slider dots) | B | S | **must** |
| 4 | F11 graph readability at 360 px (scrollable wrap) | B | S | **must** |
| 5 | F5 slider clipping/padding/dots at 360 px | B | S | **must** |
| 6 | F2 introduce first `@media` breakpoints (enabler for 1-5) | B | S | must (enabler) |
| 7 | F7 select full-width | B | S | should |
| 8 | F13 content img/pre/table overflow | C | S | should |
| 9 | F4 dashboard button grouping | B | M | should |
| 10 | F15 progress label squeeze | C | S | could |
| 11 | F9 editor toolbar compact/dropdown clamp | B | M | later editor pass (bundle untouched now) |
| 12 | F8 file-upload cosmetics | B | S | could |
| 13 | F16 overflow-wrap for long words | C | S | could |

**Top 5 musts for the mobile-first pass:** F1, F6, F3, F11, F5 (with F2 as
the enabling breakpoint set).

## Verification of the no-change rule

- `git status` before commit of this doc: clean except `docs/mobile-audit.md`
  (+ the README/ROADMAP doc lines of this commit).
- Suite + phpstan untouched and green (last CI run on `dev`: OK, 352 tests,
  2329 assertions).
