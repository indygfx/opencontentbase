<section class="card">
    <h1>User management</h1>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
        <p class="success"><?= e($success) ?></p>
    <?php endif; ?>

    <h2>Create user</h2>
    <form method="post" action="/users">
        <?= $csrf->field() ?>
        <label for="username">Username</label>
        <input id="username" name="username" required minlength="3" autofocus autocomplete="off">
        <label for="password">Password</label>
        <input id="password" type="password" name="password" required minlength="8" autocomplete="new-password">
        <label for="password_confirm">Confirm password</label>
        <input id="password_confirm" type="password" name="password_confirm" required minlength="8" autocomplete="new-password">
        <label for="role">Role</label>
        <select id="role" name="role">
            <option value="user">user</option>
            <option value="editor">editor</option>
            <option value="admin">admin</option>
        </select>
        <button type="submit">Create</button>
    </form>

    <h2>Existing users</h2>
    <table>
        <tr>
            <th>Username</th>
            <th>Role</th>
            <th>Active sessions</th>
            <th>Actions</th>
        </tr>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= e($u['username']) ?></td>
                <td>
                    <?php if ($u['username'] === 'admin' || $u['id'] === $currentUserId): ?>
                        <?= e($u['role']) ?> <span class="muted">(fixed)</span>
                    <?php else: ?>
                        <form class="inline" method="post" action="/users/<?= e($u['id']) ?>/role">
                            <?= $csrf->field() ?>
                            <select name="role">
                                <?php foreach (['user', 'editor', 'admin'] as $r): ?>
                                    <option value="<?= e($r) ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit">Set role</button>
                        </form>
                    <?php endif; ?>
                </td>
                <td><?= e((string)$u['active_sessions']) ?></td>
                <td>
                    <?php if ($u['id'] !== $currentUserId): ?>
                        <details>
                            <summary>Reset password</summary>
                            <form method="post" action="/users/<?= e($u['id']) ?>/password">
                                <?= $csrf->field() ?>
                                <label for="pw_<?= e($u["id"]) ?>">New password</label>
                                <input id="pw_<?= e($u['id']) ?>" type="password" name="password_new" required minlength="8" autocomplete="new-password">
                                <button type="submit">Reset</button>
                            </form>
                        </details>
                        <?php if ($u['username'] !== 'admin'): ?>
                            <form class="inline" method="post" action="/users/<?= e($u['id']) ?>/delete">
                                <?= $csrf->field() ?>
                                <button type="submit">Delete</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</section>
