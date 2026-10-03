<section class="card">
    <h1>Stories</h1>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>

    <h2>New story</h2>
    <form method="post" action="/writing">
        <?= $csrf->field() ?>
        <label for="title">Title</label>
        <input id="title" name="title" required minlength="2" maxlength="200" placeholder="My novel" autofocus>
        <button type="submit">Create story</button>
    </form>

    <h2>My stories</h2>
    <?php if ($projects === []): ?>
        <p class="muted">No stories yet. Create your first one above.</p>
    <?php else: ?>
        <table>
            <tr>
                <th>Title</th>
                <th>Owner</th>
                <th>Created</th>
                <th></th>
            </tr>
            <?php foreach ($projects as $p): ?>
                <tr>
                    <td><a href="/writing/<?= e($p['id']) ?>"><?= e($p['title']) ?></a></td>
                    <td>
                        <?= e($p['owner_name']) ?>
                        <?php if ((int)$p['shared'] === 1): ?>
                            <span class="muted">(shared with you)</span>
                        <?php endif; ?>
                    </td>
                    <td class="muted"><?= e((string)$p['created_at']) ?></td>
                    <td><a href="/writing/<?= e($p['id']) ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</section>
