<section class="card">
    <p class="muted"><a href="/writing/<?= e($projectId) ?>">&larr; <?= e($projectTitle) ?></a></p>
    <h1><?= $isEdit ? e(t('writing', 'edit_outline_prefix', ['title' => (string)($synopsis['title'] ?? '')])) : e(t('writing', 'new_chapter_outline')) ?></h1>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>
    <form method="post" action="<?= $isEdit
        ? '/writing/' . e($projectId) . '/synopses/' . e($synopsis['slug'])
        : '/writing/' . e($projectId) . '/synopses' ?>">
        <?= $csrf->field() ?>
        <label for="title"><?= e(t('pages', 'title_label')) ?></label>
        <input id="title" name="title" value="<?= e($synopsis['title'] ?? '') ?>" required maxlength="200">
        <label for="slug"><?= e(t('pages', 'slug_label')) ?> <small class="muted"><?= e(t('pages', 'slug_hint')) ?></small></label>
        <input id="slug" name="slug" value="<?= e($synopsis['slug'] ?? '') ?>" placeholder="automatic" pattern="[a-z0-9-]*" title="a-z, 0-9, hyphen; leave empty to derive automatically" <?= $isEdit ? 'readonly' : '' ?>>
        <label for="summary"><?= e(t('writing', 'summary')) ?> <small class="muted"><?= e(t('writing', 'summary_hint')) ?></small></label>
        <textarea id="summary" name="summary" rows="6" data-editor="tiptap" data-editor-placeholder="<?= e(t('writing', 'summary_placeholder')) ?>" placeholder="<?= e(t('writing', 'summary_placeholder')) ?>"><?= e($synopsis['summary_text'] ?? '') ?></textarea>
        <button type="submit"><?= e(t('pages', 'save')) ?></button>
    </form>
</section>
