<section class="card">
    <p class="muted"><a href="/writing/<?= e($projectId) ?>">&larr; <?= e($projectTitle) ?></a></p>
    <article>
        <h1><?= e($chapter['title'] ?? $chapter['slug']) ?></h1>
        <?php if (!empty($chapter['summary'])): ?>
            <p class="muted"><strong><?= e(t('writing', 'summary')) ?>:</strong> <?= e($chapter['summary']) ?></p>
        <?php endif; ?>
        <div class="content"><?= $rendered /* already escaped/sanitized by ContentRenderer */ ?></div>
    </article>
    <?php if ($canEdit): ?>
        <p>
            <a href="/writing/<?= e($projectId) ?>/chapters/<?= e($chapter['slug']) ?>/edit"><?= e(t('writing', 'edit')) ?></a>
            <a href="/writing/<?= e($projectId) ?>/chapters/<?= e($chapter['slug']) ?>/revisions"><?= e(t('writing', 'history')) ?></a>
            <form class="inline" method="post" action="/writing/<?= e($projectId) ?>/chapters/<?= e($chapter['slug']) ?>/delete" data-confirm="<?= e(t('writing', 'confirm_delete_chapter')) ?>">
                <?= $csrf->field() ?>
                <button type="submit"><?= e(t('core', 'users_delete')) ?></button>
            </form>
        </p>
    <?php endif; ?>
</section>
