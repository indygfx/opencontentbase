<?php // Compact "recent revisions" list embedded on Writing object pages.
     // $recentRevisions: list<array{id, author, created_at, added, removed}> (latest skipped)
     // $recentBase: history URL base, e.g. /writing/{id}/chapters/{slug} ?>
<?php if (count($recentRevisions) > 0): ?>
    <details class="recent-revisions">
        <summary><?= e(t('writing', 'history_recent')) ?></summary>
        <p class="muted"><?= e(t('writing', 'history_recent_current')) ?></p>
        <ul class="recent-revision-list">
            <?php foreach ($recentRevisions as $row): ?>
                <li>
                    <a href="<?= e($recentBase) ?>/revisions/<?= e($row['id']) ?>">
                        <?= e($row['created_at']) ?>
                    </a>
                    <span class="muted">
                        &middot; <?= e($row['author'] !== '' ? $row['author'] : t('writing', 'history_unknown_author')) ?>
                    </span>
                    <span class="diffstat-added">+<?= (int)$row['added'] ?></span>
                    <span class="diffstat-removed">&minus;<?= (int)$row['removed'] ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <p><a href="<?= e($recentBase) ?>/revisions"><?= e(t('writing', 'history')) ?></a></p>
    </details>
<?php endif; ?>
