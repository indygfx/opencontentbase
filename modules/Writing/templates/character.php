<section class="card">
    <p class="muted"><a href="/writing/<?= e($projectId) ?>">&larr; <?= e($projectTitle) ?></a></p>
    <?php if (!empty($imageError)): ?>
        <p class="error"><?= e($imageError) ?></p>
    <?php endif; ?>
    <?php if (($character['image_path'] ?? null) !== null && (string)$character['image_path'] !== ''): ?>
        <img class="character-portrait" src="/writing/<?= e($projectId) ?>/characters/<?= e($character['slug']) ?>/image"
             alt="<?= e(t('writing', 'portrait_alt', ['title' => (string)($character['title'] ?? $character['slug'])])) ?>">
    <?php endif; ?>
    <article>
        <h1><?= e($character['title'] ?? $character['slug']) ?></h1>
        <div class="content"><?= $rendered /* already escaped/sanitized by ContentRenderer */ ?></div>
    </article>
    <?php if ($canEdit): ?>
        <h2><?= e(t('writing', 'character_image')) ?></h2>
        <?php if (($character['image_path'] ?? null) !== null && (string)$character['image_path'] !== ''): ?>
            <form class="inline" method="post" action="/writing/<?= e($projectId) ?>/characters/<?= e($character['slug']) ?>/image/delete" data-confirm="<?= e(t('writing', 'confirm_remove_image')) ?>">
                <?= $csrf->field() ?>
                <button type="submit"><?= e(t('writing', 'remove_image')) ?></button>
            </form>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" action="/writing/<?= e($projectId) ?>/characters/<?= e($character['slug']) ?>/image">
            <?= $csrf->field() ?>
            <label for="image"><?= e(t('writing', 'upload_portrait')) ?> <small class="muted"><?= e(t('writing', 'image_rules')) ?></small></label>
            <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp,image/gif">
            <button type="submit"><?= (($character['image_path'] ?? null) !== null && (string)$character['image_path'] !== '') ? e(t('writing', 'replace_image')) : e(t('writing', 'upload_image')) ?></button>
        </form>
        <p>
            <a href="/writing/<?= e($projectId) ?>/characters/<?= e($character['slug']) ?>/edit"><?= e(t('writing', 'edit')) ?></a>
            <a href="/writing/<?= e($projectId) ?>/characters/<?= e($character['slug']) ?>/revisions"><?= e(t('writing', 'history')) ?></a>
            <form class="inline" method="post" action="/writing/<?= e($projectId) ?>/characters/<?= e($character['slug']) ?>/delete" data-confirm="<?= e(t('writing', 'confirm_delete_character')) ?>">
                <?= $csrf->field() ?>
                <button type="submit"><?= e(t('core', 'users_delete')) ?></button>
            </form>
        </p>
    <?php endif; ?>
    <?php /* compact recent-revisions list; renders nothing when there are
           no revisions besides the current one */ ?>
    <?php $recentBase = $recentBase ?? ''; $recentRevisions = $recentRevisions ?? []; ?>
    <?php include __DIR__ . '/_recent_revisions.php'; ?>
</section>
