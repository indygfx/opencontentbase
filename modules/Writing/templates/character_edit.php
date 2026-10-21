<section class="card">
    <p class="muted"><a href="/writing/<?= e($projectId) ?>">&larr; <?= e($projectTitle) ?></a></p>
    <h1><?= $character !== null ? e(t('writing', 'edit_prefix', ['title' => (string)($character['title'] ?? '')])) : e(t('writing', 'new_character')) ?></h1>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>
    <form method="post" action="<?= $character !== null
        ? '/writing/' . e($projectId) . '/characters/' . e($character['slug'])
        : '/writing/' . e($projectId) . '/characters' ?>">
        <?= $csrf->field() ?>
        <label for="slug"><?= e(t('pages', 'slug_label')) ?> <small class="muted"><?= e(t('pages', 'slug_hint')) ?></small></label>
        <input id="slug" name="slug" value="<?= e($character['slug'] ?? '') ?>" placeholder="automatic" pattern="[a-z0-9-]*" title="a-z, 0-9, hyphen; leave empty to derive automatically">
        <label for="title"><?= e(t('pages', 'title_label')) ?></label>
        <input id="title" name="title" value="<?= e($character['title'] ?? '') ?>" required>
        <label for="body"><?= e(t('writing', 'body_character_label')) ?></label>
        <textarea id="body" name="body" rows="14" data-editor="tiptap"><?= e($character['body'] ?? '') ?></textarea>
        <button type="submit"><?= e(t('pages', 'save')) ?></button>
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
