<section class="card">
    <h1><?= e(t('core', 'profile_title')) ?></h1>
    <table>
        <tr><th><?= e(t('core', 'profile_username')) ?></th><td><?= e($user->username()) ?></td></tr>
        <tr><th><?= e(t('core', 'profile_role')) ?></th><td><?= e($user->role()) ?></td></tr>
    </table>
    <h2><?= e(t('core', 'profile_change_password')) ?></h2>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
        <p class="success"><?= e($success) ?></p>
    <?php endif; ?>
    <form method="post" action="/profile/password">
        <?= $csrf->field() ?>
        <label for="password_old"><?= e(t('core', 'profile_current_password')) ?></label>
        <input id="password_old" type="password" name="password_old" required autocomplete="current-password">
        <label for="password_new"><?= e(t('core', 'profile_new_password')) ?></label>
        <input id="password_new" type="password" name="password_new" required minlength="8" autocomplete="new-password">
        <label for="password_confirm"><?= e(t('core', 'profile_confirm_password')) ?></label>
        <input id="password_confirm" type="password" name="password_confirm" required minlength="8" autocomplete="new-password">
        <button type="submit"><?= e(t('core', 'profile_change_password_submit')) ?></button>
    </form>
</section>
