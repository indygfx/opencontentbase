<section class="card">
    <p class="muted">
        <a href="/writing/<?= e($projectId) ?>/<?= e($type) ?>s/<?= e($slug) ?>">&larr; <?= e($objectTitle) ?></a>
    </p>
    <h1><?= e(t('writing', 'history')) ?></h1>
    <p class="muted"><?= e($objectTitle) ?> &middot; <?= e(t('writing', 'history_intro')) ?></p>

    <?php if ($rows === []): ?>
        <p class="muted"><?= e(t('writing', 'history_empty')) ?></p>
    <?php else: ?>
        <table>
            <thead>
            <tr>
                <th><?= e(t('writing', 'history_col_revision')) ?></th>
                <th><?= e(t('writing', 'history_col_author')) ?></th>
                <th><?= e(t('writing', 'history_col_when')) ?></th>
                <th><?= e(t('writing', 'history_col_changes')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td>
                        <a href="/writing/<?= e($projectId) ?>/<?= e($type) ?>s/<?= e($slug) ?>/revisions/<?= e($row['id']) ?>">
                            <?= e(t('writing', 'history_revision_link', ['title' => $row['title']])) ?>
                        </a>
                        <?php if ($row['isLatest']): ?>
                            <span class="badge"><?= e(t('writing', 'history_current')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($row['author'] !== '' ? $row['author'] : t('writing', 'history_unknown_author')) ?></td>
                    <td><?= e($row['created_at']) ?></td>
                    <td>
                        <?php if ($row['isLatest']): ?>
                            &ndash;
                        <?php else: ?>
                            <span class="diffstat-added">+<?= (int)$row['added'] ?></span>
                            <span class="diffstat-removed">&minus;<?= (int)$row['removed'] ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($canEdit && count($revisionIds) > 1): ?>
            <h2><?= e(t('writing', 'history_compare')) ?></h2>
            <form method="get" class="compare-form"
                  action="/writing/<?= e($projectId) ?>/<?= e($type) ?>s/<?= e($slug) ?>/revisions/compare">
                <label for="compare-from"><?= e(t('writing', 'history_compare_from')) ?></label>
                <select id="compare-from" name="from">
                    <?php foreach ($rows as $row): ?>
                        <option value="<?= e($row['id']) ?>"><?= e(t('writing', 'history_revision_link', ['title' => $row['title']])) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="compare-to"><?= e(t('writing', 'history_compare_to')) ?></label>
                <select id="compare-to" name="to">
                    <?php foreach ($rows as $i => $row): ?>
                        <option value="<?= e($row['id']) ?>" <?= $i === 0 ? 'selected' : '' ?>><?= e(t('writing', 'history_revision_link', ['title' => $row['title']])) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit"><?= e(t('writing', 'history_compare')) ?></button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>
