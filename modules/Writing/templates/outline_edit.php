<section class="card">
    <p class="muted"><a href="/writing/<?= e($project['id']) ?>">&larr; Back to the story</a></p>
    <h1><?= e($label) ?></h1>
    <p class="muted"><?= e($project['title']) ?></p>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>
    <form method="post" action="/writing/<?= e($project['id']) ?>/outline/<?= e($field) ?>">
        <?= $csrf->field() ?>
        <label for="text"><?= e($label) ?> (Markdown, internal links as [[chapter:slug]])</label>
        <textarea id="text" name="text" rows="14" data-preview="/writing/outline/preview"><?= e($text) ?></textarea>
        <button type="submit">Save</button>
    </form>
    <section class="preview">
        <h2>Preview</h2>
        <div id="preview" class="content" aria-live="polite"></div>
    </section>
    <script>
    (function () {
        'use strict';
        var text = document.getElementById('text');
        var preview = document.getElementById('preview');
        var url = text.getAttribute('data-preview');
        var csrfField = document.querySelector('input[name="_csrf"]');
        var csrfToken = csrfField !== null ? csrfField.value : '';
        var timer = null;
        function fetchPreview() {
            fetch(url, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({text: text.value, _csrf: csrfToken}).toString()
            }).then(function (r) {
                return r.json();
            }).then(function (data) {
                preview.innerHTML = data.html;
            }).catch(function () {
                preview.textContent = 'Preview unavailable.';
            });
        }
        text.addEventListener('input', function () {
            if (timer !== null) {
                clearTimeout(timer);
            }
            timer = setTimeout(fetchPreview, 400);
        });
        fetchPreview();
        document.addEventListener('keydown', function (ev) {
            if ((ev.metaKey || ev.ctrlKey) && ev.key === 's') {
                ev.preventDefault();
                text.form.submit();
            }
        });
    })();
    </script>
</section>
