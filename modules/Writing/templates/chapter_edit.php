<section class="card">
    <p class="muted"><a href="/writing/<?= e($projectId) ?>">&larr; <?= e($projectTitle) ?></a></p>
    <h1><?= $isEdit ? 'Edit: ' . e($chapter['title'] ?? '') : 'New chapter' ?></h1>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>
    <?php if ($synopsis !== null): ?>
        <div class="outline-box">
            <h2>Chapter outline (read only)</h2>
            <p><?= nl2br(e((string)$synopsis['summary_text'])) ?></p>
        </div>
    <?php endif; ?>
    <form method="post" action="<?= $isEdit
        ? '/writing/' . e($projectId) . '/chapters/' . e($chapter['slug'])
        : '/writing/' . e($projectId) . '/chapters' ?>">
        <?= $csrf->field() ?>
        <label for="synopsis_id">Chapter outline <small class="muted">(the title of the written chapter comes from the outline)</small></label>
        <select id="synopsis_id" name="synopsis_id" required>
            <?php if (!$isEdit): ?>
                <option value="">Pick a chapter outline&hellip;</option>
            <?php endif; ?>
            <?php foreach ($synopses as $s): ?>
                <option value="<?= e($s['id']) ?>" <?= ($synopsis['id'] ?? '') === $s['id'] ? 'selected' : '' ?>><?= e($s['title']) ?></option>
            <?php endforeach; ?>
        </select>
        <label for="slug">Slug <small class="muted">(leave empty = derived from the outline title)</small></label>
        <input id="slug" name="slug" value="<?= e($chapter['slug'] ?? '') ?>" placeholder="automatic" pattern="[a-z0-9-]*" title="a-z, 0-9, hyphen; leave empty to derive automatically">
        <label for="body">Body (Markdown, internal links as [[chapter:slug]])</label>
        <textarea id="body" name="body" rows="14"><?= e($chapter['body'] ?? '') ?></textarea>
        <button type="submit">Save</button>
    </form>
    <script>
    (function () {
        'use strict';
        var body = document.getElementById('body');
        document.addEventListener('keydown', function (ev) {
            if ((ev.metaKey || ev.ctrlKey) && ev.key === 's') {
                ev.preventDefault();
                body.form.submit();
            }
        });
    })();
    </script>
</section>
