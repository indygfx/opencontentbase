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
