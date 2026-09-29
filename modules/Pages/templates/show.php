<article>
    <h1><?= e($page['title'] ?? $page['slug']) ?></h1>
    <div class="content"><?= $rendered ?>
    </div>
    <?php if ($user->isAdmin()): ?>
    <form method="post" action="/pages/<?= e($page['slug']) ?>/delete">
        <?= $csrf->field() ?>
        <button type="submit">Seite löschen</button>
    </form>
    <?php endif; ?>
</article>

<section class="preview">
    <h2>Revisionen</h2>
    <table>
        <thead>
        <tr>
            <th>Titel</th>
            <th>Autor</th>
            <th>Erstellt</th>
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
