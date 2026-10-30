<section class="card">
    <p class="muted"><a href="/writing/<?= e($projectId) ?>">&larr; <?= e($projectTitle) ?></a></p>
    <h1><?= $background !== null ? e(t('writing', 'edit_prefix', ['title' => (string)($background['title'] ?? '')])) : e(t('writing', 'new_background')) ?></h1>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>
    <form method="post" action="<?= $background !== null
        ? '/writing/' . e($projectId) . '/backgrounds/' . e($background['slug'])
        : '/writing/' . e($projectId) . '/backgrounds' ?>">
        <?= $csrf->field() ?>
        <label for="slug"><?= e(t('pages', 'slug_label')) ?> <small class="muted"><?= e(t('pages', 'slug_hint')) ?></small></label>
        <input id="slug" name="slug" value="<?= e($background['slug'] ?? '') ?>" placeholder="automatic" pattern="[a-z0-9-]*" title="a-z, 0-9, hyphen; leave empty to derive automatically">
        <label for="title"><?= e(t('pages', 'title_label')) ?></label>
        <input id="title" name="title" value="<?= e($background['title'] ?? '') ?>" required>
        <label for="body"><?= e(t('writing', 'body_background_label')) ?></label>
        <textarea id="body" name="body" rows="14" data-editor="tiptap"><?= e($background['body'] ?? '') ?></textarea>
        <button type="submit"><?= e(t('pages', 'save')) ?></button>
    </form>
</section>
