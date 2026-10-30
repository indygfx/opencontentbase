<section class="card">
    <p class="muted"><a href="/writing/<?= e($projectId) ?>">&larr; <?= e($projectTitle) ?></a></p>
    <h1><?= $isEdit ? e(t('writing', 'edit_prefix', ['title' => (string)($chapter['title'] ?? '')])) : e(t('writing', 'new_chapter')) ?></h1>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>
    <?php if ($synopsis !== null): ?>
        <div class="outline-box">
            <h2><?= e(t('writing', 'outline_readonly')) ?></h2>
            <?= $synopsisSummaryHtml ?>
        </div>
    <?php endif; ?>
    <form method="post" action="<?= $isEdit
        ? '/writing/' . e($projectId) . '/chapters/' . e($chapter['slug'])
        : '/writing/' . e($projectId) . '/chapters' ?>">
        <?= $csrf->field() ?>
        <label for="synopsis_id"><?= e(t('writing', 'chapter_outline')) ?> <small class="muted"><?= e(t('writing', 'outline_title_hint')) ?></small></label>
        <select id="synopsis_id" name="synopsis_id" required>
            <?php if (!$isEdit): ?>
                <option value=""><?= e(t('writing', 'pick_outline')) ?>&hellip;</option>
            <?php endif; ?>
            <?php foreach ($synopses as $s): ?>
                <option value="<?= e($s['id']) ?>" <?= ($synopsis['id'] ?? '') === $s['id'] ? 'selected' : '' ?>><?= e($s['title']) ?></option>
            <?php endforeach; ?>
        </select>
        <label for="slug"><?= e(t('pages', 'slug_label')) ?> <small class="muted"><?= e(t('writing', 'slug_from_outline_hint')) ?></small></label>
        <input id="slug" name="slug" value="<?= e($chapter['slug'] ?? '') ?>" placeholder="automatic" pattern="[a-z0-9-]*" title="a-z, 0-9, hyphen; leave empty to derive automatically">
        <label for="body"><?= e(t('writing', 'body_chapter_label')) ?></label>
        <textarea id="body" name="body" rows="14" data-editor="tiptap"><?= e($chapter['body'] ?? '') ?></textarea>
        <button type="submit"><?= e(t('pages', 'save')) ?></button>
    </form>
</section>
