<section class="card">
    <h1>Profile</h1>
    <table>
        <tr><th>Username</th><td><?= e($user->username()) ?></td></tr>
        <tr><th>Role</th><td><?= e($user->role()) ?></td></tr>
    </table>

    <h2>Change password</h2>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
        <p class="success"><?= e($success) ?></p>
    <?php endif; ?>
    <form method="post" action="/profile/password">
        <?= $csrf->field() ?>
        <label for="password_old">Current password</label>
        <input id="password_old" type="password" name="password_old" required autocomplete="current-password">
        <label for="password_new">New password</label>
        <input id="password_new" type="password" name="password_new" required minlength="8" autocomplete="new-password">
        <label for="password_confirm">Confirm new password</label>
        <input id="password_confirm" type="password" name="password_confirm" required minlength="8" autocomplete="new-password">
        <button type="submit">Change password</button>
    </form>
</section>
