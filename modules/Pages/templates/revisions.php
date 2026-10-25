<section class="card">
    <p class="muted"><a href="/pages/<?= e($page['slug']) ?>">&larr; <?= e($page['title']) ?></a></p>
    <h1><?= e(t('pages', 'history')) ?></h1>
    <p class="muted"><?= e($page['title']) ?> &middot; <?= e(t('pages', 'history_intro')) ?></p>
    <?php if (!empty($notice)): ?>
        <p class="notice"><?= e($notice) ?></p>
    <?php endif; ?>

    <?php if ($rows === []): ?>
        <p class="muted"><?= e(t('pages', 'history_empty')) ?></p>
    <?php else: ?>
        <table>
            <thead>
            <tr>
                <th><?= e(t('pages', 'history_col_revision')) ?></th>
                <th><?= e(t('pages', 'history_col_author')) ?></th>
                <th><?= e(t('pages', 'history_col_when')) ?></th>
                <th><?= e(t('pages', 'history_col_changes')) ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td>
                        <a href="/pages/<?= e($page['slug']) ?>/revisions/<?= e($row['id']) ?>">
                            <?= e(t('pages', 'history_revision_link')) ?>
                        </a>
                        <?php if ($row['isLatest']): ?>
                            <span class="badge"><?= e(t('pages', 'history_current')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($row['author'] !== '' ? $row['author'] : t('pages', 'history_unknown_author')) ?></td>
                    <td><?= e($row['created_at']) ?></td>
                    <td>
                        <?php if ($row['isLatest']): ?>
                            &ndash;
                        <?php else: ?>
                            <span class="diffstat-added">+<?= (int)$row['added'] ?></span>
                            <span class="diffstat-removed">&minus;<?= (int)$row['removed'] ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!$row['isLatest']): ?>
                            <form class="inline" method="post"
                                  action="/pages/<?= e($page['slug']) ?>/revisions/<?= e($row['id']) ?>/restore"
                                  data-confirm="<?= e(t('pages', 'history_restore_confirm')) ?>">
                                <?= $csrf->field() ?>
                                <button type="submit"><?= e(t('pages', 'history_restore')) ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
