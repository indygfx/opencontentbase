<h1>Seiten</h1>
<?php if (empty($pages)): ?>
    <p class="muted">Noch keine Seiten vorhanden.</p>
<?php else: ?>
    <table>
        <thead>
        <tr>
            <th>Titel</th>
            <th>Slug</th>
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
