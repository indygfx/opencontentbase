<section class="card">
    <p class="muted"><a href="/writing">&larr; All projects</a></p>
    <h1><?= e($project['title']) ?></h1>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>

    <h2>Chapters</h2>
    <?php if ($chapters !== []): ?>
        <table>
            <tr><th>Title</th><th>Slug</th><th></th></tr>
            <?php foreach ($chapters as $ch): ?>
                <tr>
                    <td><a href="/writing/<?= e($project['id']) ?>/chapters/<?= e($ch['slug']) ?>"><?= e($ch['title']) ?></a></td>
                    <td class="muted"><?= e($ch['slug']) ?></td>
                    <td><a href="/writing/<?= e($project['id']) ?>/chapters/<?= e($ch['slug']) ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p class="muted">No chapters yet.</p>
    <?php endif; ?>
    <p><a class="button" href="/writing/<?= e($project['id']) ?>/chapters/new">New chapter</a></p>

    <?php if ($isOwner): ?>
        <h2>Rename project</h2>
        <form method="post" action="/writing/<?= e($project['id']) ?>">
            <?= $csrf->field() ?>
            <label for="title">Title</label>
            <input id="title" name="title" required minlength="2" maxlength="200" value="<?= e($project['title']) ?>">
            <button type="submit">Save</button>
        </form>

        <h2>Share project</h2>
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
            <p class="muted">There is no other user left to share this project with.</p>
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
        <form method="post" action="/writing/<?= e($project['id']) ?>/delete">
            <?= $csrf->field() ?>
            <button type="submit">Delete project</button>
        </form>
    <?php else: ?>
        <p class="muted">Shared with you by <?= e($project['owner_name'] ?? '') ?>. You can read and edit texts, but not manage the project.</p>
    <?php endif; ?>
</section>
