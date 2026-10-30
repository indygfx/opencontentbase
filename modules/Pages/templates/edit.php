<section class="card">
    <p class="muted"><a href="/pages">&larr; <?= e(t('pages', 'title')) ?></a></p>
    <h1><?= $page !== null ? e(t('pages', 'edit_page', ['title' => (string)($page['title'] ?? $page['slug'])])) : e(t('pages', 'new_page')) ?></h1>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>
    <form method="post" action="<?= $page !== null ? '/pages/' . e($page['slug']) : '/pages' ?>">
        <?= $csrf->field() ?>
        <?php if ($page === null): ?>
            <label for="slug"><?= e(t('pages', 'slug_label')) ?> <small class="muted"><?= e(t('pages', 'slug_hint')) ?></small></label>
            <input id="slug" name="slug" value="<?= e($slug ?? '') ?>" placeholder="<?= e(t('pages', 'slug_placeholder')) ?>" data-slug-preview="<?= e(t('pages', 'slug_preview', ['slug' => ''])) ?>" pattern="[a-z0-9-]*" title="<?= e(t('pages', 'slug_title')) ?>">
        <?php endif; ?>
        <label for="title"><?= e(t('pages', 'title_label')) ?></label>
        <input id="title" name="title" value="<?= e($formTitle ?? ($page['title'] ?? '')) ?>" required maxlength="200">
        <label for="body"><?= e(t('pages', 'body_label')) ?></label>
        <textarea id="body" name="body" rows="14" data-editor="tiptap"><?= e($formBody ?? ($page['body'] ?? '')) ?></textarea>
        <button type="submit"><?= e(t('pages', 'save')) ?></button>
    </form>
    <?php if ($page !== null): ?>
        <form class="inline" method="post" action="/pages/<?= e($page['slug']) ?>/delete" data-confirm="<?= e(t('pages', 'delete_page')) ?>?">
            <?= $csrf->field() ?>
            <button type="submit"><?= e(t('pages', 'delete_page')) ?></button>
        </form>
    <?php endif; ?>
</section>
