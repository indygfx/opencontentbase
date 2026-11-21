/* Page-level glue: Cmd/Ctrl+S form save, auto-slug preview, genre word targets.
   Loaded deferred from the layout footer; the Tiptap editor bundle
   (editor.js) and the story slider (story-slider.js) stay separate files. */
(function () {
    'use strict';

    // Cmd/Ctrl+S saves the surrounding form of the main textarea (#body or #text).
    var field = document.getElementById('body') || document.getElementById('text');
    if (field !== null) {
        document.addEventListener('keydown', function (ev) {
            if ((ev.metaKey || ev.ctrlKey) && ev.key === 's') {
                ev.preventDefault();
                field.form.submit();
            }
        });
    }

    // Auto-slug preview on the pages form: while the slug input is empty, its
    // placeholder mirrors the slugified title. The translated "will be: " prefix
    // travels on the input's data-slug-preview attribute.
    var slugInput = document.getElementById('slug');
    var titleInput = document.getElementById('title');
    if (slugInput !== null && titleInput !== null) {
        var syncSlug = function () {
            if (slugInput.value === '') {
                var preview = titleInput.value
                    .toLowerCase()
                    .replace(/[äöü]/g, function (c) { return {'ä': 'ae', 'ö': 'oe', 'ü': 'ue'}[c]; })
                    .replace(/ß/g, 'ss')
                    .replace(/[^a-z0-9]+/g, '-')
                    .replace(/^-+|-+$/g, '');
                slugInput.placeholder = (slugInput.dataset.slugPreview || '') + preview;
            }
        };
        titleInput.addEventListener('input', syncSlug);
        syncSlug();
    }

    // Genre select on the novel setup page auto-fills the total-words target.
    var genreSelect = document.getElementById('genre');
    var wordsInput = document.getElementById('target_words_total');
    if (genreSelect !== null) {
        var byGenre = {
            'General': 90000,
            'Fantasy': 110000,
            'Sci-Fi': 100000,
            'Romance': 80000,
            'Thriller/Crime': 90000,
            'Young Adult': 70000
        };
        genreSelect.addEventListener('change', function () {
            var target = byGenre[genreSelect.value];
            if (typeof target === 'number' && wordsInput !== null) {
                wordsInput.value = target;
            }
        });
    }
})();

/* Editor toolbar collapse (mobile <=768px, audit F8): the toolbar wraps into
   multiple rows; while collapsed only the first row is visible and a chevron
   toggle on the toolbar's right edge reveals/hides the wrapped rows. The
   toggle is only shown when a second row actually exists (measured), so
   one-row viewports never see it. The choice is sticky: resize and breakpoint
   changes re-measure but re-apply the stored state, so an expanded toolbar
   stays expanded until the toggle is tapped again. Purely additive - the
   editor bundle's DOM and listeners are untouched. */
(function () {
    'use strict';
    var mq = window.matchMedia('(max-width: 768px)');
    var CHEVRON_DOWN = '⌄';
    var CHEVRON_UP = '⌃';

    function setup(bar) {
        if (bar.dataset.collapseReady === '1') {
            return;
        }
        bar.dataset.collapseReady = '1';
        bar.dataset.collapsed = '1';
        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'editor-toolbar-toggle';
        toggle.setAttribute('aria-label', 'More formatting');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.hidden = true;
        toggle.textContent = CHEVRON_DOWN;
        toggle.addEventListener('click', function () {
            var collapsed = bar.classList.toggle('is-collapsed');
            bar.dataset.collapsed = collapsed ? '1' : '0';
            toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            toggle.textContent = collapsed ? CHEVRON_DOWN : CHEVRON_UP;
        });
        bar.appendChild(toggle);

        // Dropdown/collapse interplay: while the toolbar is collapsed
        // (is-collapsed = max-height 44px + overflow hidden) an open dropdown
        // would be clipped. So opening a dropdown expands the toolbar, closing
        // it re-collapses - but only when the stored choice is 'collapsed';
        // a toolbar the user expanded manually stays expanded.
        function syncDropdownCollapse() {
            if (!mq.matches) {
                return;
            }
            var open = bar.querySelector('.editor-menu__dropdown.is-open') !== null;
            var storedCollapsed = bar.dataset.collapsed !== '0';
            if (open && bar.classList.contains('is-collapsed')) {
                bar.classList.remove('is-collapsed');
                toggle.setAttribute('aria-expanded', 'true');
                toggle.textContent = CHEVRON_UP;
            } else if (!open && storedCollapsed && !bar.classList.contains('is-collapsed')) {
                bar.classList.add('is-collapsed');
                toggle.setAttribute('aria-expanded', 'false');
                toggle.textContent = CHEVRON_DOWN;
            }
        }
        if (typeof MutationObserver === 'function') {
            var mo = new MutationObserver(function (muts) {
                for (var i = 0; i < muts.length; i++) {
                    var t = muts[i].target;
                    if (t.classList && t.classList.contains('editor-menu__dropdown')) {
                        syncDropdownCollapse();
                        return;
                    }
                }
            });
            mo.observe(bar, { attributes: true, attributeFilter: ['class'], subtree: true });
        }
    }

    function update(bar) {
        var toggle = bar.querySelector('.editor-toolbar-toggle');
        if (toggle === null) {
            return;
        }
        if (!mq.matches) {
            // Desktop: never collapsed, no toggle. The stored choice is kept
            // so it is restored when the viewport goes back to mobile.
            bar.classList.remove('is-collapsed');
            toggle.hidden = true;
            return;
        }
        // Mobile: collapse to measure whether wrapped rows are hidden, then
        // re-apply the stored collapsed/expanded choice.
        bar.classList.add('is-collapsed');
        var overflows = bar.scrollHeight > bar.clientHeight + 2;
        if (!overflows) {
            // Single row: nothing to reveal, no toggle.
            bar.classList.remove('is-collapsed');
            bar.dataset.collapsed = '1';
            toggle.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
            toggle.textContent = CHEVRON_DOWN;
            return;
        }
        var collapsed = bar.dataset.collapsed !== '0';
        bar.classList.toggle('is-collapsed', collapsed);
        toggle.hidden = false;
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        toggle.textContent = collapsed ? CHEVRON_DOWN : CHEVRON_UP;
    }

    function updateAll() {
        var bars = document.querySelectorAll('.editor-toolbar');
        bars.forEach(function (bar) {
            setup(bar);
            update(bar);
        });
    }

    function init() {
        updateAll();
    }

    if (typeof mq.addEventListener === 'function') {
        mq.addEventListener('change', updateAll);
    } else if (typeof mq.addListener === 'function') {
        mq.addListener(updateAll);
    }
    window.addEventListener('resize', updateAll);
    document.addEventListener('DOMContentLoaded', init);
    window.addEventListener('load', init);
})();

/* PWA stage 1: service-worker registration + "update available" toast.
   Guarded by isSecureContext: on plain-HTTP dev hosts (opencontentbase.test)
   nothing is registered and no console warning appears. The toast texts are
   translated server-side onto <body> data attributes (layout.php). */
(function () {
    'use strict';
    if (!window.isSecureContext || !('serviceWorker' in navigator)) {
        return;
    }

    function showUpdateToast() {
        if (document.querySelector('.app-toast') !== null) {
            return;
        }
        var toast = document.createElement('div');
        toast.className = 'notice app-toast';
        toast.setAttribute('role', 'status');
        var text = document.createElement('span');
        text.textContent = document.body.dataset.updateAvailable || 'Update available - reload';
        var button = document.createElement('button');
        button.type = 'button';
        button.textContent = document.body.dataset.reloadNow || 'Reload now';
        button.addEventListener('click', function () { window.location.reload(); });
        toast.appendChild(text);
        toast.appendChild(button);
        document.body.appendChild(toast);
    }

    window.addEventListener('load', function () {
        navigator.serviceWorker.register('/sw.js').then(function (reg) {
            reg.addEventListener('updatefound', function () {
                var worker = reg.installing;
                if (worker === null) {
                    return;
                }
                worker.addEventListener('statechange', function () {
                    if (worker.state === 'installed' && navigator.serviceWorker.controller) {
                        showUpdateToast();
                    }
                });
            });
        }).catch(function () {
            // Registration failure must never break the app (e.g. HTTP dev).
        });
    });

    navigator.serviceWorker.addEventListener('controllerchange', function () {
        showUpdateToast();
    });
})();

/* Generic dropdown menus (story header actions): a [data-dropdown] button
   toggles its sibling .story-menu (aria-haspopup/aria-expanded); outside click
   and ESC close every open menu. On mobile the menu is a fixed bottom sheet
   (master.css 768px block). The editor bundle's own menus are untouched. */
(function () {
    'use strict';

    function menuOf(btn) {
        return btn.parentElement ? btn.parentElement.querySelector('.story-menu') : null;
    }

    function closeAll(except) {
        document.querySelectorAll('[data-dropdown]').forEach(function (btn) {
            if (btn === except) {
                return;
            }
            btn.setAttribute('aria-expanded', 'false');
            var menu = menuOf(btn);
            if (menu !== null) {
                menu.hidden = true;
            }
        });
    }

    document.querySelectorAll('[data-dropdown]').forEach(function (btn) {
        btn.addEventListener('click', function (ev) {
            ev.stopPropagation();
            var menu = menuOf(btn);
            if (menu === null) {
                return;
            }
            var willOpen = menu.hidden;
            closeAll(btn);
            menu.hidden = !willOpen;
            btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        });
    });

    document.addEventListener('click', function () { closeAll(null); });
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') {
            closeAll(null);
        }
    });
})();

/* Prompt 29 - PWA stage 2b: the writing dashboard is the primary offline entry
   point (SW: /writing is network-first with a cached fallback, auto-warmed
   below), avatars are SWR, and the per-story offline toggle moved into the
   story header dropdown as a switch.

   - Auto-warm on SW controller ready: precache ['/writing'] + all
     img[src^="/avatars/"] URLs found in the current DOM.
   - Silent refresh every 60 min: re-warm the dashboard; re-precache stories
     from the localStorage index whose last refresh (ts) is older than 12 h -
     so one story is refreshed at most every 12 h regardless of the timer.
   - Story switch (.story-menu__switch input[role=switch]): runs the same
     precache/remove flow (manifest fetch -> PRECACHE_STORY/REMOVE_STORY ->
     index update with ts). Busy = disabled; on failure the switch reverts and
     shows the error toast. Clicking it must not close the dropdown (mousedown
     stopPropagation) and must not navigate (preventDefault).
   - Dashboard offline UX: while !navigator.onLine, rows whose project is NOT in
     the index get .is-online-only (muted, links disabled, hint via CSS); body
     gets .is-offline. Reverts on 'online'. Purely client-side.
   - Without an SW controller (plain-HTTP dev) the switch stays hidden. */
(function () {
    'use strict';
    var INDEX_KEY = 'offline-stories';
    var REFRESH_MS = 60 * 60 * 1000;      // 60 min timer
    var STORY_TTL_MS = 12 * 60 * 60 * 1000; // per-story refresh at most every 12 h

    function readIndex() {
        try { return JSON.parse(localStorage.getItem(INDEX_KEY) || '{}'); } catch (e) { return {}; }
    }
    function writeIndex(idx) {
        try { localStorage.setItem(INDEX_KEY, JSON.stringify(idx)); } catch (e) { /* storage full/blocked */ }
    }
    function toast(msg) {
        var t = document.createElement('div');
        t.className = 'notice app-toast';
        t.setAttribute('role', 'status');
        var span = document.createElement('span');
        span.textContent = msg;
        t.appendChild(span);
        document.body.appendChild(t);
        setTimeout(function () { t.remove(); }, 5000);
    }
    function send(type, urls) {
        return new Promise(function (resolve) {
            var want = type === 'PRECACHE_STORY' ? 'PRECACHE_DONE' : 'REMOVE_DONE';
            var onMsg = function (ev) {
                var d = ev.data || {};
                if (d.type === want) {
                    navigator.serviceWorker.removeEventListener('message', onMsg);
                    resolve(d);
                }
            };
            navigator.serviceWorker.addEventListener('message', onMsg);
            navigator.serviceWorker.controller.postMessage({ type: type, urls: urls });
        });
    }
    function precacheUrls(urls) {
        if (!navigator.serviceWorker.controller || !urls.length) {
            return Promise.resolve({ ok: true, failed: [] });
        }
        return send('PRECACHE_STORY', urls);
    }
    function avatarUrls() {
        return Array.prototype.slice.call(document.querySelectorAll('img[src^="/avatars/"]'))
            .map(function (img) { return img.getAttribute('src'); });
    }
    function warmDashboard() {
        // auto-warm: the dashboard itself plus the avatars rendered on it
        return precacheUrls(['/writing'].concat(avatarUrls()));
    }
    function refreshStaleStories() {
        var idx = readIndex();
        var now = Date.now();
        var jobs = [];
        Object.keys(idx).forEach(function (k) {
            var entry = idx[k];
            if (!entry || !entry.urls) { return; }
            if (!entry.ts || now - entry.ts > STORY_TTL_MS) {
                jobs.push(precacheUrls(entry.urls).then(function () {
                    entry.ts = Date.now();
                    writeIndex(idx);
                }));
            }
        });
        return Promise.all(jobs);
    }

    function initOffline() {
        if (!('serviceWorker' in navigator)) {
            return;
        }
        navigator.serviceWorker.ready.then(function () {
            if (!navigator.serviceWorker.controller) {
                return;
            }
            warmDashboard();
            setInterval(function () {
                warmDashboard();
                refreshStaleStories();
            }, REFRESH_MS);
        });
    }

    // --- story header dropdown switch ---
    function initStorySwitch() {
        var row = document.querySelector('.story-menu__switch');
        if (row === null) {
            return;
        }
        if (!('serviceWorker' in navigator) || navigator.serviceWorker.controller === null) {
            row.hidden = true; // no controller (HTTP dev) -> no offline feature
            return;
        }
        row.hidden = false;
        var input = row.querySelector('input[role="switch"]');
        var project = row.dataset.project;
        var busy = false;
        // the dropdown's outside-click close must not fire for clicks on the
        // switch. NOTE: no preventDefault on click here - it would cancel the
        // checkbox's default activation, so the switch would never toggle and
        // no 'change' event would fire (the reported bug).
        row.addEventListener('mousedown', function (ev) { ev.stopPropagation(); });
        row.addEventListener('click', function (ev) { ev.stopPropagation(); });
        function sync() { input.checked = !!readIndex()[project]; }
        input.addEventListener('change', function () {
            if (busy) { sync(); return; }
            var wantOn = input.checked;
            busy = true;
            input.disabled = true;
            var idx = readIndex();
            var done = function () {
                busy = false;
                input.disabled = false;
                sync();
            };
            if (wantOn) {
                fetch('/writing/' + encodeURIComponent(project) + '/offline-manifest.json', { credentials: 'same-origin' })
                    .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('manifest ' + r.status)); })
                    .then(function (manifest) {
                        var quota = (navigator.storage && navigator.storage.estimate)
                            ? navigator.storage.estimate().then(function (e) { return (e.quota || 0) - (e.usage || 0); })
                            : Promise.resolve(Infinity);
                        return quota.then(function () {
                            return precacheUrls(manifest.urls).then(function (res) {
                                idx[project] = { project: project, title: manifest.title, urls: manifest.urls, url: '/writing/' + project, ts: Date.now() };
                                writeIndex(idx);
                                done();
                                toast(res.ok ? row.dataset.msgSuccess : row.dataset.msgFailed);
                            });
                        });
                    })
                    .catch(function () {
                        toast(row.dataset.msgFailed);
                        done(); // sync() reverts the switch because the index did not change
                    });
            } else {
                var entry = idx[project];
                precacheUrls([]); // no-op guard
                send('REMOVE_STORY', entry ? entry.urls : []).then(function () {
                    delete idx[project];
                    writeIndex(idx);
                    done();
                });
            }
        });
        sync();
    }

    // --- dashboard offline rows ---
    function initDashboardOfflineState() {
        if (window.location.pathname !== '/writing') {
            return;
        }
        function apply() {
            var offline = !navigator.onLine;
            document.body.classList.toggle('is-offline', offline);
            if (!offline) {
                document.querySelectorAll('tr.is-online-only').forEach(function (tr) { tr.classList.remove('is-online-only'); });
                return;
            }
            var idx = readIndex();
            document.querySelectorAll('tr[data-project-id]').forEach(function (tr) {
                var id = tr.getAttribute('data-project-id');
                var cached = !!idx[id];
                tr.classList.toggle('is-online-only', !cached);
                tr.querySelectorAll('a').forEach(function (a) {
                    if (!cached) { a.setAttribute('aria-disabled', 'true'); } else { a.removeAttribute('aria-disabled'); }
                });
            });
        }
        window.addEventListener('online', apply);
        window.addEventListener('offline', apply);
        apply();
    }

    initOffline();
    initStorySwitch();
    initDashboardOfflineState();
})();

/* Prompt 30 part 1: on touch devices (pointer: coarse) disable the native
   spellcheck on the editor surface. On Android Chrome, tapping a
   red-underlined word opens the native suggestion menu and ENDS the IME
   session (soft keyboard closes) - the only app-side lever is the spellcheck
   attribute. The editor bundle creates .editor-prose after page load, so we
   observe the DOM once until it exists (one-shot MutationObserver). Desktop
   (pointer: fine) is untouched: native spellcheck with underlines stays. */
(function () {
    'use strict';
    if (!window.matchMedia('(pointer: coarse)').matches) {
        return;
    }
    function apply() {
        var prose = document.querySelector('.editor-prose');
        if (prose !== null) {
            prose.setAttribute('spellcheck', 'false');
            return true;
        }
        return false;
    }
    if (apply()) {
        return;
    }
    var mo = new MutationObserver(function () {
        if (apply()) {
            mo.disconnect();
        }
    });
    mo.observe(document.body, { childList: true, subtree: true });
})();

/* Prompt 30 part 2: read-more toggles on the dashboard (idea/blurb/synopsis).
   The full text is always in the HTML (server-rendered Markdown); the toggle
   only flips data-collapsed + aria-expanded + label. */
(function () {
    'use strict';
    document.addEventListener('click', function (ev) {
        var btn = ev.target && ev.target.closest ? ev.target.closest('.read-more__toggle') : null;
        if (btn === null) {
            return;
        }
        var wrap = btn.closest('.read-more');
        var collapsed = wrap.getAttribute('data-collapsed') === '1';
        wrap.setAttribute('data-collapsed', collapsed ? '0' : '1');
        btn.setAttribute('aria-expanded', collapsed ? 'true' : 'false');
        btn.textContent = collapsed ? btn.dataset.less : btn.dataset.more;
    });
})();

/* Prompt 31 part 2: "Save draft" - background save via fetch POST to the
   existing save endpoint (JSON branch: Accept: application/json +
   X-Requested-With). No navigation, no reload, editor/focus untouched.
   Success -> toast "Draft saved"; 422 -> toast with the server messages. */
(function () {
    'use strict';
    function toast(msg) {
        var t = document.createElement('div');
        t.className = 'notice app-toast';
        t.setAttribute('role', 'status');
        var span = document.createElement('span');
        span.textContent = msg;
        t.appendChild(span);
        document.body.appendChild(t);
        setTimeout(function () { t.remove(); }, 5000);
    }
    document.querySelectorAll('.save-draft').forEach(function (btn) {
        btn.addEventListener('click', function (ev) {
            ev.preventDefault();
            var form = btn.closest('form') || document.getElementById(btn.getAttribute('form') || '');
            if (form === null || btn.disabled) {
                return;
            }
            btn.disabled = true;
            fetch(form.getAttribute('action') || window.location.pathname, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) {
                return r.json().catch(function () { return { ok: false, errors: [] }; })
                    .then(function (d) { return { status: r.status, data: d }; });
            }).then(function (res) {
                btn.disabled = false;
                if (res.data && res.data.code === 'csrf_expired') {
                    return; // prompt 32: the global 403 wrapper shows the toast + redirects
                }
                if (res.status === 200 && res.data.ok) {
                    if (window.cbClearRescue) { window.cbClearRescue(); }
                    toast(btn.dataset.msgSaved);
                } else {
                    toast((res.data.errors || []).join(' ') || btn.dataset.msgFailed);
                }
            }).catch(function () {
                btn.disabled = false;
                toast(btn.dataset.msgFailed);
            });
        });
    });
})();

/* Prompt 31 part 3: sticky save dock (.editor-save-dock) on the chapter edit
   page. Shows ONLY on meaningful DOWNWARD scroll (delta > MIN_DELTA px),
   fades out IDLE_MS after scrolling stops; upward scroll alone never shows it;
   typing without scrolling keeps it hidden. Hidden state uses visibility, so
   it is not focusable. */
(function () {
    'use strict';
    var dock = document.querySelector('.editor-save-dock');
    if (dock === null) {
        return;
    }
    var MIN_DELTA = 24;   // px - ignore micro-jitter
    var IDLE_MS = 1200;   // fade-out delay after the last scroll
    var lastY = window.scrollY || 0;
    var idleTimer = null;
    function show() {
        dock.classList.add('is-visible');
        dock.removeAttribute('aria-hidden');
    }
    function hide() {
        dock.classList.remove('is-visible');
        dock.setAttribute('aria-hidden', 'true');
    }
    function armIdle() {
        if (idleTimer !== null) {
            clearTimeout(idleTimer);
        }
        idleTimer = setTimeout(hide, IDLE_MS);
    }
    window.addEventListener('scroll', function () {
        var y = window.scrollY || 0;
        var delta = y - lastY;
        lastY = y;
        if (delta > MIN_DELTA) {
            show();
            armIdle();
        } else if (dock.classList.contains('is-visible')) {
            armIdle();
        }
    }, { passive: true });
})();

/* Prompt 32: CSRF/session expiry - never silent. A global fetch wrapper catches
   ANY POST answered with 403: stash a rescue draft when on the chapter editor,
   show the "session expired" toast, redirect to /login?return=<current>&expired=1
   (the server answers AJAX 403s with JSON {code:'csrf_expired', login:...};
   plain form POSTs get a 302 to the same URL and the login page shows the
   notice via expired=1). The rescue key is one slot per chapter and is cleared
   on every successful save (draft + form submit). */
(function () {
    'use strict';
    function toast(msg) {
        var t = document.createElement('div');
        t.className = 'notice app-toast';
        t.setAttribute('role', 'status');
        var span = document.createElement('span');
        span.textContent = msg;
        t.appendChild(span);
        document.body.appendChild(t);
        setTimeout(function () { t.remove(); }, 5000);
    }
    function expiredMsg() {
        return document.body.dataset.sessionExpired || 'Session expired - please log in again';
    }
    function loginUrl() {
        return '/login?return=' + encodeURIComponent(window.location.pathname + window.location.search) + '&expired=1';
    }
    function rescueKey() {
        var form = document.getElementById('chapter-form');
        if (form === null) { return null; }
        var m = (form.getAttribute('action') || '').match(/\/writing\/([^/]+)\/chapters\/([^/?]+)/);
        return m ? 'draft-rescue/' + m[1] + '/' + m[2] : null;
    }
    function stashRescue() {
        var key = rescueKey();
        var ta = document.querySelector('textarea[data-editor]');
        if (key === null || ta === null) { return; }
        try { localStorage.setItem(key, JSON.stringify({ md: ta.value, ts: Date.now() })); } catch (e) {}
    }
    function sessionExpired() {
        stashRescue();
        toast(expiredMsg());
        window.location.href = loginUrl();
    }
    window.cbClearRescue = function () {
        var key = rescueKey();
        if (key !== null) { try { localStorage.removeItem(key); } catch (e) {} }
    };
    var origFetch = window.fetch.bind(window);
    window.fetch = function (input, init) {
        var method = ((init && init.method) || (input && input.method) || 'GET').toUpperCase();
        return origFetch(input, init).then(function (res) {
            if (res.status === 403 && method === 'POST') {
                sessionExpired();
            }
            return res;
        });
    };
    // clear the rescue slot on regular form submit too (a 403 re-stashes via the wrapper)
    var chapterForm = document.getElementById('chapter-form');
    if (chapterForm !== null) {
        chapterForm.addEventListener('submit', function () { window.cbClearRescue(); });
    }
    // rescue banner on the chapter edit page (non-blocking, top of the card)
    var banner = document.getElementById('draft-rescue-banner');
    var key = rescueKey();
    if (banner !== null && key !== null) {
        var raw = null;
        try { raw = JSON.parse(localStorage.getItem(key) || 'null'); } catch (e) { raw = null; }
        if (raw !== null && raw.ts) {
            banner.hidden = false;
            var textEl = banner.querySelector('[data-rescue-text]');
            if (textEl !== null) {
                textEl.textContent = (banner.dataset.foundTemplate || '').replace('{time}', new Date(raw.ts).toLocaleString());
            }
            banner.querySelector('[data-rescue-restore]').addEventListener('click', function () {
                var ta = document.querySelector('textarea[data-editor]');
                if (ta !== null) { ta.value = raw.md || ''; }
                try { localStorage.removeItem(key); } catch (e) {}
                window.location.reload(); // bundle re-initializes the editor from the textarea
            });
            banner.querySelector('[data-rescue-discard]').addEventListener('click', function () {
                try { localStorage.removeItem(key); } catch (e) {}
                banner.hidden = true;
            });
        }
    }
})();

/* Prompt 32 part 5: session heartbeat for long-open tabs / returning PWA users.
   On visibilitychange -> visible AND online, at most once per 5 minutes, ping
   GET /session-check (network-only in the SW, skipped while offline). 401 ->
   toast + redirect to login with the return URL. The 403 path stays the final
   safety net for save attempts. */
(function () {
    'use strict';
    var THROTTLE_MS = 5 * 60 * 1000;
    var KEY = 'cb-session-check';
    function toast(msg) {
        var t = document.createElement('div');
        t.className = 'notice app-toast';
        t.setAttribute('role', 'status');
        var span = document.createElement('span');
        span.textContent = msg;
        t.appendChild(span);
        document.body.appendChild(t);
        setTimeout(function () { t.remove(); }, 5000);
    }
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState !== 'visible' || !navigator.onLine) {
            return;
        }
        var last = 0;
        try { last = parseInt(localStorage.getItem(KEY) || '0', 10) || 0; } catch (e) {}
        if (Date.now() - last < THROTTLE_MS) {
            return;
        }
        try { localStorage.setItem(KEY, String(Date.now())); } catch (e) {}
        fetch('/session-check', { headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(function (r) {
            if (r.status === 401) {
                toast(document.body.dataset.sessionExpired || 'Session expired - please log in again');
                window.location.href = '/login?return=' + encodeURIComponent(window.location.pathname + window.location.search) + '&expired=1';
            }
        }).catch(function () { /* offline or failed - next visible visit retries */ });
    });
})();
