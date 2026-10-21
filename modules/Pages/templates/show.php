<article>
    <h1><?= e($page['title'] ?? $page['slug']) ?></h1>
    <div class="content"><?= $rendered ?>
    </div>
    <?php if ($user->isAdmin()): ?>
    <p>
        <a href="/pages/<?= e($page['slug']) ?>/edit"><?= e(t('writing', 'edit')) ?></a>
        <form class="inline" method="post" action="/pages/<?= e($page['slug']) ?>/delete" data-confirm="<?= e(t('pages', 'delete_page')) ?>?">
            <?= $csrf->field() ?>
            <button type="submit"><?= e(t('pages', 'delete_page')) ?></button>
        </form>
    </p>
    <?php endif; ?>
</article>

<section>
    <h2><?= e(t('pages', 'revisions')) ?></h2>
    <table>
        <thead>
        <tr>
            <th><?= e(t('pages', 'table_title')) ?></th>
            <th><?= e(t('pages', 'table_author')) ?></th>
            <th><?= e(t('pages', 'table_created')) ?></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($revisions as $rev): ?>
            <tr>
                <td><?= e($rev['title']) ?></td>
                <td><?= e($rev['author'] ?? '—') ?></td>
                <td><?= e($rev['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
