<section class="card">
    <p class="muted"><a href="/writing/<?= e($projectId) ?>">&larr; <?= e($projectTitle) ?></a></p>
    <h1><?= e(t('writing', 'chapter_outline')) ?></h1>
    <h2><?= e($synopsis['title']) ?></h2>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>
    <?php if ((string)$synopsis['summary_text'] !== ''): ?>
        <div class="content"><?= $summaryHtml ?></div>
    <?php else: ?>
        <p class="muted"><?= e(t('writing', 'no_summary_yet')) ?></p>
    <?php endif; ?>
    <?php if ($chapter !== null): ?>
        <p class="muted"><?= e(t('writing', 'assigned_to_chapter')) ?>
            <a href="/writing/<?= e($projectId) ?>/chapters/<?= e($chapter['slug']) ?>"><?= e($chapter['title']) ?></a>.
        </p>
    <?php else: ?>
        <p class="muted"><?= e(t('writing', 'not_assigned_yet')) ?></p>
    <?php endif; ?>
    <?php if ($canEdit): ?>
        <p>
            <a href="/writing/<?= e($projectId) ?>/synopses/<?= e($synopsis['slug']) ?>/edit"><?= e(t('writing', 'edit')) ?></a>
            <form class="inline" method="post" action="/writing/<?= e($projectId) ?>/synopses/<?= e($synopsis['slug']) ?>/delete" data-confirm="<?= e(t('writing', 'confirm_delete_outline')) ?>">
                <?= $csrf->field() ?>
                <button type="submit"><?= e(t('core', 'users_delete')) ?></button>
            </form>
        </p>
    <?php endif; ?>
</section>
