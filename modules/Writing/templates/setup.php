<section class="card">
    <p class="muted"><a href="/writing/<?= e($project['id']) ?>">&larr; Back to the story</a></p>
    <h1>Novel Setup</h1>
    <p class="muted"><?= e($project['title']) ?></p>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>

    <h2>Title</h2>
    <form method="post" action="/writing/<?= e($project['id']) ?>">
        <?= $csrf->field() ?>
        <label for="title">Title</label>
        <input id="title" name="title" required minlength="2" maxlength="200" value="<?= e($project['title']) ?>">
        <button type="submit">Save title</button>
    </form>

    <h2>Share story</h2>
    <?php if ($shareTargets !== []): ?>
        <form method="post" action="/writing/<?= e($project['id']) ?>/share">
            <?= $csrf->field() ?>
            <label for="username">Username</label>
            <select id="username" name="username" required>
                <option value="" disabled selected>who to invite</option>
                <?php foreach ($shareTargets as $t): ?>
                    <option value="<?= e($t['username']) ?>"><?= e($t['username']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Share</button>
        </form>
    <?php else: ?>
        <p class="muted">There is no other user left to share this story with.</p>
    <?php endif; ?>

    <?php if ($members !== []): ?>
        <h3>Shared with</h3>
        <table>
            <tr><th>User</th><th>Role</th><th></th></tr>
            <?php foreach ($members as $m): ?>
                <tr>
                    <td><?= e($m['username']) ?></td>
                    <td><?= e($m['role']) ?></td>
                    <td>
                        <form class="inline" method="post" action="/writing/<?= e($project['id']) ?>/unshare">
                            <?= $csrf->field() ?>
                            <input type="hidden" name="user_id" value="<?= e($m['user_id']) ?>">
                            <button type="submit">Revoke</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <h2>Danger zone</h2>
    <form method="post" action="/writing/<?= e($project['id']) ?>/delete" data-confirm="Delete this story and all its content?">
        <?= $csrf->field() ?>
        <button type="submit">Delete story</button>
    </form>
</section>
