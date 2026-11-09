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
