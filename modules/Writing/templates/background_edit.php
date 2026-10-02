<section class="card">
    <p class="muted"><a href="/writing/<?= e($projectId) ?>">&larr; <?= e($projectTitle) ?></a></p>
    <h1><?= $background !== null ? 'Edit: ' . e($background['title'] ?? '') : 'New background' ?></h1>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>
    <form method="post" action="<?= $background !== null
        ? '/writing/' . e($projectId) . '/backgrounds/' . e($background['slug'])
        : '/writing/' . e($projectId) . '/backgrounds' ?>">
        <?= $csrf->field() ?>
        <label for="slug">Slug <small class="muted">(leave empty = derived from the title)</small></label>
        <input id="slug" name="slug" value="<?= e($background['slug'] ?? '') ?>" placeholder="automatic" pattern="[a-z0-9-]*" title="a-z, 0-9, hyphen; leave empty to derive automatically">
        <label for="title">Title</label>
        <input id="title" name="title" value="<?= e($background['title'] ?? '') ?>" required>
        <label for="body">Body (Markdown, internal links as [[background:slug]])</label>
        <textarea id="body" name="body" rows="14" data-preview="/writing/<?= e($projectId) ?>/backgrounds/preview"><?= e($background['body'] ?? '') ?></textarea>
        <button type="submit">Save</button>
    </form>
    <section class="preview">
        <h2>Preview</h2>
        <div id="preview" class="content" aria-live="polite"></div>
    </section>
    <script>
    (function () {
        'use strict';
        var body = document.getElementById('body');
        var preview = document.getElementById('preview');
        var url = body.getAttribute('data-preview');
        var csrfField = document.querySelector('input[name="_csrf"]');
        var csrfToken = csrfField !== null ? csrfField.value : '';
        var timer = null;
        function fetchPreview() {
            fetch(url, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({body: body.value, _csrf: csrfToken}).toString()
            }).then(function (r) {
                return r.json();
            }).then(function (data) {
                preview.innerHTML = data.html;
            }).catch(function () {
                preview.textContent = 'Preview unavailable.';
            });
        }
        body.addEventListener('input', function () {
            if (timer !== null) {
                clearTimeout(timer);
            }
            timer = setTimeout(fetchPreview, 400);
        });
        fetchPreview();
        document.addEventListener('keydown', function (ev) {
            if ((ev.metaKey || ev.ctrlKey) && ev.key === 's') {
                ev.preventDefault();
                body.form.submit();
            }
        });
    })();
    </script>
</section>
