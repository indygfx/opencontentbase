<section class="card">
    <h1><?= e(t('core', 'users_title')) ?></h1>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
        <p class="success"><?= e($success) ?></p>
    <?php endif; ?>
    <h2><?= e(t('core', 'users_create')) ?></h2>
    <form method="post" action="/users">
        <?= $csrf->field() ?>
        <label for="username"><?= e(t('core', 'login_username')) ?></label>
        <input id="username" name="username" required minlength="3" autofocus autocomplete="off">
        <label for="password"><?= e(t('core', 'login_password')) ?></label>
        <input id="password" type="password" name="password" required minlength="8" autocomplete="new-password">
        <label for="password_confirm"><?= e(t('core', 'profile_confirm_password')) ?></label>
        <input id="password_confirm" type="password" name="password_confirm" required minlength="8" autocomplete="new-password">
        <label for="role"><?= e(t('core', 'users_role')) ?></label>
        <select id="role" name="role">
            <option value="user">user</option>
            <option value="editor">editor</option>
            <option value="admin">admin</option>
        </select>
        <button type="submit"><?= e(t('core', 'users_create_submit')) ?></button>
    </form>
    <h2><?= e(t('core', 'users_existing')) ?></h2>
    <table>
        <tr>
            <th><?= e(t('core', 'profile_username')) ?></th>
            <th><?= e(t('core', 'users_role')) ?></th>
            <th><?= e(t('core', 'users_active_sessions')) ?></th>
            <th><?= e(t('core', 'users_actions')) ?></th>
        </tr>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= e($u['username']) ?></td>
                <td>
                    <?php if ($u['id'] === $currentUserId): ?>
                        <?= e($u['role']) ?> <span class="muted"><?= e(t('core', 'users_fixed')) ?></span>
                    <?php else: ?>
                        <form class="inline" method="post" action="/users/<?= e($u['id']) ?>/role">
                            <?= $csrf->field() ?>
                            <select name="role">
                                <?php foreach (['user', 'editor', 'admin'] as $r): ?>
                                    <option value="<?= e($r) ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit"><?= e(t('core', 'users_set_role')) ?></button>
                        </form>
                    <?php endif; ?>
                </td>
                <td><?= e((string)$u['active_sessions']) ?></td>
                <td>
                    <?php if ($u['id'] !== $currentUserId): ?>
                        <details>
                            <summary><?= e(t('core', 'users_reset_password')) ?></summary>
                            <form method="post" action="/users/<?= e($u['id']) ?>/password">
                                <?= $csrf->field() ?>
                                <label for="pw_<?= e($u["id"]) ?>"><?= e(t('core', 'profile_new_password')) ?></label>
                                <input id="pw_<?= e($u['id']) ?>" type="password" name="password_new" required minlength="8" autocomplete="new-password">
                                <button type="submit"><?= e(t('core', 'users_reset')) ?></button>
                            </form>
                        </details>
                        <form class="inline" method="post" action="/users/<?= e($u['id']) ?>/delete">
                            <?= $csrf->field() ?>
                            <button type="submit"><?= e(t('core', 'users_delete')) ?></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</section>
