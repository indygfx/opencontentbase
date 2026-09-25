<h1><?= $page !== null ? 'Bearbeiten: ' . e($page['title'] ?? $page['slug']) : 'Neue Seite' ?></h1>
<?php if (!empty($error)): ?>
    <p class="error"><?= e($error) ?></p>
<?php endif; ?>
<form method="post" action="<?= $page !== null ? '/pages/' . e($page['slug']) : '/pages' ?>" class="card">
    <?php if ($page === null): ?>
        <label for="slug">Slug</label>
        <input id="slug" name="slug" value="<?= e($slug ?? '') ?>" required pattern="[a-z0-9-]+">
    <?php endif; ?>
    <label for="title">Titel</label>
    <input id="title" name="title" value="<?= e($page['title'] ?? '') ?>" required>
    <label for="body">Inhalt (Markdown, interne Links als [[page:slug]])</label>
    <textarea id="body" name="body" rows="14" data-preview="<?= $page !== null ? '/pages/' . e($page['slug']) . '/preview' : '/pages/preview' ?>"><?= e($page['body'] ?? '') ?></textarea>
    <button type="submit">Speichern</button>
</form>

<section class="preview">
    <h2>Vorschau</h2>
    <div id="preview" class="content" aria-live="polite"></div>
</section>

<script>
(function () {
    'use strict';
    var body = document.getElementById('body');
    var preview = document.getElementById('preview');
    var url = body.getAttribute('data-preview');
    var timer = null;

    function fetchPreview() {
        fetch(url, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({body: body.value}).toString()
        }).then(function (r) {
            return r.json();
        }).then(function (data) {
            preview.innerHTML = data.html;
        }).catch(function () {
            preview.textContent = 'Vorschau nicht verfügbar.';
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
