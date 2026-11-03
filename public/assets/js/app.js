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
   one-row viewports never see it. Purely additive - the editor bundle's DOM
   and listeners are untouched. */
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
        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'editor-toolbar-toggle';
        toggle.setAttribute('aria-label', 'More formatting');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.hidden = true;
        toggle.textContent = CHEVRON_DOWN;
        toggle.addEventListener('click', function () {
            var collapsed = bar.classList.toggle('is-collapsed');
            toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            toggle.textContent = collapsed ? CHEVRON_DOWN : CHEVRON_UP;
        });
        bar.appendChild(toggle);
    }

    function update(bar) {
        var toggle = bar.querySelector('.editor-toolbar-toggle');
        if (toggle === null) {
            return;
        }
        if (!mq.matches) {
            // Desktop: never collapsed, no toggle.
            bar.classList.remove('is-collapsed');
            toggle.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
            toggle.textContent = CHEVRON_DOWN;
            return;
        }
        // Mobile: collapse first, then measure whether wrapped rows are hidden.
        bar.classList.add('is-collapsed');
        var overflows = bar.scrollHeight > bar.clientHeight + 2;
        if (overflows) {
            toggle.hidden = false;
        } else {
            bar.classList.remove('is-collapsed');
            toggle.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
            toggle.textContent = CHEVRON_DOWN;
        }
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
