<h1><?= e(t('pages', 'title')) ?></h1>
<?php if (empty($pages)): ?>
    <p class="muted"><?= e(t('pages', 'no_pages')) ?></p>
<?php else: ?>
    <table>
        <thead>
        <tr>
            <th><?= e(t('pages', 'table_title')) ?></th>
            <th><?= e(t('pages', 'table_slug')) ?></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($pages as $page): ?>
            <tr>
                <td><a href="/pages/<?= e($page['slug']) ?>"><?= e($page['title'] ?? $page['slug']) ?></a></td>
                <td><?= e($page['slug']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
