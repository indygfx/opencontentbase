<section class="card">
    <p class="muted"><a href="/writing/<?= e($projectId) ?>">&larr; <?= e($projectTitle) ?></a></p>
    <h1><?= $isEdit ? 'Edit: ' . e($chapter['title'] ?? '') : 'New chapter' ?></h1>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>
    <?php if ($isEdit && !empty($chapter['summary'])): ?>
        <div class="outline-box">
            <h2>Chapter outline (read only)</h2>
            <p><?= nl2br(e($chapter['summary'])) ?></p>
            <p class="muted"><a href="/writing/<?= e($projectId) ?>/chapters/<?= e($chapter['slug']) ?>/summary">Edit outline</a></p>
        </div>
    <?php endif; ?>
    <form method="post" action="<?= $isEdit
        ? '/writing/' . e($projectId) . '/chapters/' . e($chapter['slug'])
        : '/writing/' . e($projectId) . '/chapters' ?>">
        <?= $csrf->field() ?>
        <label for="slug">Slug <small class="muted">(leave empty = derived from the title)</small></label>
        <input id="slug" name="slug" value="<?= e($chapter['slug'] ?? '') ?>" placeholder="automatic" pattern="[a-z0-9-]*" title="a-z, 0-9, hyphen; leave empty to derive automatically" <?= $isEdit ? '' : '' ?>>
        <label for="title">Title</label>
        <input id="title" name="title" value="<?= e($chapter['title'] ?? '') ?>" required>
        <?php if (!$isEdit): ?>
            <label for="summary">Summary <small class="muted">(the outline of this chapter &mdash; write the idea first, the prose later)</small></label>
            <textarea id="summary" name="summary" rows="3" placeholder="What happens in this chapter?"><?= e($chapter['summary'] ?? '') ?></textarea>
        <?php else: ?>
            <input type="hidden" name="summary" value="<?= e($chapter['summary'] ?? '') ?>">
        <?php endif; ?>
        <label for="body">Body (Markdown, internal links as [[chapter:slug]])</label>
        <textarea id="body" name="body" rows="14" data-preview="/writing/<?= e($projectId) ?>/chapters/preview"><?= e($chapter['body'] ?? '') ?></textarea>
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
