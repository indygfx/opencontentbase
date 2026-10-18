<article>
    <h1><?= e($page['title'] ?? $page['slug']) ?></h1>
    <div class="content"><?= $rendered ?>
    </div>
    <?php if ($user->isAdmin()): ?>
    <form method="post" action="/pages/<?= e($page['slug']) ?>/delete">
        <?= $csrf->field() ?>
        <button type="submit"><?= e(t('pages', 'delete_page')) ?></button>
    </form>
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
